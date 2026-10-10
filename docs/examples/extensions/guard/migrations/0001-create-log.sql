-- {pk} is the auto-numbered primary key of the engine (MySQL and PostgreSQL); only {ext_guard_…} tables may be changed
CREATE TABLE IF NOT EXISTS {ext_guard_log} (
    id {pk},
    path VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ext_guard_log_path ON {ext_guard_log} (path);
