<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Services\DashboardService;
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

        // CMS-backed dashboard metrics. Wrapped in try/catch so a CMS
        // outage degrades the dashboard to a tab launcher rather than
        // taking the whole landing page down.
        $shipments = null;
        $inventory = null;
        $cmsError  = null;

        if (CMSDatabase::isConfigured()) {
            try {
                $svc       = new DashboardService();
                $shipments = $svc->yesterdayShipments();
                $inventory = $svc->yesterdayInventory();
            } catch (\Throwable $e) {
                $cmsError = $e->getMessage();
            }
        } else {
            $cmsError = 'CMS database not configured.';
        }

        layout('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'modules'   => $accessible,
            'shipments' => $shipments,
            'inventory' => $inventory,
            'cmsError'  => $cmsError,
        ]);
    }
}
