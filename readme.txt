=== LinkedIn Shares Importer ===
Contributors: martingude
Tested up to: 7.1
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import your LinkedIn data export as draft posts, dated to the original LinkedIn publish time.

== Description ==

You upload the LinkedIn export ZIP (or `Shares_*.csv` pulled out of it), review
a table of every share it contains, and tick the ones you want. Nothing is
created until you confirm, and everything is created as a **draft** so you edit
before publishing.

* **Sensible preselection.** Shares with two or more paragraphs, published in
  the last three years, are ticked by default. Change the date range and the
  paragraph threshold and hit "Apply filter" to recompute — you can still tick
  or untick any single row by hand.
* **Original dates.** Each draft's publish date is the original LinkedIn
  timestamp (read as UTC; filter `lsi_share_timezone` to change that).
* **Titles, with or without AI.** If the WordPress AI Client has a provider
  connected (Settings → Connections), it writes a title for each draft.
  Otherwise a no-LLM heuristic uses the first line when it already reads like a
  headline, else the first sentence. Or switch it off and add titles yourself.
  The `lsi_generate_title` filter overrides all of it.
* **Attribution.** An "Originally posted on LinkedIn" line is appended to every
  draft. On by default, template editable, or turned off — Settings → LinkedIn
  Import.
* **Re-run safe.** Every draft stores the share's URN in `_lsi_share_urn`. Load
  an overlapping export again and the shares you already imported show as
  "already imported (#123)" with the checkbox disabled.
* **Messy export, handled.** LinkedIn's CSV double-quotes every paragraph,
  splits fields across physical lines, and is frequently mojibake
  (`Poga\xC3\x84\x8dar`). The parser repairs the encoding and rebuilds real
  paragraphs. Drop the whole export ZIP in and it finds `Shares_*.csv` for you.

Self-contained: no dependency on any theme. The whole folder can move to its
own repository unchanged.

== Where to get the file ==

1. Open https://www.linkedin.com/mypreferences/d/download-my-data
   (Settings & Privacy → Data privacy → Get a copy of your data).
2. Choose **"Download larger data archive"** — the complete export.
   `Shares_*.csv` is only in that one; the fast, file-by-file download does not
   include it.
3. LinkedIn emails you a ZIP within roughly 24 hours.
4. Upload that ZIP as-is on Tools → LinkedIn Shares, or unzip it first and
   upload `Shares_*.csv`.

== How the body is built ==

One `wp:paragraph` block per paragraph. Bare URLs in the text are auto-linked.
The optional final paragraph is the attribution line: `{{url}}` is the share
permalink and `[label](target)` becomes a link; no raw HTML is allowed in the
template.

== Stored data ==

Per imported draft: `_lsi_share_urn`, `_lsi_share_url`, `_lsi_share_date` post
meta. Site options: `lsi_title_source`, `lsi_ai_prompt`,
`lsi_attribution_enabled`, `lsi_attribution_template`. A per-user
`lsi_pending_<id>` transient holds the parsed rows for up to two hours between
the review and import steps. Deleting the plugin removes the options and the
transient; it leaves the drafts and their meta alone.

== Hooks ==

* `lsi_generate_title` — filter. `('', $body, $url, $share)`; return a non-empty
  string to use it as the title.
* `lsi_share_timezone` — filter. `('UTC', $date_raw)`; the timezone the export
  timestamps are read in.
* `lsi_pre_insert_post` — filter. `($postarr, $share)` just before
  `wp_insert_post()`.
* `lsi_imported_share` — action. `($post_id, $share)` after a draft is created.

== Known limits ==

* Straight double-quotes at the very start or end of a paragraph can be lost
  when the parser peels LinkedIn's structural quotes. Curly quotes are safe.
* A URL in the `SharedUrl` / `MediaUrl` columns that itself contains a comma
  can misalign that row's trailing columns.
* Import runs synchronously. A very large selection on a slow host can hit the
  PHP time limit; import in a couple of batches if so.
* `MediaUrl` images are not downloaded — only the text is imported.

== Changelog ==

= 0.1.2 =
* Plugin URI points at the plugin's own repository.
* Added LICENSE (GPL-2.0), README.md and CONTRIBUTING.md.

= 0.1.1 =
* Import screen now spells out where the file comes from and links to
  linkedin.com/mypreferences/d/download-my-data — the "larger data archive",
  since Shares_*.csv is not in the fast partial export.
* Accept the export ZIP directly and pull Shares_*.csv out of it; plain .csv
  still works. Upload ceiling raised to 64 MB (PHP's own limit is shown and is
  usually the real gate).

= 0.1.0 =
* Initial release: upload, review table with date-range and paragraph-count
  preselection, per-share import as drafts on the original date, AI or
  first-line titles, optional attribution line, URN-based de-duplication.
