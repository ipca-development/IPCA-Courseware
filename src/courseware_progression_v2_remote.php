<?php
declare(strict_types=1);

require_once __DIR__ . '/progress_test_remote.php';
require_once __DIR__ . '/progress_test_prep.php';

trait CoursewareProgressionV2RemoteTrait
{
    public function hasRemoteTestingPermission(int $studentId, int $cohortId): bool
    {
        ptr_ensure_tables($this->pdo);
        $st = $this->pdo->prepare('SELECT remote_testing_enabled FROM student_remote_test_permissions WHERE student_id = ? AND cohort_id = ? LIMIT 1');
        $st->execute([$studentId, $cohortId]);
        return (int)$st->fetchColumn() === 1;
    }

    public function setRemoteTestingPermission(int $studentId, int $cohortId, bool $enabled, int $adminUserId, ?string $notes = null): array
    {
        ptr_ensure_tables($this->pdo);
        $st = $this->pdo->prepare('SELECT * FROM student_remote_test_permissions WHERE student_id = ? AND cohort_id = ? LIMIT 1');
        $st->execute([$studentId, $cohortId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ($enabled) {
                $up = $this->pdo->prepare("
                    UPDATE student_remote_test_permissions
                    SET remote_testing_enabled = 1,
                        approved_by_user_id = ?,
                        approved_at = UTC_TIMESTAMP(),
                        revoked_by_user_id = NULL,
                        revoked_at = NULL,
                        notes = ?,
                        updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ");
                $up->execute([$adminUserId, $notes, (int)$row['id']]);
            } else {
                $up = $this->pdo->prepare("
                    UPDATE student_remote_test_permissions
                    SET remote_testing_enabled = 0,
                        revoked_by_user_id = ?,
                        revoked_at = UTC_TIMESTAMP(),
                        notes = ?,
                        updated_at = UTC_TIMESTAMP()
                    WHERE id = ?
                ");
                $up->execute([$adminUserId, $notes, (int)$row['id']]);
            }
        } else {
            $ins = $this->pdo->prepare("
                INSERT INTO student_remote_test_permissions
                  (student_id, cohort_id, remote_testing_enabled, approved_by_user_id, approved_at, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, CASE WHEN ? = 1 THEN UTC_TIMESTAMP() ELSE NULL END, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ");
            $ins->execute([$studentId, $cohortId, $enabled ? 1 : 0, $enabled ? $adminUserId : null, $enabled ? 1 : 0, $notes]);
        }

        $this->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => 0,
            'event_type' => 'progress_test',
            'event_code' => $enabled ? 'REMOTE_TEST_PERMISSION_ENABLED' : 'REMOTE_TEST_PERMISSION_DISABLED',
            'event_status' => 'info',
            'actor_type' => 'admin',
            'actor_user_id' => $adminUserId,
            'payload' => ['remote_testing_enabled' => $enabled ? 1 : 0, 'notes' => $notes],
            'legal_note' => 'Admin updated remote progress testing permission for cohort.',
        ]);

        return ['ok' => true, 'remote_testing_enabled' => $enabled ? 1 : 0];
    }

    public function hasActiveInProgressAttempt(int $studentId, int $cohortId, int $lessonId): bool
    {
        $st = $this->pdo->prepare("
            SELECT id, status, updated_at, started_at
            FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
              AND status IN ('in_progress','processing')
              AND (formal_result_code IS NULL OR formal_result_code != 'STALE_ABORTED')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$studentId, $cohortId, $lessonId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $ts = strtotime((string)($row['started_at'] ?: $row['updated_at'] ?: ''));
        if ($ts <= 0) {
            return true;
        }
        return (time() - $ts) <= (PTR_ACTIVE_ATTEMPT_MINUTES * 60);
    }

    private function ptr_get_open_attempt(int $studentId, int $cohortId, int $lessonId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ? AND lesson_id = ?
              AND status IN ('preparing','ready','in_progress','processing')
              AND (formal_result_code IS NULL OR formal_result_code != 'STALE_ABORTED')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$studentId, $cohortId, $lessonId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function ptr_get_active_remote_auth(int $studentId, int $cohortId, int $lessonId): ?array
    {
        ptr_ensure_tables($this->pdo);
        $st = $this->pdo->prepare("
            SELECT *
            FROM progress_test_remote_authorizations
            WHERE student_id = ? AND cohort_id = ? AND lesson_id = ?
              AND status IN ('REQUESTED','EMAIL_SENT','AUTHENTICATED')
              AND expires_at > UTC_TIMESTAMP()
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$studentId, $cohortId, $lessonId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function ptr_count_recent_requests(int $studentId, int $cohortId): int
    {
        $st = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM progress_test_remote_authorizations
            WHERE student_id = ? AND cohort_id = ?
              AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
        ");
        $st->execute([$studentId, $cohortId]);
        return (int)$st->fetchColumn();
    }

    private function ptr_lesson_titles(int $lessonId): array
    {
        $st = $this->pdo->prepare("
            SELECT l.title AS lesson_title, c.title AS course_title, c.id AS course_id
            FROM lessons l
            INNER JOIN courses c ON c.id = l.course_id
            WHERE l.id = ?
            LIMIT 1
        ");
        $st->execute([$lessonId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'lesson_title' => (string)($row['lesson_title'] ?? 'Lesson'),
            'course_title' => (string)($row['course_title'] ?? 'Course'),
            'course_id' => (int)($row['course_id'] ?? 0),
        ];
    }

    private function ptr_student_enrolled(int $studentId, int $cohortId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM cohort_students WHERE cohort_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
        $st->execute([$cohortId, $studentId]);
        return (bool)$st->fetchColumn();
    }

    private function ptr_prepare_blocked_reason(int $studentId, int $cohortId, int $lessonId, array $courseCtx = []): ?array
    {
        if ($courseCtx) {
            if (!empty($courseCtx['test_passed'])) {
                return ['blocked' => true, 'reason' => 'passed', 'message' => 'This lesson progress test is already passed.'];
            }
            if (!empty($courseCtx['training_suspended'])) {
                return ['blocked' => true, 'reason' => 'training_suspended', 'message' => 'Training is currently suspended for this lesson.'];
            }
            if (!empty($courseCtx['pending_deadline_reason'])) {
                return ['blocked' => true, 'reason' => 'deadline', 'message' => 'A deadline-related action is required before you can prepare a progress test.'];
            }
            if (!empty($courseCtx['deadline_passed'])) {
                return ['blocked' => true, 'reason' => 'deadline', 'message' => 'The effective deadline for this lesson has passed.'];
            }
            if (!empty($courseCtx['pending_instructor_approval']) || !empty($courseCtx['one_on_one_required'])) {
                return ['blocked' => true, 'reason' => 'instructor_required', 'message' => 'Instructor approval is required before another attempt can begin.'];
            }
            if (!empty($courseCtx['pending_remediation'])) {
                return ['blocked' => true, 'reason' => 'remediation_required', 'message' => 'Remediation is required before another attempt can begin.'];
            }
            if (empty($courseCtx['summary_ok'])) {
                return ['blocked' => true, 'reason' => 'summary_required', 'message' => 'An acceptable lesson summary is required before this progress test can be prepared.'];
            }
            return null;
        }

        $start = $this->prepareStartDecision($studentId, $cohortId, $lessonId);
        $decision = (array)($start['decision'] ?? []);
        if (!empty($decision['training_suspended'])) {
            return ['blocked' => true, 'reason' => 'training_suspended', 'message' => 'Training is currently suspended for this lesson.'];
        }
        if (!empty($decision['deadline_blocked'])) {
            return ['blocked' => true, 'reason' => 'deadline', 'message' => 'The effective deadline for this lesson has passed.'];
        }
        if (!empty($decision['instructor_required'])) {
            return ['blocked' => true, 'reason' => 'instructor_required', 'message' => 'Instructor approval is required before another attempt can begin.'];
        }
        if (!empty($decision['remediation_required'])) {
            return ['blocked' => true, 'reason' => 'remediation_required', 'message' => 'Remediation is required before another attempt can begin.'];
        }
        if (!empty($decision['summary_blocked'])) {
            return ['blocked' => true, 'reason' => 'summary_required', 'message' => 'An acceptable lesson summary is required before this progress test can be prepared.'];
        }
        if ($this->hasCanonicalPassProgressTest($studentId, $cohortId, $lessonId)) {
            return ['blocked' => true, 'reason' => 'passed', 'message' => 'This lesson progress test is already passed.'];
        }
        return null;
    }

    public function getProgressTestButtonState(int $studentId, int $cohortId, int $lessonId, string $cookieHeader = '', array $courseCtx = []): array
    {
        static $trustedNetworkCache = [];
        static $remotePermissionCache = [];

        ptr_ensure_tables($this->pdo);
        $ptUrl = '/student/progress_test_v4.php?cohort_id=' . $cohortId . '&lesson_id=' . $lessonId;
        $passiveCoursePage = $courseCtx !== [];
        $allowBackgroundRetry = !$passiveCoursePage;

        $blocked = $this->ptr_prepare_blocked_reason($studentId, $cohortId, $lessonId, $courseCtx);
        if ($blocked) {
            return array_merge([
                'mode' => 'blocked',
                'label' => 'Start Progress Test',
                'button_class' => 'primary',
                'disabled' => true,
                'show_code_modal' => false,
                'progress_test_url' => $ptUrl,
            ], $blocked);
        }

        if ($this->hasActiveInProgressAttempt($studentId, $cohortId, $lessonId)) {
            $open = $this->ptr_get_open_attempt($studentId, $cohortId, $lessonId);
            return [
                'mode' => 'continue',
                'label' => 'Resume Progress Test',
                'button_class' => 'primary',
                'disabled' => false,
                'href' => $ptUrl,
                'show_code_modal' => false,
                'progress_test_url' => $ptUrl,
                'attempt_id' => $open ? (int)$open['id'] : null,
            ];
        }

        $trustedKey = $studentId . ':' . $cohortId;
        if (!array_key_exists($trustedKey, $trustedNetworkCache)) {
            $trustedNetworkCache[$trustedKey] = cw_progress_test_is_trusted_school_network($this->pdo, $studentId, $cohortId);
        }
        $trusted = (bool)$trustedNetworkCache[$trustedKey];

        $prep = pt_prep_course_status(
            $this->pdo,
            $studentId,
            $cohortId,
            $lessonId,
            $cookieHeader,
            $ptUrl,
            $allowBackgroundRetry
        );

        if ($trusted) {
            if (!empty($prep['show_prepare_button'])) {
                return [
                    'mode' => 'on_site_prepare',
                    'label' => 'Prepare Progress Test',
                    'button_class' => 'primary',
                    'disabled' => false,
                    'show_prepare_button' => true,
                    'show_code_modal' => false,
                    'progress_test_url' => $ptUrl,
                ];
            }
            if (!empty($prep['preparing'])) {
                return [
                    'mode' => 'on_site_preparing',
                    'label' => 'Start Progress Test',
                    'button_class' => 'primary',
                    'disabled' => true,
                    'show_bar' => true,
                    'prep' => $prep,
                    'show_code_modal' => false,
                    'progress_test_url' => $ptUrl,
                ];
            }
            if (!empty($prep['prepared']) || !empty($prep['show_button'])) {
                return [
                    'mode' => 'on_site_start',
                    'label' => (string)($prep['button_label'] ?: 'Start Progress Test'),
                    'button_class' => 'primary',
                    'disabled' => false,
                    'href' => (string)($prep['button_href'] ?: $ptUrl),
                    'show_code_modal' => false,
                    'progress_test_url' => $ptUrl,
                    'prep' => $prep,
                ];
            }
            return [
                'mode' => 'on_site_prepare',
                'label' => 'Prepare Progress Test',
                'button_class' => 'primary',
                'disabled' => false,
                'show_prepare_button' => true,
                'show_code_modal' => false,
                'progress_test_url' => $ptUrl,
            ];
        }

        if (!array_key_exists($trustedKey, $remotePermissionCache)) {
            $remotePermissionCache[$trustedKey] = $this->hasRemoteTestingPermission($studentId, $cohortId);
        }
        if (!$remotePermissionCache[$trustedKey]) {
            return [
                'mode' => 'blocked',
                'label' => 'Start Progress Test',
                'button_class' => 'primary',
                'disabled' => true,
                'reason' => 'remote_not_enabled',
                'message' => 'Remote Progress Testing is not enabled for your account. Please contact your instructor or training manager.',
                'show_code_modal' => false,
                'progress_test_url' => $ptUrl,
            ];
        }

        $open = $this->ptr_get_open_attempt($studentId, $cohortId, $lessonId);
        if ($open && pt_prep_attempt_is_prepared($open, $this->pdo)) {
            return [
                'mode' => 'remote_start',
                'label' => !empty($prep['resume']) ? 'Resume Progress Test' : 'Start Progress Test',
                'button_class' => 'primary',
                'disabled' => false,
                'href' => $ptUrl,
                'show_code_modal' => false,
                'progress_test_url' => $ptUrl,
                'attempt_id' => (int)$open['id'],
            ];
        }

        if ($open && !pt_prep_attempt_is_prepared($open, $this->pdo)) {
            return [
                'mode' => 'remote_preparing',
                'label' => 'Start Progress Test',
                'button_class' => 'primary',
                'disabled' => true,
                'show_bar' => true,
                'preparing' => true,
                'prep' => $prep,
                'show_code_modal' => false,
                'progress_test_url' => $ptUrl,
                'attempt_id' => (int)$open['id'],
            ];
        }

        $auth = $this->ptr_get_active_remote_auth($studentId, $cohortId, $lessonId);
        if ($auth && (string)$auth['status'] === 'AUTHENTICATED' && !empty($auth['verification_code_hash'])) {
            $openPrep = $this->ptr_get_open_attempt($studentId, $cohortId, $lessonId);
            if ($openPrep && !pt_prep_attempt_is_prepared($openPrep, $this->pdo)) {
                return [
                    'mode' => 'remote_preparing',
                    'label' => 'Start Progress Test',
                    'button_class' => 'primary',
                    'disabled' => true,
                    'show_bar' => true,
                    'preparing' => true,
                    'prep' => $prep,
                    'show_code_modal' => false,
                    'progress_test_url' => $ptUrl,
                    'attempt_id' => (int)$openPrep['id'],
                ];
            }
            return [
                'mode' => 'remote_code_entry',
                'label' => 'Enter Progress Test Code',
                'button_class' => 'primary',
                'disabled' => false,
                'show_code_modal' => true,
                'authorization_id' => (int)$auth['id'],
                'progress_test_url' => $ptUrl,
            ];
        }

        if ($auth && in_array((string)$auth['status'], ['REQUESTED', 'EMAIL_SENT'], true)) {
            return [
                'mode' => 'remote_auth_pending',
                'label' => 'Check your email',
                'button_class' => 'remote',
                'disabled' => true,
                'show_code_modal' => false,
                'progress_test_url' => $ptUrl,
                'message' => 'Open the authentication link in your email to receive your Progress Test Code.',
            ];
        }

        return [
            'mode' => 'remote_request',
            'label' => 'Request Progress Test',
            'button_class' => 'remote',
            'disabled' => false,
            'show_code_modal' => false,
            'progress_test_url' => $ptUrl,
        ];
    }

    public function requestRemoteProgressTestAuthorization(int $studentId, int $cohortId, int $lessonId): array
    {
        ptr_ensure_tables($this->pdo);

        if (!$this->ptr_student_enrolled($studentId, $cohortId)) {
            throw new RuntimeException('Not enrolled in this cohort.');
        }
        $blocked = $this->ptr_prepare_blocked_reason($studentId, $cohortId, $lessonId);
        if ($blocked) {
            throw new RuntimeException((string)$blocked['message']);
        }
        if (cw_progress_test_is_trusted_school_network($this->pdo, $studentId, $cohortId)) {
            throw new RuntimeException('You are on an approved school network. Use Prepare Progress Test on this page.');
        }
        if (!$this->hasRemoteTestingPermission($studentId, $cohortId)) {
            throw new RuntimeException('Remote Progress Testing is not enabled for your account. Please contact your instructor or training manager.');
        }
        if ($this->hasActiveInProgressAttempt($studentId, $cohortId, $lessonId)) {
            throw new RuntimeException('You already have an active progress test in progress.');
        }

        $existing = $this->ptr_get_active_remote_auth($studentId, $cohortId, $lessonId);
        if ($existing) {
            if ((string)$existing['status'] === 'AUTHENTICATED') {
                throw new RuntimeException('You already completed remote authentication. Click Enter Progress Test Code on the course page.');
            }
            if ((string)$existing['status'] === 'EMAIL_SENT') {
                return [
                    'ok' => true,
                    'reused' => true,
                    'authorization_id' => (int)$existing['id'],
                    'message' => 'An active authentication request already exists. Check your email for the link.',
                ];
            }
            $this->pdo->prepare('DELETE FROM progress_test_remote_authorizations WHERE id = ?')
                ->execute([(int)$existing['id']]);
        }

        if ($this->ptr_count_recent_requests($studentId, $cohortId) >= PTR_MAX_REQUESTS_PER_HOUR) {
            throw new RuntimeException('Too many remote progress test requests. Please try again later.');
        }

        $titles = $this->ptr_lesson_titles($lessonId);
        $rawToken = ptr_generate_token();
        $tokenHash = ptr_hash($rawToken);
        $validFrom = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (PTR_AUTH_TTL_MINUTES * 60));

        $ins = $this->pdo->prepare("
            INSERT INTO progress_test_remote_authorizations
              (student_id, cohort_id, course_id, lesson_id, request_token_hash, status, valid_from, expires_at,
               requested_ip, requested_user_agent_hash, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'REQUESTED', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $ins->execute([
            $studentId,
            $cohortId,
            $titles['course_id'] > 0 ? $titles['course_id'] : null,
            $lessonId,
            $tokenHash,
            $validFrom,
            $expiresAt,
            cw_progress_test_client_ip(),
            ptr_user_agent_hash() ?: null,
        ]);
        $authId = (int)$this->pdo->lastInsertId();

        $authLink = ptr_app_base_url() . '/student/progress_test_auth.php?token=' . urlencode($rawToken);

        $stUser = $this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(name), ''), email) AS student_name, email FROM users WHERE id = ? LIMIT 1");
        $stUser->execute([$studentId]);
        $userRow = $stUser->fetch(PDO::FETCH_ASSOC) ?: [];
        $studentEmail = trim((string)($userRow['email'] ?? ''));
        if ($studentEmail === '' || !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Your account does not have a valid email address on file. Please update your profile or contact support.');
        }

        $automationContext = [
            'user_id' => $studentId,
            'student_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'student_name' => (string)($userRow['student_name'] ?? 'Student'),
            'student_email' => $studentEmail,
            'lesson_title' => $titles['lesson_title'],
            'course_title' => $titles['course_title'],
            'auth_link' => $authLink,
            'expires_at' => $expiresAt,
            'support_email' => ptr_support_email(),
            'authorization_id' => $authId,
        ];

        $this->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'event_type' => 'progress_test',
            'event_code' => 'REMOTE_PROGRESS_TEST_REQUESTED',
            'event_status' => 'info',
            'actor_type' => 'student',
            'actor_user_id' => $studentId,
            'payload' => ['authorization_id' => $authId],
            'legal_note' => 'Student requested remote progress test authorization (no attempt created).',
        ]);

        $automationResult = $this->dispatchAutomationEventIfAvailable(
            'remote_progress_test_requested',
            $automationContext,
            $studentId,
            $cohortId,
            $lessonId,
            null
        );

        if (!ptr_automation_email_sent(is_array($automationResult) ? $automationResult : null)) {
            $failureReason = ptr_automation_email_failure_reason(is_array($automationResult) ? $automationResult : null);
            $this->pdo->prepare('DELETE FROM progress_test_remote_authorizations WHERE id = ?')
                ->execute([$authId]);
            $this->logProgressionEvent([
                'user_id' => $studentId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'event_type' => 'progress_test',
                'event_code' => 'REMOTE_PROGRESS_TEST_EMAIL_FAILED',
                'event_status' => 'warning',
                'actor_type' => 'system',
                'payload' => [
                    'authorization_id' => $authId,
                    'reason' => $failureReason,
                    'automation_result' => $automationResult,
                ],
                'legal_note' => 'Remote progress test authentication email could not be sent; authorization row removed so student can retry.',
            ]);
            throw new RuntimeException('Your request could not send the authentication email. Please try again in a moment or contact ' . ptr_support_email() . '.');
        }

        $this->pdo->prepare("UPDATE progress_test_remote_authorizations SET status = 'EMAIL_SENT', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$authId]);

        $this->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'event_type' => 'progress_test',
            'event_code' => 'REMOTE_PROGRESS_TEST_EMAIL_SENT',
            'event_status' => 'info',
            'actor_type' => 'system',
            'payload' => [
                'authorization_id' => $authId,
                'automation_result' => $automationResult,
            ],
            'legal_note' => 'Remote progress test authentication email dispatched via automation.',
        ]);

        return [
            'ok' => true,
            'authorization_id' => $authId,
            'message' => 'Your progress test request was received. You will receive an email with your authentication link in a few moments.',
        ];
    }

    public function loadRemoteAuthorizationByToken(string $rawToken): ?array
    {
        ptr_ensure_tables($this->pdo);
        $hash = ptr_hash(trim($rawToken));
        $st = $this->pdo->prepare('SELECT * FROM progress_test_remote_authorizations WHERE request_token_hash = ? LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function authenticateRemoteProgressTestToken(string $rawToken, int $studentId, string $password, string $photoBinary, string $photoMime): array
    {
        ptr_ensure_tables($this->pdo);
        $auth = $this->loadRemoteAuthorizationByToken($rawToken);
        if (!$auth) {
            throw new RuntimeException('Invalid or expired authentication link.');
        }
        if ((int)$auth['student_id'] !== $studentId) {
            throw new RuntimeException('This authentication link belongs to another account.');
        }
        if (!in_array((string)$auth['status'], ['REQUESTED', 'EMAIL_SENT', 'AUTHENTICATED'], true)) {
            throw new RuntimeException('This authorization is no longer valid.');
        }
        if (strtotime((string)$auth['expires_at']) <= time()) {
            $this->pdo->prepare("UPDATE progress_test_remote_authorizations SET status = 'EXPIRED', updated_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([(int)$auth['id']]);
            throw new RuntimeException('This authentication link has expired.');
        }
        if (!$this->hasRemoteTestingPermission($studentId, (int)$auth['cohort_id'])) {
            throw new RuntimeException('Remote Progress Testing is not enabled for your account.');
        }
        $blocked = $this->ptr_prepare_blocked_reason($studentId, (int)$auth['cohort_id'], (int)$auth['lesson_id']);
        if ($blocked) {
            throw new RuntimeException((string)$blocked['message']);
        }

        $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$studentId]);
        $hash = (string)$st->fetchColumn();
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new RuntimeException('Incorrect password.');
        }

        $photo = ptr_store_auth_photo((int)$auth['id'], $photoBinary, $photoMime);
        $code = ptr_generate_code();
        $codeHash = ptr_hash($code);

        $this->pdo->prepare("
            UPDATE progress_test_remote_authorizations
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
            cw_progress_test_client_ip(),
            ptr_user_agent_hash() ?: null,
            $photo['path'],
            $photo['hash'],
            (int)$auth['id'],
        ]);

        foreach (['REMOTE_PROGRESS_TEST_PHOTO_CAPTURED', 'REMOTE_PROGRESS_TEST_PASSWORD_VERIFIED', 'REMOTE_PROGRESS_TEST_AUTHENTICATED', 'REMOTE_PROGRESS_TEST_CODE_DISPLAYED'] as $codeEvent) {
            $this->logProgressionEvent([
                'user_id' => $studentId,
                'cohort_id' => (int)$auth['cohort_id'],
                'lesson_id' => (int)$auth['lesson_id'],
                'event_type' => 'progress_test',
                'event_code' => $codeEvent,
                'event_status' => 'info',
                'actor_type' => 'student',
                'actor_user_id' => $studentId,
                'payload' => ['authorization_id' => (int)$auth['id']],
            ]);
        }

        return [
            'ok' => true,
            'authorization_id' => (int)$auth['id'],
            'progress_test_code' => $code,
            'cohort_id' => (int)$auth['cohort_id'],
            'lesson_id' => (int)$auth['lesson_id'],
        ];
    }

    public function verifyRemoteProgressTestCodeAndStartAttempt(int $studentId, int $cohortId, int $lessonId, string $code, string $cookieHeader = ''): array
    {
        ptr_ensure_tables($this->pdo);
        $code = preg_replace('/\D+/', '', trim($code));
        if ($code === '') {
            throw new RuntimeException('Enter your Progress Test Code.');
        }

        if (!$this->ptr_student_enrolled($studentId, $cohortId)) {
            throw new RuntimeException('Not enrolled in this cohort.');
        }
        $blocked = $this->ptr_prepare_blocked_reason($studentId, $cohortId, $lessonId);
        if ($blocked) {
            throw new RuntimeException((string)$blocked['message']);
        }
        if (!$this->hasRemoteTestingPermission($studentId, $cohortId)) {
            throw new RuntimeException('Remote Progress Testing is not enabled for your account.');
        }
        if ($this->hasActiveInProgressAttempt($studentId, $cohortId, $lessonId)) {
            $open = $this->ptr_get_open_attempt($studentId, $cohortId, $lessonId);
            return [
                'ok' => true,
                'already_active' => true,
                'test_id' => $open ? (int)$open['id'] : null,
                'redirect_url' => '/student/course.php?cohort_id=' . $cohortId . '#progress-test-lesson-' . $lessonId,
            ];
        }

        $auth = $this->ptr_get_active_remote_auth($studentId, $cohortId, $lessonId);
        if (!$auth || (string)$auth['status'] !== 'AUTHENTICATED' || empty($auth['verification_code_hash'])) {
            throw new RuntimeException('Complete remote authentication first or request a new authorization.');
        }
        if ((int)$auth['failed_code_attempts'] >= PTR_MAX_CODE_FAILURES) {
            $this->pdo->prepare("UPDATE progress_test_remote_authorizations SET status = 'FAILED', updated_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([(int)$auth['id']]);
            throw new RuntimeException('Too many failed code attempts. Request a new progress test authorization.');
        }
        if (!hash_equals((string)$auth['verification_code_hash'], ptr_hash($code))) {
            $this->pdo->prepare('UPDATE progress_test_remote_authorizations SET failed_code_attempts = failed_code_attempts + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([(int)$auth['id']]);
            $this->logProgressionEvent([
                'user_id' => $studentId,
                'cohort_id' => $cohortId,
                'lesson_id' => $lessonId,
                'event_type' => 'progress_test',
                'event_code' => 'REMOTE_PROGRESS_TEST_CODE_FAILED',
                'event_status' => 'warning',
                'actor_type' => 'student',
                'actor_user_id' => $studentId,
                'payload' => ['authorization_id' => (int)$auth['id']],
            ]);
            throw new RuntimeException('Incorrect Progress Test Code.');
        }

        $created = $this->createProgressTestAttempt(
            $studentId,
            $cohortId,
            $lessonId,
            'student',
            $studentId,
            'remote_auth_' . (int)$auth['id']
        );
        if (!empty($created['blocked'])) {
            throw new RuntimeException('Progress test cannot be started: ' . (string)($created['reason'] ?? 'blocked'));
        }
        $testId = (int)$created['test_id'];

        $this->pdo->prepare("
            UPDATE progress_test_remote_authorizations
            SET status = 'USED',
                progress_test_id = ?,
                progress_test_attempt_id = ?,
                code_verified_at = UTC_TIMESTAMP(),
                used_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE id = ?
        ")->execute([$testId, $testId, (int)$auth['id']]);

        $this->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $testId,
            'event_type' => 'progress_test',
            'event_code' => 'REMOTE_PROGRESS_TEST_CODE_VERIFIED',
            'event_status' => 'info',
            'actor_type' => 'student',
            'actor_user_id' => $studentId,
            'payload' => ['authorization_id' => (int)$auth['id']],
        ]);

        pt_prep_schedule_progress_test($this->pdo, $studentId, $cohortId, $lessonId, 'remote_code_verified', $cookieHeader, 'student', $studentId);

        $this->logProgressionEvent([
            'user_id' => $studentId,
            'cohort_id' => $cohortId,
            'lesson_id' => $lessonId,
            'progress_test_id' => $testId,
            'event_type' => 'progress_test',
            'event_code' => 'REMOTE_PROGRESS_TEST_ATTEMPT_CREATED',
            'event_status' => 'info',
            'actor_type' => 'student',
            'actor_user_id' => $studentId,
            'payload' => ['authorization_id' => (int)$auth['id'], 'test_id' => $testId],
            'legal_note' => 'Official progress_tests_v2 attempt created only after remote code verification.',
        ]);

        $courseUrl = '/student/course.php?cohort_id=' . $cohortId . '&pt_code_verified=1#progress-test-lesson-' . $lessonId;

        return [
            'ok' => true,
            'test_id' => $testId,
            'preparing' => true,
            'redirect_url' => $courseUrl,
        ];
    }

    public function getRemoteProgressTestRequestsForInstructor(int $studentId, int $cohortId, ?int $lessonId = null): array
    {
        ptr_ensure_tables($this->pdo);
        $sql = "
            SELECT a.*,
                   l.title AS lesson_title,
                   c.title AS course_title,
                   pt.status AS attempt_status,
                   pt.score_pct AS attempt_score_pct,
                   pt.formal_result_code AS attempt_result_code,
                   pt.pass_gate_met AS attempt_pass_gate_met
            FROM progress_test_remote_authorizations a
            LEFT JOIN lessons l ON l.id = a.lesson_id
            LEFT JOIN courses c ON c.id = a.course_id
            LEFT JOIN progress_tests_v2 pt ON pt.id = a.progress_test_attempt_id
            WHERE a.student_id = ? AND a.cohort_id = ?
        ";
        $params = [$studentId, $cohortId];
        if ($lessonId !== null && $lessonId > 0) {
            $sql .= ' AND a.lesson_id = ?';
            $params[] = $lessonId;
        }
        $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT 50';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string)$row['status'];
            $rows[] = [
                'id' => (int)$row['id'],
                'status' => $status,
                'status_label' => ptr_status_label($status),
                'pill_class' => ptr_status_pill_class($status),
                'lesson_id' => (int)$row['lesson_id'],
                'lesson_title' => (string)($row['lesson_title'] ?? ''),
                'course_title' => (string)($row['course_title'] ?? ''),
                'requested_at' => (string)$row['created_at'],
                'authenticated_at' => (string)($row['authenticated_at'] ?? ''),
                'code_verified_at' => (string)($row['code_verified_at'] ?? ''),
                'used_at' => (string)($row['used_at'] ?? ''),
                'requested_ip' => (string)($row['requested_ip'] ?? ''),
                'authenticated_ip' => (string)($row['authenticated_ip'] ?? ''),
                'progress_test_attempt_id' => $row['progress_test_attempt_id'] !== null ? (int)$row['progress_test_attempt_id'] : null,
                'attempt_status' => (string)($row['attempt_status'] ?? ''),
                'attempt_score_pct' => $row['attempt_score_pct'],
                'attempt_result_code' => (string)($row['attempt_result_code'] ?? ''),
                'attempt_pass_gate_met' => (int)($row['attempt_pass_gate_met'] ?? 0),
                'has_photo' => trim((string)($row['student_photo_path'] ?? '')) !== '',
                'photo_url' => trim((string)($row['student_photo_path'] ?? '')) !== ''
                    ? '/instructor/remote_progress_test_photo.php?id=' . (int)$row['id']
                    : '',
            ];
        }
        return $rows;
    }

    public function resolveProgressTestPageAccess(int $studentId, int $cohortId, int $lessonId): array
    {
        ptr_ensure_tables($this->pdo);
        if (cw_progress_test_is_trusted_school_network($this->pdo, $studentId, $cohortId)) {
            return ['allowed' => true, 'mode' => 'trusted_ip'];
        }

        $access = cw_progress_test_access_state($this->pdo, $studentId, $cohortId);
        $hasRemote = $this->hasRemoteTestingPermission($studentId, $cohortId);

        if ($hasRemote) {
            if ($this->hasActiveInProgressAttempt($studentId, $cohortId, $lessonId)) {
                return ['allowed' => true, 'mode' => 'active_attempt'];
            }
            $open = $this->ptr_get_open_attempt($studentId, $cohortId, $lessonId);
            if ($open && pt_prep_attempt_is_prepared($open, $this->pdo)) {
                return ['allowed' => true, 'mode' => 'prepared_attempt'];
            }
            if ($open && !pt_prep_attempt_is_prepared($open, $this->pdo)) {
                return [
                    'allowed' => false,
                    'mode' => 'preparing_on_course',
                    'message' => 'Your progress test is being prepared on the course page. Return there and click Start Progress Test once preparation is complete.',
                ];
            }
            return [
                'allowed' => false,
                'mode' => 'remote_required',
                'message' => 'Complete remote progress test authorization on the course page before starting the test.',
            ];
        }

        if (!empty($access['allowed'])) {
            return ['allowed' => true, 'mode' => 'legacy_pin'];
        }

        return [
            'allowed' => false,
            'mode' => 'blocked',
            'message' => 'Progress test access is not allowed from this network.',
            'access_state' => $access,
        ];
    }

    public function expireStaleRemoteAuthorizations(): int
    {
        ptr_ensure_tables($this->pdo);
        $st = $this->pdo->query("
            SELECT id, student_id, cohort_id, lesson_id
            FROM progress_test_remote_authorizations
            WHERE expires_at < UTC_TIMESTAMP()
              AND status IN ('REQUESTED','EMAIL_SENT','AUTHENTICATED')
        ");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$rows) {
            return 0;
        }
        $this->pdo->exec("
            UPDATE progress_test_remote_authorizations
            SET status = 'EXPIRED', updated_at = UTC_TIMESTAMP()
            WHERE expires_at < UTC_TIMESTAMP()
              AND status IN ('REQUESTED','EMAIL_SENT','AUTHENTICATED')
        ");
        foreach ($rows as $row) {
            $this->logProgressionEvent([
                'user_id' => (int)$row['student_id'],
                'cohort_id' => (int)$row['cohort_id'],
                'lesson_id' => (int)$row['lesson_id'],
                'event_type' => 'progress_test',
                'event_code' => 'REMOTE_PROGRESS_TEST_AUTH_EXPIRED',
                'event_status' => 'info',
                'actor_type' => 'system',
                'payload' => ['authorization_id' => (int)$row['id']],
            ]);
        }
        return count($rows);
    }

    public function invalidateRemoteAuthAfterFailedAttempt(int $progressTestId): void
    {
        ptr_ensure_tables($this->pdo);
        $this->pdo->prepare("
            UPDATE progress_test_remote_authorizations
            SET status = 'USED', updated_at = UTC_TIMESTAMP()
            WHERE progress_test_attempt_id = ?
              AND status IN ('USED','CODE_VERIFIED','AUTHENTICATED')
        ")->execute([$progressTestId]);
    }
}
