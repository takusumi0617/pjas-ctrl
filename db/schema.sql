-- pjas-ctrl schema (SQLite)
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'viewer',   -- owner | operator | viewer
  totp_secret   TEXT,
  totp_last_step INTEGER NOT NULL DEFAULT -1,
  scope_all INTEGER NOT NULL DEFAULT 1,
  enabled INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS services (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  name           TEXT NOT NULL UNIQUE,            -- = /var/www/<name>
  domain         TEXT NOT NULL,
  runtime        TEXT NOT NULL DEFAULT 'php8.4-fpm', -- php*-fpm | static
  docroot        TEXT NOT NULL DEFAULT 'public',
  health_path    TEXT NOT NULL DEFAULT '/healthz',
  health_status  INTEGER NOT NULL DEFAULT 200,
  keep_releases  INTEGER NOT NULL DEFAULT 5,
  allow_override TEXT NOT NULL DEFAULT 'None',
  extra_conf     TEXT NOT NULL DEFAULT '',
  tls            INTEGER NOT NULL DEFAULT 0,
  shared_paths   TEXT NOT NULL DEFAULT '.env,storage',
  hooks          TEXT NOT NULL DEFAULT '',        -- newline separated, allowlisted
  state          TEXT NOT NULL DEFAULT 'unknown', -- healthy | unhealthy | unknown
  checked_at     TEXT,
  created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS releases (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id  INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  tag         TEXT NOT NULL,                      -- directory name: YYYYmmdd-HHiiss
  label       TEXT NOT NULL DEFAULT '',
  source      TEXT NOT NULL,                      -- upload:<file> | git:<ref>
  sha256      TEXT NOT NULL DEFAULT '',
  size        INTEGER NOT NULL DEFAULT 0,
  state       TEXT NOT NULL DEFAULT 'pending',    -- pending|current|archived|failed
  deployed_by TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(service_id, tag)
);

CREATE TABLE IF NOT EXISTS jobs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id  INTEGER REFERENCES services(id) ON DELETE CASCADE,
  type        TEXT NOT NULL,                      -- deploy|rollback|vhost|tls|reload
  state       TEXT NOT NULL DEFAULT 'queued',     -- queued|running|succeeded|failed
  detail      TEXT NOT NULL DEFAULT '',
  actor       TEXT NOT NULL DEFAULT '',
  started_at  TEXT NOT NULL DEFAULT (datetime('now')),
  finished_at TEXT
);

CREATE TABLE IF NOT EXISTS vhosts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id    INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  rendered_hash TEXT NOT NULL,
  applied_at    TEXT NOT NULL DEFAULT (datetime('now')),
  applied_by    TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS scheduled_tasks (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id  INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  schedule    TEXT NOT NULL,
  command     TEXT NOT NULL,
  enabled     INTEGER NOT NULL DEFAULT 1,
  created_by  TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS api_tokens (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL,
  hash         TEXT NOT NULL,
  scopes       TEXT NOT NULL DEFAULT 'read:*',
  last_used_at TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS audit_log (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  actor   TEXT NOT NULL,
  action  TEXT NOT NULL,
  target  TEXT NOT NULL DEFAULT '',
  params  TEXT NOT NULL DEFAULT '{}',
  result  TEXT NOT NULL DEFAULT 'ok',
  ip      TEXT NOT NULL DEFAULT '',
  at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_jobs_service ON jobs(service_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_rel_service  ON releases(service_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_at     ON audit_log(id DESC);
CREATE INDEX IF NOT EXISTS idx_tasks_service ON scheduled_tasks(service_id, id);

CREATE TABLE IF NOT EXISTS service_access (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK(role IN ('viewer','operator')),
  PRIMARY KEY(user_id,service_id)
);
CREATE TABLE IF NOT EXISTS recovery_codes (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  hash TEXT NOT NULL,
  PRIMARY KEY(user_id,hash)
);
CREATE TABLE IF NOT EXISTS login_attempts (
  bucket TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, reset_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS site_policies (
  service_id INTEGER PRIMARY KEY REFERENCES services(id) ON DELETE CASCADE,
  settings TEXT NOT NULL DEFAULT '{}', updated_by INTEGER REFERENCES users(id), updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS publication_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  requested_by INTEGER REFERENCES users(id), actor TEXT NOT NULL,
  source TEXT NOT NULL, publish_at TEXT NOT NULL,
  state TEXT NOT NULL DEFAULT 'queued', job_id INTEGER REFERENCES jobs(id), detail TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')), UNIQUE(service_id,source,publish_at)
);
CREATE INDEX IF NOT EXISTS idx_publication_due ON publication_queue(state,publish_at);
CREATE TABLE IF NOT EXISTS webhook_deliveries (
  delivery TEXT PRIMARY KEY, received_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS monitor_state (
  check_key TEXT PRIMARY KEY, service_id INTEGER REFERENCES services(id) ON DELETE CASCADE,
  ok INTEGER NOT NULL, detail TEXT NOT NULL, checked_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER REFERENCES services(id) ON DELETE CASCADE,
  kind TEXT NOT NULL, message TEXT NOT NULL, resolved INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')), notified_at TEXT, notification_error TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS app_state (name TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS webhook_commits (
 service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
 sha TEXT NOT NULL, received_at TEXT NOT NULL DEFAULT (datetime('now')),
 PRIMARY KEY(service_id,sha)
);

CREATE TABLE IF NOT EXISTS job_notifications (job_id INTEGER PRIMARY KEY REFERENCES jobs(id) ON DELETE CASCADE);
