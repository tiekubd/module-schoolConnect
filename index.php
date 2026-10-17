<?php
/*
School Connect - Mobile API Module
Copyright (C) 2026, High Point Education
*/

if (isActionAccessible($guid, $connection2, '/modules/School Connect/index.php') == false) {
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('School Connect'));

    // Version comes from version.php, the same file Gibbon's own Update
    // page reads — so this page can never disagree with Module Admin.
    // (version.php must match manifest.php's $version; see the note there.)
    $moduleVersion = '';
    if (file_exists(__DIR__ . '/version.php')) {
        include __DIR__ . '/version.php';
    }

    $moduleDir = __DIR__ . '/api/';

    // Files the app cannot connect without: every endpoint includes
    // cors.php and auth.php, and login.php creates the session. Checked
    // explicitly so a missing one is flagged, not just absent from the list.
    $requiredFiles = ['cors.php', 'auth.php', 'login.php'];

    // Everything else is listed from what's actually on disk, so this page
    // stays accurate as endpoints are added — a hardcoded list here went
    // stale once already.
    $presentFiles = [];
    foreach (glob($moduleDir . '*.php') ?: [] as $path) {
        $presentFiles[] = basename($path);
    }
    sort($presentFiles, SORT_NATURAL | SORT_FLAG_CASE);

    $missingRequired = array_values(array_diff($requiredFiles, $presentFiles));

    echo '<h2>' . __('School Connect Mobile API') . '</h2>';
    echo '<p>' . __('This module provides REST API endpoints for the School Connect mobile app.') . '</p>';

    if (empty($missingRequired)) {
        echo '<div class="success"><b>✓ ' . sprintf(__('Core API files are present (%1$s endpoint files found).'), count($presentFiles)) . '</b> '
            . __('The School Connect app can connect to this server.') . '</div>';
    } else {
        echo '<div class="error"><b>' . __('Required API files are missing:') . '</b> '
            . htmlspecialchars(implode(', ', $missingRequired)) . '. '
            . __('Please reinstall the module.') . '</div>';
    }

    echo '<h3>' . __('API File Status') . '</h3>';
    echo '<table class="fullWidth colorOdd" cellspacing="0">';
    echo '<tr class="head"><td>' . __('File') . '</td><td>' . __('Status') . '</td></tr>';
    foreach ($missingRequired as $file) {
        echo '<tr>';
        echo '<td><code>' . htmlspecialchars($file) . '</code> <i>(' . __('required') . ')</i></td>';
        echo '<td><span style="color:red">✗ ' . __('Missing') . '</span></td>';
        echo '</tr>';
    }
    foreach ($presentFiles as $file) {
        $isRequired = in_array($file, $requiredFiles, true);
        echo '<tr>';
        echo '<td><code>' . htmlspecialchars($file) . '</code>' . ($isRequired ? ' <i>(' . __('required') . ')</i>' : '') . '</td>';
        echo '<td><span style="color:green">✓ ' . __('Present') . '</span></td>';
        echo '</tr>';
    }
    echo '</table>';

    echo '<br><h3>' . __('App Connection URL') . '</h3>';
    $absoluteURL = $session->get('absoluteURL');
    echo '<p>' . __('Use this URL in the School Connect app:') . '</p>';
    echo '<code style="font-size:16px;background:#f0f0f0;padding:8px 16px;border-radius:4px;">' . htmlspecialchars($absoluteURL) . '</code>';

    echo '<br><br><h3>' . __('About') . '</h3>';
    echo '<p>School Connect' . ($moduleVersion !== '' ? ' v' . htmlspecialchars($moduleVersion) : '')
        . ' — developed by <a href="https://highpointedu.com" target="_blank">High Point Education</a>.</p>';
    // The app is installed from the App Store / Google Play, not from a web
    // page. During the pilot both listings are invite-only (TestFlight and
    // Play closed testing), so there is no public store link yet — swap in
    // the two store links here at public launch.
    echo '<p>Get the app: School Connect is available on the App Store and Google Play by invitation during the pilot. '
        . 'Contact <a href="mailto:tieku@highpointedu.com">tieku@highpointedu.com</a> for access.</p>';
}
