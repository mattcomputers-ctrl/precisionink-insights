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
    /** group=preferred|last → the nto_items field to group on. */
    private function groupField(): ?string
    {
        return match ($_GET['group'] ?? '') {
            'preferred'       => 'preferred_vendor',
            'last', 'vendor'  => 'vendor',
            default           => null,
        };
    }

    public function index(): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $field  = $this->groupField();

        $rows = $this->rows($search);

        $groups = [];
        if ($field !== null) {
            foreach ($rows as $r) {
                $groups[trim((string) ($r[$field] ?? ''))][] = $r;
            }
            // Vendors alphabetically; "no vendor on file" last.
            uksort($groups, fn($a, $b) => [$a === '', $a] <=> [$b === '', $b]);
        }

        layout('need_to_order::index', [
            'pageTitle' => 'Need to Order',
            'rows'      => $rows,
            'groups'    => $groups,
            'groupBy'   => $field === null ? '' : ($field === 'vendor' ? 'last' : 'preferred'),
            'search'    => $search,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'need_to_order');
    }

    public function export(): void
    {
        require_permission('export');
        $field = $this->groupField();
        $rows = $this->rows(trim((string) ($_GET['q'] ?? '')));
        if ($field !== null) {
            usort($rows, fn($a, $b) =>
                [($a[$field] ?? '') === '', $a[$field] ?? '', $a['first_needed'] ?? '9999']
                <=> [($b[$field] ?? '') === '', $b[$field] ?? '', $b['first_needed'] ?? '9999']);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['preferred_vendor'], $r['vendor'], $r['vendor_code'], $r['item_code'], $r['description'],
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
            'details' => json_encode(['rows' => count($out), 'group' => $_GET['group'] ?? 'none']),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        ListExporter::stream('Need to order',
            ['Preferred vendor', 'Last purchased from', 'Vendor code', 'Item', 'Description', 'Qty to order', 'Unit', 'On open POs', 'First needed', 'For', 'Needed by', 'To make'],
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
                stripos($r['item_code'] . ' ' . $r['description'] . ' ' . ($r['preferred_vendor'] ?? '')
                    . ' ' . ($r['vendor'] ?? '') . ' ' . ($r['vendor_code'] ?? ''), $search) !== false));
        }

        // Priority: what open tickets wait on first, then soonest needed.
        usort($rows, fn($a, $b) =>
            [-(int) $a['src_ticket'], $a['first_needed'] ?? '9999', -(float) $a['qty_needed']]
            <=> [-(int) $b['src_ticket'], $b['first_needed'] ?? '9999', -(float) $b['qty_needed']]);
        return $rows;
    }
}
