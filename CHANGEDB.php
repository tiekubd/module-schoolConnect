<?php
//USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql   = array();
$count = 0;

// v1.0.00 — creates the session table School Connect's login/auth flow
// depends on. Without this, a fresh install has no way to log a user in:
// login.php inserts into this table, auth.php reads from it, and neither
// has any fallback if it doesn't exist.
$sql[$count][0] = "1.0.00";
$sql[$count][1] = "
CREATE TABLE `gibbonConnectSession` (
  `gibbonConnectSessionID` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `gibbonPersonID` varchar(20) NOT NULL,
  `role` varchar(50) NOT NULL,
  `selectedSchoolYearID` int(10) unsigned DEFAULT NULL,
  `selectedRoleID` varchar(20) DEFAULT NULL,
  `created` datetime NOT NULL,
  `expires` datetime NOT NULL,
  `lastUsed` datetime NOT NULL,
  PRIMARY KEY (`gibbonConnectSessionID`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_gibbonConnectSession_token` (`token`),
  KEY `idx_gibbonConnectSession_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
";

// v1.16.01 — caches the licence server's "allowed" answer for 24 hours, so
// High Point's licence server is contacted at most once a day per school
// (see api/license.php). license.php also creates this table itself if it
// is missing, so IF NOT EXISTS keeps this safe on sites where that has
// already happened.
++$count;
$sql[$count][0] = "1.16.01";
$sql[$count][1] = "
CREATE TABLE IF NOT EXISTS `gibbonConnectLicenseCache` (
  `schoolHost` varchar(255) NOT NULL,
  `response` text NOT NULL,
  `checkedAt` datetime NOT NULL,
  PRIMARY KEY (`schoolHost`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;end
";
