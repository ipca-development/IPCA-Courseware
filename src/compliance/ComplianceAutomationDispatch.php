<?php
declare(strict_types=1);

/**
 * Shared fire-and-forget dispatch for Compliance-domain automation events.
 *
 * All compliance engines call this helper instead of touching AutomationRuntime
 * directly so we get one place for: defensive include, try/catch, and event-key
 * normalisation. Failures here MUST NOT roll back the originating DB write.
 */
final class ComplianceAutomationDispatch
{
    /**
     * @param array<string,mixed> $context
     */
    public static function fire(PDO $pdo, string $eventKey, array $context = array()): void
    {
        $eventKey = trim($eventKey);
        if ($eventKey === '') {
            return;
        }

        $path = __DIR__ . '/../automation_runtime.php';
        if (!is_file($path)) {
            return;
        }

        require_once $path;

        try {
            $rt = new AutomationRuntime();
            $rt->dispatchEvent($pdo, $eventKey, $context);
        } catch (Throwable $e) {
            // non-fatal — primary record has already been committed.
        }
    }
}
