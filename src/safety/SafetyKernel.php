<?php
declare(strict_types=1);

require_once __DIR__ . '/SafetySupport.php';
require_once __DIR__ . '/SafetyFeatureConfigService.php';
require_once __DIR__ . '/SafetyAccessService.php';
require_once __DIR__ . '/SafetyAuditEventService.php';
require_once __DIR__ . '/SafetyOccurrenceIntakeContextService.php';
require_once __DIR__ . '/SafetyIntakeService.php';
require_once __DIR__ . '/SafetyAnonymousService.php';
require_once __DIR__ . '/SafetyWorkflowService.php';
require_once __DIR__ . '/SafetyDomainServices.php';
require_once __DIR__ . '/SafetyAnalyticsService.php';
require_once __DIR__ . '/SafetyAiGovernanceService.php';
require_once __DIR__ . '/SafetyAttachmentService.php';
require_once __DIR__ . '/SafetyStaffService.php';
require_once __DIR__ . '/SafetyReporterVaultService.php';
require_once __DIR__ . '/SafetyEccairsService.php';

final class SafetyKernel
{
    public SafetyFeatureConfigService $config;
    public SafetyAccessService $access;
    public SafetyAuditEventService $events;
    public SafetyRateLimitService $rateLimits;
    public SafetyOccurrenceIntakeContextService $occurrenceIntakeContext;
    public SafetyIntakeService $intake;
    public SafetyAnonymousService $anonymous;
    public SafetyWorkflowService $workflow;
    public SafetyReportabilityService $reportability;
    public SafetyRiskHazardService $risk;
    public SafetyInvestigationService $investigations;
    public SafetyActionService $actions;
    public SafetyFeedbackService $feedback;
    public SafetyAnalyticsService $analytics;
    public SafetyAiGovernanceService $ai;
    public SafetyAttachmentService $attachments;
    public SafetyStaffService $staff;
    public SafetyReporterVaultService $reporterVault;
    public SafetyEccairsConfig $eccairsConfig;
    public SafetyEccairsMapper $eccairsMapper;
    public SafetyEccairsRestSerializer $eccairsRestSerializer;
    public SafetyEccairsApiClient $eccairsClient;
    public SafetyEccairsService $eccairs;

    public function __construct(
        PDO $pdo,
        ?CommunicationObjectStore $objectStore = null,
        ?CommunicationPushService $pushService = null
    )
    {
        $this->config = new SafetyFeatureConfigService($pdo);
        $this->access = new SafetyAccessService($pdo);
        $this->events = new SafetyAuditEventService($pdo);
        $this->rateLimits = new SafetyRateLimitService($pdo);
        $this->occurrenceIntakeContext = new SafetyOccurrenceIntakeContextService($pdo, $this->config);
        $this->intake = new SafetyIntakeService(
            $pdo,
            $this->access,
            $this->events,
            $this->config,
            $this->occurrenceIntakeContext
        );
        $this->anonymous = new SafetyAnonymousService(
            $pdo,
            $this->events,
            $this->config,
            $this->rateLimits,
            $this->occurrenceIntakeContext
        );
        $this->workflow = new SafetyWorkflowService($pdo, $this->access, $this->events);
        $this->reportability = new SafetyReportabilityService($pdo, $this->access, $this->events);
        $this->risk = new SafetyRiskHazardService($pdo, $this->access, $this->events);
        $this->investigations = new SafetyInvestigationService($pdo, $this->access, $this->events);
        $this->actions = new SafetyActionService($pdo, $this->access, $this->events);
        $this->feedback = new SafetyFeedbackService($pdo, $this->access, $this->events, $pushService);
        $this->analytics = new SafetyAnalyticsService($pdo, $this->access, $this->events);
        $this->ai = new SafetyAiGovernanceService($pdo, $this->access, $this->config, $this->events);
        $store = $objectStore ?? CommunicationSpacesObjectStore::tryFromEnvironment()
            ?? new CommunicationMemoryObjectStore();
        $this->attachments = new SafetyAttachmentService($pdo, $this->access, $this->config, $store);
        $this->staff = new SafetyStaffService(
            $pdo,
            $this->access,
            $this->events,
            $this->occurrenceIntakeContext
        );
        $this->reporterVault = new SafetyReporterVaultService($pdo, $this->access, $this->events);
        $this->eccairsConfig = new SafetyEccairsConfig($pdo);
        $this->eccairsMapper = new SafetyEccairsMapper($pdo);
        $this->eccairsRestSerializer = new SafetyEccairsRestSerializer();
        $this->eccairsClient = new SafetyEccairsApiClient($this->eccairsConfig);
        $this->eccairs = new SafetyEccairsService(
            $pdo,
            $this->access,
            $this->events,
            $this->eccairsConfig,
            $this->eccairsMapper,
            $this->eccairsRestSerializer,
            $this->eccairsClient
        );
    }
}
