<?php
declare(strict_types=1);

/**
 * Compliance Communications Center — configuration helper.
 *
 * Reads every Postmark / Compliance-Inbox related setting via plain `getenv()`
 * — the same convention used by src/db.php (CW_DB_*) and src/spaces.php
 * (CW_SPACES_*). On this platform env vars are injected by:
 *
 *   - PHP-FPM pool configuration   (e.g. /etc/php/8.3/fpm/pool.d/www.conf)
 *     for web requests / webhooks, and
 *   - /etc/ipca/ipca-courseware-cli.env (sourced before CLI scripts)
 *     for cron jobs / one-shot scripts.
 *
 * NO .env file is read. NO dotenv package is loaded.
 *
 * Canonical names follow the existing platform convention:
 *   - Platform/DB/Infra vars carry the CW_* prefix    (CW_DB_HOST, CW_SPACES_*).
 *   - Third-party integration vars are bare           (POSTMARK_*, OPENAI_API_KEY).
 *
 * The helper accepts both the bare name (canonical) AND the CW_-prefixed
 * variant (legacy alias) so an environment that already uses CW_POSTMARK_*
 * keeps working. New environments should set the bare names only.
 *
 * NEVER returns raw secrets in helper output that lands in the UI. Callers
 * that need to display a value must use the maskedToken() helper.
 */
final class CompliancePostmarkConfig
{
    public static function serverToken(): string
    {
        return self::firstEnv(array('POSTMARK_SERVER_TOKEN', 'CW_POSTMARK_SERVER_TOKEN'));
    }

    public static function outboundStream(): string
    {
        $v = self::firstEnv(array('POSTMARK_OUTBOUND_STREAM', 'CW_POSTMARK_OUTBOUND_STREAM'));

        return $v !== '' ? $v : 'outbound';
    }

    public static function inboundWebhookSecret(): string
    {
        return self::firstEnv(array(
            'POSTMARK_INBOUND_WEBHOOK_SECRET',
            'POSTMARK_WEBHOOK_SECRET',
            'CW_POSTMARK_INBOUND_WEBHOOK_SECRET',
        ));
    }

    public static function trackingWebhookSecret(): string
    {
        return self::firstEnv(array(
            'POSTMARK_TRACKING_WEBHOOK_SECRET',
            'POSTMARK_WEBHOOK_SECRET',
            'CW_POSTMARK_TRACKING_WEBHOOK_SECRET',
        ));
    }

    public static function complianceInboxAddress(): string
    {
        return self::firstEnv(array('COMPLIANCE_INBOX_ADDRESS', 'CW_COMPLIANCE_INBOX_ADDRESS'));
    }

    public static function complianceFromAddress(): string
    {
        $v = self::firstEnv(array('COMPLIANCE_POSTMARK_FROM', 'CW_COMPLIANCE_POSTMARK_FROM'));
        if ($v !== '') {
            return $v;
        }

        return self::complianceInboxAddress();
    }

    /**
     * Public base URL of the platform, e.g. https://ipca.training. Used to
     * synthesise webhook URLs for the Inbox help panel. CW_-prefixed because
     * this is a platform-infra setting, not a third-party integration.
     */
    public static function publicBaseUrl(): string
    {
        $v = self::firstEnv(array('CW_PUBLIC_BASE_URL', 'PUBLIC_BASE_URL', 'APP_URL'));
        if ($v !== '') {
            return rtrim($v, '/');
        }

        return '';
    }

    /**
     * The URL Postmark should POST inbound webhooks to. The secret travels as
     * a query parameter so the URL itself is the entire credential — keep it
     * private when handing to Postmark's admin panel.
     */
    public static function inboundWebhookUrl(): string
    {
        $base = self::publicBaseUrl();
        $secret = self::inboundWebhookSecret();
        if ($base === '' || $secret === '') {
            return '';
        }

        return $base . '/webhooks/postmark/compliance-inbound.php?token=' . rawurlencode($secret);
    }

    public static function trackingWebhookUrl(): string
    {
        $base = self::publicBaseUrl();
        $secret = self::trackingWebhookSecret();
        if ($base === '' || $secret === '') {
            return '';
        }

        return $base . '/webhooks/postmark/compliance-events.php?token=' . rawurlencode($secret);
    }

    /**
     * Verify a webhook request's shared secret in constant time.
     * The secret may arrive as ?token=… or as the `X-Compliance-Webhook-Token`
     * header. Either is accepted.
     */
    public static function verifyWebhookSecret(string $expected, ?string $providedQueryToken, ?string $providedHeader): bool
    {
        if ($expected === '') {
            return false;
        }
        foreach (array((string)$providedQueryToken, (string)$providedHeader) as $candidate) {
            if ($candidate !== '' && hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a UI-safe masked rendering of a token: shows the first 3 and last
     * 2 characters of a token longer than 6, otherwise just `***`. Used for
     * status panels; never log the raw token.
     */
    public static function maskedToken(string $token): string
    {
        $t = trim($token);
        $len = strlen($t);
        if ($len === 0) {
            return '(not set)';
        }
        if ($len <= 6) {
            return '***';
        }

        return substr($t, 0, 3) . str_repeat('•', max(4, $len - 5)) . substr($t, -2);
    }

    /**
     * Summary used by the inbox help/settings card so admins can sanity-check
     * the deployment without seeing raw secrets.
     *
     * @return array<string,string|bool>
     */
    public static function publicSummary(): array
    {
        return array(
            'inbox_address' => self::complianceInboxAddress(),
            'from_address' => self::complianceFromAddress(),
            'outbound_stream' => self::outboundStream(),
            'server_token_set' => self::serverToken() !== '',
            'server_token_masked' => self::maskedToken(self::serverToken()),
            'inbound_secret_set' => self::inboundWebhookSecret() !== '',
            'inbound_secret_masked' => self::maskedToken(self::inboundWebhookSecret()),
            'tracking_secret_set' => self::trackingWebhookSecret() !== '',
            'tracking_secret_masked' => self::maskedToken(self::trackingWebhookSecret()),
            'inbound_webhook_url' => self::inboundWebhookUrl(),
            'tracking_webhook_url' => self::trackingWebhookUrl(),
            'public_base_url' => self::publicBaseUrl(),
        );
    }

    private static function firstEnv(array $names): string
    {
        foreach ($names as $n) {
            $v = getenv($n);
            if (is_string($v) && $v !== '') {
                return trim($v);
            }
        }

        return '';
    }
}
