<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/communication/api_bootstrap.php';
require_once __DIR__ . '/../../../src/communication/CommunicationKernel.php';
require_once __DIR__ . '/../../../src/safety/SafetyHttp.php';
require_once __DIR__ . '/../../../src/safety/SafetyKernel.php';

$communicationKernel = new CommunicationKernel($pdo);
$safetyKernel = new SafetyKernel($pdo, $communicationKernel->objectStore, $communicationKernel->push);
