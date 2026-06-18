# Matrix QC Snag

In-site QC snagging tool for WordPress. Reviewers flag visual/functional snags directly on the front end (per page, desktop/mobile), with screenshots, a stable element reference, the matching Figma node, and threaded comments. Snags are triaged and prioritised in an admin dashboard, ready to be handed to a coding agent for fixing.

## Features

- **In-page overlay** (logged-in reviewers only), toggled from the WordPress admin toolbar ("QC Mode").
- **Click-to-pin capture**: CSS selector, viewport, screenshot, element text, and a snapshot of key computed styles.
- **Tailwind/ACF aware**: captures the block slug (e.g. `partners`) and maps it to the likely template (`template-parts/flexi/partners.php`), plus the element's Tailwind classes. Auto-generated UUID ids are converted to stable `[id^="slug-"]` selectors.
- **Figma references**: page-level mapping seeded from a CSV, plus an optional per-element Figma node link (file key + node id parsed for the Figma MCP).
- **Categories**: Frontend, Functionality, Backend, Content, Asset, Accessibility, Performance, SEO, Other.
- **Severity + priority** with a prioritised queue.
- **Statuses**: New, Triaged, Review required, In progress, PR open, Fixed, Reverted, Non-issue (status-coloured pins).
- **Comments** per snag, with an editable commenter email.
- **Dashboard**: cross-page overview, per-page counts, priority queue with inline editing, and CSV export.

## Requirements

- WordPress with the `manage_qc_snags` capability (granted to administrators and editors on activation).

## Structure

```
matrix-qc-snag.php   Plugin bootstrap, capability, activation
inc/cpt.php          qc_snag post type, meta, enums, labels
inc/figma-map.php    Page -> Figma map, URL parsing, agent instruction
inc/rest.php         REST API (snags, status, comments, screenshot)
inc/admin.php        List columns, detail metabox, dashboard, CSV export
inc/overlay.php      Front-end enqueue + admin-bar toggle
assets/overlay.js    Overlay UI (capture, pins, popover, comments)
assets/overlay.css   Overlay styles
```

## Roadmap

- Phase 2: content auto-fixer (apply low-risk content snags via the WP REST API with before/after revert).
- Phase 3: coding-agent bridge (dispatch design/code snags to a cloud agent that opens a PR), CI test gate, and PR-revert.
