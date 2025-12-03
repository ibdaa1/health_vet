<?php
// generate_report_token.php
// Secure token generator for report links.
// Extended to support visitor_interaction and complaint report types.
//
// POST params:
//   - report_type (adopter|reserved|owner_animals|applications|visitor_interaction|complaint)
//   - record_id (int or string)
//   - recipient (optional email or phone)
//   - expires_minutes (optional, default 60)
//
// Returns JSON: { success:true, token, link, expires_at } or error message.

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';     // must provide $conn (mysqli)
require_once __DIR__ . '/config.php'; // must define REPORT_SECRET and optionally MAIL_FROM

function log_error(string $msg): void {
    $logfile = __DIR__ . '/report_tokens_error.log';
    @file_put_contents($logfile, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function absolute_base_public(): string {
    // Build absolute URL to /health_vet/public/ so links returned are full URLs (safer for sharing)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . $host . '/health_vet/public/';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    session_start();
    $actor = isset($_SESSION['user']['EmpID']) ? (int)$_SESSION['user']['EmpID'] : 0;

    // Read and validate input
    $report_type = trim((string)($_POST['report_type'] ?? ''));
    $record_id_raw = $_POST['record_id'] ?? '';
    $recipient = isset($_POST['recipient']) ? trim((string)$_POST['recipient']) : '';
    $expires_minutes = isset($_POST['expires_minutes']) ? (int)$_POST['expires_minutes'] : 60;
    if ($expires_minutes <= 0) $expires_minutes = 60;

    // allowed report types (extended)
    $allowed = ['adopter','reserved','owner_animals','applications','visitor_interaction','complaint'];

    if (!in_array($report_type, $allowed, true) || empty($record_id_raw) && $record_id_raw !== '0') {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    if (!defined('REPORT_SECRET') || !REPORT_SECRET) {
        log_error('REPORT_SECRET not defined in config.php');
        echo json_encode(['success' => false, 'message' => 'Server misconfiguration (secret missing)']);
        exit;
    }

    // decide record_id: allow either integer ids or short strings (codes)
    $record_id = null;
    if (is_numeric($record_id_raw) && ((string)(int)$record_id_raw === (string)$record_id_raw)) {
        $record_id = (int)$record_id_raw;
    } else {
        // store string identifiers as-is (e.g. animal_code)
        $record_id = (string)$record_id_raw;
    }

    // build payload (UTC)
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiry = $now->modify("+{$expires_minutes} minutes");
    $nonce = bin2hex(random_bytes(16));
    $payload = [
        'report_type' => $report_type,
        'record_id'  => $record_id,
        'nonce'      => $nonce,
        'exp'        => $expiry->format('Y-m-d H:i:s'),
        'iat'        => $now->format('Y-m-d H:i:s'),
        'recipient'  => $recipient
    ];
    $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payload_json === false) {
        log_error('Payload json_encode failed: ' . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'Server error preparing token']);
        exit;
    }

    // compose token (base64url(payload) . '.' . base64url(HMAC))
    $payload_b64 = base64url_encode($payload_json);
    $signature_raw = hash_hmac('sha256', $payload_b64, REPORT_SECRET, true);
    $sig_b64 = base64url_encode($signature_raw);
    $token = $payload_b64 . '.' . $sig_b64;

    // token hash for indexed lookup
    $token_hash = hash('sha256', $token);
    $expires_at = $expiry->format('Y-m-d H:i:s');

    // insert into DB (token as TEXT to avoid truncation)
    $stmt = $conn->prepare(
        "INSERT INTO report_tokens (token, token_hash, payload, nonce, report_type, record_id, recipient_contact, created_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        log_error('DB prepare error: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'DB prepare failed']);
        exit;
    }

    // bind types: token(s), token_hash(s), payload(s), nonce(s), report_type(s), record_id(s|i), recipient(s), created_by(i), expires_at(s)
    // We will convert record_id to string for binding if it's not integer to simplify binding.
    $record_id_param = is_int($record_id) ? $record_id : (string)$record_id;
    // decide types string
    // token, token_hash, payload, nonce, report_type, record_id, recipient, created_by, expires_at
    // types: s s s s s s s i s  OR if record_id is integer: s s s s s i s i s
    if (is_int($record_id)) {
        $types = 'ssssisiss';
        $stmt->bind_param(
            $types,
            $token,
            $token_hash,
            $payload_json,
            $nonce,
            $report_type,
            $record_id_param,
            $recipient,
            $actor,
            $expires_at
        );
    } else {
        $types = 'ssssssiss';
        // record_id passed as string
        $stmt->bind_param(
            $types,
            $token,
            $token_hash,
            $payload_json,
            $nonce,
            $report_type,
            $record_id_param,
            $recipient,
            $actor,
            $expires_at
        );
    }

    if (!$stmt->execute()) {
        log_error('DB execute error: ' . $stmt->error);
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'DB insert failed', 'error' => $stmt->error]);
        exit;
    }
    $stmt->close();

    // build public link (absolute)
    $basePublic = absolute_base_public();
    $map = [
        'adopter' => 'add_adopter_report.php?token=',
        'reserved' => 'add_reserved_report.php?token=',
        'owner_animals' => 'owner_animals_report.php?token=',
        'applications' => 'applications_report.php?token=',
        'visitor_interaction' => 'add_visitor_interactions_report.php?token=',
        'complaint' => 'add_complaint_report.php?token=' // adjust filename if your complaints report page uses a different name
    ];
    $rel = $map[$report_type] ?? 'add_adopter_report.php?token=';
    $link = $basePublic . $rel . rawurlencode($token);

    echo json_encode(['success' => true, 'token' => $token, 'link' => $link, 'expires_at' => $expires_at]);
    exit;

} catch (Exception $ex) {
    log_error('Exception: ' . $ex->getMessage() . ' stack:' . $ex->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $ex->getMessage()]);
    exit;
}
?>