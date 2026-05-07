<?php

declare(strict_types=1);

namespace PII\Modules\MarginWatchdog;

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Core\Database;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

class MarginWatchdogController
{
    /**
     * GET /margin-watchdog — Date pickers + (optionally) the rendered report.
     */
    public function index(): void
    {
        $userId   = (int) current_user_id();
        $params   = $this->resolveDateRanges();
        $sort     = $_GET['sort'] ?? 'comparison';

        $hasCms      = CMSDatabase::isConfigured();
        $thresholds  = Thresholds::forUser($userId);
        $presets     = $this->loadUserPresets($userId);
        $colorsOn    = Thresholds::colorsEnabled($userId);

        $errors  = [];
        $summary = null;
        $billTos = [];

        if ($params['ready']) {
            if (!$hasCms) {
                $errors[] = 'CMS database is not configured. Edit config/config.php and add the cms_db section.';
            } else {
                try {
                    $svc = new ShipmentService();
                    $rawSummary = $svc->summary(
                        $params['baseline_start'], $params['baseline_end'],
                        $params['comparison_start'], $params['comparison_end']
                    );
                    $summary = MetricsCalculator::compute($rawSummary);

                    $billToRows = $svc->billTos(
                        $params['baseline_start'], $params['baseline_end'],
                        $params['comparison_start'], $params['comparison_end'],
                        in_array($sort, ['name', 'baseline', 'comparison'], true) ? $sort : 'comparison'
                    );
                    foreach ($billToRows as $r) {
                        $billTos[] = [
                            'raw'     => $r,
                            'metrics' => MetricsCalculator::compute($r),
                        ];
                    }

                    // Audit
                    Database::getInstance()->insert('audit_log', [
                        'user_id'     => $userId,
                        'entity_type' => 'margin_watchdog',
                        'entity_id'   => null,
                        'action'      => 'view_report',
                        'details'     => json_encode([
                            'baseline'   => [$params['baseline_start'], $params['baseline_end']],
                            'comparison' => [$params['comparison_start'], $params['comparison_end']],
                            'sort'       => $sort,
                        ]),
                        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = 'CMS query failed: ' . $e->getMessage();
                }
            }
        }

        layout('margin-watchdog/index', [
            'pageTitle'  => 'Margin Watchdog',
            'params'     => $params,
            'sort'       => $sort,
            'errors'     => $errors,
            'summary'    => $summary,
            'billTos'    => $billTos,
            'thresholds' => $thresholds,
            'presets'    => $presets,
            'colorsOn'   => $colorsOn,
            'hasCms'     => $hasCms,
        ], 'margin_watchdog');
    }

    /**
     * GET /margin-watchdog/items?bill_to=...&baseline_start=...&...&view_mode=both|either
     * Returns an HTML fragment of the item rows for that Bill To.
     */
    public function items(): void
    {
        $userId  = (int) current_user_id();
        $billTo  = (string) ($_GET['bill_to'] ?? '');
        $bStart  = (string) ($_GET['baseline_start'] ?? '');
        $bEnd    = (string) ($_GET['baseline_end']   ?? '');
        $cStart  = (string) ($_GET['comparison_start'] ?? '');
        $cEnd    = (string) ($_GET['comparison_end']   ?? '');
        $viewMode = ($_GET['view_mode'] ?? 'both') === 'either' ? 'either' : 'both';

        if ($billTo === '' || !valid_date($bStart) || !valid_date($bEnd)
            || !valid_date($cStart) || !valid_date($cEnd)) {
            http_response_code(400);
            echo '<div class="alert alert-danger">Invalid request parameters.</div>';
            return;
        }

        try {
            $svc = new ShipmentService();
            $rows = $svc->itemsForBillTo($billTo, $bStart, $bEnd, $cStart, $cEnd, $viewMode);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo '<div class="alert alert-danger">CMS query failed: ' . e($e->getMessage()) . '</div>';
            return;
        }

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'raw'     => $r,
                'metrics' => MetricsCalculator::compute($r),
            ];
        }

        $thresholds = Thresholds::forUser($userId);

        view('margin-watchdog/items_fragment', [
            'billTo'     => $billTo,
            'items'      => $items,
            'viewMode'   => $viewMode,
            'thresholds' => $thresholds,
            'rangeParams' => [
                'baseline_start'   => $bStart,
                'baseline_end'     => $bEnd,
                'comparison_start' => $cStart,
                'comparison_end'   => $cEnd,
            ],
        ]);
    }

