-- ═══════════════════════════════════════════════════════════════════
-- SoulShell Database Schema
-- Import via phpMyAdmin → SQL tab → paste & run
-- ═══════════════════════════════════════════════════════════════════

-- Users table (simple auth dengan email)
CREATE TABLE IF NOT EXISTS ss_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  display_name VARCHAR(100),
  name VARCHAR(100),
  hobby VARCHAR(200),
  avatar LONGTEXT,
  gender ENUM('male','female','unspecified') DEFAULT 'unspecified',
  profile_complete TINYINT(1) DEFAULT 0,
  xp INT DEFAULT 0,
  streak INT DEFAULT 0,
  energy ENUM('low','medium','high') DEFAULT 'medium',
  last_action_date DATE NULL,
  clarity_score INT DEFAULT 0,
  noise_level ENUM('low','medium','high') DEFAULT 'medium',
  pattern_type VARCHAR(50) DEFAULT 'Divergent',
  active_workspace_id VARCHAR(32) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration untuk DB yang sudah ada (jalanin kalau tabel sudah terbuat)
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS name VARCHAR(100);
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS hobby VARCHAR(200);
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS avatar LONGTEXT;
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS profile_complete TINYINT(1) DEFAULT 0;
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS gender ENUM('male','female','unspecified') DEFAULT 'unspecified';
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS active_workspace_id VARCHAR(32) DEFAULT NULL;
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 0;
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS wallet_address VARCHAR(64) DEFAULT NULL;
ALTER TABLE ss_users ADD COLUMN IF NOT EXISTS auth_method ENUM('email','wallet') DEFAULT 'email';

-- OTP codes table (temporary codes, expires in 10 minutes)
CREATE TABLE IF NOT EXISTS ss_otp_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  code CHAR(6) NOT NULL,
  attempts TINYINT DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Workspace table (multi-workspace per user)
CREATE TABLE IF NOT EXISTS ss_workspaces (
  id VARCHAR(32) PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT 'Workspace',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES ss_users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fluid Workspace: idea fragments / sticky notes
CREATE TABLE IF NOT EXISTS ss_notes (
  id VARCHAR(32) PRIMARY KEY,
  user_id INT NOT NULL,
  workspace_id VARCHAR(32) DEFAULT NULL,
  text TEXT,
  title VARCHAR(200) DEFAULT NULL,
  pos_x INT DEFAULT 0,
  pos_y INT DEFAULT 0,
  color_idx TINYINT DEFAULT 0,
  kind ENUM('note','fragment','mood') DEFAULT 'note',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES ss_users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id),
  INDEX idx_workspace (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration untuk notes table (kalau sudah ada)
ALTER TABLE ss_notes ADD COLUMN IF NOT EXISTS workspace_id VARCHAR(32) DEFAULT NULL;
ALTER TABLE ss_notes ADD INDEX IF NOT EXISTS idx_workspace (workspace_id);

-- Note connections (untuk garis antar note)
CREATE TABLE IF NOT EXISTS ss_connections (
  id VARCHAR(32) PRIMARY KEY,
  user_id INT NOT NULL,
  workspace_id VARCHAR(32) NOT NULL,
  from_note VARCHAR(32) NOT NULL,
  to_note VARCHAR(32) NOT NULL,
  label VARCHAR(100) DEFAULT NULL,
  arrow_type ENUM('none','one','both') DEFAULT 'one',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES ss_users(id) ON DELETE CASCADE,
  INDEX idx_workspace (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI Mirror: conversation history
CREATE TABLE IF NOT EXISTS ss_mirror (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES ss_users(id) ON DELETE CASCADE,
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dopamine Guard: tasks
CREATE TABLE IF NOT EXISTS ss_tasks (
  id VARCHAR(32) PRIMARY KEY,
  user_id INT NOT NULL,
  text VARCHAR(500) NOT NULL,
  done TINYINT(1) DEFAULT 0,
  subtasks JSON,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES ss_users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dashboard cards state (Flow Stats, Attention, Growth, Tribe, Soul.ID)
CREATE TABLE IF NOT EXISTS ss_dashboard (
  user_id INT PRIMARY KEY,
  flow_stats INT DEFAULT 0,
  attention_state ENUM('Deep','Medium','Shallow') DEFAULT 'Medium',
  growth_paths INT DEFAULT 0,
  tribe_syncs INT DEFAULT 0,
  current_project VARCHAR(200) DEFAULT 'Rebranding Self',
  focus_mode TINYINT(1) DEFAULT 0,
  velocity ENUM('Low','Medium','High') DEFAULT 'Medium',
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES ss_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Synthesis: AI-generated actionable plans dari gabungan Workspace+Mirror+Guard
CREATE TABLE IF NOT EXISTS ss_synthesis (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  summary TEXT,
  insights TEXT,
  created_tasks JSON,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES ss_users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demo user (opsional — hapus kalau nggak perlu)
INSERT INTO ss_users (email, display_name) VALUES ('demo@soulshell.ai', 'Demo User')
  ON DUPLICATE KEY UPDATE email=email;

INSERT INTO ss_dashboard (user_id)
  SELECT id FROM ss_users WHERE email='demo@soulshell.ai'
  ON DUPLICATE KEY UPDATE user_id=user_id;
