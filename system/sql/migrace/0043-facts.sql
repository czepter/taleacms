-- Business facts (2.10, Core\Facts): typed facts the site states in many places – {{fact.key}} in pages, site parts and
-- news, schema.org, llms.txt and MCP. A language version may have its own value of a text fact. Every change of a value
-- is kept, so the old value can be found in sentences that still say it.
CREATE TABLE IF NOT EXISTS ka_facts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fact_key    VARCHAR(40)  NOT NULL,                      -- a-z, digits, _; used as {{fact.<key>}}
    language    VARCHAR(2)   NOT NULL DEFAULT '',           -- '' = the default language
    label       VARCHAR(150) NOT NULL DEFAULT '',
    type        VARCHAR(10)  NOT NULL DEFAULT 'text',       -- text | number | money | date | year | phone | email | url
    value       VARCHAR(500) NOT NULL DEFAULT '',
    schema_prop VARCHAR(40)  NOT NULL DEFAULT '',           -- a schema.org property of the organisation, e.g. foundingDate
    source      VARCHAR(255) NOT NULL DEFAULT '',           -- where the fact comes from (a note or a link)
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_facts_key (fact_key, language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ka_fact_history (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fact_key   VARCHAR(40)  NOT NULL,
    language   VARCHAR(2)   NOT NULL DEFAULT '',
    old_value  VARCHAR(500) NOT NULL DEFAULT '',
    new_value  VARCHAR(500) NOT NULL DEFAULT '',
    changed_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_fact_history_key (fact_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
