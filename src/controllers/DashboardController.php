<?php

namespace Klick\Agents\controllers;

use Craft;
use Klick\Agents\Plugin;
use Klick\Agents\models\Settings;
use craft\elements\Entry;
use craft\elements\User;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DashboardController extends Controller
{
    private const SESSION_REVEALED_CREDENTIAL = 'agents.revealedCredential';
    private const SESSION_CONTROL_SIMULATION = 'agents.controlSimulation';
    private const CONTROL_TABS = ['approvals', 'rules'];

    public function actionIndex(): Response
    {
        return $this->redirect('agents/accounts');
    }

    public function actionDashboard(): Response
    {
        $request = Craft::$app->getRequest();
        $pathInfo = trim((string)$request->getPathInfo(), '/');
        if ($pathInfo === 'agents') {
            return $this->redirect('agents/accounts');
        }

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $readinessService = $plugin->getReadinessService();
        $securityPosture = $plugin->getSecurityPolicyService()->getCpPosture();

        $readinessHealth = $readinessService->getHealthSummary();
        $readinessSummary = $readinessService->getReadinessSummary();
        $readinessDiagnostics = $readinessService->getReadinessDiagnostics();
        $consumerLagSnapshot = [
            'summary' => [
                'count' => 0,
                'healthy' => 0,
                'warning' => 0,
                'critical' => 0,
                'maxLagSeconds' => 0,
            ],
            'rows' => [],
        ];
        try {
            $consumerLagSnapshot = $plugin->getConsumerLagService()->getLagSummary(100);
        } catch (Throwable $e) {
            Craft::warning('Unable to load consumer lag summary for CP: ' . $e->getMessage(), __METHOD__);
        }
        $webhookDeadLetters = [];
        try {
            $webhookDeadLetters = $plugin->getWebhookService()->getDeadLetterEvents([], 20);
        } catch (Throwable $e) {
            Craft::warning('Unable to load webhook DLQ events for CP: ' . $e->getMessage(), __METHOD__);
        }
        $webhookTestSinkSnapshot = [
            'config' => [
                'requested' => false,
                'devMode' => false,
                'enabled' => false,
                'path' => 'agents/dev/webhook-test-sink',
                'url' => '',
                'webhookUrlConfigured' => false,
                'webhookSecretConfigured' => false,
                'webhookUrlMatchesSink' => false,
                'storageReady' => false,
                'retentionLimit' => 200,
            ],
            'summary' => [
                'total' => 0,
                'valid' => 0,
                'invalid' => 0,
                'unsigned' => 0,
                'secretMissing' => 0,
                'lastCapturedAt' => null,
            ],
            'events' => [],
        ];
        try {
            $webhookTestSinkSnapshot = $plugin->getWebhookTestSinkService()->getCpSnapshot(20);
        } catch (Throwable $e) {
            Craft::warning('Unable to load webhook test sink snapshot for CP: ' . $e->getMessage(), __METHOD__);
        }
        $webhookProbeSnapshot = [
            'config' => [
                'urlConfigured' => false,
                'secretConfigured' => false,
                'enabled' => false,
                'targetUrl' => '',
                'storageReady' => false,
                'cooldownSeconds' => 300,
                'cooldownActive' => false,
                'cooldownRemainingSeconds' => 0,
                'nextAllowedAt' => null,
            ],
            'summary' => [
                'total' => 0,
                'delivered' => 0,
                'failed' => 0,
                'lastAttemptAt' => null,
                'lastDeliveredAt' => null,
                'lastFailedAt' => null,
            ],
            'runs' => [],
        ];
        try {
            $webhookProbeSnapshot = $plugin->getWebhookProbeService()->getCpSnapshot(10);
        } catch (Throwable $e) {
            Craft::warning('Unable to load webhook probe snapshot for CP: ' . $e->getMessage(), __METHOD__);
        }
        $notificationSnapshot = [
            'config' => [
                'enabled' => false,
                'recipients' => [],
                'recipientCount' => 0,
                'eventToggles' => [],
            ],
            'summary' => [
                'total' => 0,
                'queued' => 0,
                'sent' => 0,
                'failed' => 0,
                'lastSentAt' => null,
                'lastFailureAt' => null,
            ],
            'systemMonitor' => [
                'status' => 'unknown',
                'payload' => [],
            ],
            'recentNotifications' => [],
        ];
        try {
            $notificationSnapshot = $plugin->getNotificationService()->getCpSnapshot(12);
        } catch (Throwable $e) {
            Craft::warning('Unable to load operator notifications snapshot for CP: ' . $e->getMessage(), __METHOD__);
        }
        $observabilitySnapshot = [
            'service' => 'agents',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'format' => 'json-metric-series',
            'metrics' => [],
        ];
        try {
            $observabilitySnapshot = $plugin->getObservabilityMetricsService()->getMetricsSnapshot();
        } catch (Throwable $e) {
            Craft::warning('Unable to load observability metrics snapshot for CP: ' . $e->getMessage(), __METHOD__);
        }
        $observabilitySummary = $this->buildObservabilitySummary($observabilitySnapshot);

        return $this->renderCpTemplate('agents/dashboard', [
            'activeDashboardTab' => 'readiness',
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'securityWarningCounts' => (array)($securityPosture['warningCounts'] ?? []),
            'apiEndpoints' => $this->getApiEndpoints(),
            'readinessHealth' => $readinessHealth,
            'readinessSummary' => $readinessSummary,
            'readinessDiagnostics' => $readinessDiagnostics,
            'readinessHealthJson' => $this->prettyPrintJson($readinessHealth),
            'readinessSummaryJson' => $this->prettyPrintJson($readinessSummary),
            'readinessDiagnosticsJson' => $this->prettyPrintJson($readinessDiagnostics),
            'consumerLagSummary' => (array)($consumerLagSnapshot['summary'] ?? []),
            'consumerLagRows' => (array)($consumerLagSnapshot['rows'] ?? []),
            'observabilitySummary' => $observabilitySummary,
            'observabilitySnapshotJson' => $this->prettyPrintJson($observabilitySnapshot),
            'webhookDeadLetters' => $webhookDeadLetters,
            'webhookTestSinkSnapshot' => $webhookTestSinkSnapshot,
            'webhookProbeSnapshot' => $webhookProbeSnapshot,
            'notificationSnapshot' => $notificationSnapshot,
            'securityPosture' => $securityPosture,
        ]);
    }

    public function actionOverview(): Response
    {
        return $this->redirect('agents/status');
    }

    public function actionReadiness(): Response
    {
        return $this->redirect('agents/status');
    }

    public function actionSecurity(): Response
    {
        return $this->redirect('agents/status#securitySnapshotSection');
    }

    public function actionControl(): Response
    {
        $this->requireControlCpEnabled();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_VIEW);

        $plugin = Plugin::getInstance();
        $settings = $this->getSettingsModel();
        $enabledState = $plugin->getAgentsEnabledState();
        $activeControlTab = $this->resolveControlTab();
        $controlTabs = $this->controlTabs();
        $snapshot = $plugin->getControlPlaneService()->getControlPlaneSnapshot(25);
        $policies = (array)($snapshot['policies'] ?? []);
        $approvals = (array)($snapshot['approvals'] ?? []);
        $executions = (array)($snapshot['executions'] ?? []);
        $auditEvents = (array)($snapshot['audit'] ?? []);

        $actionTypes = [];
        foreach ($approvals as $approval) {
            $actionType = trim((string)($approval['actionType'] ?? ''));
            if ($actionType !== '') {
                $actionTypes[] = $actionType;
            }
        }

        foreach ($executions as $execution) {
            $actionType = trim((string)($execution['actionType'] ?? ''));
            if ($actionType !== '') {
                $actionTypes[] = $actionType;
            }
        }

        $policyHintsByActionType = $this->buildPolicyHintsByActionType($actionTypes);
        $approvalsWithPolicy = $this->appendPolicyHints($approvals, $policyHintsByActionType);
        $executionsWithPolicy = $this->appendPolicyHints($executions, $policyHintsByActionType);
        $executionsWithPolicy = $this->decorateControlItemsWithActionLabels($executionsWithPolicy);
        $latestExecutionByApprovalId = [];
        foreach ($executionsWithPolicy as $execution) {
            $approvalId = (int)($execution['approvalId'] ?? 0);
            if ($approvalId <= 0 || isset($latestExecutionByApprovalId[$approvalId])) {
                continue;
            }

            $latestExecutionByApprovalId[$approvalId] = $execution;
        }

        foreach ($approvalsWithPolicy as $index => $approval) {
            $approvalId = (int)($approval['id'] ?? 0);
            if ($approvalId > 0 && isset($latestExecutionByApprovalId[$approvalId])) {
                $approval['latestExecution'] = $latestExecutionByApprovalId[$approvalId];
            }
            $approvalsWithPolicy[$index] = $approval;
        }

        $approvalsWithPolicy = $this->decorateControlItemsWithActionLabels($approvalsWithPolicy);
        $credentialDisplayByActorId = $this->buildControlCredentialDisplayMap($plugin);
        $approvalsWithPolicy = $this->decorateControlApprovalsWithActorLabels($approvalsWithPolicy, $credentialDisplayByActorId);
        $auditEvents = $this->decorateControlAuditEventsWithActorLabels($auditEvents, $credentialDisplayByActorId);

        $pendingApprovals = [];
        $approvedApprovals = [];
        $completedApprovals = [];
        foreach ($approvalsWithPolicy as $approval) {
            $status = strtolower(trim((string)($approval['status'] ?? '')));
            if ($status === 'pending') {
                $pendingApprovals[] = $approval;
            } elseif ($status === 'approved') {
                $approvalId = (int)($approval['id'] ?? 0);
                if ($approvalId > 0) {
                    $latestExecution = $latestExecutionByApprovalId[$approvalId] ?? null;
                    $approval['latestExecution'] = $latestExecution;
                    $completionState = $this->resolveControlApprovalCompletionState($approval);
                    $approval['completionState'] = $completionState;
                    if ((bool)($completionState['completed'] ?? false)) {
                        $completedApprovals[] = $approval;
                    } else {
                        $approvedApprovals[] = $approval;
                    }
                }
            }
        }

        $attentionExecutions = [];
        foreach ($executionsWithPolicy as $execution) {
            $status = strtolower(trim((string)($execution['status'] ?? '')));
            if (in_array($status, ['blocked', 'failed'], true)) {
                $attentionExecutions[] = $execution;
            }
        }

        return $this->renderCpTemplate('agents/control', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'controlSummary' => (array)($snapshot['summary'] ?? []),
            'controlPolicies' => $policies,
            'controlEditingPolicy' => $this->resolveEditingControlPolicy($policies),
            'controlApprovals' => $approvalsWithPolicy,
            'controlExecutions' => $executionsWithPolicy,
            'controlAuditEvents' => $auditEvents,
            'controlPendingApprovals' => $pendingApprovals,
            'controlApprovedApprovals' => $approvedApprovals,
            'controlCompletedApprovals' => $completedApprovals,
            'controlAttentionExecutions' => $attentionExecutions,
            'controlPolicyHintsByActionType' => $policyHintsByActionType,
            'controlSimulationResult' => $this->pullControlSimulationResult(),
            'controlSnapshotJson' => $this->prettyPrintJson($snapshot),
            'allowCpApprovalRequests' => (bool)$settings->allowCpApprovalRequests,
            'canManagePolicies' => $this->canControlPermission(Plugin::PERMISSION_CONTROL_POLICIES_MANAGE),
            'canManageApprovals' => $this->canControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE),
            'canExecuteActions' => $this->canControlPermission(Plugin::PERMISSION_CONTROL_ACTIONS_EXECUTE),
            'activeControlTab' => $activeControlTab,
            'controlTabs' => $controlTabs,
        ]);
    }

    public function actionControlDiff(): Response
    {
        $this->requireControlCpEnabled();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_VIEW);
        $this->requireAcceptsJson();

        if (!$this->request->getIsGet()) {
            $response = $this->asJson([
                'ok' => false,
                'error' => 'METHOD_NOT_ALLOWED',
                'message' => 'Use GET.',
            ]);
            $response->setStatusCode(405);
            return $response;
        }

        $approvalId = (int)$this->request->getQueryParam('approvalId', 0);
        if ($approvalId <= 0) {
            $response = $this->asJson([
                'ok' => false,
                'error' => 'INVALID_APPROVAL_ID',
                'message' => 'Missing approval number.',
            ]);
            $response->setStatusCode(400);
            return $response;
        }

        $service = Plugin::getInstance()->getControlPlaneService();
        $approval = $service->getApprovalById($approvalId);
        if (!is_array($approval)) {
            $response = $this->asJson([
                'ok' => false,
                'error' => 'APPROVAL_NOT_FOUND',
                'message' => sprintf('Approval #%d could not be found.', $approvalId),
            ]);
            $response->setStatusCode(404);
            return $response;
        }

        $latestExecution = $service->getLatestExecutionForApproval($approvalId);
        if (is_array($latestExecution)) {
            $approval['latestExecution'] = $latestExecution;
        }

        $decorated = $this->decorateControlItemsWithActionLabels([$approval]);
        $decoratedApproval = is_array($decorated[0] ?? null) ? $decorated[0] : null;
        if (!is_array($decoratedApproval)) {
            $response = $this->asJson([
                'ok' => false,
                'error' => 'APPROVAL_NOT_DECORATABLE',
                'message' => sprintf('Approval #%d could not be prepared for diff review.', $approvalId),
            ]);
            $response->setStatusCode(500);
            return $response;
        }

        $targetEntry = $this->resolveControlTargetEntryDetails($decoratedApproval);
        return $this->asJson([
            'ok' => true,
            'diff' => $this->buildControlDiffPayload($decoratedApproval, $targetEntry),
        ]);
    }

    public function actionSettings(): Response
    {
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $writesState = $plugin->getWritesExperimentalState();
        $settingsOverrides = $this->getSettingsOverrides();
        $writesConfigLocked = (bool)($settingsOverrides['enableWritesExperimental'] ?? false);

        return $this->renderCpTemplate('agents/settings-tab', [
            'settings' => $this->getSettingsModel(),
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'writesExperimentalEnabled' => (bool)$writesState['enabled'],
            'writesExperimentalSettingLocked' => (bool)$writesState['locked'] || $writesConfigLocked,
            'writesExperimentalLockedByEnv' => (bool)$writesState['locked'],
            'writesExperimentalConfigLocked' => $writesConfigLocked,
            'writesExperimentalLockSource' => (string)($writesState['source'] ?? ''),
            'controlCpEnabled' => $plugin->isControlCpEnabled(),
            'credentialUsageIndicatorSettingLocked' => (bool)($settingsOverrides['enableCredentialUsageIndicator'] ?? false),
            'notificationsEnabledSettingLocked' => (bool)($settingsOverrides['notificationsEnabled'] ?? false),
            'notificationRecipientsSettingLocked' => (bool)($settingsOverrides['notificationRecipients'] ?? false),
            'notificationApprovalRequestedSettingLocked' => (bool)($settingsOverrides['notificationApprovalRequested'] ?? false),
            'notificationApprovalDecidedSettingLocked' => (bool)($settingsOverrides['notificationApprovalDecided'] ?? false),
            'notificationExecutionFailedSettingLocked' => (bool)($settingsOverrides['notificationExecutionFailed'] ?? false),
            'notificationWebhookDlqFailedSettingLocked' => (bool)($settingsOverrides['notificationWebhookDlqFailed'] ?? false),
            'notificationStatusChangedSettingLocked' => (bool)($settingsOverrides['notificationStatusChanged'] ?? false),
            'webhookUrlSettingLocked' => (bool)($settingsOverrides['webhookUrl'] ?? false),
            'webhookSecretSettingLocked' => (bool)($settingsOverrides['webhookSecret'] ?? false),
            'reliabilityConsumerLagWarnSecondsLocked' => (bool)($settingsOverrides['reliabilityConsumerLagWarnSeconds'] ?? false),
            'reliabilityConsumerLagCriticalSecondsLocked' => (bool)($settingsOverrides['reliabilityConsumerLagCriticalSeconds'] ?? false),
        ]);
    }

    public function actionCredentials(): Response
    {
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_VIEW);

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $posture = $plugin->getSecurityPolicyService()->getCpPosture();
        $defaultScopes = (array)($posture['authentication']['tokenScopes'] ?? []);
        $managedCredentials = $plugin->getCredentialService()->getManagedCredentials($defaultScopes);
        $lifecycleSnapshot = [
            'service' => 'agents',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => 'unknown',
            'summary' => [],
            'topRisks' => [],
            'agents' => [],
        ];
        try {
            $lifecycleSnapshot = $plugin->getLifecycleGovernanceService()->getSnapshot();
        } catch (Throwable $e) {
            Craft::warning('Unable to load lifecycle governance snapshot for CP: ' . $e->getMessage(), __METHOD__);
        }
        $lifecycleSummary = (array)($lifecycleSnapshot['summary'] ?? []);
        $lifecycleTopRisks = (array)($lifecycleSnapshot['topRisks'] ?? []);
        $lifecycleByCredentialId = [];
        foreach ((array)($lifecycleSnapshot['agents'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $credentialId = (int)($row['credentialId'] ?? 0);
            if ($credentialId <= 0) {
                continue;
            }
            $lifecycleByCredentialId[$credentialId] = $row;
        }
        $credentialExpirySummary = [
            'expired' => 0,
            'expiringSoon' => 0,
            'activeWithExpiry' => 0,
        ];
        foreach ($managedCredentials as $credential) {
            if (!is_array($credential) || (bool)($credential['revoked'] ?? false)) {
                continue;
            }

            $status = strtolower(trim((string)($credential['expiryStatus'] ?? 'none')));
            if ($status === 'expired') {
                $credentialExpirySummary['expired']++;
            } elseif ($status === 'expiring_soon') {
                $credentialExpirySummary['expiringSoon']++;
                $credentialExpirySummary['activeWithExpiry']++;
            } elseif ($status === 'active') {
                $credentialExpirySummary['activeWithExpiry']++;
            }
        }

        return $this->renderCpTemplate('agents/credentials', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'credentialUsageIndicatorEnabled' => (bool)$this->getSettingsModel()->enableCredentialUsageIndicator,
            'defaultCredentialOwnerUser' => $this->resolveCurrentCpUser(),
            'defaultCredentialOwnerUserId' => $this->resolveCurrentCpUserId(),
            'defaultCredentialOwner' => $this->resolveCurrentCpUserEmail(),
            'securityPosture' => $posture,
            'managedCredentials' => $managedCredentials,
            'lifecycleSnapshot' => $lifecycleSnapshot,
            'lifecycleSummary' => $lifecycleSummary,
            'lifecycleTopRisks' => $lifecycleTopRisks,
            'lifecycleByCredentialId' => $lifecycleByCredentialId,
            'credentialExpirySummary' => $credentialExpirySummary,
            'defaultScopes' => $defaultScopes,
            'revealedCredential' => $this->pullRevealedCredential(),
            'firstWorkerGuideUrl' => 'https://marcusscheller.com/docs/agents/get-started/first-worker',
            'workerBootstrapSiteUrl' => UrlHelper::siteUrl(''),
            'workerBootstrapBaseUrl' => UrlHelper::siteUrl('agents/v1'),
            'canManageCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE),
            'canPauseCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE),
            'canRotateCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_ROTATE),
            'canRevokeCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_REVOKE),
            'canDeleteCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_DELETE),
        ]);
    }

    public function actionCredentialUsagePulse(): Response
    {
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_VIEW);
        $this->requireAcceptsJson();

        if (!$this->request->getIsGet()) {
            $response = $this->asJson([
                'ok' => false,
                'error' => 'METHOD_NOT_ALLOWED',
                'message' => 'Use GET.',
            ]);
            $response->setStatusCode(405);
            return $response;
        }

        if (!(bool)$this->getSettingsModel()->enableCredentialUsageIndicator) {
            return $this->asJson([
                'ok' => true,
                'serverNow' => (int)floor(microtime(true) * 1000),
                'events' => [],
            ]);
        }

        $rawCredentialIds = $this->request->getQueryParam(
            'credentialIds',
            $this->request->getQueryParam('credentialIds[]', [])
        );
        $credentialIds = $this->parseIntegerIdsInput($rawCredentialIds);

        $sinceRaw = $this->request->getQueryParam('since', null);
        $sinceMs = null;
        if ($sinceRaw !== null) {
            $sinceCandidate = trim((string)$sinceRaw);
            if ($sinceCandidate !== '' && is_numeric($sinceCandidate)) {
                $sinceMs = max(0, (int)$sinceCandidate);
            }
        }

        $events = Plugin::getInstance()
            ->getCredentialService()
            ->getCredentialUsagePulseSnapshot($credentialIds, $sinceMs);

        return $this->asJson([
            'ok' => true,
            'serverNow' => (int)floor(microtime(true) * 1000),
            'events' => $events,
        ]);
    }

    public function actionHealth(): Response
    {
        return $this->redirect('agents/status');
    }

    public function actionToggleEnabled(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        if ((bool)$enabledState['locked']) {
            $this->setFailFlash('Agents enabled state is controlled by `PLUGIN_AGENTS_ENABLED` and cannot be changed from the Control Panel.');
            return $this->redirectToPostedUrl(null, 'agents/status');
        }

        $enabledRaw = strtolower(trim((string)$this->request->getBodyParam('enabled', '0')));
        $enabled = in_array($enabledRaw, ['1', 'true', 'on', 'yes'], true);

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'enabled' => $enabled,
        ]);

        if (!$saved) {
            $this->setFailFlash('Couldn’t save Agents settings.');
            return $this->redirectToPostedUrl(null, 'agents/status');
        }

        $this->setSuccessFlash($enabled ? 'Agents API enabled.' : 'Agents API disabled.');
        return $this->redirectToPostedUrl(null, 'agents/status');
    }

    public function actionSaveSettings(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $settings = $this->getSettingsModel();
        $enabledState = $plugin->getAgentsEnabledState();
        $writesState = $plugin->getWritesExperimentalState();
        $settingsOverrides = $this->getSettingsOverrides();
        $writesConfigLocked = (bool)($settingsOverrides['enableWritesExperimental'] ?? false);
        $writesEnvLocked = (bool)($writesState['locked'] ?? false);
        $writesLocked = $writesConfigLocked || $writesEnvLocked;
        $credentialUsageIndicatorLocked = (bool)($settingsOverrides['enableCredentialUsageIndicator'] ?? false);
        $notificationsEnabledLocked = (bool)($settingsOverrides['notificationsEnabled'] ?? false);
        $notificationRecipientsLocked = (bool)($settingsOverrides['notificationRecipients'] ?? false);
        $notificationApprovalRequestedLocked = (bool)($settingsOverrides['notificationApprovalRequested'] ?? false);
        $notificationApprovalDecidedLocked = (bool)($settingsOverrides['notificationApprovalDecided'] ?? false);
        $notificationExecutionFailedLocked = (bool)($settingsOverrides['notificationExecutionFailed'] ?? false);
        $notificationWebhookDlqFailedLocked = (bool)($settingsOverrides['notificationWebhookDlqFailed'] ?? false);
        $notificationStatusChangedLocked = (bool)($settingsOverrides['notificationStatusChanged'] ?? false);
        $webhookUrlLocked = (bool)($settingsOverrides['webhookUrl'] ?? false);
        $webhookSecretLocked = (bool)($settingsOverrides['webhookSecret'] ?? false);
        $consumerLagWarnLocked = (bool)($settingsOverrides['reliabilityConsumerLagWarnSeconds'] ?? false);
        $consumerLagCriticalLocked = (bool)($settingsOverrides['reliabilityConsumerLagCriticalSeconds'] ?? false);

        $settingsData = get_object_vars($settings);
        $settingsData['enabled'] = (bool)$enabledState['locked']
            ? (bool)$enabledState['enabled']
            : $this->parseBooleanBodyParam('enabled', (bool)$settings->enabled);
        $settingsData['enableWritesExperimental'] = $writesLocked
            ? (bool)$settings->enableWritesExperimental
            : $this->parseBooleanBodyParam('enableWritesExperimental', (bool)$settings->enableWritesExperimental);
        $settingsData['allowCpApprovalRequests'] = $plugin->isControlCpEnabled()
            ? $this->parseBooleanBodyParam('allowCpApprovalRequests', (bool)$settings->allowCpApprovalRequests)
            : false;
        $settingsData['enableCredentialUsageIndicator'] = $credentialUsageIndicatorLocked
            ? (bool)$settings->enableCredentialUsageIndicator
            : $this->parseBooleanBodyParam('enableCredentialUsageIndicator', (bool)$settings->enableCredentialUsageIndicator);
        $settingsData['notificationsEnabled'] = $notificationsEnabledLocked
            ? (bool)$settings->notificationsEnabled
            : $this->parseBooleanBodyParam('notificationsEnabled', (bool)$settings->notificationsEnabled);
        $settingsData['notificationRecipients'] = $notificationRecipientsLocked
            ? (string)$settings->notificationRecipients
            : $this->parseStringBodyParam('notificationRecipients', (string)$settings->notificationRecipients);
        $settingsData['notificationApprovalRequested'] = $notificationApprovalRequestedLocked
            ? (bool)$settings->notificationApprovalRequested
            : $this->parseBooleanBodyParam('notificationApprovalRequested', (bool)$settings->notificationApprovalRequested);
        $settingsData['notificationApprovalDecided'] = $notificationApprovalDecidedLocked
            ? (bool)$settings->notificationApprovalDecided
            : $this->parseBooleanBodyParam('notificationApprovalDecided', (bool)$settings->notificationApprovalDecided);
        $settingsData['notificationExecutionFailed'] = $notificationExecutionFailedLocked
            ? (bool)$settings->notificationExecutionFailed
            : $this->parseBooleanBodyParam('notificationExecutionFailed', (bool)$settings->notificationExecutionFailed);
        $settingsData['notificationWebhookDlqFailed'] = $notificationWebhookDlqFailedLocked
            ? (bool)$settings->notificationWebhookDlqFailed
            : $this->parseBooleanBodyParam('notificationWebhookDlqFailed', (bool)$settings->notificationWebhookDlqFailed);
        $settingsData['notificationStatusChanged'] = $notificationStatusChangedLocked
            ? (bool)$settings->notificationStatusChanged
            : $this->parseBooleanBodyParam('notificationStatusChanged', (bool)$settings->notificationStatusChanged);
        $settingsData['webhookUrl'] = $webhookUrlLocked
            ? (string)$settings->webhookUrl
            : $this->parseStringBodyParam('webhookUrl', (string)$settings->webhookUrl);
        $settingsData['webhookSecret'] = $webhookSecretLocked
            ? (string)$settings->webhookSecret
            : $this->parseStringBodyParam('webhookSecret', (string)$settings->webhookSecret);
        $settingsData['reliabilityConsumerLagWarnSeconds'] = $consumerLagWarnLocked
            ? $settings->reliabilityConsumerLagWarnSeconds
            : $this->parseEnvAwareIntegerSetting('reliabilityConsumerLagWarnSeconds', $settings->reliabilityConsumerLagWarnSeconds, 0, 604800);
        $settingsData['reliabilityConsumerLagCriticalSeconds'] = $consumerLagCriticalLocked
            ? $settings->reliabilityConsumerLagCriticalSeconds
            : $this->parseEnvAwareIntegerSetting('reliabilityConsumerLagCriticalSeconds', $settings->reliabilityConsumerLagCriticalSeconds, 1, 604800);

        $thresholdAdjusted = false;
        $warnIsNumeric = $this->isNumericSettingValue($settingsData['reliabilityConsumerLagWarnSeconds']);
        $criticalIsNumeric = $this->isNumericSettingValue($settingsData['reliabilityConsumerLagCriticalSeconds']);
        if ($warnIsNumeric && $criticalIsNumeric && (int)$settingsData['reliabilityConsumerLagCriticalSeconds'] <= (int)$settingsData['reliabilityConsumerLagWarnSeconds']) {
            if (!$consumerLagCriticalLocked) {
                $settingsData['reliabilityConsumerLagCriticalSeconds'] = min(604800, (int)$settingsData['reliabilityConsumerLagWarnSeconds'] + 1);
                $thresholdAdjusted = true;
            } elseif (!$consumerLagWarnLocked) {
                $settingsData['reliabilityConsumerLagWarnSeconds'] = max(0, (int)$settingsData['reliabilityConsumerLagCriticalSeconds'] - 1);
                $thresholdAdjusted = true;
            }
        }

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, $settingsData);
        if (!$saved) {
            $this->setFailFlash('Couldn’t save Agents settings.');
            return $this->redirectToPostedUrl(null, 'agents/settings');
        }

        $notes = [];
        if ((bool)$enabledState['locked']) {
            $notes[] = '`enabled` remains controlled by `PLUGIN_AGENTS_ENABLED`.';
        }
        if ($writesEnvLocked) {
            $notes[] = '`enableWritesExperimental` remains controlled by `' . (string)($writesState['source'] ?? 'environment') . '`.';
        }
        if ($writesConfigLocked) {
            $notes[] = '`enableWritesExperimental` is controlled by `config/agents.php`.';
        }
        if ($credentialUsageIndicatorLocked) {
            $notes[] = '`enableCredentialUsageIndicator` is controlled by `config/agents.php`.';
        }
        if ($notificationsEnabledLocked) {
            $notes[] = '`notificationsEnabled` is controlled by `config/agents.php`.';
        }
        if ($notificationRecipientsLocked) {
            $notes[] = '`notificationRecipients` is controlled by `config/agents.php`.';
        }
        if ($notificationApprovalRequestedLocked) {
            $notes[] = '`notificationApprovalRequested` is controlled by `config/agents.php`.';
        }
        if ($notificationApprovalDecidedLocked) {
            $notes[] = '`notificationApprovalDecided` is controlled by `config/agents.php`.';
        }
        if ($notificationExecutionFailedLocked) {
            $notes[] = '`notificationExecutionFailed` is controlled by `config/agents.php`.';
        }
        if ($notificationWebhookDlqFailedLocked) {
            $notes[] = '`notificationWebhookDlqFailed` is controlled by `config/agents.php`.';
        }
        if ($notificationStatusChangedLocked) {
            $notes[] = '`notificationStatusChanged` is controlled by `config/agents.php`.';
        }
        if ($webhookUrlLocked) {
            $notes[] = '`webhookUrl` is controlled by `config/agents.php`.';
        }
        if ($webhookSecretLocked) {
            $notes[] = '`webhookSecret` is controlled by `config/agents.php`.';
        }
        if ($consumerLagWarnLocked) {
            $notes[] = '`reliabilityConsumerLagWarnSeconds` is controlled by `config/agents.php`.';
        }
        if ($consumerLagCriticalLocked) {
            $notes[] = '`reliabilityConsumerLagCriticalSeconds` is controlled by `config/agents.php`.';
        }
        if ($thresholdAdjusted) {
            $notes[] = '`reliabilityConsumerLagCriticalSeconds` was auto-adjusted to stay greater than warn threshold.';
        }

        if (!empty($notes)) {
            $this->setSuccessFlash('Agents settings saved. ' . implode(' ', $notes));
        } else {
            $this->setSuccessFlash('Agents settings saved.');
        }

        return $this->redirectToPostedUrl(null, 'agents/settings');
    }

    public function actionDownloadDiagnosticsBundle(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            $bundle = Plugin::getInstance()->getDiagnosticsBundleService()->getBundle([
                'source' => 'cp',
            ]);
            $encoded = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new \RuntimeException('Unable to encode diagnostics bundle as JSON.');
            }

            $filename = sprintf('agents-diagnostics-%s.json', gmdate('Ymd-His'));
            return Craft::$app->getResponse()->sendContentAsFile($encoded . "\n", $filename, [
                'mimeType' => 'application/json',
            ]);
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to generate diagnostics bundle: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/status');
    }

    public function actionClearStatusVerdicts(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            $cleared = Plugin::getInstance()->getObservabilityMetricsService()->clearRuntimeCounters();
            if ($cleared > 0) {
                $this->setSuccessFlash(sprintf(
                    'Cleared %d cache-backed runtime counter%s. Status will be recalculated on the next load.',
                    $cleared,
                    $cleared === 1 ? '' : 's'
                ));
            } else {
                $this->setSuccessFlash('No cache-backed runtime counters were present. Status will be recalculated on the next load.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to clear stale status inputs: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/status');
    }

    public function actionReplayWebhookDlq(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $service = Plugin::getInstance()->getWebhookService();
        $mode = strtolower(trim((string)$this->request->getBodyParam('mode', 'single')));

        try {
            if ($mode === 'all') {
                $limit = (int)$this->request->getBodyParam('limit', 25);
                $result = $service->replayDeadLetterEvents($limit);
                $this->setSuccessFlash(sprintf(
                    'Replay queued for %d/%d dead-letter events.',
                    (int)($result['replayed'] ?? 0),
                    (int)($result['attempted'] ?? 0)
                ));
            } else {
                $id = (int)$this->request->getBodyParam('id', 0);
                if ($id <= 0) {
                    $this->setFailFlash('Missing dead-letter event ID.');
                    return $this->redirectToPostedUrl(null, 'agents/status');
                }

                $event = $service->replayDeadLetterEvent($id);
                if (!is_array($event)) {
                    $this->setFailFlash('Dead-letter event not found.');
                    return $this->redirectToPostedUrl(null, 'agents/status');
                }

                $this->setSuccessFlash(sprintf('Dead-letter event #%d queued for replay.', $id));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to replay dead-letter event(s): ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/status');
    }

    public function actionClearWebhookTestSink(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            $deleted = Plugin::getInstance()->getWebhookTestSinkService()->clearCapturedEvents();
            $this->setSuccessFlash(sprintf('Cleared %d captured webhook test-sink event%s.', $deleted, $deleted === 1 ? '' : 's'));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to clear webhook test-sink events: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/status');
    }

    public function actionSendWebhookTestSinkDelivery(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            $result = Plugin::getInstance()->getWebhookTestSinkService()->sendTestDelivery();
            $captured = is_array($result['captured'] ?? null) ? (array)$result['captured'] : null;
            $eventId = trim((string)($result['eventId'] ?? ''));

            if ($captured !== null) {
                $this->setSuccessFlash(sprintf(
                    'Sent test webhook `%s` and captured it as event #%d (%s).',
                    $eventId,
                    (int)($captured['id'] ?? 0),
                    (string)($captured['verificationStatus'] ?? 'unknown')
                ));
            } else {
                $this->setSuccessFlash(sprintf('Sent test webhook `%s` to the local sink.', $eventId));
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to send test webhook: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/status');
    }

    public function actionSendWebhookProbe(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            $result = Plugin::getInstance()->getWebhookProbeService()->sendProductionProbe($this->resolveCurrentCpUser());
            $run = is_array($result['run'] ?? null) ? (array)$result['run'] : [];
            $eventId = trim((string)($result['eventId'] ?? ''));

            $this->setSuccessFlash(sprintf(
                'Sent production webhook probe `%s` and received HTTP %d%s.',
                $eventId,
                (int)($run['httpStatusCode'] ?? 0),
                trim((string)($run['httpReason'] ?? '')) !== '' ? ' (' . trim((string)($run['httpReason'] ?? '')) . ')' : ''
            ));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to send production webhook probe: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/status#webhookProbeSection');
    }

    public function actionRunNotificationsCheck(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            $service = Plugin::getInstance()->getNotificationService();
            $config = $service->getRuntimeConfig();
            $result = $service->runStatusMonitor();
            $currentStatus = strtoupper((string)($result['currentStatus'] ?? 'unknown'));
            $previousStatus = strtoupper((string)($result['previousStatus'] ?? 'unknown'));
            $queued = (int)($result['queued'] ?? 0);

            if ((bool)($result['changed'] ?? false)) {
                $message = sprintf('Notifications check recorded a system status change from %s to %s.', $previousStatus, $currentStatus);
                if (!(bool)($config['enabled'] ?? false)) {
                    $message .= ' Operator notifications are currently disabled, so no email was queued.';
                } elseif ($queued > 0) {
                    $message .= sprintf(' Queued %d email notification%s.', $queued, $queued === 1 ? '' : 's');
                } else {
                    $message .= ' No email was queued (toggle off for this event or dedupe window still active).';
                }
                $this->setSuccessFlash($message);
            } else {
                $this->setSuccessFlash(sprintf('Notifications check completed. Current system status remains %s.', $currentStatus));
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to run notifications check: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/status#operatorNotificationsSection');
    }

    public function actionCreateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $handle = (string)$this->request->getBodyParam('credentialHandle', '');
        $displayName = (string)$this->request->getBodyParam('credentialDisplayName', '');
        $description = $this->parseStringBodyParam('credentialDescription', '');
        $owner = $this->parseStringBodyParam('credentialOwnerLegacyValue', $this->resolveCurrentCpUserEmail());
        $ownerUserId = $this->parseSingleIntegerIdBodyParam('credentialOwnerUserId');
        $token = (string)$this->request->getBodyParam('credentialToken', '');
        $scopes = $this->parseScopesInput($this->request->getBodyParam('credentialScopes', ''));
        $forceHumanApproval = $this->hasWritingCapabilityScope($scopes)
            ? $this->parseBooleanBodyParam('credentialForceHumanApproval', false)
            : false;
        $approvalRecipientUserIds = $this->hasWritingCapabilityScope($scopes)
            ? $this->parseIntegerIdsInput($this->request->getBodyParam('credentialApprovalRecipientUserIds', []))
            : [];
        $webhookSubscriptions = [
            'resourceTypes' => $this->parseWebhookDimensionInput(
                $this->request->getBodyParam('credentialWebhookResourceTypes', []),
                ['entry', 'order', 'product']
            ),
            'actions' => $this->parseWebhookDimensionInput(
                $this->request->getBodyParam('credentialWebhookActions', []),
                ['created', 'updated', 'deleted']
            ),
        ];
        $expiryPolicy = [
            'ttlDays' => $this->parseNullableIntegerBodyParam('credentialTtlDays'),
            'expiryReminderDays' => $this->parseNullableIntegerBodyParam('credentialExpiryReminderDays'),
        ];
        $networkPolicy = [
            'ipAllowlist' => $this->parseIpAllowlistInput($this->request->getBodyParam('credentialIpAllowlist')),
        ];

        try {
            $result = $plugin->getCredentialService()->createManagedCredential(
                $handle,
                $displayName,
                $description,
                $owner,
                $ownerUserId,
                $approvalRecipientUserIds,
                $forceHumanApproval,
                $token,
                $scopes,
                $defaultScopes,
                $webhookSubscriptions,
                $expiryPolicy,
                $networkPolicy
            );
            $credential = (array)($result['credential'] ?? []);
            $this->storeRevealedCredential([
                'id' => (int)($credential['id'] ?? 0),
                'token' => (string)($result['token'] ?? ''),
                'handle' => (string)($credential['handle'] ?? ''),
                'displayName' => (string)($credential['displayName'] ?? ''),
                'action' => 'created',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->setSuccessFlash(sprintf('Account `%s` created. Copy the API token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to create account: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionUpdateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        $displayName = (string)$this->request->getBodyParam('credentialDisplayName', '');
        $description = $this->parseStringBodyParam('credentialDescription', '');
        $owner = $this->parseStringBodyParam('credentialOwnerLegacyValue', '');
        $ownerUserId = $this->parseSingleIntegerIdBodyParam('credentialOwnerUserId');
        $scopes = $this->parseScopesInput($this->request->getBodyParam('credentialScopes', ''));
        $forceHumanApproval = $this->hasWritingCapabilityScope($scopes)
            ? $this->parseBooleanBodyParam('credentialForceHumanApproval', false)
            : false;
        $approvalRecipientUserIds = $this->hasWritingCapabilityScope($scopes)
            ? $this->parseIntegerIdsInput($this->request->getBodyParam('credentialApprovalRecipientUserIds', []))
            : [];
        $webhookSubscriptions = [
            'resourceTypes' => $this->parseWebhookDimensionInput(
                $this->request->getBodyParam('credentialWebhookResourceTypes', []),
                ['entry', 'order', 'product']
            ),
            'actions' => $this->parseWebhookDimensionInput(
                $this->request->getBodyParam('credentialWebhookActions', []),
                ['created', 'updated', 'deleted']
            ),
        ];
        $expiryPolicy = [
            'ttlDays' => $this->parseNullableIntegerBodyParam('credentialTtlDays'),
            'expiryReminderDays' => $this->parseNullableIntegerBodyParam('credentialExpiryReminderDays'),
        ];
        $networkPolicy = [
            'ipAllowlist' => $this->parseIpAllowlistInput($this->request->getBodyParam('credentialIpAllowlist')),
        ];

        if ($credentialId <= 0) {
            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, 'agents/accounts');
        }

        try {
            $credential = $plugin->getCredentialService()->updateManagedCredential(
                $credentialId,
                $displayName,
                $description,
                $owner,
                $ownerUserId,
                $approvalRecipientUserIds,
                $forceHumanApproval,
                $scopes,
                $defaultScopes,
                $webhookSubscriptions,
                $expiryPolicy,
                $networkPolicy
            );
            if (!is_array($credential)) {
                $this->setFailFlash('Account not found.');
                return $this->redirectToPostedUrl(null, 'agents/accounts');
            }

            $this->setSuccessFlash(sprintf('Account `%s` updated.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to update account: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionRotateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_ROTATE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        $acceptsJson = $this->request->getAcceptsJson();

        if ($credentialId <= 0) {
            if ($acceptsJson) {
                $response = $this->asJson([
                    'ok' => false,
                    'message' => 'Missing account ID.',
                ]);
                $response->setStatusCode(400);
                return $response;
            }

            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, 'agents/accounts');
        }

        try {
            $result = $plugin->getCredentialService()->rotateManagedCredential($credentialId, $defaultScopes);
            if (!is_array($result)) {
                if ($acceptsJson) {
                    $response = $this->asJson([
                        'ok' => false,
                        'message' => 'Account not found.',
                        'credentialId' => $credentialId,
                    ]);
                    $response->setStatusCode(404);
                    return $response;
                }

                $this->setFailFlash('Account not found.');
                return $this->redirectToPostedUrl(null, 'agents/accounts');
            }

            $credential = (array)($result['credential'] ?? []);
            $revealedCredential = [
                'id' => (int)($credential['id'] ?? 0),
                'token' => (string)($result['token'] ?? ''),
                'handle' => (string)($credential['handle'] ?? ''),
                'displayName' => (string)($credential['displayName'] ?? ''),
                'action' => 'rotated',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            if ($acceptsJson) {
                return $this->asJson([
                    'ok' => true,
                    'message' => sprintf('Account `%s` rotated. Copy the new API token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')),
                    'credentialId' => (int)($credential['id'] ?? $credentialId),
                    'revealedCredential' => $revealedCredential,
                    'workerEnv' => $this->buildWorkerEnvExport((string)($result['token'] ?? '')),
                    'envFilename' => $this->buildWorkerEnvFilename((string)($credential['handle'] ?? '')),
                    'firstWorkerGuideUrl' => 'https://marcusscheller.com/docs/agents/get-started/first-worker',
                ]);
            }

            $this->storeRevealedCredential($revealedCredential);
            $this->setSuccessFlash(sprintf('Account `%s` rotated. Copy the new API token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            if ($acceptsJson) {
                $response = $this->asJson([
                    'ok' => false,
                    'message' => 'Unable to rotate account token: ' . $e->getMessage(),
                    'credentialId' => $credentialId,
                ]);
                $response->setStatusCode(400);
                return $response;
            }

            $this->setFailFlash('Unable to rotate account token: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionTestCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_VIEW);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        $redirectPath = $credentialId > 0
            ? 'agents/accounts?focusCardId=' . $credentialId
            : 'agents/accounts';
        $acceptsJson = $this->request->getAcceptsJson();

        if ($credentialId <= 0) {
            if ($acceptsJson) {
                $response = $this->asJson([
                    'ok' => false,
                    'message' => 'Missing account ID.',
                ]);
                $response->setStatusCode(400);
                return $response;
            }

            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, $redirectPath);
        }

        try {
            $result = $this->buildCredentialTestResult($credentialId);
            if ($acceptsJson) {
                return $this->asJson($result);
            }

            $this->setSuccessFlash((string)$result['message']);
        } catch (Throwable $e) {
            $message = 'Unable to test account: ' . $e->getMessage();
            if ($acceptsJson) {
                $response = $this->asJson([
                    'ok' => false,
                    'message' => $message,
                    'credentialId' => $credentialId,
                ]);
                $response->setStatusCode(400);
                return $response;
            }

            $this->setFailFlash($message);
        }

        return $this->redirectToPostedUrl(null, $redirectPath);
    }

    private function buildCredentialTestResult(int $credentialId): array
    {
        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $credential = $plugin->getCredentialService()->getManagedCredentialByIdForCp($credentialId, $defaultScopes);
        if (!is_array($credential)) {
            throw new NotFoundHttpException('Account not found.');
        }

        $enabledState = $plugin->getAgentsEnabledState();
        if (!(bool)($enabledState['enabled'] ?? false)) {
            throw new \RuntimeException('Agents API is disabled. Enable runtime before testing this account.');
        }

        $mode = strtolower(trim((string)($credential['mode'] ?? 'active')));
        $modeLabel = match ($mode) {
            'expiring_soon' => 'Expiring soon',
            'paused' => 'Paused',
            'revoked' => 'Revoked',
            'expired' => 'Expired',
            default => 'Active',
        };

        if (in_array($mode, ['paused', 'revoked', 'expired'], true)) {
            throw new \RuntimeException(sprintf('Account `%s` cannot be tested while its state is %s.', (string)($credential['handle'] ?? 'account'), strtolower($modeLabel)));
        }

        $scopes = array_values(array_unique(array_map(static fn($scope) => strtolower(trim((string)$scope)), (array)($credential['scopes'] ?? []))));
        $bootstrapScopes = ['auth:read', 'health:read', 'readiness:read'];
        $availableBootstrapScopes = array_values(array_intersect($bootstrapScopes, $scopes));
        $missingBootstrapScopes = array_values(array_diff($bootstrapScopes, $availableBootstrapScopes));
        $validatedSurfaces = [];

        if (in_array('health:read', $availableBootstrapScopes, true)) {
            $plugin->getReadinessService()->getHealthSummary();
            $validatedSurfaces[] = 'health:read';
        }

        if (in_array('auth:read', $availableBootstrapScopes, true)) {
            $validatedSurfaces[] = 'auth:read';
        }

        if (in_array('readiness:read', $availableBootstrapScopes, true)) {
            $plugin->getReadinessService()->getReadinessSummary();
            $validatedSurfaces[] = 'readiness:read';
        }

        $message = sprintf('Account `%s` validated. State: %s.', (string)($credential['handle'] ?? 'account'), $modeLabel);
        if ($validatedSurfaces !== []) {
            $message .= ' Safe read surfaces: ' . implode(', ', $validatedSurfaces) . '.';
        } else {
            $message .= ' No bootstrap read scopes are assigned, so only lifecycle state was validated.';
        }

        if ($missingBootstrapScopes !== []) {
            $message .= ' Missing bootstrap scopes: ' . implode(', ', $missingBootstrapScopes) . '.';
        }

        $message .= ' External worker and cron setup are not tested here.';

        return [
            'ok' => true,
            'message' => $message,
            'credentialId' => $credentialId,
            'handle' => (string)($credential['handle'] ?? ''),
            'state' => $mode,
            'stateLabel' => $modeLabel,
            'validatedSurfaces' => $validatedSurfaces,
            'missingBootstrapScopes' => $missingBootstrapScopes,
        ];
    }

    public function actionRevokeCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_REVOKE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        $acceptsJson = $this->request->getAcceptsJson();
        if ($credentialId <= 0) {
            if ($acceptsJson) {
                $response = $this->asJson([
                    'ok' => false,
                    'message' => 'Missing account ID.',
                ]);
                $response->setStatusCode(400);
                return $response;
            }

            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, 'agents/accounts');
        }

        try {
            $revoked = Plugin::getInstance()->getCredentialService()->revokeManagedCredential($credentialId);
            if (!$revoked) {
                if ($acceptsJson) {
                    $response = $this->asJson([
                        'ok' => false,
                        'message' => 'Account not found.',
                        'credentialId' => $credentialId,
                    ]);
                    $response->setStatusCode(404);
                    return $response;
                }

                $this->setFailFlash('Account not found.');
            } else {
                if ($acceptsJson) {
                    return $this->asJson([
                        'ok' => true,
                        'message' => 'Account token revoked.',
                        'credentialId' => $credentialId,
                        'state' => 'revoked',
                        'stateLabel' => 'Revoked',
                    ]);
                }

                $this->setSuccessFlash('Account token revoked.');
            }
        } catch (Throwable $e) {
            if ($acceptsJson) {
                $response = $this->asJson([
                    'ok' => false,
                    'message' => 'Unable to revoke account token: ' . $e->getMessage(),
                    'credentialId' => $credentialId,
                ]);
                $response->setStatusCode(400);
                return $response;
            }

            $this->setFailFlash('Unable to revoke account token: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionPauseCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, 'agents/accounts');
        }

        try {
            $paused = Plugin::getInstance()->getCredentialService()->pauseManagedCredential($credentialId);
            if (!$paused) {
                $this->setFailFlash('Account could not be paused. It may be revoked, already paused, or migrations are not up to date.');
            } else {
                $this->setSuccessFlash('Account paused.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to pause account: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionResumeCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, 'agents/accounts');
        }

        try {
            $resumed = Plugin::getInstance()->getCredentialService()->resumeManagedCredential($credentialId);
            if (!$resumed) {
                $this->setFailFlash('Account could not be resumed. It may be revoked or migrations are not up to date.');
            } else {
                $this->setSuccessFlash('Account resumed.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to resume account: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionRevokeAndRotateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_ROTATE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);

        if ($credentialId <= 0) {
            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, 'agents/accounts');
        }

        try {
            $result = $plugin->getCredentialService()->rotateManagedCredential($credentialId, $defaultScopes);
            if (!is_array($result)) {
                $this->setFailFlash('Account not found.');
                return $this->redirectToPostedUrl(null, 'agents/accounts');
            }

            $credential = (array)($result['credential'] ?? []);
            $this->storeRevealedCredential([
                'id' => (int)($credential['id'] ?? 0),
                'token' => (string)($result['token'] ?? ''),
                'handle' => (string)($credential['handle'] ?? ''),
                'displayName' => (string)($credential['displayName'] ?? ''),
                'action' => 'revoked and rotated',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->setSuccessFlash(sprintf('Account `%s` revoked and rotated. Old token is now invalid. Copy the new API token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to revoke and rotate account token: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionDeleteCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_DELETE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing account ID.');
            return $this->redirectToPostedUrl(null, 'agents/accounts');
        }

        try {
            $deleted = Plugin::getInstance()->getCredentialService()->deleteManagedCredential($credentialId);
            if (!$deleted) {
                $this->setFailFlash('Account not found.');
            } else {
                $this->setSuccessFlash('Account deleted.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to delete account: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/accounts');
    }

    public function actionUpsertControlPolicy(): Response
    {
        $this->requireControlCpEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_POLICIES_MANAGE);

        $service = Plugin::getInstance()->getControlPlaneService();

        try {
            $policy = $service->upsertPolicy([
                'handle' => (string)$this->request->getBodyParam('handle', ''),
                'originalHandle' => (string)$this->request->getBodyParam('originalHandle', ''),
                'displayName' => (string)$this->request->getBodyParam('displayName', ''),
                'actionPattern' => (string)$this->request->getBodyParam('actionPattern', ''),
                'requiresApproval' => $this->parseBooleanBodyParam('requiresApproval', true),
                'enabled' => $this->parseBooleanBodyParam('enabled', true),
                'riskLevel' => (string)$this->request->getBodyParam('riskLevel', 'medium'),
                'config' => $this->parseJsonBodyParam((string)$this->request->getBodyParam('configJson', '')),
            ], $this->buildCpActorContext());

            $this->setSuccessFlash(sprintf('Rule `%s` saved.', (string)($policy['handle'] ?? 'rule')));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to save rule: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/approvals');
    }

    public function actionDeleteControlPolicy(): Response
    {
        $this->requireControlCpEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_POLICIES_MANAGE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $handle = (string)$this->request->getBodyParam('handle', '');

        try {
            $deleted = $service->deletePolicy($handle, $this->buildCpActorContext());
            if ($deleted) {
                $this->setSuccessFlash(sprintf('Rule `%s` deleted.', trim($handle)));
            } else {
                $this->setFailFlash('Rule not found.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to delete rule: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/approvals');
    }

    public function actionRequestControlApproval(): Response
    {
        $this->requireControlCpEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE);

        $settings = $this->getSettingsModel();
        if (!(bool)$settings->allowCpApprovalRequests) {
            $this->setFailFlash('Manual form is off (agent-first mode). Ask your integration to submit the request via API.');
            return $this->redirectToPostedUrl(null, 'agents/approvals');
        }

        $service = Plugin::getInstance()->getControlPlaneService();
        $idempotencyKey = trim((string)$this->request->getBodyParam('idempotencyKey', ''));
        $guidedPayload = $this->buildGuidedMap([
            'orderId' => 'payloadOrderId',
            'returnId' => 'payloadReturnId',
            'customerId' => 'payloadCustomerId',
            'note' => 'payloadNote',
        ]);
        $guidedMetadata = $this->buildGuidedMap([
            'source' => 'metadataSource',
            'channel' => 'metadataChannel',
        ]);

        try {
            $rawPayload = $this->parseJsonBodyParam((string)$this->request->getBodyParam('payloadJson', ''));
            $rawMetadata = $this->parseJsonBodyParam((string)$this->request->getBodyParam('metadataJson', ''));
            $approval = $service->requestApproval([
                'actionType' => (string)$this->request->getBodyParam('actionType', ''),
                'actionRef' => (string)$this->request->getBodyParam('actionRef', ''),
                'reason' => (string)$this->request->getBodyParam('reason', ''),
                'idempotencyKey' => $idempotencyKey,
                'payload' => array_replace($guidedPayload, $rawPayload),
                'metadata' => array_replace($guidedMetadata, $rawMetadata),
            ], $this->buildCpActorContext());

            $status = (string)($approval['status'] ?? 'pending');
            if ((bool)($approval['idempotentReplay'] ?? false)) {
                $this->setSuccessFlash(sprintf(
                    'This request was already submitted earlier (duplicate-protection key `%s`). Reusing request #%d (`%s`).',
                    $idempotencyKey !== '' ? $idempotencyKey : 'n/a',
                    (int)($approval['id'] ?? 0),
                    $status
                ));
            } else {
                $this->setSuccessFlash(sprintf(
                    'Request #%d created (`%s`).%s',
                    (int)($approval['id'] ?? 0),
                    $status,
                    $this->formatApprovalAssuranceFlashSuffix($approval)
                ));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to create request: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/approvals');
    }

    public function actionDecideControlApproval(): Response
    {
        $this->requireControlCpEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $approvalId = (int)$this->request->getBodyParam('approvalId', 0);
        $decision = (string)$this->request->getBodyParam('decision', '');
        if ($approvalId <= 0) {
            $this->setFailFlash('Missing request number.');
            return $this->redirectToPostedUrl(null, 'agents/approvals');
        }

        if (strtolower(trim($decision)) === 'approved' && !$this->canControlPermission(Plugin::PERMISSION_CONTROL_ACTIONS_EXECUTE)) {
            $this->setFailFlash('You need execute permission to approve requests, because approval runs the action immediately when threshold is met.');
            return $this->redirectToPostedUrl(null, 'agents/approvals');
        }

        try {
            $approval = $service->decideApproval(
                $approvalId,
                $decision,
                (string)$this->request->getBodyParam('decisionReason', ''),
                $this->buildCpActorContext()
            );

            if (!is_array($approval)) {
                $this->setFailFlash('Request not found.');
                return $this->redirectToPostedUrl(null, 'agents/approvals');
            }

            $decisionStatus = (string)($approval['status'] ?? 'pending');
            $approvalsRemaining = (int)($approval['approvalsRemaining'] ?? 0);
            $decisionMessage = '';
            if ($decisionStatus === 'pending' && $approvalsRemaining > 0) {
                $decisionMessage = sprintf(
                    'Request #%d (`%s`) recorded one approval and is still pending (%d more needed).%s',
                    $approvalId,
                    (string)($approval['actionType'] ?? 'action'),
                    $approvalsRemaining,
                    $this->formatApprovalAssuranceFlashSuffix($approval)
                );
            } else {
                $decisionMessage = sprintf(
                    'Request #%d (`%s`) is now `%s`.%s',
                    $approvalId,
                    (string)($approval['actionType'] ?? 'action'),
                    $decisionStatus,
                    $this->formatApprovalAssuranceFlashSuffix($approval)
                );
            }

            $shouldRunNow =
                strtolower(trim($decision)) === 'approved'
                && strtolower(trim($decisionStatus)) === 'approved';

            if ($shouldRunNow) {
                try {
                    $execution = $service->executeApprovedActionFromApprovalId($approvalId, $this->buildCpActorContext());
                    if ((bool)($execution['idempotentReplay'] ?? false)) {
                        $this->setSuccessFlash(sprintf(
                            '%s Existing run reused (#%d).',
                            $decisionMessage,
                            (int)($execution['id'] ?? 0)
                        ));
                    } elseif ((string)($execution['status'] ?? '') === 'succeeded') {
                        $this->setSuccessFlash(sprintf(
                            '%s Executed immediately (run #%d).',
                            $decisionMessage,
                            (int)($execution['id'] ?? 0)
                        ));
                    } else {
                        $this->setFailFlash(sprintf(
                            '%s Run status is `%s`. %s',
                            $decisionMessage,
                            (string)($execution['status'] ?? 'unknown'),
                            (string)($execution['errorMessage'] ?? 'Review policy and approval requirements.')
                        ));
                    }
                } catch (\InvalidArgumentException $e) {
                    $this->setFailFlash(sprintf('%s %s', $decisionMessage, $e->getMessage()));
                }
            } else {
                $this->setSuccessFlash($decisionMessage);
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to save decision: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/approvals');
    }

    public function actionExecuteControlAction(): Response
    {
        $this->requireControlCpEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_ACTIONS_EXECUTE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $idempotencyKey = trim((string)$this->request->getBodyParam('idempotencyKey', ''));
        $guidedPayload = $this->buildGuidedMap([
            'orderId' => 'payloadOrderId',
            'returnId' => 'payloadReturnId',
            'reasonCode' => 'payloadReasonCode',
            'operatorNote' => 'payloadOperatorNote',
        ]);

        try {
            $rawPayload = $this->parseJsonBodyParam((string)$this->request->getBodyParam('payloadJson', ''));
            $execution = $service->executeAction([
                'actionType' => (string)$this->request->getBodyParam('actionType', ''),
                'actionRef' => (string)$this->request->getBodyParam('actionRef', ''),
                'approvalId' => (int)$this->request->getBodyParam('approvalId', 0),
                'idempotencyKey' => $idempotencyKey,
                'payload' => array_replace($guidedPayload, $rawPayload),
            ], $this->buildCpActorContext());

            $status = (string)($execution['status'] ?? 'unknown');
            if ((bool)($execution['idempotentReplay'] ?? false)) {
                $this->setSuccessFlash(sprintf(
                    'This run already exists for duplicate-protection key `%s`. Reusing run #%d.',
                    $idempotencyKey !== '' ? $idempotencyKey : 'n/a',
                    (int)($execution['id'] ?? 0)
                ));
            } elseif ($status === 'succeeded') {
                $this->setSuccessFlash(sprintf(
                    'Run recorded for `%s` as `%s` (run #%d).',
                    (string)($execution['actionType'] ?? 'action'),
                    $status,
                    (int)($execution['id'] ?? 0)
                ));
            } else {
                $this->setFailFlash(sprintf(
                    'Run `%s` for `%s` is `%s`. %s',
                    $idempotencyKey !== '' ? $idempotencyKey : 'n/a',
                    (string)($execution['actionType'] ?? 'action'),
                    $status,
                    (string)($execution['errorMessage'] ?? 'Review rule/approval requirements.')
                ));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to run action: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/approvals');
    }

    public function actionExecuteApprovedControlAction(): Response
    {
        $this->requireControlCpEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_ACTIONS_EXECUTE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $approvalId = (int)$this->request->getBodyParam('approvalId', 0);
        if ($approvalId <= 0) {
            $this->setFailFlash('Missing request number.');
            return $this->redirectToPostedUrl(null, 'agents/approvals');
        }

        try {
            $execution = $service->executeApprovedActionFromApprovalId($approvalId, $this->buildCpActorContext());
            $status = (string)($execution['status'] ?? 'unknown');
            if ((bool)($execution['idempotentReplay'] ?? false)) {
                $this->setSuccessFlash(sprintf(
                    'Request #%d already ran. Reusing existing run #%d.',
                    $approvalId,
                    (int)($execution['id'] ?? 0)
                ));
            } elseif ($status === 'succeeded') {
                $this->setSuccessFlash(sprintf(
                    'Request #%d ran successfully (run #%d).',
                    $approvalId,
                    (int)($execution['id'] ?? 0)
                ));
            } else {
                $this->setFailFlash(sprintf(
                    'Request #%d run is `%s`. %s',
                    $approvalId,
                    $status,
                    (string)($execution['errorMessage'] ?? 'Review policy and approval requirements.')
                ));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to run approved request: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/approvals');
    }

    public function actionSimulateControlAction(): Response
    {
        $this->requireControlCpEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_ACTIONS_EXECUTE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $guidedPayload = $this->buildGuidedMap([
            'orderId' => 'simulationPayloadOrderId',
            'returnId' => 'simulationPayloadReturnId',
            'reasonCode' => 'simulationPayloadReasonCode',
            'operatorNote' => 'simulationPayloadOperatorNote',
        ]);

        try {
            $rawPayload = $this->parseJsonBodyParam((string)$this->request->getBodyParam('simulationPayloadJson', ''));
            $result = $service->simulateAction([
                'actionType' => (string)$this->request->getBodyParam('simulationActionType', ''),
                'actionRef' => (string)$this->request->getBodyParam('simulationActionRef', ''),
                'approvalId' => (int)$this->request->getBodyParam('simulationApprovalId', 0),
                'payload' => array_replace($guidedPayload, $rawPayload),
            ]);

            $this->storeControlSimulationResult($result);

            $status = (string)($result['evaluation']['status'] ?? 'unknown');
            if ($status === 'allowed') {
                $this->setSuccessFlash('Dry-run passed: this action currently satisfies policy/approval requirements.');
            } else {
                $reasons = (array)($result['evaluation']['reasons'] ?? []);
                $firstReason = !empty($reasons) ? (string)$reasons[0] : 'Dry-run blocked by current policy evaluation.';
                $this->setFailFlash(sprintf('Dry-run result: `%s`. %s', $status, $firstReason));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to run policy simulator: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/approvals');
    }

    private function getApiEndpoints(): array
    {
        $apiBasePath = '/agents/v1';
        $endpoints = [
            $apiBasePath . '/health',
            $apiBasePath . '/readiness',
            $apiBasePath . '/diagnostics/bundle',
            $apiBasePath . '/products',
            $apiBasePath . '/orders',
            $apiBasePath . '/entries',
            $apiBasePath . '/changes',
            $apiBasePath . '/sections',
            $apiBasePath . '/sync-state/lag',
            $apiBasePath . '/sync-state/checkpoint',
            $apiBasePath . '/schema',
            $apiBasePath . '/capabilities',
            $apiBasePath . '/openapi.json',
        ];
        if ($this->isControlCpEnabled()) {
            $endpoints = array_merge($endpoints, [
                $apiBasePath . '/control/policies',
                $apiBasePath . '/control/approvals',
                $apiBasePath . '/control/executions',
                $apiBasePath . '/control/policy-simulate',
                $apiBasePath . '/control/actions/execute',
                $apiBasePath . '/control/audit',
            ]);
        }

        return $endpoints;
    }

    private function controlTabs(): array
    {
        return [
            ['key' => 'approvals', 'label' => 'Approvals', 'url' => 'agents/approvals/approvals'],
            ['key' => 'rules', 'label' => 'Rules', 'url' => 'agents/approvals/rules'],
        ];
    }

    private function resolveControlTab(): string
    {
        $request = Craft::$app->getRequest();
        $tabFromQuery = strtolower(trim((string)$request->getQueryParam('tab', '')));
        if (in_array($tabFromQuery, self::CONTROL_TABS, true)) {
            return $tabFromQuery;
        }

        $pathInfo = trim((string)$request->getPathInfo(), '/');
        if (preg_match('#^agents/approvals/(approvals|rules)$#', $pathInfo, $matches) === 1) {
            return (string)$matches[1];
        }

        return 'approvals';
    }

    private function resolveEditingControlPolicy(array $policies): ?array
    {
        $editHandle = strtolower(trim((string)Craft::$app->getRequest()->getQueryParam('editRule', '')));
        if ($editHandle === '') {
            return null;
        }

        foreach ($policies as $policy) {
            if (!is_array($policy)) {
                continue;
            }

            $handle = strtolower(trim((string)($policy['handle'] ?? '')));
            if ($handle === $editHandle) {
                return $policy;
            }
        }

        return null;
    }

    private function getSettingsModel(): Settings
    {
        $settings = Plugin::getInstance()->getSettings();
        return $settings instanceof Settings ? $settings : new Settings();
    }

    private function prettyPrintJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return '{}';
        }
        return $encoded;
    }

    private function renderCpTemplate(string $template, array $variables): Response
    {
        return $this->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
    }

    private function getDefaultScopes(): array
    {
        $posture = Plugin::getInstance()->getSecurityPolicyService()->getCpPosture();
        $scopes = (array)($posture['authentication']['tokenScopes'] ?? []);
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) && !is_numeric($scope)) {
                continue;
            }
            $value = trim((string)$scope);
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function parseScopesInput(mixed $rawInput): array
    {
        $values = [];
        if (is_array($rawInput)) {
            foreach ($rawInput as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $values[] = (string)$value;
            }
        } elseif (is_string($rawInput) || is_numeric($rawInput)) {
            $values[] = (string)$rawInput;
        }

        $parts = [];
        foreach ($values as $value) {
            $chunks = preg_split('/[\s,]+/', strtolower($value)) ?: [];
            foreach ($chunks as $chunk) {
                $parts[] = $chunk;
            }
        }

        $scopes = [];
        foreach ($parts as $part) {
            $scope = trim((string)$part);
            if ($scope === '') {
                continue;
            }

            $scope = preg_replace('/[^a-z0-9:_*.-]/', '', $scope) ?: '';
            if ($scope === '') {
                continue;
            }

            if ($scope === 'entries:write') {
                $scope = 'entries:write:draft';
            }

            $scopes[] = $scope;
        }

        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        return $scopes;
    }

    private function hasWritingCapabilityScope(array $scopes): bool
    {
        if (!Plugin::getInstance()->isWritesExperimentalEnabled()) {
            return false;
        }

        $writingScopes = ['entries:write:draft', 'entries:write'];
        foreach ($scopes as $scope) {
            $normalized = strtolower(trim((string)$scope));
            if ($normalized === '') {
                continue;
            }
            if (in_array($normalized, $writingScopes, true)) {
                return true;
            }
        }

        return false;
    }

    private function parseWebhookDimensionInput(mixed $rawInput, array $allowedValues): array
    {
        $tokens = [];
        if (is_array($rawInput)) {
            foreach ($rawInput as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $tokens[] = (string)$value;
            }
        } elseif (is_string($rawInput) || is_numeric($rawInput)) {
            $tokens[] = (string)$rawInput;
        }

        $parts = [];
        foreach ($tokens as $token) {
            $chunks = preg_split('/[\s,]+/', strtolower($token)) ?: [];
            foreach ($chunks as $chunk) {
                $value = trim((string)$chunk);
                if ($value === '') {
                    continue;
                }
                $parts[] = $value;
            }
        }

        if (in_array('*', $parts, true)) {
            $parts = $allowedValues;
        }

        $normalized = [];
        foreach ($parts as $part) {
            if (in_array($part, $allowedValues, true)) {
                $normalized[] = $part;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    private function parseIpAllowlistInput(mixed $rawInput): array
    {
        $tokens = [];
        if (is_array($rawInput)) {
            foreach ($rawInput as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $tokens[] = (string)$value;
            }
        } elseif (is_string($rawInput) || is_numeric($rawInput)) {
            $tokens[] = (string)$rawInput;
        }

        $parts = [];
        foreach ($tokens as $token) {
            $chunks = preg_split('/[\s,]+/', trim($token)) ?: [];
            foreach ($chunks as $chunk) {
                $candidate = trim((string)$chunk);
                if ($candidate === '') {
                    continue;
                }
                $parts[] = $candidate;
            }
        }

        $normalized = [];
        foreach ($parts as $part) {
            $cidr = $this->normalizeCidrInput($part);
            if ($cidr === null) {
                continue;
            }
            $normalized[] = $cidr;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    private function parseIntegerIdsInput(mixed $rawInput): array
    {
        $tokens = [];
        if (is_array($rawInput)) {
            foreach ($rawInput as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $tokens[] = (string)$value;
            }
        } elseif (is_string($rawInput) || is_numeric($rawInput)) {
            $tokens[] = (string)$rawInput;
        }

        $parts = [];
        foreach ($tokens as $token) {
            $chunks = preg_split('/[\s,]+/', trim($token)) ?: [];
            foreach ($chunks as $chunk) {
                $value = trim((string)$chunk);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        $ids = [];
        foreach ($parts as $part) {
            if (!preg_match('/^\d+$/', $part)) {
                continue;
            }

            $id = (int)$part;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function parseSingleIntegerIdBodyParam(string $name): ?int
    {
        $ids = $this->parseIntegerIdsInput($this->request->getBodyParam($name));
        return $ids[0] ?? null;
    }

    private function normalizeCidrInput(string $raw): ?string
    {
        $candidate = trim($raw);
        if ($candidate === '') {
            return null;
        }

        if (str_contains($candidate, '/')) {
            [$ip, $prefix] = explode('/', $candidate, 2);
            $ip = trim($ip);
            $prefix = trim($prefix);
        } else {
            $ip = $candidate;
            $prefix = '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $isIpv6 = str_contains($ip, ':');
        if ($prefix === '') {
            $prefixInt = $isIpv6 ? 128 : 32;
        } elseif (preg_match('/^\d+$/', $prefix) === 1) {
            $prefixInt = (int)$prefix;
        } else {
            return null;
        }

        $maxPrefix = $isIpv6 ? 128 : 32;
        if ($prefixInt < 0 || $prefixInt > $maxPrefix) {
            return null;
        }

        return sprintf('%s/%d', $ip, $prefixInt);
    }

    private function parseBooleanBodyParam(string $name, bool $default = false): bool
    {
        $raw = $this->request->getBodyParam($name);
        if ($raw === null) {
            return $default;
        }

        if (is_array($raw)) {
            if (empty($raw)) {
                return $default;
            }
            $raw = end($raw);
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $value = strtolower(trim((string)$raw));
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    private function parseNullableIntegerBodyParam(string $name): ?int
    {
        $raw = $this->request->getBodyParam($name);
        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            if (empty($raw)) {
                return null;
            }
            $raw = end($raw);
        }

        $value = trim((string)$raw);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private function getSettingsOverrides(): array
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('agents');
        if (!is_array($config)) {
            return [];
        }

        return [
            'enableWritesExperimental' => array_key_exists('enableWritesExperimental', $config),
            'enableCredentialUsageIndicator' => array_key_exists('enableCredentialUsageIndicator', $config),
            'notificationsEnabled' => array_key_exists('notificationsEnabled', $config),
            'notificationRecipients' => array_key_exists('notificationRecipients', $config),
            'notificationApprovalRequested' => array_key_exists('notificationApprovalRequested', $config),
            'notificationApprovalDecided' => array_key_exists('notificationApprovalDecided', $config),
            'notificationExecutionFailed' => array_key_exists('notificationExecutionFailed', $config),
            'notificationWebhookDlqFailed' => array_key_exists('notificationWebhookDlqFailed', $config),
            'notificationStatusChanged' => array_key_exists('notificationStatusChanged', $config),
            'webhookUrl' => array_key_exists('webhookUrl', $config),
            'webhookSecret' => array_key_exists('webhookSecret', $config),
            'reliabilityConsumerLagWarnSeconds' => array_key_exists('reliabilityConsumerLagWarnSeconds', $config),
            'reliabilityConsumerLagCriticalSeconds' => array_key_exists('reliabilityConsumerLagCriticalSeconds', $config),
        ];
    }

    private function parseEnvAwareIntegerSetting(string $name, int|string $default, int $min, int $max): int|string
    {
        $raw = $this->request->getBodyParam($name);
        if ($raw === null) {
            return $default;
        }

        if (is_array($raw)) {
            if (empty($raw)) {
                return $default;
            }
            $raw = end($raw);
        }

        if (is_int($raw)) {
            return $this->clampInteger($raw, $min, $max);
        }

        if (is_float($raw)) {
            return $this->clampInteger((int)$raw, $min, $max);
        }

        $value = trim((string)$raw);
        if ($value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return $this->clampInteger((int)$value, $min, $max);
        }

        return $value;
    }

    private function clampInteger(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function isNumericSettingValue(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return is_numeric(trim($value));
    }

    private function parseStringBodyParam(string $name, string $default = ''): string
    {
        $raw = $this->request->getBodyParam($name);
        if ($raw === null) {
            return $default;
        }

        if (is_array($raw)) {
            if (empty($raw)) {
                return $default;
            }
            $raw = end($raw);
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string)$raw);
        return trim($value);
    }

    private function parseJsonBodyParam(string $raw): array
    {
        $value = trim($raw);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildGuidedMap(array $fieldMap): array
    {
        $values = [];
        foreach ($fieldMap as $key => $paramName) {
            if (!is_string($key) || !is_string($paramName)) {
                continue;
            }

            $value = trim((string)$this->request->getBodyParam($paramName, ''));
            if ($value === '') {
                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function buildPolicyHintsByActionType(array $actionTypes): array
    {
        $service = Plugin::getInstance()->getControlPlaneService();
        $hints = [];
        foreach ($actionTypes as $actionTypeRaw) {
            $actionType = trim((string)$actionTypeRaw);
            if ($actionType === '' || isset($hints[$actionType])) {
                continue;
            }

            $policy = $service->resolvePolicyForAction($actionType);
            $hints[$actionType] = [
                'handle' => (string)($policy['handle'] ?? ''),
                'riskLevel' => (string)($policy['riskLevel'] ?? 'unknown'),
                'requiresApproval' => (bool)($policy['requiresApproval'] ?? true),
                'enabled' => (bool)($policy['enabled'] ?? true),
                'requiredScope' => (string)($policy['requiredScope'] ?? 'control:actions:execute'),
            ];
        }

        return $hints;
    }

    private function buildControlCredentialDisplayMap(Plugin $plugin): array
    {
        $map = [];
        try {
            $posture = $plugin->getSecurityPolicyService()->getCpPosture();
            $defaultScopes = (array)($posture['authentication']['tokenScopes'] ?? []);
            $credentials = $plugin->getCredentialService()->getManagedCredentials($defaultScopes);
            foreach ($credentials as $credential) {
                if (!is_array($credential)) {
                    continue;
                }

                $handle = trim((string)($credential['handle'] ?? ''));
                if ($handle === '') {
                    continue;
                }

                $displayName = trim((string)($credential['displayName'] ?? ''));
                if ($displayName === '') {
                    $displayName = $this->humanizeActorToken($handle);
                }

                $map[$handle] = $displayName;
                $map['agent:' . $handle] = $displayName;
            }
        } catch (Throwable $e) {
            Craft::warning('Unable to load control credential display map: ' . $e->getMessage(), __METHOD__);
        }

        return $map;
    }

    private function decorateControlApprovalsWithActorLabels(array $approvals, array $credentialDisplayByActorId): array
    {
        $decorated = [];
        foreach ($approvals as $approval) {
            if (!is_array($approval)) {
                continue;
            }

            $approval['requestedByLabel'] = $this->formatControlActorLabel((string)($approval['requestedBy'] ?? ''), '', $credentialDisplayByActorId);
            $approval['decidedByLabel'] = $this->formatControlActorLabel((string)($approval['decidedBy'] ?? ''), 'cp-user', $credentialDisplayByActorId);
            $approval['secondaryDecisionByLabel'] = $this->formatControlActorLabel((string)($approval['secondaryDecisionBy'] ?? ''), 'cp-user', $credentialDisplayByActorId);
            $decorated[] = $approval;
        }

        return $decorated;
    }

    private function formatApprovalAssuranceFlashSuffix(array $approval): string
    {
        $label = trim((string)($approval['assuranceModeLabel'] ?? ''));
        if ($label === '') {
            return '';
        }

        $suffix = ' Assurance: ' . $label . '.';
        $reason = trim((string)($approval['assuranceReasonLabel'] ?? ''));
        if ($reason !== '') {
            $suffix .= ' ' . $reason;
        }

        return $suffix;
    }

    private function resolveControlApprovalCompletionState(array $approval): array
    {
        $actionType = strtolower(trim((string)($approval['actionType'] ?? '')));
        $latestExecution = is_array($approval['latestExecution'] ?? null) ? (array)$approval['latestExecution'] : [];
        $executionStatus = strtolower(trim((string)($latestExecution['status'] ?? '')));

        if ($actionType === 'entry.updatedraft') {
            $reviewDraftId = (int)($approval['reviewDraftId'] ?? 0);
            $reviewMode = strtolower(trim((string)($approval['reviewMode'] ?? '')));
            if ($reviewDraftId > 0 && $reviewMode !== 'draft') {
                return [
                    'completed' => true,
                    'label' => 'APPLIED',
                    'detail' => 'Draft is no longer active; treated as applied/resolved.',
                    'at' => $latestExecution['executedAt'] ?? $latestExecution['dateCreated'] ?? null,
                ];
            }

            return [
                'completed' => false,
                'label' => 'APPROVED',
                'detail' => 'Awaiting draft apply in Craft.',
                'at' => null,
            ];
        }

        if ($executionStatus === 'succeeded') {
            return [
                'completed' => true,
                'label' => 'COMPLETED',
                'detail' => 'Execution succeeded.',
                'at' => $latestExecution['executedAt'] ?? $latestExecution['dateCreated'] ?? null,
            ];
        }

        return [
            'completed' => false,
            'label' => 'APPROVED',
            'detail' => 'Awaiting follow-up.',
            'at' => null,
        ];
    }

    private function decorateControlAuditEventsWithActorLabels(array $events, array $credentialDisplayByActorId): array
    {
        $decorated = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $event['actorLabel'] = $this->formatControlActorLabel(
                (string)($event['actorId'] ?? ''),
                (string)($event['actorType'] ?? ''),
                $credentialDisplayByActorId
            );
            $decorated[] = $event;
        }

        return $decorated;
    }

    private function decorateControlItemsWithActionLabels(array $items): array
    {
        $decorated = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $actionType = (string)($item['actionType'] ?? '');
            $item['actionLabel'] = $this->humanizeControlActionType($actionType);
            $targetEntry = $this->resolveControlTargetEntryDetails($item);
            $item['targetEntryId'] = $targetEntry['id'];
            $item['targetEntrySiteId'] = $targetEntry['siteId'];
            $item['targetEntryCpUrl'] = $targetEntry['cpEditUrl'];
            $item['targetTitle'] = $this->resolveControlTargetTitle($item, $targetEntry);
            $item['targetEntryCanonicalId'] = $targetEntry['canonicalId'] ?? null;
            $item['targetEntryCanonicalSiteId'] = $targetEntry['canonicalSiteId'] ?? null;
            $item['targetEntryCanonicalCpUrl'] = $targetEntry['canonicalCpEditUrl'] ?? null;
            $item['targetEntryCanonicalTitle'] = $targetEntry['canonicalTitle'] ?? null;
            $item['reviewMode'] = $targetEntry['reviewMode'] ?? 'none';
            $item['reviewDraftId'] = $targetEntry['draftId'] ?? null;
            $item['targetEntryDraftRecordId'] = $targetEntry['draftRecordId'] ?? null;
            $reviewContext = $this->buildControlReviewContext($item, $targetEntry);
            $item['reviewChangeSummary'] = $reviewContext['changeSummary'];
            $item['reviewGuards'] = $reviewContext['guards'];
            $item['reviewHasBlockingGuards'] = (bool)($reviewContext['hasBlockingGuards'] ?? false);
            $decorated[] = $item;
        }

        return $decorated;
    }

    private function resolveControlTargetTitle(array $item, ?array $targetEntry = null): ?string
    {
        $actionType = strtolower(trim((string)($item['actionType'] ?? '')));
        $actionRef = trim((string)($item['actionRef'] ?? ''));
        $requestPayload = is_array($item['requestPayload'] ?? null) ? (array)$item['requestPayload'] : [];
        $resultPayload = is_array($item['resultPayload'] ?? null) ? (array)$item['resultPayload'] : [];
        $targetEntry ??= $this->resolveControlTargetEntryDetails($item);

        if ($actionType === 'entry.updatedraft') {
            $payloadTitle = $this->firstNonEmptyString([
                $requestPayload['title'] ?? null,
                $requestPayload['entryTitle'] ?? null,
                $resultPayload['title'] ?? null,
            ]);
            if ($payloadTitle !== null) {
                return $payloadTitle;
            }

            $entryTitle = $this->firstNonEmptyString([$targetEntry['title'] ?? null]);
            if ($entryTitle !== null) {
                return $entryTitle;
            }

            $draftTitle = $this->firstNonEmptyString([
                $requestPayload['draftName'] ?? null,
                $resultPayload['draftName'] ?? null,
            ]);
            if ($draftTitle !== null) {
                return $draftTitle;
            }
        }

        $genericTitle = $this->firstNonEmptyString([
            $requestPayload['title'] ?? null,
            $requestPayload['name'] ?? null,
            $requestPayload['label'] ?? null,
            $resultPayload['title'] ?? null,
            $resultPayload['name'] ?? null,
            $resultPayload['label'] ?? null,
        ]);
        if ($genericTitle !== null) {
            return $genericTitle;
        }

        return $this->humanizeControlActionRef($actionRef);
    }

    private function resolveControlTargetEntryDetails(array $item): array
    {
        $actionType = strtolower(trim((string)($item['actionType'] ?? '')));
        if ($actionType !== 'entry.updatedraft') {
            return [
                'id' => null,
                'siteId' => null,
                'title' => null,
                'cpEditUrl' => null,
                'canonicalId' => null,
                'canonicalSiteId' => null,
                'canonicalTitle' => null,
                'canonicalCpEditUrl' => null,
                'draftId' => null,
                'draftRecordId' => null,
                'draftExists' => null,
                'reviewMode' => 'none',
            ];
        }

        $targetIds = $this->extractControlTargetIds($item);
        $entryId = (int)($targetIds['entryId'] ?? 0);
        $preferredSiteId = (int)($targetIds['siteId'] ?? 0);
        $draftId = (int)($targetIds['draftId'] ?? 0);

        if ($entryId <= 0) {
            return [
                'id' => null,
                'siteId' => $preferredSiteId > 0 ? $preferredSiteId : null,
                'title' => null,
                'cpEditUrl' => null,
                'canonicalId' => null,
                'canonicalSiteId' => $preferredSiteId > 0 ? $preferredSiteId : null,
                'canonicalTitle' => null,
                'canonicalCpEditUrl' => null,
                'draftId' => $draftId > 0 ? $draftId : null,
                'draftRecordId' => null,
                'draftExists' => $draftId > 0 ? false : null,
                'reviewMode' => 'missing',
            ];
        }

        $canonicalDescriptor = $this->resolveEntryDescriptorById($entryId, $preferredSiteId);
        $draftDescriptor = null;
        if ($draftId > 0) {
            $candidateDraftDescriptor = $this->resolveDraftDescriptorById($draftId, $preferredSiteId);
            if (
                is_array($candidateDraftDescriptor) &&
                (int)($candidateDraftDescriptor['canonicalId'] ?? 0) === $entryId
            ) {
                $draftDescriptor = $candidateDraftDescriptor;
            }
        }

        $reviewDescriptor = $draftDescriptor ?? $canonicalDescriptor;
        if (!is_array($reviewDescriptor) && !is_array($canonicalDescriptor)) {
            return [
                'id' => null,
                'siteId' => null,
                'title' => null,
                'cpEditUrl' => null,
                'canonicalId' => null,
                'canonicalSiteId' => null,
                'canonicalTitle' => null,
                'canonicalCpEditUrl' => null,
                'draftId' => $draftId > 0 ? $draftId : null,
                'draftRecordId' => null,
                'draftExists' => $draftId > 0 ? false : null,
                'reviewMode' => 'missing',
            ];
        }

        $canonicalSiteId = (int)($canonicalDescriptor['siteId'] ?? ($preferredSiteId > 0 ? $preferredSiteId : 0));
        $reviewSiteId = (int)($reviewDescriptor['siteId'] ?? $canonicalSiteId);

        return [
            'id' => (int)($reviewDescriptor['id'] ?? 0) ?: null,
            'siteId' => $reviewSiteId > 0 ? $reviewSiteId : null,
            'title' => $reviewDescriptor['title'] ?? null,
            'cpEditUrl' => $reviewDescriptor['cpEditUrl'] ?? null,
            'canonicalId' => (int)($canonicalDescriptor['id'] ?? $entryId),
            'canonicalSiteId' => $canonicalSiteId > 0 ? $canonicalSiteId : null,
            'canonicalTitle' => $canonicalDescriptor['title'] ?? null,
            'canonicalCpEditUrl' => $canonicalDescriptor['cpEditUrl'] ?? null,
            'draftId' => $draftId > 0 ? $draftId : null,
            'draftRecordId' => isset($draftDescriptor['draftRecordId']) ? (int)$draftDescriptor['draftRecordId'] : null,
            'draftExists' => $draftId > 0 ? $draftDescriptor !== null : null,
            'reviewMode' => $draftDescriptor !== null ? 'draft' : 'canonical',
        ];
    }

    private function buildControlReviewContext(array $item, array $targetEntry): array
    {
        $context = [
            'changeSummary' => [],
            'guards' => [],
            'hasBlockingGuards' => false,
        ];

        $actionType = strtolower(trim((string)($item['actionType'] ?? '')));
        if ($actionType !== 'entry.updatedraft') {
            return $context;
        }

        $requestPayload = is_array($item['requestPayload'] ?? null) ? (array)$item['requestPayload'] : [];
        $resultPayload = is_array($item['resultPayload'] ?? null) ? (array)$item['resultPayload'] : [];
        $targetIds = $this->extractControlTargetIds($item);
        $entryId = (int)($targetIds['entryId'] ?? 0);
        $siteId = (int)($targetIds['siteId'] ?? 0);
        $draftId = (int)($targetIds['draftId'] ?? 0);
        $status = strtolower(trim((string)($item['status'] ?? '')));

        $context['changeSummary'] = $this->buildControlChangeSummary($requestPayload, $resultPayload);
        if (empty($context['changeSummary'])) {
            $context['changeSummary'][] = [
                'label' => 'Requested update',
                'value' => 'No explicit field mutations were provided in the request payload.',
            ];
        }

        if ($status !== 'pending') {
            return $context;
        }

        if ($entryId <= 0) {
            $context['guards'][] = [
                'level' => 'critical',
                'label' => 'Missing entry target',
                'detail' => 'Approval payload is missing `entryId`.',
            ];
            $context['hasBlockingGuards'] = true;
            return $context;
        }

        if ($draftId > 0 && !($targetEntry['draftExists'] ?? false)) {
            $context['guards'][] = [
                'level' => 'critical',
                'label' => 'Draft not found',
                'detail' => sprintf('Draft #%d is missing or no longer linked to entry #%d.', $draftId, $entryId),
            ];
            $context['hasBlockingGuards'] = true;
            return $context;
        }

        if ($draftId <= 0) {
            return $context;
        }

        $draft = $this->findControlEntryForReview($draftId, $siteId, true);
        if (!$draft instanceof Entry || !$draft->getIsDraft()) {
            $context['guards'][] = [
                'level' => 'critical',
                'label' => 'Draft not readable',
                'detail' => sprintf('Draft #%d could not be loaded for review.', $draftId),
            ];
            $context['hasBlockingGuards'] = true;
            return $context;
        }

        $outdatedAttributes = $draft->getOutdatedAttributes();
        $outdatedFields = $draft->getOutdatedFields();
        if (!empty($outdatedAttributes) || !empty($outdatedFields)) {
            $context['guards'][] = [
                'level' => 'warning',
                'label' => 'Canonical changed since draft',
                'detail' => sprintf(
                    '%d attributes and %d fields were updated in canonical after this draft was created.',
                    count($outdatedAttributes),
                    count($outdatedFields)
                ),
            ];
        }

        $draft->clearErrors();
        if (!$draft->validate()) {
            $validationErrors = $this->normalizeControlValidationErrors($draft->getErrors());
            $context['guards'][] = [
                'level' => 'warning',
                'label' => 'Validation warnings',
                'detail' => !empty($validationErrors)
                    ? implode(' ', array_slice($validationErrors, 0, 2))
                    : 'Draft currently has validation issues.',
            ];
        }

        return $context;
    }

    private function buildControlDiffPayload(array $item, array $targetEntry): array
    {
        $actionType = strtolower(trim((string)($item['actionType'] ?? '')));
        $targetIds = $this->extractControlTargetIds($item);
        $canonicalEntryId = (int)($targetIds['entryId'] ?? 0);
        $siteId = (int)($targetIds['siteId'] ?? 0);
        $site = $siteId > 0 ? Craft::$app->getSites()->getSiteById($siteId) : null;
        $status = strtolower(trim((string)($item['status'] ?? '')));

        $payload = [
            'approvalId' => (int)($item['id'] ?? 0),
            'actionType' => $actionType,
            'supported' => $actionType === 'entry.updatedraft',
            'target' => [
                'entryId' => $canonicalEntryId > 0 ? $canonicalEntryId : null,
                'entryTitle' => $targetEntry['canonicalTitle'] ?? $targetEntry['title'] ?? $item['targetTitle'] ?? 'Untitled target',
                'reviewEntryId' => $targetEntry['id'] ?? null,
                'draftId' => $targetEntry['draftId'] ?? null,
                'draftRecordId' => $targetEntry['draftRecordId'] ?? null,
                'siteId' => $siteId > 0 ? $siteId : null,
                'siteHandle' => $site?->handle,
                'siteName' => $site?->name,
                'language' => $site?->language,
                'sectionHandle' => null,
            ],
            'mode' => 'unsupported',
            'modeLabel' => 'Unsupported',
            'summary' => [
                'changedFieldCount' => 0,
                'unsupportedFieldCount' => 0,
            ],
            'message' => 'Diff preview is only available for governed entry draft approvals in this version.',
            'rows' => [],
            'redline' => [
                'available' => false,
                'message' => 'Redline preview is only available for text-like field changes in this version.',
                'rows' => [],
            ],
        ];

        if ($actionType !== 'entry.updatedraft') {
            return $payload;
        }

        $canonical = $canonicalEntryId > 0 ? $this->findControlEntryForReview($canonicalEntryId, $siteId, false) : null;
        if (!$canonical instanceof Entry) {
            $payload['mode'] = 'missing';
            $payload['modeLabel'] = 'Unavailable';
            $payload['message'] = 'The target canonical entry could not be loaded for diff review.';
            return $payload;
        }

        $payload['target']['entryTitle'] = trim((string)$canonical->title) !== '' ? trim((string)$canonical->title) : ($payload['target']['entryTitle'] ?? 'Untitled target');
        $payload['target']['siteId'] = (int)($canonical->siteId ?? $siteId) ?: ($payload['target']['siteId'] ?? null);
        $resolvedSiteId = (int)($payload['target']['siteId'] ?? 0);
        $resolvedSite = $resolvedSiteId > 0 ? Craft::$app->getSites()->getSiteById($resolvedSiteId) : null;
        if ($resolvedSite !== null) {
            $payload['target']['siteHandle'] = $resolvedSite->handle;
            $payload['target']['siteName'] = $resolvedSite->name;
            $payload['target']['language'] = $resolvedSite->language;
        }

        $section = $canonical->getSection();
        if ($section !== null) {
            $payload['target']['sectionHandle'] = trim((string)$section->handle) ?: null;
        }

        $fieldHandles = $this->buildControlDiffFieldHandles($item);
        $fieldLabels = $this->buildControlFieldLabelMap($canonical);
        $draftId = (int)($targetEntry['draftId'] ?? 0);
        $draft = $draftId > 0 ? $this->findControlEntryForReview($draftId, $resolvedSiteId > 0 ? $resolvedSiteId : $siteId, true) : null;
        if ($draft instanceof Entry && $draft->getIsDraft()) {
            $fieldLabels = array_replace($fieldLabels, $this->buildControlFieldLabelMap($draft));
            $payload['mode'] = 'draft';
            $payload['modeLabel'] = 'Saved draft';
            $payload['message'] = 'Showing the linked saved draft against the current canonical entry for the fields targeted by this governed request.';
            $payload['rows'] = $this->buildControlDiffRowsFromDraft($item, $canonical, $draft, $fieldHandles, $fieldLabels);
        } elseif (($revisionCompare = $this->resolveControlCompletedRevisionComparison($item, $canonical)) !== null) {
            $fieldLabels = array_replace(
                $fieldLabels,
                $this->buildControlFieldLabelMap($revisionCompare['before'], $revisionCompare['after'])
            );
            $payload['mode'] = 'revision';
            $payload['modeLabel'] = 'Applied revision';
            $payload['message'] = 'Showing the applied revision against the immediately previous revision because the active draft is no longer available.';
            $payload['rows'] = $this->buildControlDiffRowsFromEntryPair(
                $item,
                $revisionCompare['before'],
                $revisionCompare['after'],
                $fieldHandles,
                $fieldLabels
            );
        } else {
            $payload['mode'] = 'request';
            $payload['modeLabel'] = 'Requested change';
            $payload['message'] = 'Showing requested values against the current canonical entry. No readable saved draft is linked yet.';
            $payload['rows'] = $this->buildControlDiffRowsFromRequestPayload($item, $canonical, $fieldHandles, $fieldLabels);
        }

        $payload['summary'] = [
            'changedFieldCount' => count($payload['rows']),
            'unsupportedFieldCount' => count(array_filter(
                $payload['rows'],
                static fn(array $row): bool => !((bool)($row['supported'] ?? false))
            )),
        ];
        $payload['redline'] = $this->buildControlRedlinePayload($payload['rows']);

        if (empty($payload['rows'])) {
            $changedKeySet = $this->buildControlDiffChangedKeySet($item);
            if (!empty(array_intersect(array_keys($changedKeySet), ['draftname', 'draftnotes']))) {
                $payload['message'] = 'This request only changes draft metadata in this version. No entry content fields were targeted.';
            } elseif (in_array($status, ['approved', 'completed'], true)) {
                $payload['message'] = 'No changed content rows are available. The current canonical entry may already reflect the approved values, or the saved draft is no longer available for precise compare.';
            } else {
                $payload['message'] = 'No changed content rows could be derived from the current request payload.';
            }
        }

        return $payload;
    }

    private function buildControlRedlinePayload(array $rows): array
    {
        $payload = [
            'available' => false,
            'message' => 'Redline preview highlights inserted and removed text for text-like field changes only. Structured fields stay in the Structured tab.',
            'rows' => [],
        ];

        foreach ($rows as $row) {
            $redlineRow = $this->buildControlRedlineRow($row);
            if ($redlineRow !== null) {
                $payload['rows'][] = $redlineRow;
            }
        }

        if (!empty($payload['rows'])) {
            $payload['available'] = true;
            $payload['message'] = 'Redline preview shows text changes inline with surrounding context. Markup is flattened to readable text in this version.';
        }

        return $payload;
    }

    private function buildControlRedlineRow(array $row): ?array
    {
        if (!(bool)($row['supported'] ?? false)) {
            return null;
        }

        if (($row['kind'] ?? '') !== 'text') {
            return null;
        }

        $beforeText = $this->normalizeControlRedlineTextValue($row['before'] ?? null);
        $afterText = $this->normalizeControlRedlineTextValue($row['after'] ?? null);
        if ($beforeText === $afterText) {
            return null;
        }

        $beforeTokens = $this->tokenizeControlRedlineText($beforeText);
        $afterTokens = $this->tokenizeControlRedlineText($afterText);
        if ((count($beforeTokens) + count($afterTokens)) > 800) {
            return [
                'path' => (string)($row['path'] ?? ''),
                'label' => (string)($row['label'] ?? 'Field'),
                'change' => (string)($row['change'] ?? 'changed'),
                'supported' => false,
                'note' => 'Redline preview is limited to shorter text in this version. Use Structured diff for this field.',
                'segments' => $this->buildControlRedlinePrefixSuffixSegments($beforeTokens, $afterTokens),
            ];
        }

        return [
            'path' => (string)($row['path'] ?? ''),
            'label' => (string)($row['label'] ?? 'Field'),
            'change' => (string)($row['change'] ?? 'changed'),
            'supported' => true,
            'note' => null,
            'segments' => $this->buildControlRedlineSegments($beforeTokens, $afterTokens),
        ];
    }

    private function buildControlDiffRowsFromDraft(array $item, Entry $canonical, Entry $draft, array $fieldHandles, array $fieldLabels): array
    {
        return $this->buildControlDiffRowsFromEntryPair($item, $canonical, $draft, $fieldHandles, $fieldLabels);
    }

    private function buildControlDiffRowsFromRequestPayload(array $item, Entry $canonical, array $fieldHandles, array $fieldLabels): array
    {
        $rows = [];
        $requestPayload = is_array($item['requestPayload'] ?? null) ? (array)$item['requestPayload'] : [];

        if (array_key_exists('title', $requestPayload)) {
            $this->appendControlDiffRows($rows, 'title', ['Title'], (string)$canonical->title, $requestPayload['title']);
        }

        if (array_key_exists('slug', $requestPayload)) {
            $this->appendControlDiffRows($rows, 'slug', ['Slug'], (string)$canonical->slug, $requestPayload['slug']);
        }

        $requestedFields = is_array($requestPayload['fields'] ?? null) ? (array)$requestPayload['fields'] : [];
        if (!empty($fieldHandles) && !empty($requestedFields)) {
            $canonicalSerialized = $canonical->getSerializedFieldValues($fieldHandles);
            foreach ($fieldHandles as $fieldHandle) {
                if (!array_key_exists($fieldHandle, $requestedFields)) {
                    continue;
                }

                $fieldLabel = $fieldLabels[$fieldHandle] ?? $this->humanizeControlDiffKey($fieldHandle);
                $before = $canonicalSerialized[$fieldHandle] ?? null;
                $after = $requestedFields[$fieldHandle];
                $this->appendControlDiffRows($rows, 'fields.' . $fieldHandle, [$fieldLabel], $before, $after);
            }
        }

        return $rows;
    }

    private function buildControlDiffRowsFromEntryPair(array $item, Entry $beforeEntry, Entry $afterEntry, array $fieldHandles, array $fieldLabels): array
    {
        $rows = [];
        $changedKeySet = $this->buildControlDiffChangedKeySet($item);

        if (isset($changedKeySet['title'])) {
            $this->appendControlDiffRows($rows, 'title', ['Title'], (string)$beforeEntry->title, (string)$afterEntry->title);
        }

        if (isset($changedKeySet['slug'])) {
            $this->appendControlDiffRows($rows, 'slug', ['Slug'], (string)$beforeEntry->slug, (string)$afterEntry->slug);
        }

        if (!empty($fieldHandles)) {
            $beforeSerialized = $beforeEntry->getSerializedFieldValues($fieldHandles);
            $afterSerialized = $afterEntry->getSerializedFieldValues($fieldHandles);
            foreach ($fieldHandles as $fieldHandle) {
                $fieldLabel = $fieldLabels[$fieldHandle] ?? $this->humanizeControlDiffKey($fieldHandle);
                $before = $beforeSerialized[$fieldHandle] ?? null;
                $after = $afterSerialized[$fieldHandle] ?? null;
                $this->appendControlDiffRows($rows, 'fields.' . $fieldHandle, [$fieldLabel], $before, $after);
            }
        }

        return $rows;
    }

    private function resolveControlCompletedRevisionComparison(array $item, Entry $canonical): ?array
    {
        $actionType = strtolower(trim((string)($item['actionType'] ?? '')));
        if ($actionType !== 'entry.updatedraft') {
            return null;
        }

        $latestExecution = is_array($item['latestExecution'] ?? null) ? (array)$item['latestExecution'] : [];
        $executionStatus = strtolower(trim((string)($latestExecution['status'] ?? '')));
        if ($executionStatus !== 'succeeded') {
            return null;
        }

        $siteId = (int)($canonical->siteId ?? 0);
        $revisionDescriptors = $this->resolveControlRevisionDescriptorsByCanonicalId((int)$canonical->id, $siteId);
        if (count($revisionDescriptors) < 2) {
            return null;
        }

        $matchedRevision = $this->matchControlCompletedRevisionDescriptor($item, $revisionDescriptors);
        if (!is_array($matchedRevision)) {
            return null;
        }

        $matchedIndex = null;
        foreach ($revisionDescriptors as $index => $descriptor) {
            if ((int)($descriptor['id'] ?? 0) === (int)($matchedRevision['id'] ?? 0)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null || $matchedIndex === 0) {
            return null;
        }

        $previousRevision = $revisionDescriptors[$matchedIndex - 1] ?? null;
        if (!is_array($previousRevision)) {
            return null;
        }

        $afterRevisionEntry = $this->findControlRevisionForReview((int)($matchedRevision['id'] ?? 0), $siteId);
        $beforeRevisionEntry = $this->findControlRevisionForReview((int)($previousRevision['id'] ?? 0), $siteId);
        if (!$afterRevisionEntry instanceof Entry || !$beforeRevisionEntry instanceof Entry) {
            return null;
        }

        return [
            'before' => $beforeRevisionEntry,
            'after' => $afterRevisionEntry,
            'matchedRevision' => $matchedRevision,
            'previousRevision' => $previousRevision,
        ];
    }

    private function resolveControlRevisionDescriptorsByCanonicalId(int $canonicalId, int $siteId = 0): array
    {
        if ($canonicalId <= 0) {
            return [];
        }

        $query = (new Query())
            ->select([
                'elements.id',
                'elements.canonicalId',
                'elements.revisionId',
                'elements.dateCreated',
                'elements.dateUpdated',
                'elements_sites.siteId',
                'elements_sites.title',
                'elements_sites.uri',
                'revisions.num',
                'revisions.notes',
            ])
            ->from(['elements' => '{{%elements}}'])
            ->innerJoin('{{%revisions}} revisions', '[[revisions.id]] = [[elements.revisionId]]')
            ->innerJoin('{{%elements_sites}} elements_sites', '[[elements_sites.elementId]] = [[elements.id]]')
            ->where([
                'elements.type' => Entry::class,
                'elements.canonicalId' => $canonicalId,
            ])
            ->andWhere(['not', ['elements.revisionId' => null]])
            ->orderBy([
                'revisions.num' => SORT_ASC,
                'elements.id' => SORT_ASC,
            ]);

        if ($siteId > 0) {
            $query->andWhere(['elements_sites.siteId' => $siteId]);
        }

        $rows = $query->all();
        if (empty($rows)) {
            return [];
        }

        return array_values(array_map(function(array $row): array {
            $siteId = (int)($row['siteId'] ?? 0);
            $title = trim((string)($row['title'] ?? ''));
            $uri = trim((string)($row['uri'] ?? ''));

            return [
                'id' => (int)($row['id'] ?? 0),
                'canonicalId' => (int)($row['canonicalId'] ?? 0),
                'revisionId' => (int)($row['revisionId'] ?? 0),
                'num' => (int)($row['num'] ?? 0),
                'siteId' => $siteId > 0 ? $siteId : null,
                'title' => $title !== '' ? $title : null,
                'notes' => trim((string)($row['notes'] ?? '')) ?: null,
                'cpEditUrl' => $uri !== '' ? 'entries/' . (int)($row['id'] ?? 0) : null,
                'dateCreated' => trim((string)($row['dateCreated'] ?? '')) ?: null,
                'dateUpdated' => trim((string)($row['dateUpdated'] ?? '')) ?: null,
            ];
        }, $rows));
    }

    private function matchControlCompletedRevisionDescriptor(array $item, array $descriptors): ?array
    {
        if (empty($descriptors)) {
            return null;
        }

        $requestPayload = is_array($item['requestPayload'] ?? null) ? (array)$item['requestPayload'] : [];
        $latestExecution = is_array($item['latestExecution'] ?? null) ? (array)$item['latestExecution'] : [];
        $requestedTitle = trim((string)($requestPayload['title'] ?? ''));
        $draftNotes = trim((string)($requestPayload['draftNotes'] ?? ''));
        $executedAtTs = strtotime((string)($latestExecution['executedAt'] ?? $latestExecution['dateCreated'] ?? ''));

        $ranked = [];
        foreach ($descriptors as $descriptor) {
            $score = 0;
            if ($draftNotes !== '' && trim((string)($descriptor['notes'] ?? '')) === $draftNotes) {
                $score += 100;
            }
            if ($requestedTitle !== '' && trim((string)($descriptor['title'] ?? '')) === $requestedTitle) {
                $score += 50;
            }

            $descriptorTs = strtotime((string)($descriptor['dateCreated'] ?? $descriptor['dateUpdated'] ?? ''));
            $timeDistance = ($executedAtTs !== false && $descriptorTs !== false)
                ? abs($descriptorTs - $executedAtTs)
                : PHP_INT_MAX;

            $ranked[] = [
                'descriptor' => $descriptor,
                'score' => $score,
                'timeDistance' => $timeDistance,
            ];
        }

        usort($ranked, static function(array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            if ($left['timeDistance'] !== $right['timeDistance']) {
                return $left['timeDistance'] <=> $right['timeDistance'];
            }

            return ((int)($right['descriptor']['num'] ?? 0)) <=> ((int)($left['descriptor']['num'] ?? 0));
        });

        $best = $ranked[0]['descriptor'] ?? null;
        return is_array($best) ? $best : null;
    }

    private function buildControlDiffFieldHandles(array $item): array
    {
        $handles = [];
        $requestPayload = is_array($item['requestPayload'] ?? null) ? (array)$item['requestPayload'] : [];
        if (is_array($requestPayload['fields'] ?? null)) {
            foreach (array_keys((array)$requestPayload['fields']) as $fieldHandle) {
                $normalized = trim((string)$fieldHandle);
                if ($normalized !== '') {
                    $handles[] = $normalized;
                }
            }
        }

        $changedKeySet = $this->buildControlDiffChangedKeySet($item);
        foreach (array_keys($changedKeySet) as $key) {
            if (str_starts_with($key, 'fields.')) {
                $fieldHandle = trim(substr($key, 7));
                if ($fieldHandle !== '') {
                    $handles[] = $fieldHandle;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    private function buildControlDiffChangedKeySet(array $item): array
    {
        $keys = [];
        $requestPayload = is_array($item['requestPayload'] ?? null) ? (array)$item['requestPayload'] : [];
        if (array_key_exists('title', $requestPayload)) {
            $keys['title'] = true;
        }
        if (array_key_exists('slug', $requestPayload)) {
            $keys['slug'] = true;
        }
        if (array_key_exists('draftName', $requestPayload)) {
            $keys['draftname'] = true;
        }
        if (array_key_exists('draftNotes', $requestPayload)) {
            $keys['draftnotes'] = true;
        }

        if (is_array($requestPayload['fields'] ?? null)) {
            foreach (array_keys((array)$requestPayload['fields']) as $fieldHandle) {
                $normalized = trim((string)$fieldHandle);
                if ($normalized !== '') {
                    $keys['fields.' . $normalized] = true;
                }
            }
        }

        $sources = [
            is_array($item['resultPayload'] ?? null) ? (array)$item['resultPayload'] : [],
            is_array($item['latestExecution']['resultPayload'] ?? null) ? (array)$item['latestExecution']['resultPayload'] : [],
        ];
        foreach ($sources as $payload) {
            foreach ((array)($payload['changedKeys'] ?? []) as $changedKey) {
                $normalized = strtolower(trim((string)$changedKey));
                if ($normalized !== '') {
                    $keys[$normalized] = true;
                }
            }
        }

        return $keys;
    }

    private function buildControlFieldLabelMap(Entry ...$entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $fieldLayout = $entry->getFieldLayout();
            if ($fieldLayout === null) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                $handle = trim((string)$field->handle);
                if ($handle === '') {
                    continue;
                }

                $label = trim((string)($field->name ?? ''));
                $map[$handle] = $label !== '' ? $label : $this->humanizeControlDiffKey($handle);
            }
        }

        return $map;
    }

    private function appendControlDiffRows(array &$rows, string $path, array $labelParts, mixed $before, mixed $after): void
    {
        $beforeValue = $this->normalizeControlDiffValue($before);
        $afterValue = $this->normalizeControlDiffValue($after);
        if ($this->controlDiffValuesEqual($beforeValue, $afterValue)) {
            return;
        }

        $beforeIsArray = is_array($beforeValue);
        $afterIsArray = is_array($afterValue);

        if ($beforeIsArray xor $afterIsArray) {
            $rows[] = $this->createControlDiffRow($path, $labelParts, $beforeValue, $afterValue, 'structured', false, 'This field changed shape and is only shown as a fallback summary in this version.');
            return;
        }

        if (!$beforeIsArray && !$afterIsArray) {
            $rows[] = $this->createControlDiffRow($path, $labelParts, $beforeValue, $afterValue, $this->resolveControlDiffKind($beforeValue, $afterValue), true);
            return;
        }

        if ($this->isControlDiffSimpleList($beforeValue) && $this->isControlDiffSimpleList($afterValue)) {
            $rows[] = $this->createControlDiffRow($path, $labelParts, $beforeValue, $afterValue, 'list', true);
            return;
        }

        $rowCountBefore = count($rows);
        if (array_is_list($beforeValue) || array_is_list($afterValue)) {
            $max = max(count($beforeValue), count($afterValue));
            for ($index = 0; $index < $max; $index++) {
                $this->appendControlDiffRows(
                    $rows,
                    sprintf('%s[%d]', $path, $index),
                    array_merge($labelParts, [$this->describeControlDiffListItem($index, $beforeValue[$index] ?? null, $afterValue[$index] ?? null)]),
                    $beforeValue[$index] ?? null,
                    $afterValue[$index] ?? null
                );
            }
        } else {
            $keys = array_values(array_unique(array_merge(array_keys($beforeValue), array_keys($afterValue))));
            foreach ($keys as $key) {
                $normalizedKey = trim((string)$key);
                if ($normalizedKey === '' || $this->isControlDiffInternalKey($normalizedKey)) {
                    continue;
                }

                if ($normalizedKey === 'fields' && (is_array($beforeValue[$key] ?? null) || is_array($afterValue[$key] ?? null))) {
                    $this->appendControlDiffRows($rows, $path . '.fields', $labelParts, $beforeValue[$key] ?? [], $afterValue[$key] ?? []);
                    continue;
                }

                $this->appendControlDiffRows(
                    $rows,
                    $path . '.' . $normalizedKey,
                    array_merge($labelParts, [$this->humanizeControlDiffKey($normalizedKey)]),
                    $beforeValue[$key] ?? null,
                    $afterValue[$key] ?? null
                );
            }
        }

        if (count($rows) === $rowCountBefore) {
            $rows[] = $this->createControlDiffRow($path, $labelParts, $beforeValue, $afterValue, 'structured', false, 'This field changed, but only a fallback summary is available in this version.');
        }
    }

    private function normalizeControlDiffValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $nested) {
                $normalized[$key] = $this->normalizeControlDiffValue($nested);
            }
            return $normalized;
        }

        if ($value instanceof \Stringable) {
            return (string)$value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return $this->normalizeControlDiffValue((array)$value);
        }

        return $value;
    }

    private function controlDiffValuesEqual(mixed $before, mixed $after): bool
    {
        if (is_array($before) || is_array($after)) {
            return $this->encodeComparableControlDiffValue($before) === $this->encodeComparableControlDiffValue($after);
        }

        return $before === $after;
    }

    private function encodeComparableControlDiffValue(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return is_string($encoded) ? $encoded : '';
    }

    private function isControlDiffSimpleList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                if (!$this->isControlDiffSimpleDisplayMap($item)) {
                    return false;
                }
                continue;
            }

            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    private function isControlDiffSimpleDisplayMap(array $value): bool
    {
        $keys = array_map(static fn($key): string => strtolower(trim((string)$key)), array_keys($value));
        foreach ($keys as $key) {
            if (!in_array($key, ['id', 'label', 'title', 'name', 'filename', 'slug', 'uri', 'url'], true)) {
                return false;
            }
        }

        return true;
    }

    private function isControlDiffInternalKey(string $key): bool
    {
        $normalized = strtolower($key);
        return in_array($normalized, [
            'id',
            'uid',
            'sortorder',
            'fieldid',
            'typeid',
            'ownerid',
            'canonicalid',
            'siteid',
        ], true);
    }

    private function describeControlDiffListItem(int $index, mixed $before, mixed $after): string
    {
        $candidate = is_array($after) ? $after : (is_array($before) ? $before : []);
        $type = $this->firstNonEmptyString([
            is_array($candidate) ? ($candidate['type'] ?? null) : null,
            is_array($candidate) ? ($candidate['typeHandle'] ?? null) : null,
            is_array($candidate) ? ($candidate['blockType'] ?? null) : null,
            is_array($candidate) ? ($candidate['kind'] ?? null) : null,
        ]);

        if ($type !== null) {
            return sprintf('Block %d (%s)', $index + 1, $this->humanizeControlDiffKey($type));
        }

        return sprintf('Item %d', $index + 1);
    }

    private function createControlDiffRow(
        string $path,
        array $labelParts,
        mixed $before,
        mixed $after,
        string $kind,
        bool $supported,
        ?string $note = null,
    ): array {
        return [
            'path' => $path,
            'label' => implode(' -> ', array_filter(array_map(static fn(mixed $part): string => trim((string)$part), $labelParts))),
            'kind' => $kind,
            'change' => $this->resolveControlDiffChangeType($before, $after),
            'before' => $before,
            'after' => $after,
            'beforeDisplay' => $this->formatControlDiffDisplayValue($before),
            'afterDisplay' => $this->formatControlDiffDisplayValue($after),
            'supported' => $supported,
            'note' => $note,
        ];
    }

    private function resolveControlDiffChangeType(mixed $before, mixed $after): string
    {
        if ($this->isControlDiffEmptyValue($before) && !$this->isControlDiffEmptyValue($after)) {
            return 'added';
        }

        if (!$this->isControlDiffEmptyValue($before) && $this->isControlDiffEmptyValue($after)) {
            return 'removed';
        }

        return 'changed';
    }

    private function resolveControlDiffKind(mixed $before, mixed $after): string
    {
        $candidate = $after ?? $before;
        if (is_bool($candidate)) {
            return 'boolean';
        }

        if (is_int($candidate) || is_float($candidate)) {
            return 'number';
        }

        if (is_array($candidate)) {
            return 'list';
        }

        if (is_string($candidate) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/', trim($candidate)) === 1) {
            return 'datetime';
        }

        return 'text';
    }

    private function isControlDiffEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return empty($value);
        }

        return false;
    }

    private function formatControlDiffDisplayValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_string($value)) {
            $text = preg_replace("/\r\n?/", "\n", $value) ?? $value;
            if (preg_match('/<[^>]+>/', $text) === 1) {
                $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
            }
            $text = trim((string)$text);
            return $text !== '' ? $text : 'Empty';
        }

        if (is_array($value)) {
            if ($this->isControlDiffSimpleList($value)) {
                $parts = [];
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $parts[] = $this->formatControlDiffSimpleDisplayMap($item);
                    } elseif ($item === null || (is_string($item) && trim($item) === '')) {
                        $parts[] = 'Empty';
                    } else {
                        $parts[] = (string)$item;
                    }
                }

                return !empty($parts) ? implode(', ', $parts) : 'Empty';
            }

            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return is_string($encoded) && $encoded !== '' ? $encoded : '[structured value]';
        }

        return trim((string)$value) !== '' ? trim((string)$value) : '—';
    }

    private function normalizeControlRedlineTextValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return '';
        }

        $text = preg_replace("/\r\n?/", "\n", (string)$value) ?? (string)$value;
        if (preg_match('/<[^>]+>/', $text) === 1) {
            $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        }

        return trim($text);
    }

    private function tokenizeControlRedlineText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\s+|[^\s]+/u', $text, $matches);
        return array_values(array_filter($matches[0] ?? [], static fn(mixed $token): bool => is_string($token) && $token !== ''));
    }

    private function buildControlRedlineSegments(array $beforeTokens, array $afterTokens): array
    {
        $operations = $this->diffControlRedlineTokens($beforeTokens, $afterTokens);
        if (empty($operations)) {
            return $this->buildControlRedlinePrefixSuffixSegments($beforeTokens, $afterTokens);
        }

        $segments = [];
        foreach ($operations as $operation) {
            $type = match ((string)($operation['op'] ?? 'equal')) {
                'insert' => 'added',
                'delete' => 'removed',
                default => 'context',
            };
            $text = implode('', (array)($operation['tokens'] ?? []));
            if ($text === '') {
                continue;
            }

            $lastIndex = count($segments) - 1;
            if ($lastIndex >= 0 && ($segments[$lastIndex]['type'] ?? null) === $type) {
                $segments[$lastIndex]['text'] .= $text;
                continue;
            }

            $segments[] = [
                'type' => $type,
                'text' => $text,
            ];
        }

        return $segments;
    }

    private function diffControlRedlineTokens(array $beforeTokens, array $afterTokens): array
    {
        $beforeCount = count($beforeTokens);
        $afterCount = count($afterTokens);
        if ($beforeCount === 0 && $afterCount === 0) {
            return [];
        }

        $lcs = array_fill(0, $beforeCount + 1, null);
        for ($beforeIndex = 0; $beforeIndex <= $beforeCount; $beforeIndex++) {
            $lcs[$beforeIndex] = array_fill(0, $afterCount + 1, 0);
        }

        $operations = [];
        for ($beforeIndex = $beforeCount - 1; $beforeIndex >= 0; $beforeIndex--) {
            for ($afterIndex = $afterCount - 1; $afterIndex >= 0; $afterIndex--) {
                if ($beforeTokens[$beforeIndex] === $afterTokens[$afterIndex]) {
                    $lcs[$beforeIndex][$afterIndex] = $lcs[$beforeIndex + 1][$afterIndex + 1] + 1;
                } else {
                    $lcs[$beforeIndex][$afterIndex] = max($lcs[$beforeIndex + 1][$afterIndex], $lcs[$beforeIndex][$afterIndex + 1]);
                }
            }
        }

        $beforeIndex = 0;
        $afterIndex = 0;
        while ($beforeIndex < $beforeCount && $afterIndex < $afterCount) {
            if ($beforeTokens[$beforeIndex] === $afterTokens[$afterIndex]) {
                $operations[] = ['op' => 'equal', 'tokens' => [$beforeTokens[$beforeIndex]]];
                $beforeIndex++;
                $afterIndex++;
                continue;
            }

            if ($lcs[$beforeIndex + 1][$afterIndex] >= $lcs[$beforeIndex][$afterIndex + 1]) {
                $operations[] = ['op' => 'delete', 'tokens' => [$beforeTokens[$beforeIndex]]];
                $beforeIndex++;
            } else {
                $operations[] = ['op' => 'insert', 'tokens' => [$afterTokens[$afterIndex]]];
                $afterIndex++;
            }
        }

        while ($beforeIndex < $beforeCount) {
            $operations[] = ['op' => 'delete', 'tokens' => [$beforeTokens[$beforeIndex]]];
            $beforeIndex++;
        }

        while ($afterIndex < $afterCount) {
            $operations[] = ['op' => 'insert', 'tokens' => [$afterTokens[$afterIndex]]];
            $afterIndex++;
        }

        return $this->coalesceControlRedlineOperations($operations);
    }

    private function coalesceControlRedlineOperations(array $operations): array
    {
        $coalesced = [];
        foreach ($operations as $operation) {
            $op = (string)($operation['op'] ?? '');
            $tokens = array_values(array_filter((array)($operation['tokens'] ?? []), static fn(mixed $token): bool => is_string($token) && $token !== ''));
            if ($op === '' || empty($tokens)) {
                continue;
            }

            $lastIndex = count($coalesced) - 1;
            if ($lastIndex >= 0 && ($coalesced[$lastIndex]['op'] ?? null) === $op) {
                $coalesced[$lastIndex]['tokens'] = array_merge((array)$coalesced[$lastIndex]['tokens'], $tokens);
                continue;
            }

            $coalesced[] = [
                'op' => $op,
                'tokens' => $tokens,
            ];
        }

        return $coalesced;
    }

    private function buildControlRedlinePrefixSuffixSegments(array $beforeTokens, array $afterTokens): array
    {
        $beforeCount = count($beforeTokens);
        $afterCount = count($afterTokens);
        $prefix = 0;

        while ($prefix < $beforeCount && $prefix < $afterCount && $beforeTokens[$prefix] === $afterTokens[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            ($beforeCount - $suffix - 1) >= $prefix &&
            ($afterCount - $suffix - 1) >= $prefix &&
            $beforeTokens[$beforeCount - $suffix - 1] === $afterTokens[$afterCount - $suffix - 1]
        ) {
            $suffix++;
        }

        $segments = [];
        $prefixText = implode('', array_slice($afterTokens, 0, $prefix));
        $removedText = implode('', array_slice($beforeTokens, $prefix, max(0, $beforeCount - $prefix - $suffix)));
        $addedText = implode('', array_slice($afterTokens, $prefix, max(0, $afterCount - $prefix - $suffix)));
        $suffixText = $suffix > 0 ? implode('', array_slice($afterTokens, $afterCount - $suffix)) : '';

        if ($prefixText !== '') {
            $segments[] = ['type' => 'context', 'text' => $prefixText];
        }
        if ($removedText !== '') {
            $segments[] = ['type' => 'removed', 'text' => $removedText];
        }
        if ($addedText !== '') {
            $segments[] = ['type' => 'added', 'text' => $addedText];
        }
        if ($suffixText !== '') {
            $segments[] = ['type' => 'context', 'text' => $suffixText];
        }

        return $segments;
    }

    private function formatControlDiffSimpleDisplayMap(array $value): string
    {
        $display = $this->firstNonEmptyString([
            $value['label'] ?? null,
            $value['title'] ?? null,
            $value['name'] ?? null,
            $value['filename'] ?? null,
            $value['slug'] ?? null,
            $value['uri'] ?? null,
            $value['url'] ?? null,
        ]);
        if ($display !== null) {
            return $display;
        }

        if (isset($value['id']) && (is_numeric($value['id']) || is_string($value['id']))) {
            return '#' . trim((string)$value['id']);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) && $encoded !== '' ? $encoded : '[item]';
    }

    private function humanizeControlDiffKey(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return 'Field';
        }

        $normalized = preg_replace('/([a-z])([A-Z])/', '$1 $2', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        return $normalized !== '' ? ucwords(strtolower($normalized)) : $value;
    }

    private function buildControlChangeSummary(array $requestPayload, array $resultPayload): array
    {
        $summary = [];

        $title = $this->firstNonEmptyString([
            $requestPayload['title'] ?? null,
            $resultPayload['title'] ?? null,
        ]);
        if ($title !== null) {
            $summary[] = [
                'label' => 'Title',
                'value' => $this->formatControlReviewTextSnippet($title, 100),
            ];
        }

        $slug = $this->firstNonEmptyString([
            $requestPayload['slug'] ?? null,
            $resultPayload['slug'] ?? null,
        ]);
        if ($slug !== null) {
            $summary[] = [
                'label' => 'Slug',
                'value' => $slug,
            ];
        }

        $draftName = $this->firstNonEmptyString([
            $requestPayload['draftName'] ?? null,
            $resultPayload['draftName'] ?? null,
        ]);
        if ($draftName !== null) {
            $summary[] = [
                'label' => 'Draft name',
                'value' => $this->formatControlReviewTextSnippet($draftName, 100),
            ];
        }

        $draftNotes = $this->firstNonEmptyString([
            $requestPayload['draftNotes'] ?? null,
            $resultPayload['draftNotes'] ?? null,
        ]);
        if ($draftNotes !== null) {
            $summary[] = [
                'label' => 'Draft notes',
                'value' => $this->formatControlReviewTextSnippet($draftNotes, 140),
            ];
        }

        if (is_array($requestPayload['fields'] ?? null) && !empty($requestPayload['fields'])) {
            $fieldHandles = array_values(array_filter(array_map(static fn($handle): string => trim((string)$handle), array_keys($requestPayload['fields']))));
            if (!empty($fieldHandles)) {
                $summary[] = [
                    'label' => 'Fields',
                    'value' => implode(', ', array_slice($fieldHandles, 0, 6)),
                ];
            }
        } elseif (is_array($resultPayload['changedKeys'] ?? null) && !empty($resultPayload['changedKeys'])) {
            $changedKeys = array_values(array_filter(array_map(static fn($key): string => trim((string)$key), $resultPayload['changedKeys'])));
            if (!empty($changedKeys)) {
                $summary[] = [
                    'label' => 'Changed keys',
                    'value' => implode(', ', array_slice($changedKeys, 0, 6)),
                ];
            }
        }

        return $summary;
    }

    private function extractControlTargetIds(array $item): array
    {
        $requestPayload = is_array($item['requestPayload'] ?? null) ? (array)$item['requestPayload'] : [];
        $resultPayload = is_array($item['resultPayload'] ?? null) ? (array)$item['resultPayload'] : [];
        $boundEntryId = isset($item['boundDraftEntryId']) ? (int)$item['boundDraftEntryId'] : 0;
        $boundSiteId = isset($item['boundDraftSiteId']) ? (int)$item['boundDraftSiteId'] : 0;
        $boundDraftId = isset($item['boundDraftId']) ? (int)$item['boundDraftId'] : 0;
        $latestExecution = is_array($item['latestExecution'] ?? null) ? (array)$item['latestExecution'] : [];
        $latestExecutionRequestPayload = is_array($latestExecution['requestPayload'] ?? null) ? (array)$latestExecution['requestPayload'] : [];
        $latestExecutionResultPayload = is_array($latestExecution['resultPayload'] ?? null) ? (array)$latestExecution['resultPayload'] : [];

        $entryId = 0;
        if (isset($requestPayload['entryId'])) {
            $entryId = (int)$requestPayload['entryId'];
        } elseif (isset($resultPayload['entryId'])) {
            $entryId = (int)$resultPayload['entryId'];
        } elseif ($boundEntryId > 0) {
            $entryId = $boundEntryId;
        } elseif (isset($latestExecutionRequestPayload['entryId'])) {
            $entryId = (int)$latestExecutionRequestPayload['entryId'];
        } elseif (isset($latestExecutionResultPayload['entryId'])) {
            $entryId = (int)$latestExecutionResultPayload['entryId'];
        }

        $siteId = 0;
        if (isset($requestPayload['siteId'])) {
            $siteId = (int)$requestPayload['siteId'];
        } elseif (isset($resultPayload['siteId'])) {
            $siteId = (int)$resultPayload['siteId'];
        } elseif ($boundSiteId > 0) {
            $siteId = $boundSiteId;
        } elseif (isset($latestExecutionRequestPayload['siteId'])) {
            $siteId = (int)$latestExecutionRequestPayload['siteId'];
        } elseif (isset($latestExecutionResultPayload['siteId'])) {
            $siteId = (int)$latestExecutionResultPayload['siteId'];
        }

        $draftId = 0;
        if (isset($requestPayload['draftId'])) {
            $draftId = (int)$requestPayload['draftId'];
        } elseif (isset($resultPayload['draftId'])) {
            $draftId = (int)$resultPayload['draftId'];
        } elseif ($boundDraftId > 0) {
            $draftId = $boundDraftId;
        } elseif (isset($latestExecutionRequestPayload['draftId'])) {
            $draftId = (int)$latestExecutionRequestPayload['draftId'];
        } elseif (isset($latestExecutionResultPayload['draftId'])) {
            $draftId = (int)$latestExecutionResultPayload['draftId'];
        }

        return [
            'entryId' => $entryId,
            'siteId' => $siteId,
            'draftId' => $draftId,
        ];
    }

    private function resolveDraftDescriptorById(int $draftId, int $preferredSiteId = 0): ?array
    {
        static $cache = [];
        if ($draftId <= 0) {
            return null;
        }

        $cacheKey = sprintf('draft:%d:%d', $draftId, max(0, $preferredSiteId));
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $query = Entry::find()
            ->id($draftId)
            ->drafts(true)
            ->status(null);

        if ($preferredSiteId > 0) {
            $query->siteId($preferredSiteId);
        } else {
            $query->site('*');
        }

        $draft = $query->one();
        if (!$draft instanceof Entry || !$draft->getIsDraft()) {
            $fallbackDescriptor = $this->resolveDraftDescriptorByDbLookup($draftId, $preferredSiteId);
            $cache[$cacheKey] = $fallbackDescriptor;
            return $fallbackDescriptor;
        }

        $title = trim((string)$draft->title);
        $cpEditUrl = trim((string)$draft->getCpEditUrl());
        $cache[$cacheKey] = [
            'id' => (int)$draft->id,
            'siteId' => (int)($draft->siteId ?? 0),
            'canonicalId' => (int)$draft->getCanonicalId(),
            'draftRecordId' => (int)($draft->draftId ?? 0),
            'title' => $title !== '' ? $title : null,
            'cpEditUrl' => $cpEditUrl !== '' ? $cpEditUrl : null,
        ];

        return $cache[$cacheKey];
    }

    private function resolveDraftDescriptorByDbLookup(int $draftId, int $preferredSiteId = 0): ?array
    {
        if ($draftId <= 0) {
            return null;
        }

        $query = (new Query())
            ->select([
                'elements.id',
                'elements.canonicalId',
                'elements.draftId',
                'elements_sites.siteId',
                'elements_sites.title',
                'elements_sites.uri',
            ])
            ->from(['elements' => '{{%elements}}'])
            ->innerJoin('{{%elements_sites}} elements_sites', '[[elements_sites.elementId]] = [[elements.id]]')
            ->where([
                'elements.id' => $draftId,
                'elements.type' => Entry::class,
            ])
            ->andWhere(['not', ['elements.draftId' => null]]);

        if ($preferredSiteId > 0) {
            $query->andWhere(['elements_sites.siteId' => $preferredSiteId]);
        } else {
            $query->orderBy(['elements_sites.siteId' => SORT_ASC]);
        }

        $row = $query->one();
        if (!is_array($row)) {
            return null;
        }

        $canonicalId = (int)($row['canonicalId'] ?? 0);
        $draftRecordId = (int)($row['draftId'] ?? 0);
        $siteId = (int)($row['siteId'] ?? 0);
        $title = trim((string)($row['title'] ?? ''));
        $uri = trim((string)($row['uri'] ?? ''));

        return [
            'id' => $draftId,
            'siteId' => $siteId > 0 ? $siteId : null,
            'canonicalId' => $canonicalId > 0 ? $canonicalId : null,
            'draftRecordId' => $draftRecordId > 0 ? $draftRecordId : null,
            'title' => $title !== '' ? $title : null,
            'cpEditUrl' => $uri !== '' ? 'entries/' . $draftId : null,
        ];
    }

    private function findControlEntryForReview(int $entryId, int $preferredSiteId = 0, bool $drafts = false): ?Entry
    {
        if ($entryId <= 0) {
            return null;
        }

        $query = Entry::find()
            ->id($entryId)
            ->status(null);

        if ($drafts) {
            $query->drafts(true);
        } else {
            $query->canonicalsOnly();
        }

        if ($preferredSiteId > 0) {
            $query->siteId($preferredSiteId);
        } else {
            $query->site('*');
        }

        $entry = $query->one();
        return $entry instanceof Entry ? $entry : null;
    }

    private function findControlRevisionForReview(int $entryId, int $preferredSiteId = 0): ?Entry
    {
        if ($entryId <= 0) {
            return null;
        }

        $query = Entry::find()
            ->id($entryId)
            ->revisions(true)
            ->status(null);

        if ($preferredSiteId > 0) {
            $query->siteId($preferredSiteId);
        } else {
            $query->site('*');
        }

        $entry = $query->one();
        return $entry instanceof Entry ? $entry : null;
    }

    private function normalizeControlValidationErrors(array $errors): array
    {
        $normalized = [];
        foreach ($errors as $attributeErrors) {
            if (!is_array($attributeErrors)) {
                continue;
            }
            foreach ($attributeErrors as $error) {
                $message = trim((string)$error);
                if ($message !== '') {
                    $normalized[] = $message;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    private function formatControlReviewTextSnippet(string $value, int $maxLength = 120): string
    {
        $plain = preg_replace('/\s+/', ' ', trim(strip_tags($value)));
        if (!is_string($plain) || $plain === '') {
            return '';
        }

        if ((function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain)) <= $maxLength) {
            return $plain;
        }

        $snippet = function_exists('mb_substr')
            ? mb_substr($plain, 0, $maxLength - 1)
            : substr($plain, 0, $maxLength - 1);

        return rtrim((string)$snippet) . '…';
    }

    private function resolveEntryTitleById(int $entryId, int $preferredSiteId = 0): ?string
    {
        $descriptor = $this->resolveEntryDescriptorById($entryId, $preferredSiteId);
        return is_array($descriptor) ? ($descriptor['title'] ?? null) : null;
    }

    private function resolveEntryDescriptorById(int $entryId, int $preferredSiteId = 0): ?array
    {
        static $cache = [];
        if ($entryId <= 0) {
            return null;
        }

        $cacheKey = sprintf('%d:%d', $entryId, max(0, $preferredSiteId));
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $query = Entry::find()
            ->id($entryId)
            ->canonicalsOnly()
            ->status(null);

        if ($preferredSiteId > 0) {
            $query->siteId($preferredSiteId);
        } else {
            $query->site('*');
        }

        $entry = $query->one();
        if (!$entry instanceof Entry && $preferredSiteId > 0) {
            $entry = Entry::find()
                ->id($entryId)
                ->canonicalsOnly()
                ->status(null)
                ->site('*')
                ->one();
        }

        if (!$entry instanceof Entry) {
            $cache[$cacheKey] = null;
            return null;
        }

        $title = trim((string)$entry->title);
        $cpEditUrl = trim((string)$entry->getCpEditUrl());
        $cache[$cacheKey] = [
            'id' => (int)$entry->id,
            'siteId' => (int)($entry->siteId ?? 0),
            'title' => $title !== '' ? $title : null,
            'cpEditUrl' => $cpEditUrl !== '' ? $cpEditUrl : null,
        ];
        return $cache[$cacheKey];
    }

    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $candidate = trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function humanizeControlActionRef(string $actionRef): ?string
    {
        $normalizedRef = trim($actionRef);
        if ($normalizedRef === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(':', $normalizedRef)), static fn(string $part): bool => $part !== ''));
        if (empty($parts)) {
            return null;
        }

        if (count($parts) >= 2) {
            $tail = (string)end($parts);
            $simulatedTitlesByTail = [
                'seo-refresh' => 'Spring Campaign SEO Landing Page',
                'content-refresh' => 'Homepage Hero Content Block',
                'pricing-refresh' => 'Pricing Overview and CTA Block',
            ];
            if (isset($simulatedTitlesByTail[$tail])) {
                return $simulatedTitlesByTail[$tail];
            }

            $humanTail = $this->humanizeActorToken($tail);
            if (preg_match('/^entry-(\d+)$/i', (string)$parts[0], $matches) === 1) {
                return sprintf('Entry %d - %s', (int)$matches[1], $humanTail);
            }

            return $humanTail;
        }

        return $this->humanizeActorToken($parts[0]);
    }

    private function humanizeControlActionType(string $actionType): string
    {
        $normalized = strtolower(trim($actionType));
        if ($normalized === '') {
            return 'Unknown action';
        }

        if ($normalized === 'entry.updatedraft') {
            return 'Update Entry Draft';
        }

        if (str_starts_with($normalized, 'returns.exception.refund')) {
            return 'Handle Return Refund Exception';
        }

        if (str_starts_with($normalized, 'returns.exception.manual-review')) {
            return 'Run Return Manual Review';
        }

        $parts = preg_split('/[.:_-]+/', $normalized) ?: [];
        $words = [];
        foreach ($parts as $part) {
            $token = trim((string)$part);
            if ($token === '' || preg_match('/^\d+$/', $token) === 1) {
                continue;
            }
            $words[] = ucfirst($token);
        }

        if (empty($words)) {
            return 'Unknown action';
        }

        return implode(' ', $words);
    }

    private function formatControlActorLabel(string $actorId, string $actorType, array $credentialDisplayByActorId): string
    {
        $normalizedActorId = trim($actorId);
        if ($normalizedActorId === '') {
            return 'n/a';
        }

        if ($actorType === 'cp-user' || str_starts_with($normalizedActorId, 'cp:')) {
            if (preg_match('/^cp:user-(\d+)$/', $normalizedActorId, $matches) === 1) {
                $resolvedById = $this->resolveCpUserDisplayNameById((int)$matches[1]);
                if ($resolvedById !== null) {
                    return $resolvedById;
                }
            }

            if (preg_match('/^cp:(.+)$/', $normalizedActorId, $matches) === 1) {
                $username = trim((string)($matches[1] ?? ''));
                if ($username !== '') {
                    $resolvedByUsername = $this->resolveCpUserDisplayNameByUsername($username);
                    if ($resolvedByUsername !== null) {
                        return $resolvedByUsername;
                    }

                    return $this->humanizeActorToken($username);
                }
            }

            return 'Control Panel user';
        }

        if (isset($credentialDisplayByActorId[$normalizedActorId])) {
            return (string)$credentialDisplayByActorId[$normalizedActorId];
        }

        if ($actorType === 'credential' && $normalizedActorId !== '') {
            return $this->humanizeActorToken($normalizedActorId);
        }

        if ($normalizedActorId === 'system') {
            return 'System';
        }

        if (str_starts_with($normalizedActorId, 'system:')) {
            $suffix = trim(substr($normalizedActorId, 7));
            if ($suffix === '') {
                return 'System';
            }

            return sprintf('System (%s)', $this->humanizeActorToken($suffix));
        }

        if (str_starts_with($normalizedActorId, 'agent:')) {
            $suffix = trim(substr($normalizedActorId, 6));
            if ($suffix === '') {
                return 'Agent';
            }

            if (isset($credentialDisplayByActorId[$suffix])) {
                return (string)$credentialDisplayByActorId[$suffix];
            }

            return $this->humanizeActorToken($suffix);
        }

        return $this->humanizeActorToken($normalizedActorId);
    }

    private function resolveCpUserDisplayNameById(int $id): ?string
    {
        static $cache = [];
        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }

        $user = Craft::$app->getUsers()->getUserById($id);
        $cache[$id] = $this->extractCpUserDisplayName($user);
        return $cache[$id];
    }

    private function resolveCpUserDisplayNameByUsername(string $username): ?string
    {
        static $cache = [];
        $normalized = trim($username);
        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, $cache)) {
            return $cache[$normalized];
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($normalized);
        $cache[$normalized] = $this->extractCpUserDisplayName($user);
        return $cache[$normalized];
    }

    private function extractCpUserDisplayName(mixed $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $fullName = '';
        if (method_exists($user, 'getFullName')) {
            $fullName = trim((string)$user->getFullName());
        }
        if ($fullName !== '') {
            return $fullName;
        }

        $username = trim((string)($user->username ?? ''));
        if ($username !== '') {
            return $username;
        }

        $email = trim((string)($user->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        return null;
    }

    private function humanizeActorToken(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return 'n/a';
        }

        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $normalized) ?: '';
        $normalized = trim($normalized);
        if ($normalized === '') {
            return $value;
        }

        return ucwords(strtolower($normalized));
    }

    private function appendPolicyHints(array $items, array $hintsByActionType): array
    {
        $enriched = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $actionType = trim((string)($item['actionType'] ?? ''));
            if ($actionType !== '' && isset($hintsByActionType[$actionType])) {
                $item['policy'] = $hintsByActionType[$actionType];
            }

            $enriched[] = $item;
        }

        return $enriched;
    }

    private function buildObservabilitySummary(array $snapshot): array
    {
        $reliability = (array)($snapshot['reliability'] ?? []);
        if (empty($reliability)) {
            $plugin = Plugin::getInstance();
            if ($plugin !== null) {
                try {
                    $reliability = $plugin->getReliabilitySignalService()->evaluateSnapshot($snapshot);
                } catch (Throwable) {
                    $reliability = [];
                }
            }
        }
        $reliabilitySummary = (array)($reliability['summary'] ?? []);

        return [
            'generatedAt' => (string)($snapshot['generatedAt'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'requestsTotal' => (int)$this->resolveMetricValue($snapshot, 'agents_requests_total'),
            'authFailuresTotal' => (int)$this->resolveMetricValue($snapshot, 'agents_auth_failures_total'),
            'forbiddenTotal' => (int)$this->resolveMetricValue($snapshot, 'agents_forbidden_total'),
            'rateLimitExceededTotal' => (int)$this->resolveMetricValue($snapshot, 'agents_rate_limit_exceeded_total'),
            'errors5xxTotal' => (int)$this->resolveMetricValue($snapshot, 'agents_errors_5xx_total'),
            'queueDepth' => (int)$this->resolveMetricValue($snapshot, 'agents_queue_depth'),
            'webhookDlqFailed' => (int)$this->resolveMetricValue($snapshot, 'agents_webhook_dlq_failed'),
            'consumerLagMaxSeconds' => (int)$this->resolveMetricValue($snapshot, 'agents_consumer_lag_max_seconds'),
            'activeCredentials7d' => (int)$this->resolveMetricValue($snapshot, 'agents_adoption_active_credentials_7d'),
            'reliabilityStatus' => (string)($reliability['status'] ?? 'ok'),
            'reliabilityThresholdsVersion' => (string)($reliability['thresholdsVersion'] ?? ''),
            'reliabilitySignalsWarn' => (int)($reliabilitySummary['signalsWarn'] ?? 0),
            'reliabilitySignalsCritical' => (int)($reliabilitySummary['signalsCritical'] ?? 0),
            'reliabilitySignalsEvaluated' => (int)($reliabilitySummary['signalsEvaluated'] ?? 0),
            'reliabilityTopSignals' => array_values(array_slice((array)($reliability['topSignals'] ?? []), 0, 5)),
            'reliabilitySignals' => array_values((array)($reliability['signals'] ?? [])),
        ];
    }

    private function resolveMetricValue(array $snapshot, string $name): int|float
    {
        $metrics = $snapshot['metrics'] ?? null;
        if (!is_array($metrics) || $name === '') {
            return 0;
        }

        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            if ((string)($metric['name'] ?? '') !== $name) {
                continue;
            }

            $value = $metric['value'] ?? 0;
            if (is_int($value) || is_float($value)) {
                return $value;
            }

            if (is_string($value) && is_numeric($value)) {
                return str_contains($value, '.') ? (float)$value : (int)$value;
            }

            return 0;
        }

        return 0;
    }

    private function buildWorkerEnvExport(string $token): string
    {
        $lines = [
            'SITE_URL=' . UrlHelper::siteUrl(''),
            'BASE_URL=' . UrlHelper::siteUrl('agents/v1'),
            'AGENTS_TOKEN=' . $token,
            'REQUEST_TIMEOUT_MS=15000',
            'PRINT_JSON=1',
        ];

        return implode("\n", $lines);
    }

    private function buildWorkerEnvFilename(string $handle): string
    {
        $normalizedHandle = preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($handle)) ?: '';
        $normalizedHandle = trim($normalizedHandle, '-');

        if ($normalizedHandle === '') {
            return 'agents-worker.env';
        }

        return 'accounts-' . $normalizedHandle . '-worker.env';
    }

    private function storeRevealedCredential(array $credential): void
    {
        Craft::$app->getSession()->set(self::SESSION_REVEALED_CREDENTIAL, $credential);
    }

    private function storeControlSimulationResult(array $result): void
    {
        Craft::$app->getSession()->set(self::SESSION_CONTROL_SIMULATION, $result);
    }

    private function pullControlSimulationResult(): ?array
    {
        $session = Craft::$app->getSession();
        $value = $session->get(self::SESSION_CONTROL_SIMULATION);
        $session->remove(self::SESSION_CONTROL_SIMULATION);

        return is_array($value) ? $value : null;
    }

    private function pullRevealedCredential(): ?array
    {
        $session = Craft::$app->getSession();
        $value = $session->get(self::SESSION_REVEALED_CREDENTIAL);
        $session->remove(self::SESSION_REVEALED_CREDENTIAL);

        return is_array($value) ? $value : null;
    }

    private function requireCredentialPermission(string $permission): void
    {
        if ($this->canCredentialPermission($permission)) {
            return;
        }

        $this->requirePermission($permission);
    }

    private function canCredentialPermission(string $permission): bool
    {
        $userComponent = Craft::$app->getUser();
        $identity = $userComponent->getIdentity();
        if ($identity === null) {
            return false;
        }

        if ((bool)$identity->admin) {
            return true;
        }

        return $userComponent->checkPermission($permission);
    }

    private function requireControlPermission(string $permission): void
    {
        if ($this->canControlPermission($permission)) {
            return;
        }

        $this->requirePermission($permission);
    }

    private function canControlPermission(string $permission): bool
    {
        $userComponent = Craft::$app->getUser();
        $identity = $userComponent->getIdentity();
        if ($identity === null) {
            return false;
        }

        if ((bool)$identity->admin) {
            return true;
        }

        if (!$userComponent->checkPermission(Plugin::PERMISSION_CONTROL_VIEW)) {
            return false;
        }

        return $userComponent->checkPermission($permission);
    }

    private function resolveCurrentCpUserEmail(): string
    {
        $identity = $this->resolveCurrentCpUser();
        if ($identity === null) {
            return '';
        }

        $email = trim((string)($identity->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        $username = trim((string)($identity->username ?? ''));
        return $username;
    }

    private function resolveCurrentCpUserId(): ?int
    {
        $identity = $this->resolveCurrentCpUser();
        if ($identity === null) {
            return null;
        }

        $userId = (int)($identity->id ?? 0);
        return $userId > 0 ? $userId : null;
    }

    private function resolveCurrentCpUser(): ?User
    {
        $identity = Craft::$app->getUser()->getIdentity();
        if (!$identity instanceof User) {
            return null;
        }

        return $identity;
    }

    private function buildCpActorContext(): array
    {
        $identity = Craft::$app->getUser()->getIdentity();
        $actorId = 'cp-user';
        if ($identity !== null) {
            $username = trim((string)($identity->username ?? ''));
            $id = (int)($identity->id ?? 0);
            if ($username !== '') {
                $actorId = 'cp:' . $username;
            } elseif ($id > 0) {
                $actorId = 'cp:user-' . $id;
            }
        }

        return [
            'actorType' => 'cp-user',
            'actorId' => $actorId,
            'requestId' => 'cp-' . substr(sha1(uniqid('', true)), 0, 12),
            'ipAddress' => (string)(Craft::$app->getRequest()->getUserIP() ?: 'unknown'),
        ];
    }

    private function isControlCpEnabled(): bool
    {
        return Plugin::getInstance()->isControlCpEnabled();
    }

    private function requireControlCpEnabled(): void
    {
        if ($this->isControlCpEnabled()) {
            return;
        }

        throw new NotFoundHttpException('Not found.');
    }
}
