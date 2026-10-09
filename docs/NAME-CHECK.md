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
| EUIPO / USPTO, classes 9 and 42 | **Not searched** (the registers are interactive, not reachable from the build environment). Search "Talea" by hand: [EUIPO TMview](https://www.tmdn.org/tmview/), [USPTO Trademark Search](https://tmsearch.uspto.gov/). A hit in class 9 or 42 for software would turn this into a NO-GO. |

## Decision

GO under these conditions, to be confirmed before the first public release:

1. Register `taleacms.com` (and `.org`/`.dev` if cheap), the GitHub organisation `taleacms` and the Docker Hub namespace `taleacms`.
2. Run the two trademark searches above; record the result as a comment on issue #19.
3. If either finds a conflicting software mark: take the fallback shortlist (Acre `ac_`, Cairn `ca_`; both were unregistered in DNS only –
   `cairncms.io` and `cairncms.dev` are already taken, `acrecms.*` is free) and run HF-10 again; the rename is one scripted pass.

Out of scope: registering the name, a domain or a trademark; legal advice.
