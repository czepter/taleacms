# Release and support policy

This is what you can rely on when you build sites on Talea – for yourself or for clients. The policy was written for Kaleta 2.1 and Talea keeps it, with one difference: Talea releases are container images, and a site never updates itself (docs/DEPLOYMENT.md).

## Versions and pace

Talea versions are `MAJOR.MINOR.PATCH`.

| Release | What it brings | How often |
| --- | --- | --- |
| **Minor** (2.1, 2.2…) | New features and improvements. Nothing you use stops working. | About once a month |
| **Patch** (2.1.1…) | Fixes only. | When needed |
| **Security** (a patch marked as security) | A fix for a vulnerability. Flagged in the admin notice; the operator pulls the new image (Kaleta installs it on its own). | When needed, as fast as possible |
| **Major** (3.0…) | Removes what was deprecated earlier (see below). No surprise removals. | At most once a year |

Every release is announced in the GitHub releases of the project (TODO: and a changelog on the project site once its domain is registered, see NAME-CHECK.md).

## Which versions are supported

**The latest release.** A site on any earlier version reaches it in one step by pulling the newest image – there are no
required stops in between; the container applies the migrations before it starts serving. `composer test:e2e` builds the
image and updates a real installation (docs/DEPLOYMENT.md).

Security releases are flagged in the admin notice; the operator pulls the image. Unlike Kaleta, a Talea site never installs
anything on its own. How vulnerabilities are reported and fixed
is in [SECURITY.md](../SECURITY.md).

## Deprecation

When something has to go, it is:

1. **announced** in the release notes of a minor release, with what to use instead;
2. **shown on the sites that still use it**, in **Settings → System status**;
3. **kept working for at least two minor releases and at least six months**;
4. **removed only in a major release**.

## What counts as the public contract

These are covered by the rules above. They are recorded in [`tools/contracts/`](../tools/contracts) and the tests fail when
any of them is removed or changed outside a deprecation:

- **The Claude connection (MCP):** tool names, their parameters and parameter types, which parameters are required, and
  each tool's annotations (read, write, destructive). The hidden Czech tool names of older connections keep working too.
- **Design tokens** – both the stored names (`--tl-color-primary`) and the English ones (`--tl-color-primary`) – so
  custom CSS in shared classes keeps working.
- **Builder elements** and their content properties, so every saved page keeps rendering.
- **The site export** (Administration → Import and export): an export can be imported into the same or any later release.
- **Addresses of your content:** an update never changes the address of a page, news item, collection item or feed.
  When you change the address of a page, news item or category yourself, Talea adds a redirect from the old one
  (Redirects extension).
- **The release feed and its signatures**, so installed sites keep seeing new versions.

## What is not a contract

- PHP classes, functions and database tables. Talea has no plug-in API; build on the export, the Claude connection,
  webhooks and feeds instead.
- The HTML of the admin and the exact HTML of public pages (the look is yours through the design system and classes; the
  markup around it may improve).
- The wording of texts, and anything marked as experimental in its release notes.
