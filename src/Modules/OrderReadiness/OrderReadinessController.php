<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

use PII\Core\CSRF;
use PII\Core\Database;
use PII\Services\Settings;
use PII\Services\Sync\SyncSettings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * OrderReadinessController — snapshot-table reads only (never CMS).
 */
class OrderReadinessController
{
    /* ── Overview ──────────────────────────────────────────────── */

    public function overview(): void
    {
        $db = Database::getInstance();
        $rollups = $this->orderRollups();

        $counts = ['ready' => 0, 'waiting' => 0, 'short' => 0, 'short_discontinued' => 0];
        foreach ($rollups as $r) {
            $counts[$r['status']]++;
        }

        $sug = ['below' => 0, 'near' => 0, 'negative' => 0];
        foreach ($db->fetchAll("SELECT severity, COUNT(*) AS n FROM or_production_suggestions GROUP BY severity") as $r) {
            $sug[$r['severity']] = (int) $r['n'];
        }

        layout('order_readiness::overview', [
            'pageTitle' => 'Order Readiness',
            'counts'    => $counts,
            'sug'       => $sug,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    /* ── Orders list (tabs per status) ─────────────────────────── */

    public function orders(): void
    {
        $tab    = $_GET['tab'] ?? 'ready';
        $tab    = in_array($tab, ['ready', 'waiting', 'short', 'discontinued', 'all', 'priority'], true) ? $tab : 'ready';
        $search = trim((string) ($_GET['q'] ?? ''));

        $rollups = $this->orderRollups();

        // Attach note counts.
        $noteCounts = [];
        foreach (Database::getInstance()->fetchAll(
            "SELECT ordr, COUNT(*) AS n FROM or_order_notes GROUP BY ordr"
        ) as $r) {
            $noteCounts[(int) $r['ordr']] = (int) $r['n'];
        }

        $filtered = array_filter($rollups, function ($o) use ($tab, $search) {
            $match = match ($tab) {
                'ready'        => $o['status'] === 'ready',
                'waiting'      => $o['status'] === 'waiting',
                'short'        => in_array($o['status'], ['short', 'short_discontinued'], true),
                'discontinued' => $o['status'] === 'short_discontinued',
                default        => true,
            };
            if ($match && $search !== '') {
                $hay = $o['ordr'] . ' ' . $o['bill_to'] . ' ' . $o['bill_to_name'] . ' '
                     . $o['ship_to_name'] . ' ' . $o['po_number'] . ' ' . $o['items'];
                $match = stripos($hay, $search) !== false;
            }
            return $match;
        });

        if ($tab === 'priority') {
            usort($filtered, fn($a, $b) => $a['position'] <=> $b['position']);
        } else {
            usort($filtered, fn($a, $b) =>
                [$a['date_required'] ?? '9999', $a['ordr']] <=> [$b['date_required'] ?? '9999', $b['ordr']]);
        }

        $tabCounts = ['ready' => 0, 'waiting' => 0, 'short' => 0, 'discontinued' => 0, 'all' => count($rollups)];
        foreach ($rollups as $o) {
            if ($o['status'] === 'ready')   $tabCounts['ready']++;
            if ($o['status'] === 'waiting') $tabCounts['waiting']++;
            if (in_array($o['status'], ['short', 'short_discontinued'], true)) $tabCounts['short']++;
            if ($o['status'] === 'short_discontinued') $tabCounts['discontinued']++;
        }

        layout('order_readiness::orders', [
            'pageTitle'  => 'Orders',
            'tab'        => $tab,
            'search'     => $search,
            'orders'     => array_values($filtered),
            'tabCounts'  => $tabCounts,
            'noteCounts' => $noteCounts,
            'asOf'       => SyncSettings::lastSuccessAt(),
            'canReorder' => user_can('production.mark'),
        ], 'order_readiness');
    }

    /** AJAX: the expanded line view for one order (+ notes). */
    public function orderLines(string $ordr): void
    {
        $db   = Database::getInstance();
        $ordr = (int) $ordr;

        $lines = $db->fetchAll(
            "SELECT l.*, c.status AS cov_status, c.reason, c.shortfall, c.allocations
             FROM or_order_lines l
             LEFT JOIN or_coverage_results c ON c.ord_detail = l.ord_detail
             WHERE l.ordr = ?
             ORDER BY l.ord_detail",
            [$ordr]
        );
        $notes = $db->fetchAll(
            "SELECT n.*, u.display_name, u.username
             FROM or_order_notes n LEFT JOIN users u ON u.id = n.user_id
             WHERE n.ordr = ? ORDER BY n.id DESC",
            [$ordr]
        );

        view('order_readiness::_order_lines', [
            'ordr'  => $ordr,
            'lines' => $lines,
            'notes' => $notes,
        ]);
    }

    /** POST: add a note to an order (kept forever; searchable later). */
    public function addNote(string $ordr): void
    {
        CSRF::validateRequest();
        $ordr = (int) $ordr;
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '' || $ordr <= 0) {
            json_response(['ok' => false, 'message' => 'Write something first.'], 400);
        }
        if (mb_strlen($note) > 2000) {
            json_response(['ok' => false, 'message' => 'Notes are limited to 2,000 characters.'], 400);
        }
        Database::getInstance()->insert('or_order_notes', [
            'ordr'       => $ordr,
            'note'       => $note,
            'user_id'    => current_user_id(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        json_response(['ok' => true]);
    }

    /** POST: new priority order (drag-and-drop list of order numbers). */
    public function savePriority(): void
    {
        require_permission('production.mark');
        CSRF::validateRequest();

        $raw = $_POST['positions'] ?? '';
        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw))));
        if ($ids === []) {
            json_response(['ok' => false, 'message' => 'Nothing to save.'], 400);
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Keep any orders not in the submitted list after the listed ones.
            $existing = array_column($db->fetchAll("SELECT ordr FROM or_order_priority ORDER BY position"), 'ordr');
            $rest = array_values(array_diff(array_map('intval', $existing), $ids));
            $db->delete('or_order_priority', '1=1');
            foreach (array_merge($ids, $rest) as $i => $o) {
                $db->insert('or_order_priority', ['ordr' => $o, 'position' => $i + 1]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            json_response(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        // Recompute coverage from the current snapshot with the new priority.
        $this->recomputeCoverageFromSnapshot();

        Database::getInstance()->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'order_priority',
            'entity_id' => null, 'action' => 'reorder',
            'details' => json_encode(['order' => $ids]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        json_response(['ok' => true]);
    }

    /* ── By item ───────────────────────────────────────────────── */

    public function byItem(): void
    {
        require_permission('orders.items_view');
        $db = Database::getInstance();
        $search = trim((string) ($_GET['q'] ?? ''));

        $rows = $db->fetchAll(
            "SELECT l.item_code,
                    MAX(COALESCE(l.alias_description, i.description)) AS description,
                    MAX(i.unit) AS unit,
                    SUM(l.qty_remaining) AS open_demand,
                    MAX(COALESCE(v.qty_usable, 0)) AS available,
                    MAX(COALESCE(i.is_discontinued, 0)) AS is_discontinued,
                    COUNT(DISTINCT l.ordr) AS order_count
             FROM or_order_lines l
             LEFT JOIN or_items i     ON i.item_code = l.item_code
             LEFT JOIN or_inventory v ON v.item_code = l.item_code
             GROUP BY l.item_code
             ORDER BY l.item_code"
        );

        $supply = [];
        foreach ($db->fetchAll("SELECT item_code, SUM(qty) AS q FROM or_tickets WHERE context='MFPP' GROUP BY item_code") as $t) {
            $supply[$t['item_code']] = (float) $t['q'];
        }

        // Who gets the stock: allocations per item from coverage results.
        $allocByItem = [];
        foreach ($db->fetchAll(
            "SELECT c.ord_detail, c.ordr, c.status, c.allocations, l.item_code, l.qty_remaining, o.bill_to_name
             FROM or_coverage_results c
             JOIN or_order_lines l ON l.ord_detail = c.ord_detail
             LEFT JOIN or_orders o ON o.ordr = c.ordr"
        ) as $cr) {
            $allocByItem[$cr['item_code']][] = $cr;
        }

        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                stripos($r['item_code'] . ' ' . $r['description'], $search) !== false));
        }

        layout('order_readiness::by_item', [
            'pageTitle'   => 'Coverage by item',
            'rows'        => $rows,
            'supply'      => $supply,
            'allocByItem' => $allocByItem,
            'search'      => $search,
            'asOf'        => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    /* ── Batch tickets ─────────────────────────────────────────── */

    public function tickets(): void
    {
        require_permission('orders.tickets_view');
        $db = Database::getInstance();

        $tickets = $db->fetchAll(
            "SELECT t.*, i.description
             FROM or_tickets t
             LEFT JOIN or_items i ON i.item_code = t.item_code
             ORDER BY t.date_required, t.ordr"
        );

        // Which order lines each ticket is covering (from allocations JSON).
        $covering = [];
        foreach ($db->fetchAll(
            "SELECT c.allocations, c.ordr, o.bill_to_name, l.item_code, l.alias_code
             FROM or_coverage_results c
             JOIN or_order_lines l ON l.ord_detail = c.ord_detail
             LEFT JOIN or_orders o ON o.ordr = c.ordr
             WHERE c.allocations LIKE '%ticket%'"
        ) as $cr) {
            foreach (json_decode((string) $cr['allocations'], true) ?: [] as $a) {
                if (($a['type'] ?? '') === 'ticket') {
                    $covering[(int) $a['ticket']][] = [
                        'ordr' => (int) $cr['ordr'],
                        'bill_to_name' => $cr['bill_to_name'],
                        'qty' => (float) $a['qty'],
                    ];
                }
            }
        }

        layout('order_readiness::tickets', [
            'pageTitle' => 'Batch tickets',
            'tickets'   => $tickets,
            'covering'  => $covering,
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    /* ── Production plan ───────────────────────────────────────── */

    public function production(): void
    {
        require_permission('production.view');
        $db = Database::getInstance();

        $rows = $db->fetchAll(
            "SELECT s.*, i.description, m.mark, m.skip_until, m.note AS mark_note,
                    u.display_name AS marked_by
             FROM or_production_suggestions s
             LEFT JOIN or_items i ON i.item_code = s.item_code
             LEFT JOIN or_suggestion_marks m ON m.item_code = s.item_code
             LEFT JOIN users u ON u.id = m.user_id
             ORDER BY FIELD(s.severity, 'negative','below','near'), s.shortfall DESC"
        );

        // Reactivated / newly-discontinued badges (§8.2).
        $badgeDays = Settings::getInt('or.badge_days', 14);
        $badges = [];
        foreach ($db->fetchAll(
            "SELECT item_code, old_value, new_value, seen_at
             FROM or_item_min_stock_history
             WHERE seen_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY id",
            [$badgeDays]
        ) as $h) {
            $was99 = $h['old_value'] !== null && abs((float) $h['old_value'] - 99.0) < 1e-9;
            $is99  = $h['new_value'] !== null && abs((float) $h['new_value'] - 99.0) < 1e-9;
            if ($was99 && !$is99) {
                $badges[$h['item_code']] = 'reactivated';
            } elseif (!$was99 && $is99) {
                $badges[$h['item_code']] = 'newly_discontinued';
            }
        }

        layout('order_readiness::production', [
            'pageTitle' => 'Production plan',
            'rows'      => $rows,
            'badges'    => $badges,
            'canMark'   => user_can('production.mark'),
            'asOf'      => SyncSettings::lastSuccessAt(),
        ], 'order_readiness');
    }

    public function markSuggestion(): void
    {
        require_permission('production.mark');
        CSRF::validateRequest();
        $db   = Database::getInstance();
        $code = trim((string) ($_POST['item_code'] ?? ''));
        $mark = (string) ($_POST['mark'] ?? '');

        if ($code === '') {
            back();
        }
        if ($mark === 'clear') {
            $db->delete('or_suggestion_marks', 'item_code = ?', [$code]);
        } elseif (in_array($mark, ['scheduled', 'skip'], true)) {
            $until = ($mark === 'skip' && valid_date((string) ($_POST['skip_until'] ?? ''))) ? $_POST['skip_until'] : null;
            $db->delete('or_suggestion_marks', 'item_code = ?', [$code]);
            $db->insert('or_suggestion_marks', [
                'item_code'  => $code,
                'mark'       => $mark,
                'skip_until' => $until,
                'note'       => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255),
                'user_id'    => current_user_id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $db->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'production_mark',
            'entity_id' => $code, 'action' => $mark,
            'details' => json_encode(['note' => $_POST['note'] ?? '']),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $_SESSION['_flash']['success'] = 'Saved.';
        back();
    }

    /* ── Notes lookup ──────────────────────────────────────────── */

    public function notes(): void
    {
        $db = Database::getInstance();
        $search = trim((string) ($_GET['q'] ?? ''));

        $sql = "SELECT n.*, u.display_name, u.username, o.bill_to_name
                FROM or_order_notes n
                LEFT JOIN users u ON u.id = n.user_id
                LEFT JOIN or_orders o ON o.ordr = n.ordr";
        $params = [];
        if ($search !== '') {
            $sql .= " WHERE CAST(n.ordr AS CHAR) LIKE ? OR n.note LIKE ? OR o.bill_to_name LIKE ?";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like];
        }
        $sql .= " ORDER BY n.id DESC LIMIT 300";

        layout('order_readiness::notes', [
            'pageTitle' => 'Order notes',
            'rows'      => $db->fetchAll($sql, $params),
            'search'    => $search,
        ], 'order_readiness');
    }

    /* ── Exports ───────────────────────────────────────────────── */

    public function export(string $what): void
    {
        require_permission('export');
        $db = Database::getInstance();
        $format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        [$title, $header, $rows] = match ($what) {
            'orders' => $this->exportOrders(),
            'production' => $this->exportProduction($db),
            'tickets' => $this->exportTickets($db),
            default => [null, [], []],
        };
        if ($title === null) {
            render_not_found();
        }

        Database::getInstance()->insert('audit_log', [
            'user_id' => current_user_id(), 'entity_type' => 'order_readiness',
            'entity_id' => $what, 'action' => 'export_' . $format,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        ListExporter::stream($title, $header, $rows, $format);
    }

    private function exportOrders(): array
    {
        $rollups = $this->orderRollups();
        usort($rollups, fn($a, $b) => [$a['date_required'] ?? '9999', $a['ordr']] <=> [$b['date_required'] ?? '9999', $b['ordr']]);
        $statusLabel = ['ready' => 'Ready to ship', 'waiting' => 'Waiting on batch', 'short' => 'Short', 'short_discontinued' => 'Short - discontinued'];
        $rows = [];
        foreach ($rollups as $o) {
            $rows[] = [
                $o['ordr'], $o['status_code'], $o['bill_to_name'], $o['ship_to_name'],
                $o['date_required'], $o['line_count'], $statusLabel[$o['status']], $o['summary'], $o['po_number'],
            ];
        }
        return ['Open orders', ['Order #', 'CMS status', 'Customer', 'Ship to', 'Required', 'Lines', 'Readiness', 'Why', 'PO number'], $rows];
    }

    private function exportProduction(Database $db): array
    {
        $rows = [];
        foreach ($db->fetchAll(
            "SELECT s.*, i.description FROM or_production_suggestions s
             LEFT JOIN or_items i ON i.item_code = s.item_code
             ORDER BY FIELD(s.severity,'negative','below','near'), s.shortfall DESC"
        ) as $s) {
            $rows[] = [
                $s['item_code'], $s['description'], ucfirst($s['severity']), $s['minimum_stock'],
                $s['on_hand'], $s['on_tickets'], $s['open_demand'], $s['projected'],
                $s['shortfall'], $s['suggested_qty'], $s['recipe_number'], $s['last_made'],
            ];
        }
        return ['Production plan', ['Item', 'Description', 'Severity', 'Minimum', 'On hand', 'On tickets', 'Open demand', 'Projected', 'Shortfall', 'Suggested qty', 'Recipe', 'Last made'], $rows];
    }

    private function exportTickets(Database $db): array
    {
        $rows = [];
        foreach ($db->fetchAll(
            "SELECT t.*, i.description FROM or_tickets t
             LEFT JOIN or_items i ON i.item_code = t.item_code
             ORDER BY t.date_required, t.ordr"
        ) as $t) {
            $rows[] = [
                $t['ordr'], $t['context'], $t['status'], $t['item_code'], $t['description'],
                $t['qty'], $t['unit'], $t['date_required'], $t['date_released'], $t['customer_hint'],
            ];
        }
        return ['Open batch tickets', ['Ticket #', 'Type', 'Status', 'Item', 'Description', 'Qty', 'Unit', 'Required', 'Released', 'Cut for'], $rows];
    }

    /* ── Shared helpers ────────────────────────────────────────── */

    /** One row per open order with its rolled-up coverage status. */
    private function orderRollups(): array
    {
        $db = Database::getInstance();
        $orders = [];
        foreach ($db->fetchAll(
            "SELECT o.*, COALESCE(p.position, 999999) AS position
             FROM or_orders o
             LEFT JOIN or_order_priority p ON p.ordr = o.ordr"
        ) as $o) {
            $o['lines'] = [];
            $orders[(int) $o['ordr']] = $o;
        }
        foreach ($db->fetchAll(
            "SELECT c.ordr, c.status, l.item_code, l.alias_code
             FROM or_coverage_results c JOIN or_order_lines l ON l.ord_detail = c.ord_detail"
        ) as $c) {
            if (isset($orders[(int) $c['ordr']])) {
                $orders[(int) $c['ordr']]['lines'][] = $c;
            }
        }

        $out = [];
        foreach ($orders as $o) {
            if ($o['lines'] === []) {
                continue;   // nothing open to cover
            }
            $roll = CoverageEngine::rollUp(array_column($o['lines'], 'status'));
            $out[] = [
                'ordr'          => (int) $o['ordr'],
                'status_code'   => $o['status'],
                'bill_to'       => $o['bill_to'],
                'bill_to_name'  => $o['bill_to_name'],
                'ship_to_name'  => $o['ship_to_name'],
                'po_number'     => $o['po_number'],
                'date_required' => $o['date_required'],
                'date_ordered'  => $o['date_ordered'],
                'position'      => (int) $o['position'],
                'line_count'    => count($o['lines']),
                'status'        => $roll['status'],
                'summary'       => $roll['summary'],
                'items'         => implode(' ', array_column($o['lines'], 'alias_code')),
            ];
        }
        return $out;
    }

    /** Re-run the pure coverage engine against the CURRENT snapshot (no CMS). */
    private function recomputeCoverageFromSnapshot(): void
    {
        $db = Database::getInstance();

        $positions = [];
        foreach ($db->fetchAll("SELECT ordr, position FROM or_order_priority") as $p) {
            $positions[(int) $p['ordr']] = ['position' => (int) $p['position']];
        }
        $items = [];
        foreach ($db->fetchAll("SELECT * FROM or_items") as $i) {
            $items[$i['item_code']] = [
                'unit' => $i['unit'], 'is_discontinued' => (bool) $i['is_discontinued'],
                'description' => $i['description'],
            ];
        }
        $inventory = [];
        foreach ($db->fetchAll("SELECT item_code, qty_usable FROM or_inventory") as $v) {
            $inventory[$v['item_code']] = (float) $v['qty_usable'];
        }
        $lines = $db->fetchAll("SELECT * FROM or_order_lines");
        foreach ($lines as &$l) {
            $l['ord_detail'] = (int) $l['ord_detail'];
            $l['ordr'] = (int) $l['ordr'];
            $l['qty_remaining'] = (float) $l['qty_remaining'];
        }
        unset($l);
        $tickets = $db->fetchAll("SELECT * FROM or_tickets WHERE context = 'MFPP'");
        foreach ($tickets as &$t) {
            $t['ordr'] = (int) $t['ordr'];
            $t['qty'] = (float) $t['qty'];
        }
        unset($t);

        $results = CoverageEngine::compute($positions, $lines, $items, $inventory, $tickets);

        $db->beginTransaction();
        try {
            $db->delete('or_coverage_results', '1=1');
            foreach ($results as $detail => $res) {
                $ordr = 0;
                foreach ($lines as $l) {
                    if ($l['ord_detail'] === $detail) { $ordr = $l['ordr']; break; }
                }
                $db->insert('or_coverage_results', [
                    'ord_detail'  => $detail,
                    'ordr'        => $ordr,
                    'status'      => $res['status'],
                    'allocations' => json_encode($res['allocations'], JSON_UNESCAPED_SLASHES),
                    'covered_qty' => $res['covered_qty'],
                    'shortfall'   => $res['shortfall'],
                    'reason'      => $res['reason'],
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
