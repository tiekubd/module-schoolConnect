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
