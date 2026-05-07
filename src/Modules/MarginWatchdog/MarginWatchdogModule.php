<?php

declare(strict_types=1);

namespace PII\Modules\MarginWatchdog;

use PII\Core\Module;
use PII\Core\Router;

class MarginWatchdogModule extends Module
{
    public function key(): string         { return 'margin_watchdog'; }
    public function name(): string        { return 'Margin Watchdog'; }
    public function basePath(): string    { return '/margin-watchdog'; }
    public function icon(): string        { return '📉'; }

    public function registerRoutes(Router $router): void
    {
        $controller = MarginWatchdogController::class;
        $router->group('/margin-watchdog', function (Router $r) use ($controller) {
            $r->get('',         $controller . '@index');
            $r->get('/items',   $controller . '@items');   // AJAX: items for one Bill To
            $r->get('/export',  $controller . '@export');  // Excel export
        });
    }
}
