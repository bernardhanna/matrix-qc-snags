# Matrix QC Snag

In-site QC snagging tool for WordPress. Reviewers flag visual/functional snags directly on the front end (per page, desktop/mobile), with screenshots, a stable element reference, the matching Figma node, and threaded comments. Snags are triaged and prioritised in an admin dashboard, then dispatched to a Cursor cloud agent that opens a pull request on the theme repo. A CI gate runs the test suite before any fix is merged.

---

## How it works (the loop)

1. **Capture** — a reviewer turns on QC Mode, clicks an element, and logs a snag (screenshot + selector + Tailwind classes + block/template hint + optional Figma element link + comments).
2. **Triage** — snags are categorised, given a severity and a numeric priority, and reviewed on the QC Dashboard.
3. **Dispatch** — one click sends a snag (or a batch) to the **Cursor Cloud Agents API**. The agent works on a fresh `cursor/…` branch off `main` and opens a **pull request**.
4. **Gate** — GitHub Actions runs PHP lint + Pest unit tests + an asset build on the PR.
5. **Review & merge** — a human reviews the PR and merges. Merging `main` triggers the Plesk deploy to staging.

Your `main` branch is never committed to directly — every fix is a reviewable PR.

---

## Features

### Capture
- **In-page overlay** (logged-in reviewers only), toggled from the WordPress admin toolbar ("QC Mode").
- **Click-to-pin capture**: CSS selector, viewport, screenshot, element text, and a snapshot of key computed styles.
- **Tailwind/ACF aware**: captures the block slug (e.g. `partners`) and maps it to the likely template (`template-parts/flexi/partners.php`), plus the element's Tailwind classes. Auto-generated UUID ids are converted to stable `[id^="slug-"]` selectors.
- **Figma references**: page-level mapping seeded from a CSV, plus an optional per-element Figma node link.
- Re-anchoring, status-coloured pins with popovers for edit/comment/locate.

### Organise
- **Categories**: Frontend, Functionality, Backend, Content, Asset, Accessibility, Performance, SEO, Other.
- **Severity + numeric priority** with a prioritised queue.
- **Statuses**: New, Triaged, Review required, In progress, PR open, Fixed, Reverted, Non-issue.
- **Comments** per snag, with an editable commenter email.
- **Dashboard**: cross-page overview, per-page counts, priority queue with inline editing.
- **Exports**: CSV, a Markdown "Agent brief", and machine-readable JSON.

### Fix
- **Content auto-fixer (no git)** — for text snags, edit the page directly in WordPress: the plugin matches the captured element text and replaces it across the target post's content and ACF fields, storing a before snapshot for **one-click revert**.
- **Code fixes via agent** — see below. A **Revert via agent** button asks the agent to open a clean revert PR for a previous fix.
- **Auto comment** — when a PR is detected it's posted back as a comment on the snag.

### Agent bridge (Cursor Cloud Agents API)
- **Send to agent** from the snag edit screen, a list **row action**, or **bulk actions**:
  - *Send to agent (one PR each)* — separate PR per snag.
  - *Send to agent (one combined PR)* — one PR for all selected snags.
  - *Dispatch open to agent* (Dashboard) — one PR for every open snag.
- **Live status** — the edit screen links to the live agent run, shows the agent id/status, and has a **Check now** button that polls immediately.
- **Auto PR tracking** — a 5-minute WP-cron poll records the PR URL and flips the snag to **PR open**.
- **Diagnostics** — **Test connection** (`/v1/me`) and **Check repo access** (`/v1/repositories`) on the settings page.

---

## Setup

### 1. Activate the plugin
Activate **Matrix QC Snag** in WordPress. On activation it registers the `qc_snag` post type and grants the `manage_qc_snags` capability to administrators and editors.

### 2. Enable Cursor "Privacy Mode" (NOT Legacy)
Cloud/background agents require server-side storage for the agent's temporary workspace. If your Cursor account is on **Privacy Mode (Legacy)**, the API returns `feature_unavailable: Storage mode is disabled`.

1. Go to **https://cursor.com/settings** (or Dashboard → Settings).
2. Switch from **Privacy Mode (Legacy)** → **Privacy Mode**.
3. On a **team**, only a **team admin** can change this (Dashboard → Settings).

> The newer Privacy Mode still enforces **zero data retention** with model providers — your code is not stored by them or used for training. It only allows Cursor to *temporarily* hold the workspace the agent needs, then deletes it with the agent.

### 3. Create a Cursor API key
1. In the Cursor dashboard, open **Integrations → API Keys**.
2. Create a key (an **Integrations** key — plain dashboard keys may not work for agent creation).
3. Copy it.

### 4. Connect the theme repo to Cursor's GitHub App
The agent clones and opens PRs through Cursor's GitHub App, so it needs access to the theme repo.

1. In Cursor, open **Integrations → GitHub** and grant access to `bernardhanna/st-patricks`.
2. You can verify this later with **Check repo access** (below).

### 5. Configure the plugin
In WordPress: **QC Snags → Agent**.

| Field | Value |
| --- | --- |
| **Cursor API key** | Paste the key from step 3. Stored in the WP database, never in git. |
| **Repository URL** | Auto-detected from the theme repo's git remote; override if needed. |
| **Base branch / ref** | `main` |
| **Model** | Choose from the live dropdown, or leave **(account default)**. |
| **Auto-create PR** | Leave **on**. |
| **Staging auth user / password** | If staging is behind HTTP basic auth, set these so the agent can load pages. Defaults to `matrix` / current year. |

