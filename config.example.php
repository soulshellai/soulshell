<?php
// ═══════════════════════════════════════════════════════════════════
// SoulShell — Backend Configuration (TEMPLATE)
//
// SETUP INSTRUCTIONS:
// 1. Copy this file to `config.php` in the same directory
// 2. Fill in your own credentials below
// 3. NEVER commit config.php to git (it's in .gitignore)
// ═══════════════════════════════════════════════════════════════════

// ── Database ────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// ── Anthropic Claude API ─────────────────────────────────────────
// Get your key at https://console.anthropic.com
define('ANTHROPIC_API_KEY', 'sk-ant-YOUR_API_KEY_HERE');
define('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514');

// ── SMTP Email (for OTP verification) ────────────────────────────
// Use any SMTP provider: Hostinger, Gmail, SendGrid, Mailgun, etc.
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USER', 'noreply@yourdomain.com');
define('SMTP_PASS', 'your_email_password');
define('SMTP_FROM_NAME', 'SoulShell');
define('SMTP_FROM_EMAIL', 'noreply@yourdomain.com');

// ── App Settings ─────────────────────────────────────────────────
define('APP_ENV', 'production');        // 'development' for debug mode
define('CORS_ORIGIN', '*');              // Restrict to your domain in production
define('OTP_EXPIRY_MINUTES', 10);
define('OTP_MAX_ATTEMPTS', 5);

// ── Response Helpers ─────────────────────────────────────────────
function send_cors() {
  header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-User-Email');
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
  header('Content-Type: application/json');
}

function json_response($data, $status = 200) {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

// ── DB Connection ────────────────────────────────────────────────
function db() {
  static $pdo = null;
  if ($pdo === null) {
    try {
      $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES => false,
        ]
      );
    } catch (PDOException $e) {
      json_response(['error' => 'Database connection failed'], 500);
    }
  }
  return $pdo;
}

// ── Auth Helper ──────────────────────────────────────────────────
function get_user_id() {
  $email = $_SERVER['HTTP_X_USER_EMAIL'] ?? '';
  $email = filter_var(trim(strtolower($email)), FILTER_VALIDATE_EMAIL);
  if (!$email) json_response(['error' => 'Unauthorized: missing X-User-Email header'], 401);

  $stmt = db()->prepare('SELECT id FROM ss_users WHERE email = ? LIMIT 1');
  $stmt->execute([$email]);
  $row = $stmt->fetch();

  if (!$row) {
    $ins = db()->prepare('INSERT INTO ss_users (email, display_name) VALUES (?, ?)');
    $ins->execute([$email, explode('@', $email)[0]]);
    $id = db()->lastInsertId();
    db()->prepare('INSERT INTO ss_dashboard (user_id) VALUES (?)')->execute([$id]);
    $wid = 'ws_' . bin2hex(random_bytes(8));
    db()->prepare('INSERT INTO ss_workspaces (id, user_id, name) VALUES (?, ?, ?)')
        ->execute([$wid, $id, 'Main Workspace']);
    db()->prepare('UPDATE ss_users SET active_workspace_id = ? WHERE id = ?')
        ->execute([$wid, $id]);
    return (int)$id;
  }

  $uid = (int)$row['id'];
  $ws = db()->prepare('SELECT COUNT(*) as c FROM ss_workspaces WHERE user_id = ?');
  $ws->execute([$uid]);
  if ((int)$ws->fetch()['c'] === 0) {
    $wid = 'ws_' . bin2hex(random_bytes(8));
    db()->prepare('INSERT INTO ss_workspaces (id, user_id, name) VALUES (?, ?, ?)')
        ->execute([$wid, $uid, 'Main Workspace']);
    db()->prepare('UPDATE ss_users SET active_workspace_id = ? WHERE id = ?')
        ->execute([$wid, $uid]);
    db()->prepare('UPDATE ss_notes SET workspace_id = ? WHERE user_id = ? AND workspace_id IS NULL')
        ->execute([$wid, $uid]);
  }
  return $uid;
}