    /**
     * GET /margin-watchdog/export — Excel export of the full hierarchy.
     */
    public function export(): void
    {
        $userId = (int) current_user_id();
        $params = $this->resolveDateRanges();
        if (!$params['ready']) {
            $_SESSION['_flash']['error'] = 'Date ranges are required to export.';
            redirect('/margin-watchdog');
        }
        $sort     = in_array($_GET['sort'] ?? 'comparison', ['name', 'baseline', 'comparison'], true) ? $_GET['sort'] : 'comparison';
        $viewMode = ($_GET['view_mode'] ?? 'both') === 'either' ? 'either' : 'both';

        if (!CMSDatabase::isConfigured()) {
            $_SESSION['_flash']['error'] = 'CMS database is not configured.';
            redirect('/margin-watchdog');
        }

        $svc = new ShipmentService();
        $summary = MetricsCalculator::compute($svc->summary(
            $params['baseline_start'], $params['baseline_end'],
            $params['comparison_start'], $params['comparison_end']
        ));

        $billTos = [];
        foreach ($svc->billTos(
            $params['baseline_start'], $params['baseline_end'],
            $params['comparison_start'], $params['comparison_end'],
            $sort
        ) as $r) {
            $items = $svc->itemsForBillTo(
                $r['bill_to'],
                $params['baseline_start'], $params['baseline_end'],
                $params['comparison_start'], $params['comparison_end'],
                $viewMode
            );
            $billTos[] = [
                'raw'     => $r,
                'metrics' => MetricsCalculator::compute($r),
                'items'   => array_map(fn($i) => [
                    'raw'     => $i,
                    'metrics' => MetricsCalculator::compute($i),
                ], $items),
            ];
        }

        Database::getInstance()->insert('audit_log', [
            'user_id'     => $userId,
            'entity_type' => 'margin_watchdog',
            'entity_id'   => null,
            'action'      => 'export_excel',
            'details'     => json_encode([
                'baseline'   => [$params['baseline_start'], $params['baseline_end']],
                'comparison' => [$params['comparison_start'], $params['comparison_end']],
                'sort'       => $sort,
                'view_mode'  => $viewMode,
            ]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $exporter = new ExcelExporter();
        $exporter->stream(
            $params,
            $summary,
            $billTos,
            App::config('app.name', 'Precision Ink Insights')
        );
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Resolve baseline and comparison date ranges from query params.
     * Returns ['ready' => bool, 'baseline_start' => ..., ...].
     */
    private function resolveDateRanges(): array
    {
        $bStart = $_GET['baseline_start']   ?? '';
        $bEnd   = $_GET['baseline_end']     ?? '';
        $cStart = $_GET['comparison_start'] ?? '';
        $cEnd   = $_GET['comparison_end']   ?? '';

        $ready = valid_date($bStart) && valid_date($bEnd)
              && valid_date($cStart) && valid_date($cEnd)
              && $bStart <= $bEnd
              && $cStart <= $cEnd;

        return [
            'ready'             => $ready,
            'baseline_start'    => $bStart,
            'baseline_end'      => $bEnd,
            'comparison_start'  => $cStart,
            'comparison_end'    => $cEnd,
        ];
    }

    private function loadUserPresets(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT id, name, baseline_start, baseline_end, comparison_start, comparison_end
             FROM date_range_presets
             WHERE user_id = ?
             ORDER BY name",
            [$userId]
        );
    }
}
