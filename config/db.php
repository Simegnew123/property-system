<?php
/**
 * Database Connection (PDO) + Session Bootstrap
 * All pages include this file first.
 */
declare(strict_types=1);

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Database Configuration ───
const DB_HOST = '127.0.0.1';
const DB_NAME = 'property_mgmt';
const DB_USER = 'root';
const DB_PASS = '';   // Change to your MySQL password
const DB_CHARSET = 'utf8mb4';

// ─── PDO Connection ───
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

// ─── Helper: format ETB currency ───
function etb(float $amount): string {
    return 'ETB ' . number_format($amount, 2, '.', ',');
}

// ─── Helper: generate random access code for tenants ───
function generateAccessCode(int $length = 16): string {
    return bin2hex(random_bytes($length / 2));
}

// ─── i18n: English ⇄ Amharic translation layer (auto-activates on all pages) ───
require_once __DIR__ . '/i18n.php';
