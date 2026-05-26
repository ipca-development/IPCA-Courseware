<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_oral_bootstrap.php';
require_once __DIR__ . '/WeakAreaAggregationService.php';
require_once __DIR__ . '/SessionBlueprintService.php';
require_once __DIR__ . '/SessionQuotaService.php';
require_once __DIR__ . '/ConversationalOrchestrator.php';
require_once __DIR__ . '/MockOralDebriefService.php';
require_once __DIR__ . '/../remote_session_auth/remote_session_auth_service.php';
require_once __DIR__ . '/../remote_session_auth/remote_session_auth_constants.php';

function mo_photo_storage_dir(): string
{
    return mo_storage_dir('mock_oral_auth_photos');
}

function mo_photo_absolute_path(?string $storedPath): ?string
{
    return rsa_photo_absolute_path(mo_photo_storage_dir(), $storedPath);
}
