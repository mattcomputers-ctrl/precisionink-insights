<?php

declare(strict_types=1);

namespace PII\Modules\Scheduling;

use PII\Core\Module;
use PII\Core\Router;

class SchedulingModule extends Module
{
    public function key(): string      { return 'scheduling'; }
    public function name(): string     { return 'Scheduling'; }
    public function basePath(): string { return '/scheduling'; }
    public function icon(): string     { return '🏭'; }

    public function registerRoutes(Router $router): void
    {
        $c = SchedulingController::class;
        $router->group('/scheduling', function (Router $r) use ($c) {
            $r->get('',                 $c . '@index');
            $r->get('/generate',        $c . '@generate');      // JSON schedule for a week
            $r->post('/export',         $c . '@export');        // POST edited schedule JSON -> xlsx

            $r->get('/settings',                    $c . '@settings');
            $r->post('/settings/mills',             $c . '@storeMill');
            $r->post('/settings/mills/{id}',        $c . '@updateMill');
            $r->post('/settings/mills/{id}/delete', $c . '@deleteMill');
            $r->post('/settings/color-order',       $c . '@saveColorOrder');
            $r->get('/settings/item-search',        $c . '@itemSearch');    // CMS bulk item lookup
            $r->post('/settings/items',             $c . '@saveItemConfig');
            $r->post('/settings/items/delete',      $c . '@deleteItemConfig');
        });
    }
}
