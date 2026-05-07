<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Services\PermissionService;

require_once dirname(__DIR__) . '/Helpers/layout.php';

class DashboardController
{
    public function index(): void
    {
        $userId  = current_user_id();
        $modules = App::modules()->all();

        $accessible = [];
        foreach ($modules as $module) {
            if (PermissionService::canRead((int) $userId, $module->permissionKey())) {
                $accessible[] = $module;
            }
        }

        layout('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'modules'   => $accessible,
        ]);
    }
}
