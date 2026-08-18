<?php
declare(strict_types=1);

// Deliberately does not load CommunicationKernel and does not authenticate a
// Communication bearer/session. Anonymous routes use only the database.
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/safety/SafetyHttp.php';
require_once __DIR__ . '/../../../src/safety/SafetyKernel.php';

$pdo = cw_db();
$safetyKernel = new SafetyKernel($pdo);
