<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationAuthService.php';
require_once __DIR__ . '/ConversationService.php';
require_once __DIR__ . '/MessageService.php';
require_once __DIR__ . '/CommunicationSyncService.php';
require_once __DIR__ . '/CommunicationPushService.php';
require_once __DIR__ . '/CommunicationApnsClient.php';
require_once __DIR__ . '/CommunicationObjectStore.php';
require_once __DIR__ . '/CommunicationAttachmentService.php';
require_once __DIR__ . '/CommunicationSystemMessageService.php';
require_once __DIR__ . '/CommunicationTrainingService.php';
require_once __DIR__ . '/CommunicationCommunityService.php';
require_once __DIR__ . '/CommunicationEnrollmentService.php';
require_once __DIR__ . '/CommunicationTrainingVideoService.php';

final class CommunicationKernel
{
    public CommunicationConfigService $config;
    public CommunicationAuthService $auth;
    public ConversationService $conversations;
    public MessageService $messages;
    public CommunicationSyncService $sync;
    public CommunicationPushService $push;
    public CommunicationAttachmentService $attachments;
    public CommunicationObjectStore $objectStore;
    public CommunicationSystemMessageService $systemMessages;
    public CommunicationTrainingService $training;
    public CommunicationCommunityService $community;
    public CommunicationEnrollmentService $enrollment;
    public CommunicationTrainingVideoService $trainingVideos;

    public function __construct(PDO $pdo, ?CommunicationObjectStore $objectStore = null)
    {
        $this->config = new CommunicationConfigService($pdo);
        $this->auth = new CommunicationAuthService($pdo, $this->config);
        $this->conversations = new ConversationService($pdo, $this->auth, $this->config);
        $this->push = new CommunicationPushService($pdo, $this->conversations, $this->config, CommunicationApnsClient::fromEnvironment());
        $this->objectStore = $objectStore ?? CommunicationSpacesObjectStore::tryFromEnvironment() ?? new CommunicationMemoryObjectStore();
        $this->attachments = new CommunicationAttachmentService($pdo, $this->conversations, $this->config, $this->objectStore);
        $this->messages = new MessageService($pdo, $this->conversations, $this->push, $this->attachments);
        $this->systemMessages = new CommunicationSystemMessageService($pdo, $this->config, $this->auth, $this->conversations, $this->messages, $this->push);
        $this->training = new CommunicationTrainingService($pdo, $this->config);
        $this->community = new CommunicationCommunityService($pdo, $this->config, $this->auth, $this->objectStore, $this->push);
        $this->enrollment = new CommunicationEnrollmentService($pdo);
        $this->trainingVideos = new CommunicationTrainingVideoService($pdo, $this->config, $this->objectStore);
        $this->sync = new CommunicationSyncService($pdo, $this->conversations, $this->messages, $this->config, $this->push, $this->systemMessages);
    }
}
