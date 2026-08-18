<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationSupport.php';

final class CommunicationConfigService
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $key, string $default = '0'): string
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function enabled(string $key): bool
    {
        $value = strtolower(trim($this->get($key, '0')));
        return in_array($value, array('1', 'true', 'yes', 'on'), true);
    }

    /**
     * @return array<string,mixed>
     */
    public function capabilities(): array
    {
        return array(
            'protocol_version' => (int)$this->get('protocol_version', '1'),
            'min_app_version' => $this->get('min_app_version', '1.0.0'),
            'min_ios_version' => $this->get('min_ios_version', '17.0'),
            'messaging_enabled' => $this->enabled('messaging_enabled'),
            'groups_enabled' => $this->enabled('groups_enabled'),
            'attachments_enabled' => $this->enabled('attachments_enabled'),
            'system_messages_enabled' => $this->enabled('system_messages_enabled'),
            'training_enabled' => $this->enabled('training_enabled'),
            'training_videos_enabled' => $this->enabled('training_videos_enabled'),
            'community_enabled' => $this->enabled('community_enabled'),
            'community_posting_enabled' => $this->enabled('community_posting_enabled'),
            'safety_reporting_enabled' => $this->enabled('safety_reporting_enabled'),
            'anonymous_reporting_enabled' => $this->enabled('anonymous_reporting_enabled'),
        );
    }

    public function requireMessaging(): void
    {
        if (!$this->enabled('messaging_enabled')) {
            throw new CommunicationException('messaging_disabled', 'Messaging is currently unavailable.', 403);
        }
    }

    public function requireGroups(): void
    {
        $this->requireMessaging();
        if (!$this->enabled('groups_enabled')) {
            throw new CommunicationException('groups_disabled', 'Group messaging is currently unavailable.', 403);
        }
    }

    public function requireAttachments(): void
    {
        $this->requireMessaging();
        if (!$this->enabled('attachments_enabled')) {
            throw new CommunicationException('attachments_disabled', 'Attachments are currently unavailable.', 403);
        }
    }

    public function requireSystemMessages(): void
    {
        $this->requireMessaging();
        if (!$this->enabled('system_messages_enabled')) {
            throw new CommunicationException('system_messages_disabled', 'Official messages are currently unavailable.', 403);
        }
    }

    public function requireTraining(): void
    {
        if (!$this->enabled('training_enabled')) {
            throw new CommunicationException('training_disabled', 'Training is currently unavailable.', 403);
        }
    }

    public function requireTrainingVideos(): void
    {
        if (!$this->enabled('training_videos_enabled')) {
            throw new CommunicationException('training_videos_disabled', 'Training Videos are currently unavailable.', 403);
        }
    }

    public function requireCommunity(): void
    {
        if (!$this->enabled('community_enabled')) {
            throw new CommunicationException('community_disabled', 'Community is currently unavailable.', 403);
        }
    }

    public function requireCommunityPosting(): void
    {
        $this->requireCommunity();
        if (!$this->enabled('community_posting_enabled')) {
            throw new CommunicationException('community_posting_disabled', 'Community posting is currently unavailable.', 403);
        }
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = array();
        try {
            $rows = $this->pdo->query('SELECT config_key, config_value FROM ipca_communication_app_config');
            if ($rows === false) {
                return $this->cache;
            }
            foreach ($rows as $row) {
                $this->cache[(string)$row['config_key']] = (string)$row['config_value'];
            }
        } catch (Throwable) {
            $this->cache = array();
        }
        return $this->cache;
    }
}
