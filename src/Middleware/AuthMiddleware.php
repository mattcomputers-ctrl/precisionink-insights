<?php

declare(strict_types=1);

namespace PII\Middleware;

use PII\Core\App;
use PII\Services\PermissionService;

/**
 * AuthMiddleware — Redirects unauthenticated users to /login,
 * gates /admin routes to admin users, and enforces module-level
 * read permissions for authenticated users.
 */
class AuthMiddleware
{
    /** @var list<string> URI prefixes that bypass authentication. */
    private const PUBLIC_PATHS = [
        '/login',
        '/logout',
        '/css',
        '/js',
        '/assets',
        '/favicon.ico',
    ];

    public function handle(callable $next): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($this->isPublicPath($uri)) {
            $next();
            return;
        }

        if (!isset($_SESSION['_user'])) {
            $_SESSION['_flash']['intended_url'] = $uri;
            redirect('/login');
        }

        // /admin/* requires admin
        if (str_starts_with($uri, '/admin')) {
            if (empty($_SESSION['_user']['is_admin'])) {
                $this->sendForbidden();
                return;
            }
        }

        // Module-level permission check
        $module = App::modules()->moduleForPath($uri);
        if ($module !== null) {
            $userId = (int) $_SESSION['_user']['id'];
            if (!PermissionService::canRead($userId, $module->permissionKey())) {
                $this->sendForbidden();
                return;
            }
        }

        $next();
    }

    private function isPublicPath(string $uri): bool
    {
        foreach (self::PUBLIC_PATHS as $prefix) {
            if ($uri === $prefix
                || str_starts_with($uri, $prefix . '/')
                || str_starts_with($uri, $prefix . '?')
            ) {
                return true;
            }
        }
        return false;
    }

    private function sendForbidden(): void
    {
        http_response_code(403);
        $viewFile = dirname(__DIR__) . '/Views/errors/403.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            echo '<h1>403 — Forbidden</h1><p>You do not have permission to access this resource.</p>';
        }
        exit;
    }
}
