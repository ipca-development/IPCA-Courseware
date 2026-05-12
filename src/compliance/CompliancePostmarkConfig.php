<?php
declare(strict_types=1);

/**
 * Compliance Communications Center — configuration helper.
 *
 * Reads every Postmark / Compliance-Inbox related setting from environment
 * variables. Both `CW_*` and bare `*` names are accepted so ops can use
 * either platform-style or vendor-style env names.
 *
 * NEVER returns raw secrets in helper output that lands in the UI. Callers
 * that need to display a value must use the maskedToken() helper.
 */
final class CompliancePostmarkConfig
{
    public static function serverToken(): string
    {
        return self::firstEnv(array('CW_POSTMARK_SERVER_TOKEN', 'POSTMARK_SERVER_TOKEN'));
    }

    public static function outboundStream(): string
    {
        $v = self::firstEnv(array('CW_POSTMARK_OUTBOUND_STREAM', 'POSTMARK_OUTBOUND_STREAM'));

        return $v !== '' ? $v : 'outbound';
    }

    public static function inboundWebhookSecret(): string
    {
        return self::firstEnv(array('CW_POSTMARK_INBOUND_WEBHOOK_SECRET', 'POSTMARK_INBOUND_WEBHOOK_SECRET'));
    }

    public static function trackingWebhookSecret(): string
    {
        return self::firstEnv(array('CW_POSTMARK_TRACKING_WEBHOOK_SECRET', 'POSTMARK_TRACKING_WEBHOOK_SECRET'));
    }

    public static function complianceInboxAddress(): string
    {
        return self::firstEnv(array('CW_COMPLIANCE_INBOX_ADDRESS', 'COMPLIANCE_INBOX_ADDRESS'));
    }

    public static function complianceFromAddress(): string
    {
        $v = self::firstEnv(array('CW_COMPLIANCE_POSTMARK_FROM', 'COMPLIANCE_POSTMARK_FROM'));
        if ($v !== '') {
            return $v;
        }

        return self::complianceInboxAddress();
    }

    /**
     * Public base URL of the platform, e.g. https://ipca.training. Used to
     * synthesise webhook URLs for the Settings/Help panel.
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
