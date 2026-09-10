=== LinkedIn Shares Importer ===
Contributors: martingude
Tested up to: 7.1
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import your LinkedIn data export (Shares_*.csv) as draft posts, dated to the original LinkedIn publish time.

== Description ==

You upload the "Shares" CSV from your LinkedIn data export, review a table of
every share it contains, and tick the ones you want. Nothing is created until
you confirm, and everything is created as a **draft** so you edit before
publishing.

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
  paragraphs.

Self-contained: no dependency on any theme. The whole folder can move to its
own repository unchanged.

== Where to get the CSV ==

On LinkedIn: **Settings & Privacy → Data privacy → Get a copy of your data →
"Posts"** (the archive contains `Shares_*.csv`). The full export can take up to
24 hours to arrive.

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

= 0.1.0 =
* Initial release: upload, review table with date-range and paragraph-count
  preselection, per-share import as drafts on the original date, AI or
  first-line titles, optional attribution line, URN-based de-duplication.
