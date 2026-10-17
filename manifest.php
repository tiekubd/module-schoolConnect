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
$version     = "1.16.03"; // Tracks the Flutter app's marketing version (pubspec.yaml) —
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
