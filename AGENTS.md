# AGENTS.md — LinkedIn Shares Importer

A standalone WordPress plugin. It currently lives inside the `onygo.26`
monorepo under `plugins/`, but it has **no dependency on the Onygo theme or on
Neon** and is meant to be lifted into its own repository unchanged — keep it
that way.

## What it is

An importer for LinkedIn's `Shares_*.csv` data export. Upload → review table →
create the picked shares as **draft** posts, dated to the original LinkedIn
timestamp.

## Shape

```
linkedin-shares-importer.php   bootstrap: constants, requires, plugins_loaded
inc/class-lsi-share.php         value object for one parsed share (+ to/from array)
inc/class-lsi-csv.php           the lenient parser + mojibake repair + paragraphing
inc/class-lsi-title.php         title: lsi_generate_title filter → AI Client → first-line heuristic → ''
inc/class-lsi-settings.php      Settings → LinkedIn Import (4 options)
inc/class-lsi-importer.php      wp_insert_post loop, block body, attribution line, URN dedupe
inc/class-lsi-admin.php         Tools → LinkedIn Shares: upload (CSV or the export ZIP) / review / results
assets/admin.css, assets/admin.js   review-table only, enqueued on our hook suffix
uninstall.php                   delete the 4 options + leftover lsi_pending_* transients
```

## Why the parser is not a CSV library

LinkedIn's export is not RFC 4180: the commentary field is quote-wrapped, then
every paragraph inside it is *also* quote-wrapped and split across physical
lines, and the file is usually mojibake (UTF-8 once decoded as Windows-1252).
`LSI_CSV` re-assembles records by their leading `YYYY-MM-DD HH:MM:SS,`
timestamp, peels `SharedUrl,MediaUrl,VISIBILITY` off the right, and repairs
what's left. `fix_mojibake()` only swaps in a repaired string when it strictly
reduces the tell-tale byte sequences and does not lose characters. If you touch
the parser, re-check it against a real export and against the fixture notes in
the repo's DECISIONS.md.

## Non-negotiables

- **Nothing is created without an explicit confirm.** Upload and "Apply filter"
  never write; only the "Import selected as drafts" button does.
- **Drafts, always.** `post_status => 'draft'`, no option to change it.
- **Re-run safe.** `_lsi_share_urn` on every draft; `find_existing()` blocks a
  second import and the review table disables those rows.
- **Original date.** `post_date_gmt` is the export timestamp; never "now".
- **Escaping at every boundary.** `esc_html` on all share-derived text in the
  body and the table, `esc_url` on links, `wp_kses`/restricted markdown for the
  attribution template — the CSV is untrusted input.
- **Capabilities + nonces.** Screen requires `import`; every POST branch calls
  `check_admin_referer`. Settings require `manage_options`.
- **No hard theme dependency, no Neon.** wp-admin styling only; lean on core
  `.widefat`/`.button` so admin dark schemes keep working.
- **Degrades without JavaScript.** The select-all is an enhancement; both form
  buttons carry their own `lsi_action` value, so import and filter work with JS
  off.

## Title sources

`LSI_Title::generate()` order: `lsi_generate_title` filter → if option
`lsi_title_source` is `ai` and `wp_ai_client_prompt()` exists, try it (any
failure or missing provider falls through) → `firstline` heuristic → `''`. The
heuristic needs no LLM and is the automatic fallback whenever the AI Client is
absent.

## Before calling a change done

1. `php -l` clean on every file.
2. Upload a real `Shares_*.csv` **and** the whole export ZIP: both reach the
   review table; paragraphs rebuilt, umlauts and em-dashes and emoji correct
   (no `Ã` / `â€` left), dates right, `¶` counts plausible.
3. Preselection matches "≥ 2 paragraphs, last 3 years"; "Apply filter"
   recomputes; hand ticks survive it.
4. Import a couple; drafts have the original date, the block body, the
   attribution line; re-uploading the same file shows them as already imported.
5. With no AI provider connected, titles come from the first-line heuristic and
   the settings screen says so.
6. Keyboard only through the review table; no console errors; JS disabled still
   imports.
7. Anything decided along the way goes in the repo's `DECISIONS.md` while the
   plugin still lives in the monorepo.
