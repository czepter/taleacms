-- Scheduled Claude runs (2.17, Core\AgentSchedules): the site keeps the schedules (a weekly review, a monthly report, a
-- daily triage of enquiries, the open requests, or the administrator's own instructions), tells a Claude routine what is
-- due (get_due_agent_runs), records each run (report_agent_run) and notices runs nobody picked up. The site cannot run
-- Claude by itself: the run happens in Claude, over a drafts-only connection. Not in the site export: the schedule belongs
-- to the team working on the site, not to its content.
CREATE TABLE IF NOT EXISTS ka_agent_schedules (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    task        VARCHAR(30) NOT NULL,                    -- review | report | triage | requests | custom
    text        TEXT NOT NULL,                           -- the instructions of a custom task; optional extra for the others
    cadence     VARCHAR(10) NOT NULL,                    -- daily | weekly | monthly
    day         TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- weekly: ISO weekday 1–7; monthly: day of month 1–28
    time        CHAR(5) NOT NULL DEFAULT '07:00',        -- HH:MM in the site's time zone
    active      TINYINT(1) NOT NULL DEFAULT 1,
    next_due    DATETIME NULL,                           -- the next moment a run is handed out (site time)
    last_run_at DATETIME NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_agent_schedules_due (active, next_due)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One run of a schedule: handed out (running) until the routine reports it (ok | partial | failed), or missed when nobody
-- picked it up within 6 hours. The summary and the links to the drafts are what the routine reported.
CREATE TABLE IF NOT EXISTS ka_agent_runs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NOT NULL,
    due_at      DATETIME NOT NULL,
    started_at  DATETIME NULL,                           -- when get_due_agent_runs handed it out
    finished_at DATETIME NULL,
    status      VARCHAR(10) NOT NULL DEFAULT 'running',  -- running | ok | partial | failed | missed
    summary     TEXT NULL,
    links       TEXT NULL,                               -- JSON list of {"label": …, "url": …}
    connection  VARCHAR(100) NOT NULL DEFAULT '',        -- the name of the Claude connection that did the run
    PRIMARY KEY (id),
    KEY ix_agent_runs_schedule (schedule_id, id),
    CONSTRAINT fk_agent_runs_schedule FOREIGN KEY (schedule_id) REFERENCES ka_agent_schedules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
