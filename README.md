# LinkedIn Shares Importer

A WordPress plugin that imports your LinkedIn data export as **draft** posts,
dated to the original LinkedIn publish time.

Upload the export ZIP (or `Shares_*.csv` from it) under **Tools → LinkedIn
Shares**, review a table of every share, and tick the ones to import. Shares
with two or more paragraphs from the last three years are preselected. Titles
come from the WordPress AI Client when a provider is connected
(**Settings → Connections**), otherwise from a first-line heuristic that needs
no LLM. An optional "Originally posted on LinkedIn" line is appended to each
draft. Re-running an overlapping import creates no duplicates.

## Install

No release packages yet — build the ZIP from a checkout:

```bash
git clone https://github.com/ma6/linkedin-shares.git linkedin-shares-importer
zip -r linkedin-shares-importer.zip linkedin-shares-importer -x '.git/*'
```

Then **Plugins → Add New → Upload Plugin**, or drop the
`linkedin-shares-importer` folder into `wp-content/plugins/`. Requires
WordPress 6.5+ and PHP 8.0+.

## Getting the LinkedIn file

<https://www.linkedin.com/mypreferences/d/download-my-data> → **"Download larger
data archive"**. `Shares_*.csv` is only in that complete export, not the fast
file-by-file one, and it arrives by email within roughly 24 hours.

## Documentation

- [`AGENTS.md`](AGENTS.md) — how the plugin is built and the rules that govern
  changes. Canonical; `CLAUDE.md` points here.
- [`readme.txt`](readme.txt) — WordPress-format readme: full option list, hooks,
  known limits, changelog.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — a one-person project: no support,
  issues opened by the maintainer only, no pull requests.

## Licence

GPL-2.0-or-later — see [`LICENSE`](LICENSE).
