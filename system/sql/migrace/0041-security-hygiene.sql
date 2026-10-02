-- Security hygiene that runs itself (2.8, Core\SecurityHygiene): accounts unused for 90 days and Claude connections
-- unused for 60 days are reported in System status and the site audit, and blocked or revoked automatically when the
-- administrator switches it on (setting auto_suspend).
--  - potvrzeno: when the account was created or last confirmed by an administrator (saved in Users, reactivated) – the
--    unused-account check counts from it when the account has never signed in; existing accounts start from their last
--    sign-in, or from now.
--  - blokovano_automaticky: when the automatic suspension blocked the account (NULL = not blocked by it) – Users shows the reason.
ALTER TABLE ka_uzivatele ADD COLUMN potvrzeno DATETIME NULL AFTER posledni_login;
ALTER TABLE ka_uzivatele ADD COLUMN blokovano_automaticky DATETIME NULL AFTER blokovat;
UPDATE ka_uzivatele SET potvrzeno = COALESCE(posledni_login, NOW()) WHERE potvrzeno IS NULL;
