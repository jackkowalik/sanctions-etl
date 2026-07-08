-- Schema for the MySQL storage backend (STORAGE=mysql).
-- Loaded automatically on first boot by the docker-compose setup,
-- or apply by hand: mysql -u sanctions -p sanctions < schema.sql

CREATE TABLE IF NOT EXISTS sanctions_sources (
    source_id       VARCHAR(64)  NOT NULL PRIMARY KEY,
    display_name    VARCHAR(255) NOT NULL DEFAULT '',
    last_synced_at  DATETIME     NULL,
    last_file_hash  CHAR(64)     NULL,
    entity_count    INT UNSIGNED NOT NULL DEFAULT 0,
    last_changeset  JSON         NULL,
    status          VARCHAR(32)  NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entities are never deleted. A delist sets delisted_at, so history
-- survives and a re-listed entity flips back to NULL.
CREATE TABLE IF NOT EXISTS sanctions_entities (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id        VARCHAR(64)  NOT NULL,
    source_entity_id VARCHAR(128) NOT NULL,
    entity_type      VARCHAR(32)  NOT NULL DEFAULT 'unknown',
    primary_name     VARCHAR(512) NOT NULL,
    -- list-of-scalar fields; the relational children below are the
    -- ones worth joining on
    dates            JSON NULL,
    nationalities    JSON NULL,
    programs         JSON NULL,
    -- deliberately a string: sources disagree on date formats and a
    -- strict DATE column would reject real upstream values
    listed_date      VARCHAR(32) NULL,
    remarks          TEXT NULL,
    raw              JSON NULL,
    content_hash     CHAR(64) NOT NULL,
    delisted_at      DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source_entity (source_id, source_entity_id),
    KEY idx_source_active (source_id, delisted_at),
    KEY idx_primary_name (primary_name(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sanctions_aliases (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_id   BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(512) NOT NULL,
    alias_type  VARCHAR(32)  NOT NULL DEFAULT 'aka',
    low_quality TINYINT(1)   NOT NULL DEFAULT 0,
    KEY idx_entity (entity_id),
    KEY idx_name (name(191)),
    CONSTRAINT fk_aliases_entity FOREIGN KEY (entity_id)
        REFERENCES sanctions_entities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sanctions_identifiers (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_id BIGINT UNSIGNED NOT NULL,
    id_type   VARCHAR(64)  NOT NULL DEFAULT 'unknown',
    id_value  VARCHAR(255) NOT NULL,
    country   VARCHAR(64)  NOT NULL DEFAULT '',
    valid     TINYINT(1)   NOT NULL DEFAULT 1,
    KEY idx_entity (entity_id),
    KEY idx_value (id_value),
    CONSTRAINT fk_identifiers_entity FOREIGN KEY (entity_id)
        REFERENCES sanctions_entities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sanctions_addresses (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_id    BIGINT UNSIGNED NOT NULL,
    full_address VARCHAR(750) NULL,
    city         VARCHAR(128) NULL,
    region       VARCHAR(128) NULL,
    postal_code  VARCHAR(32)  NULL,
    country      VARCHAR(64)  NULL,
    KEY idx_entity (entity_id),
    CONSTRAINT fk_addresses_entity FOREIGN KEY (entity_id)
        REFERENCES sanctions_entities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only audit log, one row per source per sync run.
CREATE TABLE IF NOT EXISTS sanctions_sync_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id       VARCHAR(64) NOT NULL,
    sync_type       VARCHAR(16) NOT NULL DEFAULT 'full',
    status          VARCHAR(16) NOT NULL,
    file_hash       CHAR(64)    NULL,
    entities_parsed INT UNSIGNED NULL,
    inserts         INT UNSIGNED NULL,
    updates         INT UNSIGNED NULL,
    delists         INT UNSIGNED NULL,
    duration_ms     INT UNSIGNED NULL,
    error_message   TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_source_time (source_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
