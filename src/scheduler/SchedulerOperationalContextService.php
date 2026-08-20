<?php
declare(strict_types=1);

require_once __DIR__ . '/../flight_training/AirportDataService.php';
require_once __DIR__ . '/../tv_kiosk_config.php';

final class SchedulerOperationalContextService
{
    public const SOURCE = 'tv_kiosk_config';
    public const ASTRONOMY_METHOD = 'php_date_sun_info_civil_twilight_v1';

    /** @var callable():array<string,mixed> */
    private $configProvider;

    /**
     * @param null|callable():array<string,mixed> $configProvider
     */
    public function __construct(
        private PDO $pdo,
        private string $operationalTimezone,
        ?callable $configProvider = null
    ) {
        $this->configProvider = $configProvider ?? static fn(): array => tv_kiosk_config();
    }

    /** @return array<string,mixed> */
    public function homeBase(int $organizationId): array
    {
        $config = ($this->configProvider)();
        $identifier = strtoupper(trim((string)($config['home_airport'] ?? '')));
        if ($identifier === '') {
            throw new RuntimeException('The canonical online-operations home airport is not configured.');
        }

        $airport = $this->airport($identifier);
        $latitude = $airport['latitude_deg'] ?? $config['gate_lat'] ?? null;
        $longitude = $airport['longitude_deg'] ?? $config['gate_lon'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new RuntimeException('The canonical home airport has no usable coordinates.');
        }

        $airportId = isset($airport['id']) && (int)$airport['id'] > 0
            ? (int)$airport['id']
            : null;
        $displayName = trim((string)($airport['full_name'] ?? $config['gate_label'] ?? $identifier));

        return array(
            'id' => $airportId,
            'organization_id' => $organizationId,
            'display_name' => $displayName !== '' ? $displayName : $identifier,
            'airport_identifier' => $identifier,
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
            'operational_timezone' => $this->operationalTimezone,
            'source' => self::SOURCE,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function astronomyDays(
        int $organizationId,
        string $startDate,
        string $endDate
    ): array {
        $base = $this->homeBase($organizationId);
        $timezone = new DateTimeZone($this->operationalTimezone);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);
        if (!$day || !$last || $day > $last) {
            throw new InvalidArgumentException('A valid astronomy date range is required.');
        }

        $days = array();
        while ($day <= $last) {
            $date = $day->format('Y-m-d');
            $reference = new DateTimeImmutable($date . ' 12:00:00', $timezone);
            $sun = date_sun_info(
                $reference->getTimestamp(),
                (float)$base['latitude'],
                (float)$base['longitude']
            );
            $days[] = array(
                'date' => $date,
                'morning_civil_twilight_begin' => $this->localEvent(
                    $sun['civil_twilight_begin'] ?? null,
                    $timezone
                ),
                'sunrise' => $this->localEvent($sun['sunrise'] ?? null, $timezone),
                'sunset' => $this->localEvent($sun['sunset'] ?? null, $timezone),
                'evening_civil_twilight_end' => $this->localEvent(
                    $sun['civil_twilight_end'] ?? null,
                    $timezone
                ),
                'operational_timezone' => $this->operationalTimezone,
                'location_id' => $base['id'],
                'airport_identifier' => $base['airport_identifier'],
                'calculation_method' => self::ASTRONOMY_METHOD,
            );
            $day = $day->modify('+1 day');
        }
        return $days;
    }

    /** @return array<string,mixed>|null */
    private function airport(string $identifier): ?array
    {
        try {
            return (new AirportDataService($this->pdo))->lookupAirport($identifier, false);
        } catch (Throwable) {
            return null;
        }
    }

    private function localEvent(mixed $timestamp, DateTimeZone $timezone): ?string
    {
        if (!is_int($timestamp)) {
            return null;
        }
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone)
            ->format('Y-m-d\TH:i:s.v');
    }
}
