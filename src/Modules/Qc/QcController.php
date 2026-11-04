<?php

declare(strict_types=1);

namespace PII\Modules\Qc;

use PII\Core\CMSDatabase;
use PII\Core\CMSUnavailableException;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * QcController — live, read-only CMS queries over test results.
 *
 * Chain (verified live 2026-11-04): LocationSampleTest → Location (the
 * sample container) → Inventory → Sublot → Lot → Lot.OrdDetail → the
 * batch ticket that made the lot, + Lot.Item for the item tested.
 * Specs come from ItemTest for that item + test.
 */
class QcController
{
    private const MAX_ROWS = 200;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $failedOnly = ($_GET['failed'] ?? '') === '1';
        $results = [];
        $error = null;

        try {
            $cms = CMSDatabase::getInstance();
            $where = '1=1';
            $params = [];
            if ($failedOnly) {
                $where .= ' AND lst.Passed = 0';
            }
            if ($q !== '') {
                $like = '%' . cms_like_escape($q) . '%';
                $where .= " AND (i.ItemCode LIKE ? ESCAPE '!' OR i.Description LIKE ? ESCAPE '!'
                    OR CAST(lot.Lot AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR CAST(o.Ordr AS VARCHAR(20)) LIKE ? ESCAPE '!'
                    OR t.Description LIKE ? ESCAPE '!' OR u.DisplayName LIKE ? ESCAPE '!')";
                $params = array_fill(0, 6, $like);
            }

            $results = $cms->fetchAll("
                SELECT TOP " . (self::MAX_ROWS + 1) . "
                       CONVERT(VARCHAR(16), lst.TestedTime, 120) AS TestedTime,
                       t.Description AS TestName, t.Unit AS TestUnit,
                       CONVERT(VARCHAR(400), lst.Result) AS Result, lst.Passed,
                       CONVERT(VARCHAR(1000), lst.Comment) AS Comment,
                       u.DisplayName AS TestedBy,
                       i.ItemCode, i.Description AS ItemDescription,
                       lot.Lot, o.Ordr AS Ticket, o.Context AS TicketCtx,
                       spec.Min AS SpecMin, spec.Target AS SpecTarget, spec.Max AS SpecMax
                FROM CMS.dbo.LocationSampleTest lst
                JOIN CMS.dbo.Test t ON t.Test = lst.Test
                LEFT JOIN CMS.dbo.[User] u ON u.[User] = lst.TestedBy
                LEFT JOIN CMS.dbo.Inventory inv ON inv.Location = lst.Location
                LEFT JOIN CMS.dbo.Sublot sl ON sl.Sublot = inv.Sublot
                LEFT JOIN CMS.dbo.Lot lot ON lot.Lot = sl.Lot
                LEFT JOIN CMS.dbo.Item i ON i.Item = lot.Item
                LEFT JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = lot.OrdDetail
                LEFT JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
                OUTER APPLY (
                    SELECT TOP 1 it.Min, it.Max, it.Target
                    FROM CMS.dbo.ItemTest it
                    WHERE it.Item = lot.Item AND it.Test = lst.Test
                ) spec
                WHERE {$where}
                ORDER BY lst.TestedTime DESC", $params);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $truncated = count($results) > self::MAX_ROWS;
        layout('qc::index', [
            'pageTitle'  => 'QC Results',
            'q'          => $q,
            'failedOnly' => $failedOnly,
            'results'    => array_slice($results, 0, self::MAX_ROWS),
            'truncated'  => $truncated,
            'error'      => $error,
            'asOf'       => SyncSettings::lastSuccessAt(),
        ], 'qc');
    }
}
