<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\CSRF;
use PII\Modules\MarginWatchdog\Thresholds;

require_once dirname(__DIR__) . '/Helpers/layout.php';

class PreferencesController
{
    public function index(): void
    {
        $userId = (int) current_user_id();

        layout('preferences/index', [
            'pageTitle'  => 'Preferences',
            'thresholds' => Thresholds::forUser($userId),
            'colorsOn'   => Thresholds::colorsEnabled($userId),
            'labels'     => Thresholds::LABELS,
        ]);
    }

    public function save(): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();

        // Colors toggle
        $colorsEnabled = !empty($_POST['colors_enabled']);
        Thresholds::setColorsEnabled($userId, $colorsEnabled);

        // Per-metric thresholds
        $thresholds = $_POST['thresholds'] ?? [];
        foreach (array_keys(Thresholds::DEFAULTS) as $metric) {
            $green = (float) ($thresholds[$metric]['green'] ?? Thresholds::DEFAULTS[$metric]['green']);
            $red   = (float) ($thresholds[$metric]['red']   ?? Thresholds::DEFAULTS[$metric]['red']);
            Thresholds::save($userId, $metric, $green, $red);
        }

        $_SESSION['_flash']['success'] = 'Preferences saved.';
        redirect('/preferences');
    }

    public function reset(): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();
        Thresholds::resetAll($userId);
        $_SESSION['_flash']['success'] = 'Thresholds reset to defaults.';
        redirect('/preferences');
    }
}
