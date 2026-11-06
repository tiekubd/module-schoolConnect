<?php
/*
School Connect - Mobile API Module
Copyright (C) 2026, High Point Education

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
*/

// Basic variables
$name        = "School Connect";
$description = "Mobile app API for School Connect, an app for Gibbon — provides secure REST endpoints for the School Connect Flutter app, enabling teachers, students and parents to access timetables, attendance, markbook, and more from their mobile devices.";
$entryURL    = "index.php";
$type        = "Additional";
$category    = "Other";
$version     = "1.16.11"; // Tracks the Flutter app's marketing version (pubspec.yaml) —
                          // bump this whenever a change on the PHP side ships, matching
                          // whichever app version it was released alongside. Not every
                          // Flutter build number needs a bump here, just PHP module
                          // changes. The patch digit can move independently of the
                          // app's own marketing version when a PHP-only sweep ships
                          // without the app version itself changing.
                          //
                          // KEEP version.php's $moduleVersion IDENTICAL to this. Gibbon
                          // stores THIS value in gibbonModule.version on install, but
                          // its Update page reads version.php — if they differ, the
                          // Update page either errors ("An error has occurred
                          // determining the version") or never offers the update.
                          // Verified against Gibbon core's System Admin
                          // module_manage_installProcess.php / module_manage_update.php.
                          //
                          // 1.16.00: Homework Calendar reads the Academic Calendar
                          // module's settings (Markbook assessment dates alongside
                          // homework), released with app 1.16.0. No schema change,
                          // so no CHANGEDB.php entry.
                          //
                          // 1.16.01: Licence check moved into license.php and cached
                          // for 24 hours on the school's server (new table
                          // gibbonConnectLicenseCache), so High Point's licence server
                          // is contacted at most once a day per school, as the privacy
                          // policy states. Released with app 1.16.1.
                          //
                          // 1.16.02: Company name updated to High Point Education in
                          // the licence message, author line and About text. No
                          // schema change, so no CHANGEDB.php entry.
                          //
                          // 1.16.03: When the licence check refuses a school, the app
                          // now explains why: not set up yet (with a link to start a
                          // free trial), trial ended, or not active. The wording is
                          // in gibbonConnectLicenseMessage() in license.php. No schema
                          // change, so no CHANGEDB.php entry.
                          //
                          // 1.16.04: The daily licence check also sends the number of
                          // students enrolled this school year (one number, counted
                          // as Gibbon core does; see gibbonConnectStudentCount() in
                          // license.php). Minimum Gibbon version raised to v30.0.01,
                          // the version School Connect is tested on. No schema
                          // change, so no CHANGEDB.php entry.
                          //
                          // 1.16.05: New attendance.php action my_form_groups_today
                          // for the app's "My form group" dashboard card (app
                          // 1.16.4): the tutor's form groups with attendance on,
                          // whether attendance is taken today, absent/late counts,
                          // and whether the school is open. Also fixes: (1) re-opening
                          // a class or form group register showed everyone as the
                          // default code, because the query loading saved marks
                          // failed on an ambiguous `direction` column; (2) times
                          // written to Gibbon tables used MySQL NOW() (server clock,
                          // often UTC) instead of Gibbon's local time, now
                          // gibbonConnectNow() in auth.php. No schema change, so no
                          // CHANGEDB.php entry.
                          //
                          // 1.16.06: New endpoint strings.php returns the school's
                          // String Replacements (gibbonString) so the app (1.16.5)
                          // renames its interface text the same way Gibbon does. No
                          // schema change, so no CHANGEDB.php entry.
                          //
                          // 1.16.07: Fix: a register with no marks saved yet failed to
                          // open in the app ("Could not load data"): attendance.php
                          // returned an empty list instead of an empty object. Also:
                          // the Parent Dashboard listed a child twice when the parent
                          // belongs to two families that both include the child
                          // (parent_dashboard.php now returns each child once). No
                          // schema change, so no CHANGEDB.php entry.
                          //
                          // 1.16.08: New module_info.php reports the module version
                          // (uploaded files and installed record) for the app's About
                          // screen, released with app 1.16.7. No schema change, so no
                          // CHANGEDB.php entry.
                          //
                          // 1.16.09: Impersonate User in the app (api/impersonate.php),
                          // with core's own checks including config.php's
                          // $allowImpersonateUser list. New table
                          // gibbonConnectImpersonation (CHANGEDB 1.16.09); auth.php
                          // never extends impersonation tokens (2 hours). Released
                          // with app 1.16.8.
                          //
                          // 1.16.10: Translations for the app (api/i18n.php,
                          // api/i18n_mo.php): the person's Personal Language or the
                          // school default, read from Gibbon's own gibbon.mo plus this
                          // module's i18n/<code>/LC_MESSAGES/School Connect.mo. Module
                          // languages: French (fr_FR), Brazilian Portuguese (pt_BR), Arabic (ar_SA),
                          // AI drafts pending review by native speakers at a school
                          // using each language. i18n/School Connect.pot is the
                          // template for further languages. No schema change, so no
                          // CHANGEDB.php entry.
                          //
                          // 1.16.11: More languages for the app: German (de_DE) and
                          // Spanish (es_ES) for ISU, Traditional Chinese for Hong Kong
                          // (zh_HK) for KIS, Simplified Chinese (zh_CN), Mexican
                          // (es_MX) and Dominican (es_DO) Spanish, European
                          // Portuguese (pt_PT), American English (en_US: only the
                          // strings that change, e.g. Homeroom, Behavior, Enrollment),
                          // Italian (it_IT), Dutch (nl_NL), Turkish (tr_TR) and
                          // Japanese (ja_JP). AI drafts pending review; they follow
                          // Gibbon's own vocabulary for each language (e.g.
                          // Formulargruppe, Curso, Tareas). api/i18n.php now tidies
                          // whitespace in Gibbon's translations the way a browser
                          // shows them (stray line breaks, double spaces, HTML entities
                          // such as it_IT's "Et&agrave", and byte-order marks), and leaves
                          // out any whose placeholders differ from the English (ja_JP
                          // had "{日付}" for "{date}"), so the app shows English instead. Gibbon's
                          // own translations of the strings the app uses were
                          // reviewed line by line for every language: casing made
                          // consistent, mistranslations corrected for the app (e.g.
                          // de "Formulargruppe" -> "Stammgruppe", zh_HK contact 2
                          // labels that said contact 1); see each .po's comments. No schema
                          // change, so no CHANGEDB.php entry.
                          //
                          // Since 1.16.11 (translation files only, so no version change;
                          // the app refetches when a file changes): Gibbon's default
                          // attendance code names corrected where Gibbon's files are wrong
                          // (ja_JP 左 for Left, it_IT Manca, de_DE Verlassen...), and Polish
                          // (pl_PL), Ukrainian (uk_UA), Hebrew (he_IL), Thai (th_TH), Greek (el_GR), Catalan (ca_ES), Afrikaans (af_ZA), Lithuanian (lt_LT), Vietnamese (vi_VN), Albanian (sq_AL), Croatian (hr_HR), Urdu (ur_PK) and Romanian (ro_RO) added, AI drafts
                          // pending review. Also corrected: other
                          // database values the app translates (day names, e.g. ja_JP 太陽 for
                          // Sun; role names, e.g. it_IT Parente for Parent; class roles such as
                          // "Student - Left"; user status filters; module names in notifications). Simplified Chinese (zh_CN) re-reviewed line by line: mainland school terms (班级 form group, 教学班 class, 班主任, 教职工).
$author      = "Tieku Bortei-Doku / High Point Education";
$url         = "https://highpointedu.com";

// Actions
$count = 0;
$actionRows[$count]['name']                    = 'Manage School Connect';
$actionRows[$count]['precedence']              = 0;
$actionRows[$count]['category']                = 'Admin';
$actionRows[$count]['description']             = 'Install and manage the School Connect mobile API.';
$actionRows[$count]['URLList']                 = 'index.php';
$actionRows[$count]['entryURL']                = 'index.php';
$actionRows[$count]['entrySidebar']            = 'N';
$actionRows[$count]['menuShow']                = 'N';   // hidden from main menu
$actionRows[$count]['defaultPermissionAdmin']  = 'Y';
$actionRows[$count]['defaultPermissionTeacher']= 'N';
$actionRows[$count]['defaultPermissionStudent']= 'N';
$actionRows[$count]['defaultPermissionParent'] = 'N';
$actionRows[$count]['defaultPermissionSupport']= 'N';
$actionRows[$count]['categoryPermissionStaff'] = 'N';
$actionRows[$count]['categoryPermissionStudent']='N';
$actionRows[$count]['categoryPermissionParent']= 'N';
$actionRows[$count]['categoryPermissionOther'] = 'N';
