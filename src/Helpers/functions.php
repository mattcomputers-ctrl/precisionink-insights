<?php

/**
 * Global helper functions for Precision Ink Insights.
 * Intentionally not namespaced for convenient use in views/controllers.
 */

if (defined('PII_HELPERS_LOADED')) {
    return;
}
define('PII_HELPERS_LOADED', true);

/* ------------------------------------------------------------------
 *  HTTP helpers
 * ----------------------------------------------------------------*/

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function back(): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '/';
    redirect($referer);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ------------------------------------------------------------------
 *  Output / escaping
 * ----------------------------------------------------------------*/

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------
 *  View rendering
 * ----------------------------------------------------------------*/

function view(string $__template, array $__data = []): void
{
    $__template = str_replace('.', '/', $__template);
    $__file = dirname(__DIR__) . '/Views/' . $__template . '.php';

    if (!file_exists($__file)) {
        throw new \RuntimeException("View [{$__template}] not found at [{$__file}].");
    }

    extract($__data, EXTR_SKIP);
    include $__file;
}

/* ------------------------------------------------------------------
 *  Form helpers
 * ----------------------------------------------------------------*/

function old(string $key, string $default = ''): string
{
    if (isset($_POST[$key])) {
        return (string) $_POST[$key];
    }
    if (isset($_SESSION['_flash']['_old_input'][$key])) {
        return (string) $_SESSION['_flash']['_old_input'][$key];
    }
    return $default;
}

function csrf_field(): string
{
    return \PII\Core\CSRF::field();
}

function csrf_token(): string
{
    return \PII\Core\CSRF::token();
}

/* ------------------------------------------------------------------
 *  Flash messages
 * ----------------------------------------------------------------*/

function flash_messages(): string
{
    $types = ['success', 'error', 'warning', 'info'];
    $html  = '';

    foreach ($types as $type) {
        if (isset($_SESSION['_flash'][$type])) {
            $message = $_SESSION['_flash'][$type];
            $cls     = ($type === 'error') ? 'danger' : $type;
            $html   .= '<div class="alert alert-' . $cls . '" role="alert">';
            $html   .= e($message);
            $html   .= '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
            $html   .= '</div>';
            unset($_SESSION['_flash'][$type]);
        }
    }

    unset($_SESSION['_flash']['_old_input']);
    return $html;
}

/* ------------------------------------------------------------------
 *  Authenticated user helpers
 * ----------------------------------------------------------------*/

function current_user(): ?array
{
    return $_SESSION['_user'] ?? null;
}

function current_user_id(): ?int
{
    return isset($_SESSION['_user']['id']) ? (int) $_SESSION['_user']['id'] : null;
}

function is_admin(): bool
{
    return !empty($_SESSION['_user']['is_admin']);
}

/* ------------------------------------------------------------------
 *  Number / money formatting
 * ----------------------------------------------------------------*/

function fmt_money(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    return '$' . number_format((float) $n, $decimals, '.', ',');
}

function fmt_number(float|int|null $n, int $decimals = 0): string
{
    if ($n === null) return '—';
    return number_format((float) $n, $decimals, '.', ',');
}

function fmt_pct(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    return number_format((float) $n, $decimals, '.', ',') . '%';
}

/**
 * Percentage-point delta — the literal subtraction of two percentages
 * (33% − 30% = +3, NOT a relative percent change). Displayed with a "%"
 * suffix to match exec preference; context (e.g. "X% of Y → 33% → 30%")
 * makes it unambiguous that this is a point delta, not a percent change.
 */
function fmt_pp(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    $sign = $n > 0 ? '+' : '';
    return $sign . number_format((float) $n, $decimals, '.', ',') . '%';
}

function fmt_signed_money(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    $sign = $n > 0 ? '+' : ($n < 0 ? '-' : '');
    return $sign . '$' . number_format(abs((float) $n), $decimals, '.', ',');
}

function fmt_signed_pct(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    $sign = $n > 0 ? '+' : '';
    return $sign . number_format((float) $n, $decimals, '.', ',') . '%';
}

function fmt_date(?string $date, string $format = 'm/d/Y'): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts !== false ? date($format, $ts) : '';
}

/**
 * Calculate percentage change from baseline to comparison.
 * Returns null when baseline is zero (cannot compute %).
 */
function pct_change(float|int|null $baseline, float|int|null $comparison): ?float
{
    $b = (float) ($baseline ?? 0);
    $c = (float) ($comparison ?? 0);
    if ($b == 0.0) return null;
    return (($c - $b) / abs($b)) * 100.0;
}

/**
 * Validate a YYYY-MM-DD date string.
 */
function valid_date(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    $parts = explode('-', $s);
    return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
}
