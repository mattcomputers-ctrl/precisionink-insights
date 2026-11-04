<?php

declare(strict_types=1);

namespace PII\Modules\BatchTickets;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * BatchTicketsController — live, read-only CMS queries over MFBA/MFPP
 * tickets, open and historical.
 */
class BatchTicketsController
{
    private const MAX_ROWS = 200;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $tab = in_array($_GET['tab'] ?? '', ['open', 'completed', 'all'], true) ? $_GET['tab'] : 'open';
        $tickets = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $where = match ($tab) {
                'open'      => "o.Status NOT IN ('CMP','CLS')",
                'completed' => "o.Status IN ('CMP','CLS')",
                default     => '1=1',
            };
            $params = [];
            if ($q !== '') {
                $like = '%' . cms_like_escape($q) . '%';
                $where .= " AND (CAST(o.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR i.ItemCode LIKE ? ESCAPE '!' OR i.Description LIKE ? ESCAPE '!'
                    OR EXISTS (SELECT 1 FROM CMS.dbo.Lot plot
                               JOIN CMS.dbo.OrdDetail pod ON pod.OrdDetail = plot.OrdDetail
                               WHERE pod.Ordr = o.Ordr AND CAST(plot.Lot AS VARCHAR(20)) LIKE ? ESCAPE '!'))";
                $params = array_fill(0, 4, $like);
            }

            $tickets = $cms->fetchAll("
                SELECT TOP " . (self::MAX_ROWS + 1) . "
                       o.Ordr, o.Context, o.Status,
                       i.ItemCode, i.Description, od.QtyReqd AS Qty, i.Unit,
                       CONVERT(VARCHAR(10), o.DateRequired, 23)  AS DateRequired,
                       CONVERT(VARCHAR(10), o.DateCompleted, 23) AS DateCompleted,
                       bsd.Ordr AS BatchOrdr, bsd.CustomerCode
                FROM CMS.dbo.Ordr o
                JOIN CMS.dbo.OrdDetail od ON od.Ordr = o.Ordr AND od.Context = 'PK'
                JOIN CMS.dbo.Item i ON i.Item = od.Item
                OUTER APPLY (
                    SELECT TOP 1 b.Ordr, b.CustomerCode
                    FROM CMS.dbo.BatchSheetDemand b WHERE b.DemandOrdr = o.Ordr
                ) bsd
                WHERE o.Context IN ('MFBA','MFPP') AND {$where}
                ORDER BY o.Ordr DESC", $params);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $truncated = count($tickets) > self::MAX_ROWS;
        layout('batch_tickets::index', [
            'pageTitle' => 'Batch Tickets',
            'q'         => $q,
            'tab'       => $tab,
            'tickets'   => array_slice($tickets, 0, self::MAX_ROWS),
            'truncated' => $truncated,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'batch_tickets');
    }

    public function detail(string $ordr): void
    {
        $ordr = (int) $ordr;
        $head = null;
        $lines = [];
        $lots = [];
        $qc = [];
        $linked = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $head = $cms->fetch("
                SELECT o.Ordr, o.Context, o.Status, CONVERT(VARCHAR(2000), o.Comment) AS Comment,
                       i.ItemCode, i.Description, od.QtyReqd AS Qty, i.Unit,
                       CONVERT(VARCHAR(10), o.DateRequired, 23)  AS DateRequired,
                       CONVERT(VARCHAR(10), o.DateReleased, 23)  AS DateReleased,
                       CONVERT(VARCHAR(10), o.DateScheduled, 23) AS DateScheduled,
                       CONVERT(VARCHAR(10), o.DateCompleted, 23) AS DateCompleted
                FROM CMS.dbo.Ordr o
                JOIN CMS.dbo.OrdDetail od ON od.Ordr = o.Ordr AND od.Context = 'PK'
                JOIN CMS.dbo.Item i ON i.Item = od.Item
                WHERE o.Ordr = ? AND o.Context IN ('MFBA','MFPP')", [$ordr]);

            if ($head) {
                // The batch sheet: ingredients + instructions in execution order.
                $lines = $cms->fetchAll("
                    SELECT od.Context, od.QtyReqd, od.QtyUsed, od.[Percent],
                           od.Description AS InstrText,
                           COALESCE(NULLIF(od.SupUnit, ''), i.Unit) AS Unit,
                           i.ItemCode, i.Description AS ItemDescription
                    FROM CMS.dbo.OrdDetail od
                    LEFT JOIN CMS.dbo.Item i ON i.Item = od.Item
                    WHERE od.Ordr = ? AND od.Context IN ('UI','INSTR')
                    ORDER BY COALESCE(od.ExecOrder, od.Line, 999999), od.OrdDetail", [$ordr]);

                // Lots this ticket produced…
                $lots = $cms->fetchAll("
                    SELECT lot.Lot, i.ItemCode,
                           CONVERT(VARCHAR(10), lot.ManfDate, 23) AS ManfDate
                    FROM CMS.dbo.Lot lot
                    JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = lot.OrdDetail
                    LEFT JOIN CMS.dbo.Item i ON i.Item = lot.Item
                    WHERE od.Ordr = ? AND od.Context = 'PK'
                    ORDER BY lot.Lot", [$ordr]);

                // …and the QC results recorded against those lots.
                if ($lots !== []) {
                    $in = implode(',', array_fill(0, count($lots), '?'));
                    $qc = $cms->fetchAll("
                        SELECT lot2.Lot, t.Description AS TestName,
                               CONVERT(VARCHAR(400), lst.Result) AS Result, lst.Passed,
                               CONVERT(VARCHAR(16), lst.TestedTime, 120) AS TestedTime,
                               u.DisplayName AS TestedBy, CONVERT(VARCHAR(1000), lst.Comment) AS Comment
                        FROM CMS.dbo.LocationSampleTest lst
                        JOIN CMS.dbo.Test t ON t.Test = lst.Test
                        JOIN CMS.dbo.Inventory inv ON inv.Location = lst.Location
                        JOIN CMS.dbo.Sublot sl ON sl.Sublot = inv.Sublot
                        JOIN CMS.dbo.Lot lot2 ON lot2.Lot = sl.Lot
                        LEFT JOIN CMS.dbo.[User] u ON u.[User] = lst.TestedBy
                        WHERE lot2.Lot IN ({$in})
                        ORDER BY lst.TestedTime", array_column($lots, 'Lot'));
                }

                // The rest of the chain: this pack's batch / this batch's packs.
                $linked = $cms->fetchAll("
                    SELECT bsd.Ordr AS SupplyOrdr, bsd.DemandOrdr, bsd.MakeItemCode, bsd.CustomerCode
                    FROM CMS.dbo.BatchSheetDemand bsd
                    WHERE bsd.Ordr = ? OR bsd.DemandOrdr = ?", [$ordr, $ordr]);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        if ($head === null && $error === null) {
            render_not_found();
        }

        layout('batch_tickets::detail', [
            'pageTitle' => 'Ticket ' . $ordr,
            'ordr'      => $ordr,
            'head'      => $head,
            'lines'     => $lines,
            'lots'      => $lots,
            'qc'        => $qc,
            'linked'    => $linked,
            'error'     => $error,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'batch_tickets');
    }
}
