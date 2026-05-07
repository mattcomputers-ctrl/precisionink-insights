<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Core\CSRF;
use PII\Core\Database;
use PII\Models\User;

class AuthController
{
    public function loginForm(): void
    {
        if (isset($_SESSION['_user'])) {
            redirect('/');
        }

        view('auth/login', [
            'pageTitle' => 'Sign In',
        ]);
    }

    public function login(): void
    {
        try {
            CSRF::validateRequest();
        } catch (\RuntimeException $e) {
            $_SESSION['_flash']['error'] = 'Invalid or expired form token. Please try again.';
            redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $_SESSION['_flash']['error'] = 'Username and password are required.';
            $_SESSION['_flash']['_old_input'] = ['username' => $username];
            redirect('/login');
        }

        $user = User::authenticate($username, $password);

        if ($user === false) {
            try {
                Database::getInstance()->insert('audit_log', [
                    'user_id'     => null,
                    'entity_type' => 'auth',
                    'entity_id'   => $username,
                    'action'      => 'login_failed',
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (\Throwable $e) { /* don't reveal DB issues to login form */ }

            $_SESSION['_flash']['error'] = 'Invalid username or password.';
            $_SESSION['_flash']['_old_input'] = ['username' => $username];
            redirect('/login');
        }

        User::updateLastLogin((int) $user['id']);
        $_SESSION['_user'] = $user;
        session_regenerate_id(true);

        Database::getInstance()->insert('audit_log', [
            'user_id'     => $user['id'],
            'entity_type' => 'auth',
            'entity_id'   => (string) $user['id'],
            'action'      => 'login',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $intended = $_SESSION['_flash']['intended_url'] ?? '/';
        unset($_SESSION['_flash']['intended_url']);

        $_SESSION['_flash']['success'] = 'Welcome back, ' . ($user['display_name'] ?: $user['username']) . '.';
        redirect($intended);
    }

    public function logout(): void
    {
        $userId = current_user_id();

        if ($userId) {
            try {
                Database::getInstance()->insert('audit_log', [
                    'user_id'     => $userId,
                    'entity_type' => 'auth',
                    'entity_id'   => (string) $userId,
                    'action'      => 'logout',
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (\Throwable $e) { /* ignore */ }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        redirect('/login');
    }
}
