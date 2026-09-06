<?php
// ============================================================
//  SMARTTECH  |  connection.php
//  Include this file on every page:  require_once 'connection.php';
// ============================================================

define('DB_HOST',    'localhost');
define('DB_USER',    'root');        // ← your MySQL username
define('DB_PASS',    '');            // ← your MySQL password
define('DB_NAME',    'smarttech_db');
define('DB_CHARSET', 'utf8mb4');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("<div style='font-family:sans-serif;padding:40px;color:#c0392b'>
         <h2>⚠ Database Connection Failed</h2>
         <p>" . $conn->connect_error . "</p>
         <p>Please check your database settings in <strong>connection.php</strong></p>
         </div>");
}

$conn->set_charset(DB_CHARSET);

// ── Safe query helper (prepared statement) ────────────────────
function db_query($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if ($types && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function db_rows($conn, $sql, $types = '', $params = []) {
    $r = db_query($conn, $sql, $types, $params);
    return ($r && $r instanceof mysqli_result) ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

function db_row($conn, $sql, $types = '', $params = []) {
    $r = db_query($conn, $sql, $types, $params);
    return ($r && $r instanceof mysqli_result) ? ($r->fetch_assoc() ?: null) : null;
}

function db_value($conn, $sql, $types = '', $params = []) {
    $row = db_row($conn, $sql, $types, $params);
    return $row ? array_values($row)[0] : null;
}

function db_run($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if ($types && $params) $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $ok ? ($id ?: true) : false;
}

// ── Common helpers ─────────────────────────────────────────────
function esc($s)  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($n)  { return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function ago($d)  { return date('M d, Y', strtotime($d)); }

function stars($n) {
    $n = round((float)$n);
    return str_repeat('<span style="color:#f39c12">★</span>', $n)
         . str_repeat('<span style="color:#ddd">☆</span>', 5 - $n);
}

function thumb($img) {
    return $img ? esc($img) : 'https://placehold.co/300x220/f0f0f0/aaa?text=No+Image';
}

function redirect($url) {
    header("Location: $url"); exit;
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}
