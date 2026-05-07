<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * Module — Abstract base for a top-level dashboard tab.
 *
 * Each module is a self-contained feature (Margin Watchdog, future
 * production analytics, AR aging, etc.) that registers its own
 * routes and exposes its display metadata to the nav layer.
 *
 * To add a new tab:
 *   1. Create a class extending Module under src/Modules/{Name}/
 *   2. Implement key(), name(), basePath(), permissionKey(), registerRoutes()
 *   3. Add it to ModuleRegistry in PII\Core\App::buildModuleRegistry()
 *
 * That's it — the nav, permission system, and router pick it up.
 */
abstract class Module
{
    /** Unique key used for permissions and routing (e.g. 'margin_watchdog'). */
    abstract public function key(): string;

    /** Human-readable name shown in the tab nav. */
    abstract public function name(): string;

    /** Base URL path (e.g. '/margin-watchdog'). Must start with '/'. */
    abstract public function basePath(): string;

    /**
     * Permission key used to gate access. Conventionally the same as key().
     * Future: per-module group permissions stored in MySQL.
     */
    public function permissionKey(): string
    {
        return $this->key();
    }

    /** Optional icon (HTML entity / emoji) shown in the tab. */
    public function icon(): string
    {
        return '';
    }

    /**
     * Register this module's routes on the shared router.
     * Routes are typically nested under basePath() via $router->group().
     */
    abstract public function registerRoutes(Router $router): void;
}
