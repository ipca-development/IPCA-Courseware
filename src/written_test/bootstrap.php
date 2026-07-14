<?php
declare(strict_types=1);

require_once __DIR__ . '/WrittenTestSupport.php';
require_once __DIR__ . '/WrittenTestProgramService.php';
require_once __DIR__ . '/WrittenTestAllocationService.php';
require_once __DIR__ . '/WrittenTestPolicyService.php';
require_once __DIR__ . '/WrittenTestAccessService.php';

function written_test_student_has_any_allocation(PDO $pdo, int $studentId): bool
{
    try {
        $svc = new WrittenTestAllocationService($pdo);
        return $svc->hasStudentAllocation($studentId);
    } catch (Throwable) {
        return false;
    }
}