Then:
- Click **Save settings**.
- Click **Test connection** → expect "Connection OK — authenticated as …".
- Click **Check repo access** → expect "Cursor can access the configured repo".

### 6. Add the CI gate (one click)
The gate lives in your repo at `.github/workflows/qc-pr.yml` and runs on every pull request (so it gates the agent's `cursor/*` PRs): PHP lint, Pest unit tests, and an asset build.

- On **activation** the plugin auto-installs the workflow into the detected repo root (if it isn't already there).
- You can also install/redownload it any time from **QC Snags → Agent → CI gate workflow** (Install into repo / Download `qc-pr.yml`).
- After it's written, **commit and push** the file so the gate runs.

The workflow is **project-agnostic**: the PHP, Pest and npm steps are skipped automatically when those files aren't present.

---

## Reuse on other projects

The plugin is project-agnostic — drop it into any WordPress site:

- **Repo** is auto-detected from the active theme's git remote (override in settings).
- **CI workflow** auto-installs to the repo root on activation and skips steps for tooling you don't use.
- **Figma map is optional and per-project.** You don't have to build it: per-element Figma links on each snag work on their own. If you want page-level auto-suggestions, drop a CSV at `<theme>/old/qc-figma-map.csv` (columns: `PAGE URL, NAME, FIGMA DESKTOP, FIGMA MOBILE`) or hook `matrix_qc_snag_seed_csv_candidates`, then re-seed from the Figma Map page.
- **Template hints** default to `template-parts/flexi/<slug>.php`; filter `matrix_qc_snag_template_hint` to match other project conventions.

## Daily usage

1. Open any front-end page and click **QC Mode** in the admin toolbar.
2. Click an element, fill in the snag (title, category, severity, optional Figma link), save.
3. Triage on **QC Snags → Dashboard** (set priority, status).
4. Fix it one of two ways:
   - **Content (text) fix — no git:** on the snag, type the replacement in **Content fix**, Save, click **Preview changes** to see exactly which fields/occurrences will change (old → new), then **Apply content fix**. Undo any time with **Revert content fix**.
   - **Code/design fix — via agent:** dispatch to the Cursor agent:
     - One snag: edit screen or the **Send to agent** row action.
     - Several: select snags → **Bulk actions → Send to agent (one PR each / one combined PR)**.
     - Everything open: **Dashboard → Dispatch open to agent**.
5. For agent fixes, click **View live agent run** to watch, or **Check now** to poll. When the PR is detected the snag flips to **PR open**, the link is posted as a comment, and you can **Revert via agent** if needed.
6. Review and merge the PR. Merge deploys to staging.

---

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `feature_unavailable: Storage mode is disabled` | Account on Privacy Mode (Legacy). Switch to **Privacy Mode** (step 2). |
| `invalid_model: Model '…' is not available` | Pick a model from the dropdown or use **(account default)**. The plugin also auto-drops invalid saved models. |
| Dispatched but no run link / not marked dispatched | Older dispatch stored no agent id — just **Re-send**. |
| **Check now** says "Branch pushed … no PR opened" | Cursor's GitHub App can't open PRs on the repo. Run **Check repo access** and connect the repo (step 4). |
| No notification email | Email is a Cursor account setting, not sent by the plugin. Use the live run link / Check now. |
| Repo check is slow / errors intermittently | `/v1/repositories` is rate limited by Cursor (~1/min). Wait and retry. |

---

## Structure

```
matrix-qc-snag.php   Plugin bootstrap, capability, activation, cron cleanup
inc/cpt.php          qc_snag post type, meta, enums, labels
inc/figma-map.php    Page -> Figma map, URL parsing, agent instruction
inc/rest.php         REST API (snags, status, comments, screenshot)
inc/admin.php        List columns, detail metabox, dashboard, exports
inc/overlay.php      Front-end enqueue + admin-bar toggle
inc/agent.php        Cursor Cloud Agents bridge (settings, dispatch, poll, diagnostics, CI install)
inc/content-fix.php  Direct content fixer (apply/revert via WP, no git) + system comments
assets/overlay.js    Overlay UI (capture, pins, popover, comments)
assets/overlay.css   Overlay styles
```

CI gate (in the theme repo): `.github/workflows/qc-pr.yml`.

---

## Cursor Cloud Agents API reference

- Create agent: `POST https://api.cursor.com/v1/agents` (Basic auth: API key as username, empty password). Body: `prompt.text`, `repos[].url`, `repos[].startingRef`, `autoCreatePR`, optional `model.id`. Response nests the record under `agent` (`agent.id`, `agent.url`).
- Get agent: `GET /v1/agents/{id}` — PR appears in `git.branches[].prUrl` / `target.prUrl`.
- Identity: `GET /v1/me`. Models: `GET /v1/models`. Repos: `GET /v1/repositories`.

---

## Roadmap

- **Done** — Phase 1 (capture + dashboard + exports), Phase 2 (content auto-fixer with revert, per-snag PR revert, auto PR comment), Phase 3 (agent bridge + CI gate).
- **Ideas** — richer field targeting (specific ACF field vs whole-page text match); merge-status sync from GitHub.
```
