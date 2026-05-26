<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_oral/mock_oral_engine.php';

trait CoursewareProgressionV2MockOralTrait
{
    public function hasMockOralPermission(int $studentId, int $cohortId, ?int $catalogId = null): bool
    {
        mo_ensure_tables($this->pdo);
        $catalogId = $catalogId ?: mo_default_catalog_id($this->pdo);
        if ($catalogId <= 0) {
            return false;
        }
        $st = $this->pdo->prepare('
            SELECT mock_oral_enabled FROM student_mock_oral_permissions
            WHERE student_id = ? AND cohort_id = ? AND catalog_id = ?
            LIMIT 1
        ');
        $st->execute([$studentId, $cohortId, $catalogId]);
        return (int)$st->fetchColumn() === 1;
    }

    public function setMockOralPermission(int $studentId, int $cohortId, bool $enabled, int $adminUserId, ?string $notes = null, ?int $catalogId = null): array
    {
        mo_ensure_tables($this->pdo);
        $catalogId = $catalogId ?: mo_default_catalog_id($this->pdo);
        if ($catalogId <= 0) {
            throw new RuntimeException('Mock oral catalog not configured.');
        }

        $st = $this->pdo->prepare('SELECT id FROM student_mock_oral_permissions WHERE student_id = ? AND cohort_id = ? AND catalog_id = ? LIMIT 1');
        $st->execute([$studentId, $cohortId, $catalogId]);
        $existingId = (int)$st->fetchColumn();

        if ($existingId > 0) {
            if ($enabled) {
                $this->pdo->prepare("
                    UPDATE student_mock_oral_permissions
                    SET mock_oral_enabled = 1, approved_by_user_id = ?, approved_at = UTC_TIMESTAMP(),
                        revoked_by_user_id = NULL, revoked_at = NULL, notes = ?, updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ")->execute([$adminUserId, $notes, $existingId]);
            } else {
                $this->pdo->prepare("
                    UPDATE student_mock_oral_permissions
                    SET mock_oral_enabled = 0, revoked_by_user_id = ?, revoked_at = UTC_TIMESTAMP(),
                        notes = ?, updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ")->execute([$adminUserId, $notes, $existingId]);
            }
        } else {
            $this->pdo->prepare("
                INSERT INTO student_mock_oral_permissions
                  (student_id, cohort_id, catalog_id, mock_oral_enabled, approved_by_user_id, approved_at, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, CASE WHEN ? = 1 THEN UTC_TIMESTAMP() ELSE NULL END, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ")->execute([$studentId, $cohortId, $catalogId, $enabled ? 1 : 0, $enabled ? $adminUserId : null, $enabled ? 1 : 0, $notes]);
        }

        $this->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => 0,
            'event_type' => 'mock_oral',
            'event_code' => $enabled ? 'MOCK_ORAL_PERMISSION_ENABLED' : 'MOCK_ORAL_PERMISSION_DISABLED',
            'event_status' => 'info',
            'actor_type' => 'admin',
            'actor_user_id' => $adminUserId,
            'payload' => ['mock_oral_enabled' => $enabled ? 1 : 0, 'catalog_id' => $catalogId, 'notes' => $notes],
            'legal_note' => 'Admin updated mock oral exam permission.',
        ]);

        return ['ok' => true, 'mock_oral_enabled' => $enabled ? 1 : 0];
    }

    public function isTheoryCompleteForMockOral(int $studentId, int $cohortId): bool
    {
        $st = $this->pdo->prepare('
            SELECT DISTINCT l.course_id
            FROM cohort_lesson_deadlines d
            INNER JOIN lessons l ON l.id = d.lesson_id
            WHERE d.cohort_id = ?
        ');
        $st->execute([$cohortId]);
        $courseIds = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$courseIds) {
            return false;
        }
        foreach ($courseIds as $courseId) {
            if (!$this->isCourseCompletedForProgression($studentId, $cohortId, (int)$courseId)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function listMockOralAreas(int $catalogId): array
    {
        mo_ensure_tables($this->pdo);
        $st = $this->pdo->prepare('
            SELECT * FROM mock_oral_acs_areas
            WHERE catalog_id = ? AND is_active = 1
            ORDER BY sort_order ASC, id ASC
        ');
        $st->execute([$catalogId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMockOralModuleButtonState(int $studentId, int $cohortId, int $areaId): array
    {
        mo_ensure_tables($this->pdo);
        $area = mo_area_by_id($this->pdo, $areaId);
        $sessionUrl = '/student/mock_oral_session.php?cohort_id=' . $cohortId . '&area_id=' . $areaId;
        $hubUrl = '/student/mock_oral.php?cohort_id=' . $cohortId;

        if (!$area) {
            return ['mode' => 'blocked', 'label' => 'Unavailable', 'disabled' => true, 'message' => 'Module not found.'];
        }

        if (!$this->mo_student_enrolled($studentId, $cohortId)) {
            return ['mode' => 'blocked', 'label' => 'Not Enrolled', 'disabled' => true, 'message' => 'You are not enrolled in this cohort.'];
        }

        if (!$this->isTheoryCompleteForMockOral($studentId, $cohortId)) {
            return [
                'mode' => 'blocked',
                'label' => 'Complete Theory First',
                'disabled' => true,
                'message' => 'Complete all theory lessons and progress tests before mock oral preparation.',
            ];
        }

        $catalogId = (int)$area['catalog_id'];
        if (!$this->hasMockOralPermission($studentId, $cohortId, $catalogId)) {
            return [
                'mode' => 'blocked',
                'label' => 'Awaiting Approval',
                'disabled' => true,
                'message' => 'Mock Oral Exam access requires Head of Training approval.',
            ];
        }

        $quotaSvc = new SessionQuotaService($this->pdo);
        $quotaSvc->expireStaleSessions($studentId, $cohortId);
        $activeSession = $quotaSvc->getActiveSession($studentId, $cohortId);
        if ($activeSession && (int)$activeSession['area_id'] === $areaId) {
            return [
                'mode' => 'continue',
                'label' => 'Resume Mock Oral',
                'disabled' => false,
                'href' => $sessionUrl . '&session_id=' . (int)$activeSession['id'],
                'session_id' => (int)$activeSession['id'],
            ];
        }

        $auth = $this->mo_get_active_remote_auth($studentId, $cohortId, $areaId);
        if ($auth) {
            if ((string)$auth['status'] === 'AUTHENTICATED') {
                return [
                    'mode' => 'enter_code',
                    'label' => 'Enter Mock Oral Code',
                    'disabled' => false,
                    'show_code_modal' => true,
                    'area_id' => $areaId,
                    'hub_url' => $hubUrl,
                ];
            }
            if (in_array((string)$auth['status'], ['REQUESTED', 'EMAIL_SENT'], true)) {
                return [
                    'mode' => 'auth_pending',
                    'label' => 'Check Your Email',
                    'disabled' => true,
                    'message' => 'An authentication email was sent. Complete photo verification, then enter your code.',
                ];
            }
        }

        if (!cw_progress_test_is_trusted_school_network($this->pdo, $studentId, $cohortId)) {
            return [
                'mode' => 'start_auth',
                'label' => 'Start Mock Oral Exam',
                'disabled' => false,
                'button_class' => 'remote',
                'area_id' => $areaId,
                'network' => 'remote',
            ];
        }

        return [
            'mode' => 'start_auth',
            'label' => 'Start Mock Oral Exam',
            'disabled' => false,
            'button_class' => 'remote',
            'area_id' => $areaId,
            'network' => 'trusted',
        ];
    }

    public function requestMockOralAuthorization(int $studentId, int $cohortId, int $areaId): array
    {
        mo_ensure_tables($this->pdo);
        $area = mo_area_by_id($this->pdo, $areaId);
        if (!$area) {
            throw new RuntimeException('Module not found.');
        }
        if (!$this->mo_student_enrolled($studentId, $cohortId)) {
            throw new RuntimeException('Not enrolled in this cohort.');
        }
        if (!$this->isTheoryCompleteForMockOral($studentId, $cohortId)) {
            throw new RuntimeException('Complete theory training before requesting a mock oral session.');
        }
        if (!$this->hasMockOralPermission($studentId, $cohortId, (int)$area['catalog_id'])) {
            throw new RuntimeException('Mock Oral Exam access is not enabled for your account.');
        }

        $trustedNetwork = cw_progress_test_is_trusted_school_network($this->pdo, $studentId, $cohortId);

        $quotaSvc = new SessionQuotaService($this->pdo);
        $quotaCheck = $quotaSvc->canStartSession($studentId, $cohortId);
        if (empty($quotaCheck['allowed'])) {
            throw new RuntimeException((string)($quotaCheck['message'] ?? 'Cannot start session.'));
        }

        $existing = $this->mo_get_active_remote_auth($studentId, $cohortId, $areaId);
        if ($existing) {
            if ((string)$existing['status'] === 'AUTHENTICATED') {
                throw new RuntimeException('Complete authentication by entering your code on the mock oral page.');
            }
            if (in_array((string)$existing['status'], ['REQUESTED', 'EMAIL_SENT'], true)) {
                if ($trustedNetwork) {
                    $rawToken = $this->mo_reissue_auth_token((int)$existing['id']);
                    if ($rawToken !== '') {
                        return [
                            'ok' => true,
                            'reused' => true,
                            'trusted_network' => true,
                            'auth_url' => mo_app_base_url() . '/student/mock_oral_auth.php?token=' . urlencode($rawToken),
                            'message' => 'Continue identity verification to start your mock oral exam.',
                        ];
                    }
                }
                return ['ok' => true, 'reused' => true, 'message' => 'Check your email for the authentication link.'];
            }
            $this->pdo->prepare('DELETE FROM mock_oral_remote_authorizations WHERE id = ?')->execute([(int)$existing['id']]);
        }

        if ($this->mo_count_recent_requests($studentId, $cohortId) >= RSA_MAX_REQUESTS_PER_HOUR) {
            throw new RuntimeException('Too many requests. Please try again later.');
        }

        $rawToken = rsa_generate_token();
        $tokenHash = rsa_hash($rawToken);
        $validFrom = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (RSA_AUTH_TTL_MINUTES * 60));

        $this->pdo->prepare('
            INSERT INTO mock_oral_remote_authorizations
              (student_id, cohort_id, catalog_id, area_id, request_token_hash, status, valid_from, expires_at,
               requested_ip, requested_user_agent_hash, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, \'REQUESTED\', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ')->execute([
            $studentId,
            $cohortId,
            (int)$area['catalog_id'],
            $areaId,
            $tokenHash,
            $validFrom,
            $expiresAt,
            rsa_client_ip(),
            rsa_user_agent_hash() ?: null,
        ]);
        $authId = (int)$this->pdo->lastInsertId();
        $authLink = mo_app_base_url() . '/student/mock_oral_auth.php?token=' . urlencode($rawToken);

        if ($trustedNetwork) {
            $this->pdo->prepare("UPDATE mock_oral_remote_authorizations SET status = 'EMAIL_SENT', updated_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$authId]);

            $this->logProgressionEvent([
                'user_id' => $studentId,
                'cohort_id' => $cohortId,
                'lesson_id' => 0,
                'event_type' => 'mock_oral',
                'event_code' => 'MOCK_ORAL_AUTH_REQUESTED_TRUSTED',
                'event_status' => 'info',
                'actor_type' => 'student',
                'actor_user_id' => $studentId,
                'payload' => ['authorization_id' => $authId, 'area_id' => $areaId, 'trusted_network' => 1],
                'legal_note' => 'Student started mock oral authentication on trusted network (email skipped).',
            ]);

            return [
                'ok' => true,
                'authorization_id' => $authId,
                'trusted_network' => true,
                'auth_url' => $authLink,
                'message' => 'Continue to identity verification.',
            ];
        }

        $stUser = $this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(name), ''), email) AS student_name, email FROM users WHERE id = ? LIMIT 1");
        $stUser->execute([$studentId]);
        $userRow = $stUser->fetch(PDO::FETCH_ASSOC) ?: [];
        $studentEmail = trim((string)($userRow['email'] ?? ''));
        if ($studentEmail === '' || !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            $this->pdo->prepare('DELETE FROM mock_oral_remote_authorizations WHERE id = ?')->execute([$authId]);
            throw new RuntimeException('Your account does not have a valid email address.');
        }

        $automationContext = [
            'user_id' => $studentId,
            'student_id' => $studentId,
            'cohort_id' => $cohortId,
            'area_id' => $areaId,
            'student_name' => (string)($userRow['student_name'] ?? 'Student'),
            'student_email' => $studentEmail,
            'area_title' => (string)$area['title'],
            'auth_link' => $authLink,
            'expires_at' => $expiresAt,
            'support_email' => mo_support_email(),
            'authorization_id' => $authId,
        ];

        $this->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => 0,
            'event_type' => 'mock_oral',
            'event_code' => 'MOCK_ORAL_AUTH_REQUESTED',
            'event_status' => 'info',
            'actor_type' => 'student',
            'actor_user_id' => $studentId,
            'payload' => ['authorization_id' => $authId, 'area_id' => $areaId],
            'legal_note' => 'Student requested mock oral authentication (no session created).',
        ]);

        $automationResult = $this->dispatchAutomationEventIfAvailable(
            'mock_oral_auth_requested',
            $automationContext,
            $studentId,
            $cohortId,
            0,
            null
        );

        if (!$this->mo_automation_email_sent(is_array($automationResult) ? $automationResult : null)) {
            $this->pdo->prepare('DELETE FROM mock_oral_remote_authorizations WHERE id = ?')->execute([$authId]);
            throw new RuntimeException('Authentication email could not be sent. Please try again.');
        }

        $this->pdo->prepare("UPDATE mock_oral_remote_authorizations SET status = 'EMAIL_SENT', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$authId]);

        return ['ok' => true, 'authorization_id' => $authId, 'message' => 'Check your email for the authentication link.'];
    }

    public function loadMockOralAuthorizationByToken(string $rawToken): ?array
    {
        mo_ensure_tables($this->pdo);
        $st = $this->pdo->prepare('SELECT * FROM mock_oral_remote_authorizations WHERE request_token_hash = ? LIMIT 1');
        $st->execute([rsa_hash(trim($rawToken))]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function authenticateMockOralToken(string $rawToken, int $studentId, string $password, string $photoBinary, string $photoMime): array
    {
        mo_ensure_tables($this->pdo);
        $auth = $this->loadMockOralAuthorizationByToken($rawToken);
        if (!$auth) {
            throw new RuntimeException('Invalid or expired authentication link.');
        }

        return RemoteSessionAuthService::authenticateWithPhoto(
            $this->pdo,
            $auth,
            $studentId,
            $password,
            $photoBinary,
            $photoMime,
            mo_photo_storage_dir(),
            'mo_auth',
            function (int $authId, string $codeHash, string $photoPath, string $photoHash) use ($auth, $studentId): void {
                if (!$this->hasMockOralPermission($studentId, (int)$auth['cohort_id'], (int)$auth['catalog_id'])) {
                    throw new RuntimeException('Mock Oral Exam access is not enabled.');
                }
                $this->pdo->prepare("
                    UPDATE mock_oral_remote_authorizations
                    SET status = 'AUTHENTICATED',
                        verification_code_hash = ?,
                        authenticated_at = UTC_TIMESTAMP(),
                        authenticated_ip = ?,
                        authenticated_user_agent_hash = ?,
                        student_photo_path = ?,
                        student_photo_hash = ?,
                        updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ")->execute([
                    $codeHash,
                    rsa_client_ip(),
                    rsa_user_agent_hash() ?: null,
                    $photoPath,
                    $photoHash,
                    $authId,
                ]);
            }
        );
    }

    public function verifyMockOralCodeAndPrepareSession(int $studentId, int $cohortId, int $areaId, string $code): array
    {
        mo_ensure_tables($this->pdo);
        $auth = $this->mo_get_active_remote_auth($studentId, $cohortId, $areaId);
        if (!$auth || (string)$auth['status'] !== 'AUTHENTICATED') {
            throw new RuntimeException('No authenticated mock oral authorization found.');
        }

        $result = RemoteSessionAuthService::verifyCode(
            $auth,
            $studentId,
            $code,
            function (int $authId): int {
                $st = $this->pdo->prepare('SELECT failed_code_attempts FROM mock_oral_remote_authorizations WHERE id = ? LIMIT 1');
                $st->execute([$authId]);
                $failures = (int)$st->fetchColumn() + 1;
                $status = $failures >= RSA_MAX_CODE_FAILURES ? 'FAILED' : 'AUTHENTICATED';
                $this->pdo->prepare('
                    UPDATE mock_oral_remote_authorizations
                    SET failed_code_attempts = ?, status = ?, updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ')->execute([$failures, $status, $authId]);
                return $failures;
            },
            function (int $authId) use ($studentId, $cohortId, $areaId, $auth): array {
                $this->pdo->beginTransaction();
                try {
                    $session = $this->mo_create_session_with_blueprint(
                        $studentId,
                        $cohortId,
                        $areaId,
                        $authId,
                        'remote_auth_' . $authId
                    );
                    $this->pdo->prepare("
                        UPDATE mock_oral_remote_authorizations
                        SET status = 'USED', session_id = ?, used_at = UTC_TIMESTAMP(), code_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                        WHERE id = ?
                    ")->execute([(int)$session['session_id'], $authId]);
                    $this->pdo->commit();
                    return $session;
                } catch (Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
        );

        return $result;
    }

    public function startOnSiteMockOralSession(int $studentId, int $cohortId, int $areaId): array
    {
        throw new RuntimeException('Use the standard mock oral authentication flow.');
    }

    public function mo_create_session_with_blueprint(int $studentId, int $cohortId, int $areaId, ?int $authId, string $idempotencyKey): array
    {
        $area = mo_area_by_id($this->pdo, $areaId);
        if (!$area) {
            throw new RuntimeException('Module not found.');
        }

        $existing = $this->pdo->prepare('SELECT id, status FROM mock_oral_sessions WHERE idempotency_key = ? LIMIT 1');
        $existing->execute([$idempotencyKey]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingRow) {
            $existingStatus = (string)$existingRow['status'];
            if ($existingStatus === 'ready' || in_array($existingStatus, ['in_progress', 'turn_evaluating', 'debrief_generating', 'completed'], true)) {
                return ['session_id' => (int)$existingRow['id'], 'status' => $existingStatus, 'idempotent_reuse' => true];
            }
            if ($existingStatus === 'failed' || $existingStatus === 'aborted' || $existingStatus === 'stale') {
                $this->pdo->prepare('DELETE FROM mock_oral_sessions WHERE id = ?')->execute([(int)$existingRow['id']]);
            }
        }

        // Generate blueprint in memory first — no mock_oral_sessions row until auth passed and blueprint is ready.
        $weakSvc = new WeakAreaAggregationService($this->pdo);
        $weakProfile = $weakSvc->buildProfile($studentId, $cohortId, (int)$area['catalog_id'], $areaId);
        $blueprintSvc = new SessionBlueprintService($this->pdo);
        $blueprint = $blueprintSvc->generate($studentId, $cohortId, (int)$area['catalog_id'], $areaId, $weakProfile);
        $scenario = [
            'cross_country_context' => (string)($blueprint['cross_country_context'] ?? ''),
            'opening' => (string)($blueprint['opening_scenario'] ?? ''),
        ];

        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare("
                INSERT INTO mock_oral_sessions
                  (user_id, cohort_id, catalog_id, area_id, authorization_id, status, weak_area_snapshot_json,
                   blueprint_json, scenario_json, max_duration_sec, idempotency_key, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'ready', ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ");
            $ins->execute([
                $studentId,
                $cohortId,
                (int)$area['catalog_id'],
                $areaId,
                $authId,
                mo_json_encode($weakProfile),
                mo_json_encode($blueprint),
                mo_json_encode($scenario),
                RSA_SESSION_MAX_DURATION_SEC,
                $idempotencyKey,
            ]);
            $sessionId = (int)$this->pdo->lastInsertId();

            $quotaSvc = new SessionQuotaService($this->pdo);
            $quotaSvc->consumeSession($studentId, $cohortId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['session_id' => $sessionId, 'status' => 'ready'];
    }

    private function mo_reissue_auth_token(int $authId): string
    {
        $rawToken = rsa_generate_token();
        $this->pdo->prepare('
            UPDATE mock_oral_remote_authorizations
            SET request_token_hash = ?, status = \'EMAIL_SENT\', updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ')->execute([rsa_hash($rawToken), $authId]);

        return $rawToken;
    }

    private function mo_student_enrolled(int $studentId, int $cohortId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
        $st->execute([$cohortId, $studentId]);
        return (bool)$st->fetchColumn();
    }

    private function mo_get_active_remote_auth(int $studentId, int $cohortId, int $areaId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT * FROM mock_oral_remote_authorizations
            WHERE student_id = ? AND cohort_id = ? AND area_id = ?
              AND status IN ('REQUESTED','EMAIL_SENT','AUTHENTICATED')
              AND expires_at > UTC_TIMESTAMP()
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute([$studentId, $cohortId, $areaId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function mo_count_recent_requests(int $studentId, int $cohortId): int
    {
        $st = $this->pdo->prepare('
            SELECT COUNT(*) FROM mock_oral_remote_authorizations
            WHERE student_id = ? AND cohort_id = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
        ');
        $st->execute([$studentId, $cohortId]);
        return (int)$st->fetchColumn();
    }

    private function mo_automation_email_sent(?array $automationResult): bool
    {
        if (!$automationResult || empty($automationResult['ok'])) {
            return false;
        }
        foreach ((array)($automationResult['results'] ?? []) as $row) {
            if (!is_array($row) || ($row['action_key'] ?? '') !== 'send_email') {
                continue;
            }
            if (!empty($row['ok']) && empty($row['skipped'])) {
                $send = (array)($row['result'] ?? []);
                if (!empty($send['ok']) && empty($send['suppressed'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
