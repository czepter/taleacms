# Name check: Talea CMS (HF-15)

Checked on 2026-10-10. Result: **provisional GO** – nothing found that blocks the name; the two trademark registers could not be
searched from here and must be searched by hand before the first public release (links below).

| Check | Result |
|---|---|
| Web search "Talea" + CMS / website builder / SaaS / trademark | No CMS, website builder or software product called Talea. Unrelated: Talea Pflege GmbH (home care, Berlin), Taleva (data / recruiting, different spelling). |
| GitHub `talea` | A personal account exists (1 repository, `swtor`, 0 stars). `github.com/taleacms` is **free** → use `taleacms` for the organisation. |
| GitHub repository search "talea cms" | 0 results. |
| npm `talea`, `taleacms` | Both free (404). |
| Packagist | `talea/cms` free; `talea` as vendor has no package. |
| Docker Hub | User `talea` exists; `taleacms` is **free** → image `taleacms/taleacms`. |
| Domains | `taleacms.com`, `.io`, `.dev`, `.org` all unregistered in DNS (no A/NS records). `talea.com`, `talea.io`, `talea.org` are taken; `talea.dev` is free in DNS. |
| EUIPO / USPTO, classes 9 and 42 | Searched on 2026-10-10 in [TMView](https://www.tmdn.org/tmview/) (EUIPO + USPTO, "talea", 2,855 hits, active and lapsed). **Live identical word mark in class 42:** US 5874971 "TALEA", The Bancorp Inc., registered 2019, classes 36 and 42 – SaaS for loan origination and securities-backed lines of credit (goods are narrow and financial). Older identical marks are dead: EU "TALEA" filed 2014 (classes 9, 35, 41, 42) is expired; US "TALEA" 2014 (35, 41, 42) is ended. No live EU "TALEA" in class 9 or 42. Similar marks: EU "KALEA" pending (2025, classes 9, 35, 36, 42, 45), US "TALIA" pending (2026, class 42). Not a legal opinion. |

## Decision

GO under these conditions, to be confirmed before the first public release:

1. Register `taleacms.com` (and `.org`/`.dev` if cheap), the GitHub organisation `taleacms` and the Docker Hub namespace `taleacms`.
2. Run the two trademark searches above; record the result as a comment on issue #19.
3. If either finds a conflicting software mark: take the fallback shortlist (Acre `ac_`, Cairn `ca_`; both were unregistered in DNS only –
   `cairncms.io` and `cairncms.dev` are already taken, `acrecms.*` is free) and run HF-10 again; the rename is one scripted pass.

Out of scope: registering the name, a domain or a trademark; legal advice.

## Trademark search result (2026-10-10)

Risk summary for the maintainer's go/no-go: no live EU mark "TALEA" in classes 9/42; one live US mark "TALEA" in class 42 whose registered
services (loan-origination SaaS) are far from a website builder, so confusion is unlikely but not excluded; two similar pending marks
(KALEA in the EU, TALIA in the US) worth watching. Recommendation: GO, with a short check by a trademark attorney before any filing or a
paid launch in the US. **Decision (maintainer, 2026-10-10): GO.** An attorney check precedes any trademark filing or paid US launch.
