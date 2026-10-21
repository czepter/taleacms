# Talea security policy

## Reporting a vulnerability

Talea is a hard fork of [Kaleta](https://github.com/phprs-cms/kaletacms). If the problem also exists in Kaleta, please report it
to the Kaleta project as well, through its own security policy.

Please **do not report security issues publicly** in Issues. Use GitHub's private reporting
(*Security → Report a vulnerability*). Describe the version, the steps
and the impact. We reply within 3 working days and usually release a fix within 14 days; critical issues
are fixed as soon as possible.

## How a fix reaches users

1. The fix ships as a new container image (signed with cosign); its GitHub release is marked as a **security release**.
2. Operators hear of it through the repository's releases (watch → releases) or the advisory below.
3. The operator updates: `docker compose pull && docker compose up -d`. The container backs up the database
   and applies the migrations before it serves the new version (docs/DEPLOYMENT.md).
   This differs from Kaleta, where a site installs a security release on its own; Talea never updates itself.
4. After the fix is released we publish a security advisory (GitHub Security Advisory) with a description
   and credit to the reporter.

Only the latest release is supported; every earlier version updates to it in one step. Release pace, deprecation and
what stays compatible: [docs/RELEASE-POLICY.md](docs/RELEASE-POLICY.md).

## What the system protects

Prepared statements everywhere, `password_hash` passwords, CSRF protection on every admin action, escaped
output, re-encoded uploaded images, `system/` and `storage/` not reachable from the web, and images signed
with cosign. The system uses no third-party libraries at runtime.