function read_json_body() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// ── Email OTP (native SMTP, no dependencies) ─────────────────────
function send_email_otp($to_email, $code) {
  $subject = 'Your SoulShell verification code: ' . $code;

  $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>';
  $html .= '<body style="margin:0;padding:0;background:#0a0a0a;font-family:Arial,Helvetica,sans-serif;">';
  $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:40px 20px;">';
  $html .= '<tr><td align="center">';
  $html .= '<table width="480" cellpadding="0" cellspacing="0" style="background:#111;border-radius:16px;overflow:hidden;">';
  $html .= '<tr><td style="padding:40px 40px 20px;">';
  $html .= '<div style="font-size:11px;letter-spacing:3px;color:#ea580c;text-transform:uppercase;margin-bottom:8px;">SoulShell</div>';
  $html .= '<h1 style="color:#fff;font-size:24px;margin:0 0 12px;font-weight:500;">Your verification code</h1>';
  $html .= '<p style="color:#888;font-size:14px;line-height:1.6;margin:0 0 24px;">Enter this code in SoulShell to verify your email:</p>';
  $html .= '<div style="background:#1a1a1a;border:1px solid #333;border-radius:12px;padding:20px;text-align:center;margin-bottom:24px;">';
  $html .= '<div style="font-size:36px;letter-spacing:8px;color:#ea580c;font-weight:600;font-family:monospace;">' . htmlspecialchars($code) . '</div>';
  $html .= '</div>';
  $html .= '<p style="color:#666;font-size:12px;line-height:1.6;margin:0 0 8px;">This code expires in ' . OTP_EXPIRY_MINUTES . ' minutes.</p>';
  $html .= '<p style="color:#666;font-size:12px;line-height:1.6;margin:0;">If you didn\'t request this, you can safely ignore this email.</p>';
  $html .= '</td></tr>';
  $html .= '<tr><td style="padding:0 40px 40px;border-top:1px solid #222;padding-top:20px;">';
  $html .= '<p style="color:#444;font-size:11px;margin:0;">SoulShell · Cognition OS for neurodivergent minds</p>';
  $html .= '</td></tr></table></td></tr></table></body></html>';

  return smtp_send($to_email, $subject, $html);
}

function smtp_send($to_email, $subject, $html_body) {
  $protocol = (SMTP_SECURE === 'ssl') ? 'ssl://' : '';
  $socket = @stream_socket_client($protocol . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 15);
  if (!$socket) {
    error_log("SMTP connect failed: $errstr ($errno)");
    return false;
  }

  $read = function() use ($socket) {
    $res = '';
    while ($line = fgets($socket, 515)) {
      $res .= $line;
      if (substr($line, 3, 1) === ' ') break;
    }
    return $res;
  };
  $write = function($cmd) use ($socket) { fputs($socket, $cmd . "\r\n"); };

  $read();
  $write("EHLO soulshell.xyz"); $read();

  if (SMTP_SECURE === 'tls') {
    $write("STARTTLS"); $read();
    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      fclose($socket); return false;
    }
    $write("EHLO soulshell.xyz"); $read();
  }

  $write("AUTH LOGIN"); $read();
  $write(base64_encode(SMTP_USER)); $read();
  $write(base64_encode(SMTP_PASS));
  $auth_res = $read();
  if (strpos($auth_res, '235') === false) { fclose($socket); return false; }

  $write("MAIL FROM: <" . SMTP_FROM_EMAIL . ">"); $read();
  $write("RCPT TO: <$to_email>"); $r = $read();
  if (strpos($r, '250') === false && strpos($r, '251') === false) { fclose($socket); return false; }

  $write("DATA"); $read();
  $headers  = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
  $headers .= "To: <$to_email>\r\n";
  $headers .= "Subject: $subject\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";

  fputs($socket, $headers . $html_body . "\r\n.\r\n");
  $end_res = $read();
  $write("QUIT");
  fclose($socket);

  return strpos($end_res, '250') !== false;
}
