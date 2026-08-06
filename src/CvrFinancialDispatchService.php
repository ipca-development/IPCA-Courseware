<?php
declare(strict_types=1);

/**
 * Financial dispatch for Operational Legs: rates, live totals, draft save, lock/unlock.
 */
final class CvrFinancialDispatchService
{
    public const DEFAULT_GROUND_HOURS = 0.3;
    public const DEFAULT_UNLOCK_CODE = 'IPCA-FIN-UNLOCK';

    public function __construct(private PDO $pdo)
    {
        $this->ensureTables();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function instructionalRates(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, rate_code, label, rate_usd_per_hour, sort_order
             FROM ipca_instructional_rates
             WHERE active = 1
             ORDER BY sort_order ASC, id ASC'
        );
        $rows = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : array();
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $rate = (float)($row['rate_usd_per_hour'] ?? 0);
            $label = trim((string)($row['label'] ?? ''));
            $out[] = array(
                'id' => (int)($row['id'] ?? 0),
                'rate_code' => (string)($row['rate_code'] ?? ''),
                'label' => $label,
                'rate_usd_per_hour' => $rate,
                'option_label' => $label . ' ($' . number_format($rate, 2, '.', '') . '/hr)',
            );
        }
        return $out;
    }

    /**
     * @return array<string,array<string,mixed>> registration => rate row
     */
    public function aircraftRentalRateMap(): array
    {
        $statement = $this->pdo->query(
            'SELECT aircraft_id, aircraft_registration, display_label, rate_usd_per_hour
             FROM ipca_aircraft_rental_rates
             WHERE active = 1'
        );
        $map = array();
        foreach (($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : array()) ?: array() as $row) {
            $reg = strtoupper(trim((string)($row['aircraft_registration'] ?? '')));
            if ($reg === '') {
                continue;
            }
            $map[$reg] = array(
                'aircraft_id' => (int)($row['aircraft_id'] ?? 0),
                'aircraft_registration' => $reg,
                'display_label' => trim((string)($row['display_label'] ?? '')) ?: $reg,
                'rate_usd_per_hour' => (float)($row['rate_usd_per_hour'] ?? 0),
            );
        }
        return $map;
    }

    /**
     * @param list<int> $dispatchIds
     * @return array<int,array<string,mixed>>
     */
    public function mapForDispatchIds(array $dispatchIds): array
    {
        $ids = array();
        foreach ($dispatchIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === array()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT * FROM ipca_cvr_financial_dispatches WHERE dispatch_id IN ({$placeholders})"
        );
        $statement->execute(array_values($ids));
        $map = array();
        foreach (($statement->fetchAll(PDO::FETCH_ASSOC) ?: array()) as $row) {
            $normalized = $this->normalizeRow($row);
            $map[(int)$normalized['dispatch_id']] = $normalized;
        }
        return $map;
    }

    public function balanceForUser(int $userId): float
    {
        if ($userId <= 0) {
            return 0.0;
        }
        $statement = $this->pdo->prepare(
            'SELECT balance_usd FROM ipca_user_account_balances WHERE user_id = ? LIMIT 1'
        );
        $statement->execute(array($userId));
        $value = $statement->fetchColumn();
        return is_numeric($value) ? round((float)$value, 2) : 0.0;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function forDispatch(int $dispatchId): ?array
    {
        if ($dispatchId <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM ipca_cvr_financial_dispatches WHERE dispatch_id = ? LIMIT 1'
        );
        $statement->execute(array($dispatchId));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveDraft(int $dispatchId, array $input, ?int $actorUserId, bool $lock = false): array
    {
        if ($dispatchId <= 0) {
            throw new RuntimeException('Dispatch id is required for financial dispatch.');
        }
        $existing = $this->forDispatch($dispatchId);
        if (is_array($existing) && ($existing['status'] ?? '') === 'locked') {
            throw new RuntimeException('Financial dispatch is locked. Unlock it before editing.');
        }

        $computed = $this->computeTotals($input);
        $now = $this->utcNow();
        $payload = json_encode($computed['overview'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_array($existing)) {
            $statement = $this->pdo->prepare(
                'UPDATE ipca_cvr_financial_dispatches SET
                    workflow_flight_record_uuid = ?,
                    customer_user_id = ?, customer_name = ?,
                    instructor_user_id = ?, instructor_name = ?,
                    aircraft_registration = ?, aircraft_label = ?,
                    preflight_briefing_hours = ?, flight_instruction_hours = ?, ground_instruction_hours = ?,
                    instructional_rate_id = ?, instructional_rate_code = ?, instructional_rate_label = ?,
                    instructional_rate_usd_per_hour = ?, aircraft_rate_usd_per_hour = ?,
                    aircraft_rental_total_usd = ?, flight_instruction_total_usd = ?, ground_instruction_total_usd = ?,
                    session_total_usd = ?, existing_balance_usd = ?, grand_total_usd = ?,
                    status = ?, payload_json = ?, updated_by = ?,
                    locked_at = CASE WHEN ? = 1 THEN ? ELSE locked_at END,
                    locked_by = CASE WHEN ? = 1 THEN ? ELSE locked_by END,
                    updated_at = CURRENT_TIMESTAMP(3)
                 WHERE dispatch_id = ?'
            );
            $statement->execute(array(
                $computed['workflow_flight_record_uuid'],
                $computed['customer_user_id'],
                $computed['customer_name'],
                $computed['instructor_user_id'],
                $computed['instructor_name'],
                $computed['aircraft_registration'],
                $computed['aircraft_label'],
                $computed['preflight_briefing_hours'],
                $computed['flight_instruction_hours'],
                $computed['ground_instruction_hours'],
                $computed['instructional_rate_id'],
                $computed['instructional_rate_code'],
                $computed['instructional_rate_label'],
                $computed['instructional_rate_usd_per_hour'],
                $computed['aircraft_rate_usd_per_hour'],
                $computed['aircraft_rental_total_usd'],
                $computed['flight_instruction_total_usd'],
                $computed['ground_instruction_total_usd'],
                $computed['session_total_usd'],
                $computed['existing_balance_usd'],
                $computed['grand_total_usd'],
                $lock ? 'locked' : 'draft',
                $payload,
                $actorUserId,
                $lock ? 1 : 0,
                $lock ? $now : null,
                $lock ? 1 : 0,
                $lock ? $actorUserId : null,
                $dispatchId,
            ));
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO ipca_cvr_financial_dispatches (
                    dispatch_id, workflow_flight_record_uuid,
                    customer_user_id, customer_name, instructor_user_id, instructor_name,
                    aircraft_registration, aircraft_label,
                    preflight_briefing_hours, flight_instruction_hours, ground_instruction_hours,
                    instructional_rate_id, instructional_rate_code, instructional_rate_label,
                    instructional_rate_usd_per_hour, aircraft_rate_usd_per_hour,
                    aircraft_rental_total_usd, flight_instruction_total_usd, ground_instruction_total_usd,
                    session_total_usd, existing_balance_usd, grand_total_usd,
                    status, locked_at, locked_by, payload_json, created_by, updated_by
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $statement->execute(array(
                $dispatchId,
                $computed['workflow_flight_record_uuid'],
                $computed['customer_user_id'],
                $computed['customer_name'],
                $computed['instructor_user_id'],
                $computed['instructor_name'],
                $computed['aircraft_registration'],
                $computed['aircraft_label'],
                $computed['preflight_briefing_hours'],
                $computed['flight_instruction_hours'],
                $computed['ground_instruction_hours'],
                $computed['instructional_rate_id'],
                $computed['instructional_rate_code'],
                $computed['instructional_rate_label'],
                $computed['instructional_rate_usd_per_hour'],
                $computed['aircraft_rate_usd_per_hour'],
                $computed['aircraft_rental_total_usd'],
                $computed['flight_instruction_total_usd'],
                $computed['ground_instruction_total_usd'],
                $computed['session_total_usd'],
                $computed['existing_balance_usd'],
                $computed['grand_total_usd'],
                $lock ? 'locked' : 'draft',
                $lock ? $now : null,
                $lock ? $actorUserId : null,
                $payload,
                $actorUserId,
                $actorUserId,
            ));
        }

        if ($lock) {
            $this->applyBalanceOnLock(
                (int)$computed['customer_user_id'],
                (float)$computed['grand_total_usd']
            );
        }

        $saved = $this->forDispatch($dispatchId);
        if (!is_array($saved)) {
            throw new RuntimeException('Financial dispatch save failed.');
        }
        return $saved;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function computeTotals(array $input): array
    {
        $customerUserId = (int)($input['customer_user_id'] ?? 0);
        $instructorUserId = (int)($input['instructor_user_id'] ?? 0);
        $customerName = trim((string)($input['customer_name'] ?? ''));
        $instructorName = trim((string)($input['instructor_name'] ?? ''));
        $registration = strtoupper(trim((string)($input['aircraft_registration'] ?? '')));
        $rateId = (int)($input['instructional_rate_id'] ?? 0);

        $flightHours = $this->hours($input['flight_instruction_hours'] ?? null, 0.0);
        $groundHours = $this->hours($input['ground_instruction_hours'] ?? null, self::DEFAULT_GROUND_HOURS);
        $preflightHours = $this->hours($input['preflight_briefing_hours'] ?? null, 0.0);

        $rate = $this->rateById($rateId);
        if ($rate === null && $rateId <= 0) {
            $rates = $this->instructionalRates();
            $rate = $rates[0] ?? null;
        }
        if ($rate === null) {
            throw new RuntimeException('Select an instructor rate.');
        }

        $rentalMap = $this->aircraftRentalRateMap();
        $rental = $rentalMap[$registration] ?? array(
            'display_label' => $registration !== '' ? $registration : 'Aircraft',
            'rate_usd_per_hour' => 0.0,
        );
        $aircraftRate = (float)($rental['rate_usd_per_hour'] ?? 0);
        $aircraftLabel = trim((string)($rental['display_label'] ?? '')) ?: $registration;
        $instrRate = (float)($rate['rate_usd_per_hour'] ?? 0);

        $aircraftTotal = round($flightHours * $aircraftRate, 2);
        $flightInstrTotal = round($flightHours * $instrRate, 2);
        $groundInstrTotal = round($groundHours * $instrRate, 2);
        $preflightTotal = round($preflightHours * $instrRate, 2);
        $sessionTotal = round($aircraftTotal + $flightInstrTotal + $groundInstrTotal + $preflightTotal, 2);
        $existingBalance = $customerUserId > 0 ? $this->balanceForUser($customerUserId) : 0.0;
        $grandTotal = round($existingBalance + $sessionTotal, 2);

        $overview = array(
            'aircraft_rental' => array(
                'label' => '#' . $registration . ' ' . $aircraftLabel,
                'hours' => $flightHours,
                'total' => $aircraftTotal,
            ),
            'instruction' => array(),
            'existing_balance' => $existingBalance,
            'session_total' => $sessionTotal,
            'grand_total' => $grandTotal,
        );
        if ($flightHours > 0) {
            $overview['instruction'][] = array(
                'label' => 'Flight Time (' . ($instructorName !== '' ? $instructorName : 'Instructor') . ')',
                'hours' => $flightHours,
                'total' => $flightInstrTotal,
            );
        }
        if ($groundHours > 0) {
            $overview['instruction'][] = array(
                'label' => 'Ground Instruction (' . ($instructorName !== '' ? $instructorName : 'Instructor') . ')',
                'hours' => $groundHours,
                'total' => $groundInstrTotal,
            );
        }
        if ($preflightHours > 0) {
            $overview['instruction'][] = array(
                'label' => 'Preflight Briefing (' . ($instructorName !== '' ? $instructorName : 'Instructor') . ')',
                'hours' => $preflightHours,
                'total' => $preflightTotal,
            );
        }

        return array(
            'workflow_flight_record_uuid' => trim((string)($input['workflow_flight_record_uuid'] ?? '')) ?: null,
            'customer_user_id' => $customerUserId > 0 ? $customerUserId : null,
            'customer_name' => $customerName,
            'instructor_user_id' => $instructorUserId > 0 ? $instructorUserId : null,
            'instructor_name' => $instructorName,
            'aircraft_registration' => $registration,
            'aircraft_label' => $aircraftLabel,
            'preflight_briefing_hours' => $preflightHours,
            'flight_instruction_hours' => $flightHours,
            'ground_instruction_hours' => $groundHours,
            'instructional_rate_id' => (int)($rate['id'] ?? 0),
            'instructional_rate_code' => (string)($rate['rate_code'] ?? ''),
            'instructional_rate_label' => (string)($rate['label'] ?? ''),
            'instructional_rate_usd_per_hour' => $instrRate,
            'aircraft_rate_usd_per_hour' => $aircraftRate,
            'aircraft_rental_total_usd' => $aircraftTotal,
            'flight_instruction_total_usd' => $flightInstrTotal,
            'ground_instruction_total_usd' => $groundInstrTotal,
            'session_total_usd' => $sessionTotal,
            'existing_balance_usd' => $existingBalance,
            'grand_total_usd' => $grandTotal,
            'overview' => $overview,
            'is_complete' => $this->isComplete(array(
                'customer_user_id' => $customerUserId,
                'instructor_user_id' => $instructorUserId,
                'instructional_rate_id' => (int)($rate['id'] ?? 0),
                'flight_instruction_hours' => $flightHours,
                'aircraft_registration' => $registration,
            )),
        );
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function isComplete(array $fields): bool
    {
        return (int)($fields['customer_user_id'] ?? 0) > 0
            && (int)($fields['instructor_user_id'] ?? 0) > 0
            && (int)($fields['instructional_rate_id'] ?? 0) > 0
            && trim((string)($fields['aircraft_registration'] ?? '')) !== ''
            && is_numeric($fields['flight_instruction_hours'] ?? null)
            && (float)$fields['flight_instruction_hours'] >= 0;
    }

    public function unlock(int $dispatchId, ?int $actorUserId, string $audience, string $unlockCode = '', string $reason = ''): array
    {
        $row = $this->forDispatch($dispatchId);
        if (!is_array($row)) {
            throw new RuntimeException('No financial dispatch found for this leg.');
        }
        if (($row['status'] ?? '') !== 'locked') {
            return $row;
        }
        $isAdmin = strtolower(trim($audience)) === 'admin';
        if (!$isAdmin) {
            $expected = trim((string)(getenv('CW_FINANCIAL_DISPATCH_UNLOCK_CODE') ?: self::DEFAULT_UNLOCK_CODE));
            if ($expected === '' || !hash_equals($expected, trim($unlockCode))) {
                throw new RuntimeException('Unlock code is invalid.');
            }
        }
        $statement = $this->pdo->prepare(
            'UPDATE ipca_cvr_financial_dispatches
             SET status = \'draft\',
                 unlocked_at = ?,
                 unlocked_by = ?,
                 unlock_reason = ?,
                 locked_at = NULL,
                 locked_by = NULL,
                 updated_by = ?,
                 updated_at = CURRENT_TIMESTAMP(3)
             WHERE dispatch_id = ?'
        );
        $statement->execute(array(
            $this->utcNow(),
            $actorUserId,
            trim($reason),
            $actorUserId,
            $dispatchId,
        ));
        $customerUserId = (int)($row['customer_user_id'] ?? 0);
        if ($customerUserId > 0) {
            $restoreBalance = round((float)($row['existing_balance_usd'] ?? 0), 2);
            $balanceStatement = $this->pdo->prepare(
                'INSERT INTO ipca_user_account_balances (user_id, balance_usd)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE balance_usd = VALUES(balance_usd), updated_at = CURRENT_TIMESTAMP(3)'
            );
            $balanceStatement->execute(array($customerUserId, $restoreBalance));
        }
        $updated = $this->forDispatch($dispatchId);
        if (!is_array($updated)) {
            throw new RuntimeException('Unlock failed.');
        }
        return $updated;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $overview = array();
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string)$row['payload_json'], true);
            if (is_array($decoded)) {
                $overview = $decoded;
            }
        }
        return array(
            'id' => (int)($row['id'] ?? 0),
            'dispatch_id' => (int)($row['dispatch_id'] ?? 0),
            'workflow_flight_record_uuid' => (string)($row['workflow_flight_record_uuid'] ?? ''),
            'customer_user_id' => isset($row['customer_user_id']) ? (int)$row['customer_user_id'] : null,
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'instructor_user_id' => isset($row['instructor_user_id']) ? (int)$row['instructor_user_id'] : null,
            'instructor_name' => (string)($row['instructor_name'] ?? ''),
            'aircraft_registration' => (string)($row['aircraft_registration'] ?? ''),
            'aircraft_label' => (string)($row['aircraft_label'] ?? ''),
            'preflight_briefing_hours' => (float)($row['preflight_briefing_hours'] ?? 0),
            'flight_instruction_hours' => (float)($row['flight_instruction_hours'] ?? 0),
            'ground_instruction_hours' => (float)($row['ground_instruction_hours'] ?? self::DEFAULT_GROUND_HOURS),
            'instructional_rate_id' => isset($row['instructional_rate_id']) ? (int)$row['instructional_rate_id'] : null,
            'instructional_rate_code' => (string)($row['instructional_rate_code'] ?? ''),
            'instructional_rate_label' => (string)($row['instructional_rate_label'] ?? ''),
            'instructional_rate_usd_per_hour' => (float)($row['instructional_rate_usd_per_hour'] ?? 0),
            'aircraft_rate_usd_per_hour' => (float)($row['aircraft_rate_usd_per_hour'] ?? 0),
            'aircraft_rental_total_usd' => (float)($row['aircraft_rental_total_usd'] ?? 0),
            'flight_instruction_total_usd' => (float)($row['flight_instruction_total_usd'] ?? 0),
            'ground_instruction_total_usd' => (float)($row['ground_instruction_total_usd'] ?? 0),
            'session_total_usd' => (float)($row['session_total_usd'] ?? 0),
            'existing_balance_usd' => (float)($row['existing_balance_usd'] ?? 0),
            'grand_total_usd' => (float)($row['grand_total_usd'] ?? 0),
            'status' => (string)($row['status'] ?? 'draft'),
            'locked_at' => (string)($row['locked_at'] ?? ''),
            'locked_by' => isset($row['locked_by']) ? (int)$row['locked_by'] : null,
            'overview' => $overview,
            'is_locked' => strtolower((string)($row['status'] ?? '')) === 'locked',
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function rateById(int $rateId): ?array
    {
        if ($rateId <= 0) {
            return null;
        }
        foreach ($this->instructionalRates() as $rate) {
            if ((int)$rate['id'] === $rateId) {
                return $rate;
            }
        }
        return null;
    }

    private function applyBalanceOnLock(int $customerUserId, float $grandTotal): void
    {
        if ($customerUserId <= 0) {
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO ipca_user_account_balances (user_id, balance_usd)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE balance_usd = VALUES(balance_usd), updated_at = CURRENT_TIMESTAMP(3)'
        );
        $statement->execute(array($customerUserId, round($grandTotal, 2)));
    }

    private function hours(mixed $value, float $default): float
    {
        if ($value === null || $value === '') {
            return round($default, 1);
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('Hours must be numeric.');
        }
        $hours = (float)$value;
        if ($hours < 0) {
            throw new RuntimeException('Hours cannot be negative.');
        }
        return round($hours, 1);
    }

    private function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }

    private function ensureTables(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        // Tables are created by migration; tolerate missing during early boot by no-op.
        $statement = $this->pdo->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'ipca_cvr_financial_dispatches' LIMIT 1"
        );
        if (!$statement || !$statement->fetchColumn()) {
            throw new RuntimeException('Apply scripts/sql/2026_08_06_cvr_financial_dispatch.sql first.');
        }
        $ready = true;
    }
}
