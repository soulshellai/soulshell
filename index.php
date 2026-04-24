<?php
// ═══════════════════════════════════════════════════════════════════
// SoulShell — API Router
// Endpoint: /api/index.php?action=XYZ
// ═══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/config.php';
send_cors();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─────────────────────────────────────────────────────────────
// PUBLIC AUTH ENDPOINTS (tidak butuh user login)
// ─────────────────────────────────────────────────────────────
try {
  switch ($action) {
    // Request OTP: kirim 6-digit code ke email
    case 'otp_request':
      $body = read_json_body();
      $email = filter_var(trim($body['email'] ?? ''), FILTER_VALIDATE_EMAIL);
      if (!$email) json_response(['error' => 'Invalid email'], 400);

      // Rate limit: max 3 OTP per 10 menit per email
      $recent = db()->prepare('SELECT COUNT(*) as c FROM ss_otp_codes WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
      $recent->execute([$email]);
      if ((int)$recent->fetch()['c'] >= 3) {
        json_response(['error' => 'Too many OTP requests. Please wait 10 minutes.'], 429);
      }

      // Generate 6-digit code
      $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      $expires_at = date('Y-m-d H:i:s', time() + (OTP_EXPIRY_MINUTES * 60));

      db()->prepare('INSERT INTO ss_otp_codes (email, code, expires_at) VALUES (?, ?, ?)')
          ->execute([$email, $code, $expires_at]);

      // Kirim email
      $sent = send_email_otp($email, $code);
      if (!$sent && APP_ENV === 'production') {
        json_response(['error' => 'Failed to send email. Please try again.'], 500);
      }

      $response = ['ok' => true, 'expires_in' => OTP_EXPIRY_MINUTES * 60];
      // Debug mode: tampilin code di response (HANYA dev mode)
      if (APP_ENV === 'development') $response['debug_code'] = $code;
      json_response($response);
      break;

    // Verify OTP: cek code, kalau cocok → create/update user + return sukses
    case 'otp_verify':
      $body = read_json_body();
      $email = filter_var(trim($body['email'] ?? ''), FILTER_VALIDATE_EMAIL);
      $code = trim($body['code'] ?? '');

      if (!$email) json_response(['error' => 'Invalid email'], 400);
      if (!preg_match('/^\d{6}$/', $code)) json_response(['error' => 'Invalid code format'], 400);

      // Ambil OTP terbaru untuk email ini
      $stmt = db()->prepare('SELECT id, code, attempts, expires_at, used FROM ss_otp_codes WHERE email = ? ORDER BY created_at DESC LIMIT 1');
      $stmt->execute([$email]);
      $otp = $stmt->fetch();

      if (!$otp) json_response(['error' => 'No code found. Request a new one.'], 404);
      if ($otp['used']) json_response(['error' => 'Code already used'], 400);
      if (strtotime($otp['expires_at']) < time()) json_response(['error' => 'Code expired. Request a new one.'], 400);
      if ((int)$otp['attempts'] >= OTP_MAX_ATTEMPTS) json_response(['error' => 'Too many attempts. Request a new code.'], 429);

      // Increment attempts
      db()->prepare('UPDATE ss_otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);

      if ($otp['code'] !== $code) {
        json_response(['error' => 'Invalid code', 'attempts_left' => OTP_MAX_ATTEMPTS - $otp['attempts'] - 1], 400);
      }

      // Mark code used
      db()->prepare('UPDATE ss_otp_codes SET used = 1 WHERE id = ?')->execute([$otp['id']]);

      // Create or get user, mark email_verified
      $u = db()->prepare('SELECT id FROM ss_users WHERE email = ?');
      $u->execute([$email]);
      $existing = $u->fetch();

      if (!$existing) {
        $ins = db()->prepare('INSERT INTO ss_users (email, email_verified, auth_method) VALUES (?, 1, ?)');
        $ins->execute([$email, 'email']);
        $new_id = db()->lastInsertId();
        db()->prepare('INSERT INTO ss_dashboard (user_id) VALUES (?)')->execute([$new_id]);
        $wid = 'ws_' . bin2hex(random_bytes(8));
        db()->prepare('INSERT INTO ss_workspaces (id, user_id, name) VALUES (?, ?, ?)')
            ->execute([$wid, $new_id, 'Main Workspace']);
        db()->prepare('UPDATE ss_users SET active_workspace_id = ? WHERE id = ?')
            ->execute([$wid, $new_id]);
      } else {
        db()->prepare('UPDATE ss_users SET email_verified = 1 WHERE id = ?')->execute([$existing['id']]);
      }

      json_response(['ok' => true, 'email' => $email, 'verified' => true]);
      break;

    // Wallet connect: link address ke user
    case 'wallet_connect':
      $body = read_json_body();
      $address = trim($body['address'] ?? '');
      $signature = $body['signature'] ?? null; // signed message from wallet
      $message = $body['message'] ?? null;

      // Validate Solana address (base58, 32-44 chars)
      if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
        json_response(['error' => 'Invalid wallet address'], 400);
      }

      // Note: For simple auth, we trust client-side wallet connection.
      // For production security, verify signature of a nonce message server-side using ed25519.
      // Current implementation: treat wallet connection as auth proof.

      // Create user via wallet address (pseudo-email to fit schema)
      $pseudo_email = 'wallet_' . substr($address, 0, 16) . '@wallet.soulshell.xyz';

      $u = db()->prepare('SELECT id FROM ss_users WHERE wallet_address = ? OR email = ?');
      $u->execute([$address, $pseudo_email]);
      $existing = $u->fetch();

      if (!$existing) {
        $ins = db()->prepare('INSERT INTO ss_users (email, wallet_address, auth_method, email_verified) VALUES (?, ?, ?, 0)');
        $ins->execute([$pseudo_email, $address, 'wallet']);
        $new_id = db()->lastInsertId();
        db()->prepare('INSERT INTO ss_dashboard (user_id) VALUES (?)')->execute([$new_id]);
        $wid = 'ws_' . bin2hex(random_bytes(8));
        db()->prepare('INSERT INTO ss_workspaces (id, user_id, name) VALUES (?, ?, ?)')
            ->execute([$wid, $new_id, 'Main Workspace']);
        db()->prepare('UPDATE ss_users SET active_workspace_id = ? WHERE id = ?')
            ->execute([$wid, $new_id]);
      } else {
        // Update wallet address kalau belum (kalau user pernah login via email dulu)
        db()->prepare('UPDATE ss_users SET wallet_address = ? WHERE id = ?')
            ->execute([$address, $existing['id']]);
      }

      json_response(['ok' => true, 'email' => $pseudo_email, 'wallet' => $address]);
      break;

    // Link wallet ke email account yang sudah ada (logged-in user)
    case 'wallet_link':
      // Butuh header X-User-Email
      $uid = get_user_id();
      $body = read_json_body();
      $address = trim($body['address'] ?? '');
      if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
        json_response(['error' => 'Invalid wallet address'], 400);
      }
      db()->prepare('UPDATE ss_users SET wallet_address = ? WHERE id = ?')
          ->execute([$address, $uid]);
      json_response(['ok' => true]);
      break;
  }

  // Routes di atas adalah public. Kalau action tidak match apapun di atas, lanjut ke authenticated routes.
  // Untuk authenticated routes, butuh user_id
  $uid = get_user_id();

  switch ($action) {

    // ─────────────────────────────────────────────────────────────
    // STATE: get all user data in one call
    // ─────────────────────────────────────────────────────────────
    case 'state':
      $user = db()->prepare('SELECT id, email, display_name, name, hobby, avatar, gender, profile_complete, xp, streak, energy, last_action_date, clarity_score, noise_level, pattern_type, active_workspace_id FROM ss_users WHERE id = ?');
      $user->execute([$uid]);
      $u = $user->fetch();
      if ($u) $u['profile_complete'] = (bool)$u['profile_complete'];

      $dash = db()->prepare('SELECT flow_stats, attention_state, growth_paths, tribe_syncs, current_project, focus_mode, velocity FROM ss_dashboard WHERE user_id = ?');
      $dash->execute([$uid]);
      $d = $dash->fetch() ?: [];

      // All workspaces
      $ws = db()->prepare('SELECT id, name, created_at FROM ss_workspaces WHERE user_id = ? ORDER BY created_at ASC');
      $ws->execute([$uid]);
      $workspaces = $ws->fetchAll();

      // Notes (all, frontend filters by active workspace)
      $notes = db()->prepare('SELECT id, workspace_id, text, title, pos_x, pos_y, color_idx, kind, created_at FROM ss_notes WHERE user_id = ? ORDER BY created_at DESC');
      $notes->execute([$uid]);

      // Connections (all)
      $conns = db()->prepare('SELECT id, workspace_id, from_note, to_note, label, arrow_type FROM ss_connections WHERE user_id = ?');
      $conns->execute([$uid]);

      $mirror = db()->prepare('SELECT role, content, created_at FROM ss_mirror WHERE user_id = ? ORDER BY created_at ASC LIMIT 100');
      $mirror->execute([$uid]);

      $tasks = db()->prepare('SELECT id, text, done, subtasks, created_at, completed_at FROM ss_tasks WHERE user_id = ? ORDER BY created_at DESC');
      $tasks->execute([$uid]);

      $taskRows = $tasks->fetchAll();
      foreach ($taskRows as &$t) {
        $t['subtasks'] = $t['subtasks'] ? json_decode($t['subtasks'], true) : [];
        $t['done'] = (bool)$t['done'];
      }

      json_response([
        'user' => $u,
        'dashboard' => $d,
        'workspaces' => $workspaces,
        'notes' => $notes->fetchAll(),
        'connections' => $conns->fetchAll(),
        'mirror' => $mirror->fetchAll(),
        'tasks' => $taskRows,
      ]);
      break;

    // ─────────────────────────────────────────────────────────────
    // USER: update meta (xp, streak, energy)
    // ─────────────────────────────────────────────────────────────
    case 'update_meta':
      $body = read_json_body();
      $fields = [];
      $values = [];
      foreach (['xp','streak','energy','clarity_score','noise_level','pattern_type'] as $f) {
        if (isset($body[$f])) {
          $fields[] = "$f = ?";
          $values[] = $body[$f];
        }
      }
      if (isset($body['touch_streak']) && $body['touch_streak']) {
        $today = date('Y-m-d');
        $fields[] = 'last_action_date = ?';
        $values[] = $today;
      }
      if (!$fields) json_response(['ok' => true]);
      $values[] = $uid;
      $sql = 'UPDATE ss_users SET ' . implode(',', $fields) . ' WHERE id = ?';
      db()->prepare($sql)->execute($values);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // PROFILE: update name, hobby, avatar, gender (onboarding)
    // ─────────────────────────────────────────────────────────────
    case 'update_profile':
      $body = read_json_body();
      $name = trim($body['name'] ?? '');
      $hobby = trim($body['hobby'] ?? '');
      $avatar = $body['avatar'] ?? null;
      $gender = $body['gender'] ?? 'unspecified';
      if (!in_array($gender, ['male','female','unspecified'])) $gender = 'unspecified';

      if (!$name || strlen($name) > 100) json_response(['error' => 'Invalid name'], 400);
      if (!$hobby || strlen($hobby) > 200) json_response(['error' => 'Invalid hobby'], 400);

      if ($avatar && strlen($avatar) > 700000) {
        json_response(['error' => 'Avatar too large (max ~500KB)'], 400);
      }

      $stmt = db()->prepare('UPDATE ss_users SET name = ?, hobby = ?, avatar = ?, gender = ?, profile_complete = 1 WHERE id = ?');
      $stmt->execute([$name, $hobby, $avatar, $gender, $uid]);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // WORKSPACES
    // ─────────────────────────────────────────────────────────────
    case 'workspace_create':
      $b = read_json_body();
      $name = trim($b['name'] ?? 'New Workspace');
      if (strlen($name) > 100) $name = substr($name, 0, 100);
      $id = 'ws_' . bin2hex(random_bytes(8));
      db()->prepare('INSERT INTO ss_workspaces (id, user_id, name) VALUES (?, ?, ?)')
          ->execute([$id, $uid, $name]);
      json_response(['ok' => true, 'id' => $id, 'name' => $name]);
      break;

    case 'workspace_rename':
      $b = read_json_body();
      $name = trim($b['name'] ?? '');
      if (!$name || strlen($name) > 100) json_response(['error' => 'Invalid name'], 400);
      db()->prepare('UPDATE ss_workspaces SET name = ? WHERE id = ? AND user_id = ?')
          ->execute([$name, $b['id'], $uid]);
      json_response(['ok' => true]);
      break;

    case 'workspace_delete':
      $b = read_json_body();
      // Pastikan tidak delete workspace terakhir
      $cnt = db()->prepare('SELECT COUNT(*) as c FROM ss_workspaces WHERE user_id = ?');
      $cnt->execute([$uid]);
      if ((int)$cnt->fetch()['c'] <= 1) {
        json_response(['error' => 'Cannot delete the last workspace'], 400);
      }
      // Delete notes + connections in this workspace
      db()->prepare('DELETE FROM ss_notes WHERE workspace_id = ? AND user_id = ?')
          ->execute([$b['id'], $uid]);
      db()->prepare('DELETE FROM ss_connections WHERE workspace_id = ? AND user_id = ?')
          ->execute([$b['id'], $uid]);
      db()->prepare('DELETE FROM ss_workspaces WHERE id = ? AND user_id = ?')
          ->execute([$b['id'], $uid]);
      // If deleted was active, switch to first available
      $first = db()->prepare('SELECT id FROM ss_workspaces WHERE user_id = ? ORDER BY created_at ASC LIMIT 1');
      $first->execute([$uid]);
      $fallback = $first->fetch();
      if ($fallback) {
        db()->prepare('UPDATE ss_users SET active_workspace_id = ? WHERE id = ?')
            ->execute([$fallback['id'], $uid]);
      }
      json_response(['ok' => true, 'active_workspace_id' => $fallback['id'] ?? null]);
      break;

    case 'workspace_switch':
      $b = read_json_body();
      db()->prepare('UPDATE ss_users SET active_workspace_id = ? WHERE id = ?')
          ->execute([$b['id'], $uid]);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // CONNECTIONS (garis antar note)
    // ─────────────────────────────────────────────────────────────
    case 'connection_upsert':
      $b = read_json_body();
      $id = $b['id'] ?? ('c_' . bin2hex(random_bytes(8)));
      $arrow = $b['arrow_type'] ?? 'one';
      if (!in_array($arrow, ['none','one','both'])) $arrow = 'one';
      db()->prepare('
        INSERT INTO ss_connections (id, user_id, workspace_id, from_note, to_note, label, arrow_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE label=VALUES(label), arrow_type=VALUES(arrow_type)
      ')->execute([
        $id, $uid,
        $b['workspace_id'] ?? '',
        $b['from_note'] ?? '',
        $b['to_note'] ?? '',
        $b['label'] ?? null,
        $arrow,
      ]);
      json_response(['ok' => true, 'id' => $id]);
      break;

    case 'connection_delete':
      $b = read_json_body();
      db()->prepare('DELETE FROM ss_connections WHERE id = ? AND user_id = ?')
          ->execute([$b['id'], $uid]);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // SYNTHESIZE: generate actionable plan dari Workspace+Mirror+Guard
    // ─────────────────────────────────────────────────────────────
    case 'synthesize':
      // Ambil data user
      $user = db()->prepare('SELECT name, hobby FROM ss_users WHERE id = ?');
      $user->execute([$uid]);
      $userData = $user->fetch();
      $userName = $userData['name'] ?? 'friend';
      $userHobby = $userData['hobby'] ?? '';

      // Ambil notes terbaru (max 10)
      $n = db()->prepare('SELECT text FROM ss_notes WHERE user_id = ? AND text IS NOT NULL AND text != "" ORDER BY updated_at DESC LIMIT 10');
      $n->execute([$uid]);
      $notes = array_column($n->fetchAll(), 'text');

      // Ambil mirror chat terbaru (max 10 pesan user terakhir)
      $m = db()->prepare('SELECT content FROM ss_mirror WHERE user_id = ? AND role = "user" ORDER BY created_at DESC LIMIT 10');
      $m->execute([$uid]);
      $mirrorMsgs = array_column($m->fetchAll(), 'content');

      // Ambil tasks aktif (belum done)
      $t = db()->prepare('SELECT text, done FROM ss_tasks WHERE user_id = ? ORDER BY created_at DESC LIMIT 15');
      $t->execute([$uid]);
      $tasks = $t->fetchAll();

      // Validasi: minimal 1 dari masing-masing
      if (count($notes) < 1 || count($mirrorMsgs) < 1 || count($tasks) < 1) {
        json_response([
          'error' => 'Not enough data',
          'requirements' => [
            'notes' => count($notes),
            'mirror' => count($mirrorMsgs),
            'tasks' => count($tasks),
          ]
        ], 400);
      }

      // Build context
      $notesText = implode("\n- ", $notes);
      $mirrorText = implode("\n- ", $mirrorMsgs);
      $tasksText = implode("\n- ", array_map(fn($x) => $x['text'] . ($x['done'] ? ' (DONE)' : ''), $tasks));

      $systemPrompt = 'You are the Synthesis Engine inside SoulShell — a tool for neurodivergent minds.

Your job: read the user\'s scattered thoughts (Workspace notes), emotional dumps (AI Mirror), and intended tasks (Guard), then synthesize them into an ACTIONABLE PLAN.

Output format: Return ONLY valid JSON, no markdown fences, no prose. Structure:
{
  "summary": "2-3 sentences identifying the core pattern or priority you see across all three inputs. Address the user by name.",
  "insights": "1 short paragraph (3-4 sentences) connecting the dots between their thoughts, emotions, and tasks. What\'s the underlying thread? What are they avoiding? What matters most?",
  "new_tasks": ["micro-step 1", "micro-step 2", "micro-step 3"]
}

Rules for new_tasks:
- 3 to 5 concrete micro-actions, each doable in under 10 minutes
- They should emerge from the synthesis, not repeat existing tasks
- Start with physical verbs: "Open", "Write", "Call", "Close", "Draft"
- Prioritize what unblocks the user emotionally OR tactically';

      $userMessage = "USER: {$userName} (hobby/interest: {$userHobby})

THOUGHTS DUMPED (Workspace notes):
- {$notesText}

EMOTIONAL DUMPS (Mirror chat):
- {$mirrorText}

INTENDED TASKS (Guard):
- {$tasksText}

Now synthesize. Return JSON only.";

      $reply = call_anthropic($systemPrompt, $userMessage, 1500);
      $cleaned = trim(str_replace(['```json', '```'], '', $reply));
      $result = json_decode($cleaned, true);

      if (!is_array($result) || !isset($result['summary'])) {
        json_response(['error' => 'AI returned invalid format', 'raw' => $reply], 502);
      }

      // Auto-create new tasks di Guard
      $createdTasks = [];
      if (isset($result['new_tasks']) && is_array($result['new_tasks'])) {
        $ins = db()->prepare('INSERT INTO ss_tasks (id, user_id, text, done, subtasks) VALUES (?, ?, ?, 0, "[]")');
        foreach ($result['new_tasks'] as $taskText) {
          if (!is_string($taskText) || !trim($taskText)) continue;
          $tid = 't_syn_' . bin2hex(random_bytes(6));
          $ins->execute([$tid, $uid, trim($taskText)]);
          $createdTasks[] = ['id' => $tid, 'text' => trim($taskText)];
        }
      }

      // Simpan synthesis history
      $synIns = db()->prepare('INSERT INTO ss_synthesis (user_id, summary, insights, created_tasks) VALUES (?, ?, ?, ?)');
      $synIns->execute([
        $uid,
        $result['summary'] ?? '',
        $result['insights'] ?? '',
        json_encode($createdTasks),
      ]);

      json_response([
        'summary' => $result['summary'] ?? '',
        'insights' => $result['insights'] ?? '',
        'created_tasks' => $createdTasks,
      ]);
      break;

    // ─────────────────────────────────────────────────────────────
    // DASHBOARD: update cards
    // ─────────────────────────────────────────────────────────────
    case 'update_dashboard':
      $body = read_json_body();
      $fields = [];
      $values = [];
      foreach (['flow_stats','attention_state','growth_paths','tribe_syncs','current_project','focus_mode','velocity'] as $f) {
        if (isset($body[$f])) {
          $fields[] = "$f = ?";
          $values[] = $body[$f];
        }
      }
      if (!$fields) json_response(['ok' => true]);
      $values[] = $uid;
      db()->prepare('UPDATE ss_dashboard SET ' . implode(',', $fields) . ' WHERE user_id = ?')->execute($values);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // NOTES (Fluid Workspace / Idea Fragments)
    // ─────────────────────────────────────────────────────────────
    case 'note_upsert':
      $b = read_json_body();
      $id = $b['id'] ?? ('n_' . bin2hex(random_bytes(8)));
      $stmt = db()->prepare('
        INSERT INTO ss_notes (id, user_id, workspace_id, text, title, pos_x, pos_y, color_idx, kind)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE text=VALUES(text), title=VALUES(title), pos_x=VALUES(pos_x), pos_y=VALUES(pos_y), color_idx=VALUES(color_idx), kind=VALUES(kind), workspace_id=VALUES(workspace_id)
      ');
      $stmt->execute([
        $id, $uid,
        $b['workspace_id'] ?? null,
        $b['text'] ?? '',
        $b['title'] ?? null,
        (int)($b['pos_x'] ?? 0),
        (int)($b['pos_y'] ?? 0),
        (int)($b['color_idx'] ?? 0),
        $b['kind'] ?? 'note',
      ]);
      json_response(['ok' => true, 'id' => $id]);
      break;

    case 'note_delete':
      $b = read_json_body();
      db()->prepare('DELETE FROM ss_notes WHERE id = ? AND user_id = ?')->execute([$b['id'], $uid]);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // TASKS (Dopamine Guard)
    // ─────────────────────────────────────────────────────────────
    case 'task_upsert':
      $b = read_json_body();
      $id = $b['id'] ?? ('t_' . bin2hex(random_bytes(8)));
      $completed = !empty($b['done']) ? date('Y-m-d H:i:s') : null;
      $stmt = db()->prepare('
        INSERT INTO ss_tasks (id, user_id, text, done, subtasks, completed_at)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE text=VALUES(text), done=VALUES(done), subtasks=VALUES(subtasks), completed_at=VALUES(completed_at)
      ');
      $stmt->execute([
        $id, $uid,
        $b['text'] ?? '',
        !empty($b['done']) ? 1 : 0,
        isset($b['subtasks']) ? json_encode($b['subtasks']) : '[]',
        $completed,
      ]);
      json_response(['ok' => true, 'id' => $id]);
      break;

    case 'task_delete':
      $b = read_json_body();
      db()->prepare('DELETE FROM ss_tasks WHERE id = ? AND user_id = ?')->execute([$b['id'], $uid]);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // AI MIRROR: proxy ke Anthropic API
    // ─────────────────────────────────────────────────────────────
    case 'mirror':
      $b = read_json_body();
      $message = trim($b['message'] ?? '');
      $energy = $b['energy'] ?? 'medium';
      if (!$message) json_response(['error' => 'empty message'], 400);

      // Simpan user message
      db()->prepare('INSERT INTO ss_mirror (user_id, role, content) VALUES (?, "user", ?)')
          ->execute([$uid, $message]);

      $systemPrompt = "You are AI Mirror inside SoulShell, a tool built for neurodivergent minds (ADHD, autism, etc).

Your role: the user dumps chaotic, tangential, scattered thoughts at you. You REFLECT them back — not by judging or rewriting, but by:
1. Acknowledging the underlying feeling or intent first (1 short sentence)
2. Restating the thought in clearer, more structured language
3. Extracting 1-3 actionable next steps (tiny, concrete — \"open the doc\" not \"write the report\")

User energy state: {$energy} (low = be gentle + minimal, medium = normal, high = match their intensity, be punchy).

Keep responses tight. No preamble, no \"great question\". No emoji. Use short paragraphs. End with actions as a small list prefixed with \"→\".";

      $reply = call_anthropic($systemPrompt, $message, 1000);

      // Simpan assistant reply
      db()->prepare('INSERT INTO ss_mirror (user_id, role, content) VALUES (?, "assistant", ?)')
          ->execute([$uid, $reply]);

      json_response(['reply' => $reply]);
      break;

    case 'mirror_clear':
      db()->prepare('DELETE FROM ss_mirror WHERE user_id = ?')->execute([$uid]);
      json_response(['ok' => true]);
      break;

    // ─────────────────────────────────────────────────────────────
    // AI: breakdown task menjadi micro-steps
    // ─────────────────────────────────────────────────────────────
    case 'breakdown':
      $b = read_json_body();
      $taskText = trim($b['text'] ?? '');
      if (!$taskText) json_response(['error' => 'empty task'], 400);

      $systemPrompt = 'You break big tasks into tiny, dopamine-friendly micro-steps for ADHD brains.

Rules:
- Return ONLY a JSON array of 3-5 strings, no prose, no markdown fences.
- Each step must be doable in under 5 minutes.
- Start with the smallest possible action (e.g. "open the file" not "write the draft").
- Use plain verbs. No fluff.

Example input: "Write quarterly report"
Example output: ["Open a blank doc", "Write the title only", "List 3 bullet points you remember", "Draft the intro paragraph", "Save and close"]';

      $reply = call_anthropic($systemPrompt, $taskText, 500);
      $cleaned = trim(str_replace(['```json', '```'], '', $reply));
      $steps = json_decode($cleaned, true);
      if (!is_array($steps)) $steps = [];
      json_response(['steps' => $steps]);
      break;

    // ─────────────────────────────────────────────────────────────
    // AI: generate mood board title / idea fragment
    // ─────────────────────────────────────────────────────────────
    case 'fragment':
      $b = read_json_body();
      $context = trim($b['context'] ?? 'neurodivergent creativity');

      $systemPrompt = 'Generate 1 short evocative "idea fragment" — a poetic, philosophical sentence about creativity, chaos, or cognition. 1-2 sentences max. Return ONLY the sentence, no quotes, no preamble.';

      $reply = call_anthropic($systemPrompt, "Topic: $context", 150);
      json_response(['fragment' => trim($reply)]);
      break;

    default:
      json_response(['error' => 'Unknown action: ' . $action], 404);
  }

} catch (Throwable $e) {
  json_response([
    'error' => 'Server error',
    'detail' => APP_ENV === 'development' ? $e->getMessage() : null,
  ], 500);
}

// ─────────────────────────────────────────────────────────────
// Anthropic API call (cURL)
// ─────────────────────────────────────────────────────────────
function call_anthropic($system, $userMessage, $maxTokens = 1000) {
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  $payload = json_encode([
    'model' => ANTHROPIC_MODEL,
    'max_tokens' => $maxTokens,
    'system' => $system,
    'messages' => [['role' => 'user', 'content' => $userMessage]],
  ]);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'x-api-key: ' . ANTHROPIC_API_KEY,
      'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT => 60,
  ]);

  $response = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($err) json_response(['error' => 'Anthropic connection failed', 'detail' => APP_ENV === 'development' ? $err : null], 502);
  if ($status >= 400) json_response(['error' => 'Anthropic API error', 'status' => $status, 'detail' => APP_ENV === 'development' ? $response : null], 502);

  $data = json_decode($response, true);
  $text = '';
  if (isset($data['content']) && is_array($data['content'])) {
    foreach ($data['content'] as $block) {
      if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
  }
  return $text ?: '(no response)';
}
