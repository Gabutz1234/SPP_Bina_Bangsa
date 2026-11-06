<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function current_role(): ?string
{
    $user = current_user();
    return isset($user['role']) ? (string) $user['role'] : null;
}

function role_home(string $role): string
{
    return match ($role) {
        'admin' => 'admin/dashboard.php',
        'kepala_sekolah' => 'kepala_sekolah/dashboard.php',
        'teller' => 'teller/dashboard.php',
        'siswa' => 'siswa/dashboard.php',
        default => 'index.php',
    };
}

function redirect_to_role_home(): never
{
    header('Location: ' . role_home(current_role() ?? ''));
    exit;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: ../index.php');
        exit;
    }

    return $user;
}

function require_role(string|array $roles): array
{
    $user = require_login();
    $allowed = is_array($roles) ? $roles : [$roles];

    if (!in_array((string) ($user['role'] ?? ''), $allowed, true)) {
        http_response_code(403);
        exit('Akses ditolak. Anda tidak memiliki izin untuk membuka halaman ini.');
    }

    return $user;
}

function can_access(string $permission): bool
{
    $permissions = [
        'admin' => ['manage_master_data', 'manage_users', 'manage_bills', 'view_transactions', 'view_reports'],
        'kepala_sekolah' => ['view_students', 'view_bills', 'view_transactions', 'view_reports'],
        'teller' => ['view_students', 'view_bills', 'view_transactions', 'view_reports', 'print_reports'],
        'siswa' => ['view_own_profile', 'view_own_bills', 'view_own_transactions'],
    ];

    $role = current_role();
    return $role !== null && in_array($permission, $permissions[$role] ?? [], true);
}
