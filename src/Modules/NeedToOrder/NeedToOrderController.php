<?php

declare(strict_types=1);

namespace PII\Modules\NeedToOrder;

use PII\Core\Database;
use PII\Modules\OrderReadiness\ListExporter;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * NeedToOrderController — snapshot reads only (nto_items), flat or
 * grouped by vendor. Priority = first-needed date, open tickets first.
 */
class NeedToOrderController
{
    public function index(): void
    {
        $search  = trim((string) ($_GET['q'] ?? ''));
        $byVendor = ($_GET['group'] ?? '') === 'vendor';

        $rows = $this->rows($search);

        $groups = [];
        if ($byVendor) {
            foreach ($rows as $r) {
                $groups[$r['vendor'] ?? ''][] = $r;
            }
            // Vendors alphabetically; "no vendor on file" last.
            uksort($groups, fn($a, $b) => [$a === '', $a] <=> [$b === '', $b]);
        }

        layout('need_to_order::index', [
            'pageTitle' => 'Need to Order',
            'rows'      => $rows,
            'groups'    => $groups,
            'byVendor'  => $byVendor,
            'search'    => $search,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'need_to_order');
    }

    public function export(): void
    {
        require_permission('export');
        $byVendor = ($_GET['group'] ?? '') === 'vendor';
        $rows = $this->rows(trim((string) ($_GET['q'] ?? '')));
        if ($byVendor) {
            usort($rows, fn($a, $b) =>
                [($a['vendor'] ?? '') === '', $a['vendor'] ?? '', $a['first_needed'] ?? '9999']
                <=> [($b['vendor'] ?? '') === '', $b['vendor'] ?? '', $b['first_needed'] ?? '9999']);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['vendor'], $r['vendor_code'], $r['item_code'], $r['description'],
                round((float) $r['qty_needed'], 2), $r['unit'], (float) $r['on_po'],
                $r['first_needed'],
                ($r['src_ticket'] ? 'open tickets' : '') . ($r['src_ticket'] && $r['src_order'] ? ' + ' : '') . ($r['src_order'] ? 'new batches' : ''),
                implode('; ', array_column($r['refs_list'], 'ref')),
                implode(' → ', $r['via_list']),
            ];
        }

        Database::getInstance()->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'need_to_order',
            'entity_id' => null, 'action' => 'export',
            'details' => json_encode(['rows' => count($out), 'group' => $byVendor ? 'vendor' : 'none']),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        ListExporter::stream('Need to order',
            ['Vendor', 'Vendor code', 'Item', 'Description', 'Qty to order', 'Unit', 'On open POs', 'First needed', 'For', 'Needed by', 'To make'],
            $out, ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx');
    }

    /** Snapshot rows, decoded + filtered + priority-sorted. */
    private function rows(string $search): array
    {
        $rows = Database::getInstance()->fetchAll("SELECT * FROM nto_items");
        foreach ($rows as &$r) {
            $r['refs_list'] = json_decode((string) ($r['refs'] ?? '[]'), true) ?: [];
            $r['via_list']  = json_decode((string) ($r['via'] ?? '[]'), true) ?: [];
        }
        unset($r);

        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                stripos($r['item_code'] . ' ' . $r['description'] . ' ' . ($r['vendor'] ?? '') . ' ' . ($r['vendor_code'] ?? ''), $search) !== false));
        }

        // Priority: what open tickets wait on first, then soonest needed.
        usort($rows, fn($a, $b) =>
            [-(int) $a['src_ticket'], $a['first_needed'] ?? '9999', -(float) $a['qty_needed']]
            <=> [-(int) $b['src_ticket'], $b['first_needed'] ?? '9999', -(float) $b['qty_needed']]);
        return $rows;
    }
}
