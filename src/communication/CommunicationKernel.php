<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationAuthService.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/MessageService.php';
require_once __DIR__ . '/CommunicationSyncService.php';

final class CommunicationKernel
{
    public CommunicationConfigService $config;
    public CommunicationAuthService $auth;
    public ConversationService $conversations;
    public MessageService $messages;
    public CommunicationSyncService $sync;

    public function __construct(PDO $pdo)
    {
        $this->config = new CommunicationConfigService($pdo);
        $this->auth = new CommunicationAuthService($pdo, $this->config);
        $this->conversations = new ConversationService($pdo, $this->auth, $this->config);
        $this->messages = new MessageService($pdo, $this->conversations);
        $this->sync = new CommunicationSyncService($pdo, $this->conversations, $this->messages, $this->config);
    }
}
