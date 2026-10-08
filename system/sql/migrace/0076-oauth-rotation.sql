-- OAuth for the Claude connector (3.3.4, security audit of 8 October 2026).
-- approved: when a person on this site first allowed the client. The consent screen marks a client nobody approved as
-- new, and the daily job deletes such a client a day after its registration when it has no token and no code (N65, N66).
-- Every client from before this release counts as approved, so no stored client of a connected Claude app is ever
-- deleted – Claude signs in again with the client_id it keeps.
ALTER TABLE ka_oauth_klienti ADD COLUMN approved DATETIME NULL AFTER vytvoren;
UPDATE ka_oauth_klienti SET approved = vytvoren WHERE approved IS NULL;

-- Refresh tokens that were exchanged for a new pair (N13): reusing one within Front\OAuth::REFRESH_GRACE seconds returns
-- the same pair (derived from the token and the salt), reusing it later revokes the client's tokens for the user.
CREATE TABLE ka_oauth_rotated (
    hash       CHAR(64)     NOT NULL,              -- sha256 of the rotated refresh token
    client_id  CHAR(32)     NOT NULL,
    idu        INT UNSIGNED NOT NULL,
    salt       CHAR(64)     NOT NULL,              -- with the old token it derives the pair it was rotated into
    rotated_at DATETIME     NOT NULL,
    expires_at DATETIME     NOT NULL,              -- when the rotated token would have expired; the record goes then
    PRIMARY KEY (hash),
    KEY ix_oauth_rotated_client (client_id, idu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
