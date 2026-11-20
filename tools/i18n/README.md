# Translation working files (not shipped)

Everything used to build and review the app's translations in `i18n/`. Excluded from the
release zip by `.gitattributes`. See the project note `claude/handoff-2026-11-04-multilingual.md`
for the process and per-language decisions.

## Files per language
- `LANG.py` — app-only strings: `T` (singular), `P` (plural forms, as many as the language's
  Plural-Forms), `EXTRA` (database labels the app shows), `GAPS` / `OVERRIDES` for Gibbon strings.
- `LANG_final.py` — every Gibbon string the app uses, as the app should show it (the line-by-line
  read), plus the database values in `dyn.py`. Used for pl_PL onwards.
- `LANG_review.py` — `AUTO` (casing fixes from `casefix.py`) and `MANUAL` (corrections to Gibbon's
  wording). `split_final.py LANG` writes it from `LANG_final.py`.

## Shared
- `all.pot` — every string the app uses (app + Gibbon). `School Connect.pot` — the app-only ones.
- `dyn.py` — database values the app passes to tr(): day names, roles, class roles, statuses, module names.
- `codes.py` — fixes to those database values for languages done before `dyn.py` existed.
- `build_po.py LANG` — merges the above into `LANG/LC_MESSAGES/School Connect.po/.mo`; prints
  missing strings and placeholder mismatches. Copy the result into `i18n/LANG/LC_MESSAGES/`.
- `casefix.py LANG PERSON` — title/sentence casing for languages that need it (it, es, pt, tr, fr, nl).
- `make_es_MX.py`, `make_es_DO.py`, `make_en_US.py`, `make_zh_CN.py` — derived languages.
- `call.sh PERSON` — calls `api/i18n.php` on the local test site as a test person (one per language).

## Paths to set before running
The scripts were written in a scratch folder and expect, relative to the working directory:
`site/i18n/LANG/LC_MESSAGES/gibbon.mo` (Gibbon's file for the language), and
`/home/claude/gibbon-i18n/` — a clone of https://github.com/GibbonEdu/i18n. Adjust those two
paths (build_po.py, casefix.py, split_final.py) when running elsewhere.

## With every release (do not skip)
Any release that adds or changes text in the app needs this, or the new text shows in English
in every other language (it happened for app 1.16.13–1.16.17; caught up in module 1.16.18).
1. In the app repo: `python3 tools/extract_strings.py --gibbon <gibbon-i18n>/fr_FR/LC_MESSAGES/gibbon.po --out "School Connect.pot"`
   and `python3 tools/extract_strings.py --out all.pot`; copy both here (and School Connect.pot to `i18n/`).
2. Diff against the previous pots: new app-only strings go into each `LANG.py` (`T`, and `P` with the
   language's number of plural forms); new Gibbon strings the app now uses go into each `LANG_final.py`
   `FINAL`, as the app should show them (read Gibbon's translation first). A one-off script like
   `add_1_16_18.py` does both for all languages.
3. `split_final.py LANG`, then `build_po.py LANG` for every language: missing and placeholder mismatch must be empty.
4. Copy each `LANG/LC_MESSAGES/School Connect.po/.mo` into `i18n/LANG/LC_MESSAGES/`, check every string in
   `all.pot` resolves for every language, and release with the module. No app rebuild is needed: apps fetch
   the new translations from the school's server by themselves.

## Known gap, planned for module 1.16.19: messages from the server
About 150 `'message' => '…'` strings in `api/*.php` (mostly errors, e.g. "The selected record does not exist,
or you do not have access to it.") reach the app in English and are shown as they are; only the few that are also
Gibbon strings get translated. Plan: extend the extractor to collect them from `api/*.php` into the pot, have the
app pass every server message through `tr()` (unknown text falls back to itself), and translate them in all
languages. Messages before sign-in (login.php, login_google.php, school check, licence) stay English like the
screens they appear on.
