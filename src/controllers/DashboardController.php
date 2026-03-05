<?php

namespace Klick\Agents\controllers;

use Craft;
use Klick\Agents\Plugin;
use Klick\Agents\models\Settings;
use craft\web\Controller;
use craft\web\View;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DashboardController extends Controller
{
    private const SESSION_REVEALED_CREDENTIAL = 'agents.revealedCredential';
    private const SESSION_CONTROL_SIMULATION = 'agents.controlSimulation';
    private const DASHBOARD_TABS = ['overview', 'readiness', 'discovery', 'security'];

    public function actionIndex(): Response
    {
        return $this->redirect('agents/dashboard/overview');
    }

    public function actionDashboard(): Response
    {
        $request = Craft::$app->getRequest();
        $pathInfo = trim((string)$request->getPathInfo(), '/');
        if ($pathInfo === 'agents' || $pathInfo === 'agents/dashboard') {
            return $this->redirect('agents/dashboard/overview');
        }

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $settings = $this->getSettingsModel();
        $readinessService = $plugin->getReadinessService();
        $securityPosture = $plugin->getSecurityPolicyService()->getCpPosture();
        $activeDashboardTab = $this->resolveDashboardTab();
        $dashboardTabs = $this->dashboardTabs();

        $overviewReadiness = $readinessService->getReadinessDiagnostics();
        $readinessHealth = $readinessService->getHealthSummary();
        $readinessSummary = $readinessService->getReadinessSummary();
        $readinessDiagnostics = $readinessService->getReadinessDiagnostics();
        $discoveryStatus = $plugin->getDiscoveryTxtService()->getDiscoveryStatus();
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
            'activeDashboardTab' => $activeDashboardTab,
            'dashboardTabs' => $dashboardTabs,
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'overviewReadiness' => $overviewReadiness,
            'overviewReadinessJson' => $this->prettyPrintJson($overviewReadiness['summary'] ?? []),
            'discoveryEnabled' => [
                'llms' => (bool)$settings->enableLlmsTxt,
                'llmsFull' => (bool)$settings->enableLlmsFullTxt,
                'commerce' => (bool)$settings->enableCommerceTxt,
            ],
            'webhookEnabled' => (bool)($securityPosture['webhook']['enabled'] ?? false),
            'securityWarningCounts' => (array)($securityPosture['warningCounts'] ?? []),
            'apiEndpoints' => $this->getApiEndpoints(),
            'discoveryEndpoints' => $this->getDiscoveryEndpoints(),
            'readinessHealth' => $readinessHealth,
            'readinessSummary' => $readinessSummary,
            'readinessDiagnostics' => $readinessDiagnostics,
            'readinessHealthJson' => $this->prettyPrintJson($readinessHealth),
            'readinessSummaryJson' => $this->prettyPrintJson($readinessSummary),
            'readinessDiagnosticsJson' => $this->prettyPrintJson($readinessDiagnostics),
            'discoveryStatus' => $discoveryStatus,
            'discoveryStatusJson' => $this->prettyPrintJson($discoveryStatus),
            'discoveryConfig' => [
                'llmsEnabled' => (bool)$settings->enableLlmsTxt,
                'llmsFullEnabled' => (bool)$settings->enableLlmsFullTxt,
                'commerceEnabled' => (bool)$settings->enableCommerceTxt,
                'llmsTtl' => (int)$settings->llmsTxtCacheTtl,
                'commerceTtl' => (int)$settings->commerceTxtCacheTtl,
            ],
            'consumerLagSummary' => (array)($consumerLagSnapshot['summary'] ?? []),
            'consumerLagRows' => (array)($consumerLagSnapshot['rows'] ?? []),
            'observabilitySummary' => $observabilitySummary,
            'observabilitySnapshotJson' => $this->prettyPrintJson($observabilitySnapshot),
            'webhookDeadLetters' => $webhookDeadLetters,
            'securityPosture' => $securityPosture,
            'securityPostureJson' => $this->prettyPrintJson($securityPosture),
        ]);
    }

    public function actionOverview(): Response
    {
        return $this->redirect('agents/dashboard/overview');
    }

    public function actionReadiness(): Response
    {
        return $this->redirect('agents/dashboard/readiness');
    }

    public function actionDiscovery(): Response
    {
        return $this->redirect('agents/dashboard/discovery');
    }

    public function actionSecurity(): Response
    {
        return $this->redirect('agents/dashboard/security');
    }

    public function actionControl(): Response
    {
        $this->requireRefundApprovalsExperimentalEnabled();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_VIEW);

        $plugin = Plugin::getInstance();
        $settings = $this->getSettingsModel();
        $enabledState = $plugin->getAgentsEnabledState();
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

        $pendingApprovals = [];
        $approvedApprovalsById = [];
        foreach ($approvalsWithPolicy as $approval) {
            $status = strtolower(trim((string)($approval['status'] ?? '')));
            if ($status === 'pending') {
                $pendingApprovals[] = $approval;
            } elseif ($status === 'approved') {
                $approvalId = (int)($approval['id'] ?? 0);
                if ($approvalId > 0) {
                    $approvedApprovalsById[$approvalId] = (string)($approval['actionType'] ?? '');
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
            'controlApprovals' => $approvalsWithPolicy,
            'controlExecutions' => $executionsWithPolicy,
            'controlAuditEvents' => $auditEvents,
            'controlPendingApprovals' => $pendingApprovals,
            'controlAttentionExecutions' => $attentionExecutions,
            'controlPolicyHintsByActionType' => $policyHintsByActionType,
            'controlApprovedApprovalsById' => $approvedApprovalsById,
            'controlSimulationResult' => $this->pullControlSimulationResult(),
            'controlSnapshotJson' => $this->prettyPrintJson($snapshot),
            'allowCpApprovalRequests' => (bool)$settings->allowCpApprovalRequests,
            'canManagePolicies' => $this->canControlPermission(Plugin::PERMISSION_CONTROL_POLICIES_MANAGE),
            'canManageApprovals' => $this->canControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE),
            'canExecuteActions' => $this->canControlPermission(Plugin::PERMISSION_CONTROL_ACTIONS_EXECUTE),
        ]);
    }

    public function actionSettings(): Response
    {
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $settingsOverrides = $this->getSettingsOverrides();

        return $this->renderCpTemplate('agents/settings-tab', [
            'settings' => $this->getSettingsModel(),
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'refundApprovalsExperimentalEnabled' => $plugin->isRefundApprovalsExperimentalEnabled(),
            'credentialUsageIndicatorSettingLocked' => (bool)($settingsOverrides['enableCredentialUsageIndicator'] ?? false),
            'llmsTxtSettingLocked' => (bool)($settingsOverrides['enableLlmsTxt'] ?? false),
            'llmsFullTxtSettingLocked' => (bool)($settingsOverrides['enableLlmsFullTxt'] ?? false),
            'commerceTxtSettingLocked' => (bool)($settingsOverrides['enableCommerceTxt'] ?? false),
            'llmsTxtBodySettingLocked' => (bool)($settingsOverrides['llmsTxtBody'] ?? false),
            'commerceTxtBodySettingLocked' => (bool)($settingsOverrides['commerceTxtBody'] ?? false),
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
            'securityPosture' => $posture,
            'managedCredentials' => $managedCredentials,
            'credentialExpirySummary' => $credentialExpirySummary,
            'defaultScopes' => $defaultScopes,
            'revealedCredential' => $this->pullRevealedCredential(),
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
        return $this->redirect('agents/dashboard/readiness');
    }

    public function actionToggleEnabled(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        if ((bool)$enabledState['locked']) {
            $this->setFailFlash('Agents enabled state is controlled by `PLUGIN_AGENTS_ENABLED` and cannot be changed from the Control Panel.');
            return $this->redirectToPostedUrl(null, 'agents/dashboard/overview');
        }

        $enabledRaw = strtolower(trim((string)$this->request->getBodyParam('enabled', '0')));
        $enabled = in_array($enabledRaw, ['1', 'true', 'on', 'yes'], true);

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'enabled' => $enabled,
        ]);

        if (!$saved) {
            $this->setFailFlash('Couldn’t save Agents settings.');
            return $this->redirectToPostedUrl(null, 'agents/dashboard/overview');
        }

        $this->setSuccessFlash($enabled ? 'Agents API enabled.' : 'Agents API disabled.');
        return $this->redirectToPostedUrl(null, 'agents/dashboard/overview');
    }

    public function actionSaveSettings(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $settings = $this->getSettingsModel();
        $enabledState = $plugin->getAgentsEnabledState();
        $settingsOverrides = $this->getSettingsOverrides();
        $resetDiscoveryBodyTarget = strtolower(trim((string)$this->request->getBodyParam('resetDiscoveryBodyTarget', '')));
        $llmsLocked = (bool)($settingsOverrides['enableLlmsTxt'] ?? false);
        $llmsFullLocked = (bool)($settingsOverrides['enableLlmsFullTxt'] ?? false);
        $commerceLocked = (bool)($settingsOverrides['enableCommerceTxt'] ?? false);
        $credentialUsageIndicatorLocked = (bool)($settingsOverrides['enableCredentialUsageIndicator'] ?? false);
        $llmsBodyLocked = (bool)($settingsOverrides['llmsTxtBody'] ?? false);
        $commerceBodyLocked = (bool)($settingsOverrides['commerceTxtBody'] ?? false);

        $settingsData = get_object_vars($settings);
        $settingsData['enabled'] = (bool)$enabledState['locked']
            ? (bool)$enabledState['enabled']
            : $this->parseBooleanBodyParam('enabled', (bool)$settings->enabled);
        $settingsData['allowCpApprovalRequests'] = $plugin->isRefundApprovalsExperimentalEnabled()
            ? $this->parseBooleanBodyParam('allowCpApprovalRequests', (bool)$settings->allowCpApprovalRequests)
            : false;
        $settingsData['enableCredentialUsageIndicator'] = $credentialUsageIndicatorLocked
            ? (bool)$settings->enableCredentialUsageIndicator
            : $this->parseBooleanBodyParam('enableCredentialUsageIndicator', (bool)$settings->enableCredentialUsageIndicator);
        $settingsData['enableLlmsTxt'] = $llmsLocked
            ? (bool)$settings->enableLlmsTxt
            : $this->parseBooleanBodyParam('enableLlmsTxt', (bool)$settings->enableLlmsTxt);
        $settingsData['enableLlmsFullTxt'] = $llmsFullLocked
            ? (bool)$settings->enableLlmsFullTxt
            : $this->parseBooleanBodyParam('enableLlmsFullTxt', (bool)$settings->enableLlmsFullTxt);
        $settingsData['enableCommerceTxt'] = $commerceLocked
            ? (bool)$settings->enableCommerceTxt
            : $this->parseBooleanBodyParam('enableCommerceTxt', (bool)$settings->enableCommerceTxt);
        $settingsData['llmsTxtBody'] = $llmsBodyLocked
            ? (string)$settings->llmsTxtBody
            : $this->parseStringBodyParam('llmsTxtBody', (string)$settings->llmsTxtBody);
        $settingsData['commerceTxtBody'] = $commerceBodyLocked
            ? (string)$settings->commerceTxtBody
            : $this->parseStringBodyParam('commerceTxtBody', (string)$settings->commerceTxtBody);

        if ($resetDiscoveryBodyTarget === 'llms' && !$llmsBodyLocked) {
            $settingsData['llmsTxtBody'] = '';
        }
        if ($resetDiscoveryBodyTarget === 'commerce' && !$commerceBodyLocked) {
            $settingsData['commerceTxtBody'] = '';
        }

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, $settingsData);
        if (!$saved) {
            $this->setFailFlash('Couldn’t save Agents settings.');
            return $this->redirectToPostedUrl(null, 'agents/settings');
        }

        $plugin->getDiscoveryTxtService()->invalidateAllCaches();

        $notes = [];
        if ((bool)$enabledState['locked']) {
            $notes[] = '`enabled` remains controlled by `PLUGIN_AGENTS_ENABLED`.';
        }
        if ($llmsLocked) {
            $notes[] = '`enableLlmsTxt` is controlled by `config/agents.php`.';
        }
        if ($llmsFullLocked) {
            $notes[] = '`enableLlmsFullTxt` is controlled by `config/agents.php`.';
        }
        if ($commerceLocked) {
            $notes[] = '`enableCommerceTxt` is controlled by `config/agents.php`.';
        }
        if ($credentialUsageIndicatorLocked) {
            $notes[] = '`enableCredentialUsageIndicator` is controlled by `config/agents.php`.';
        }
        if ($llmsBodyLocked) {
            $notes[] = '`llmsTxtBody` is controlled by `config/agents.php`.';
        }
        if ($commerceBodyLocked) {
            $notes[] = '`commerceTxtBody` is controlled by `config/agents.php`.';
        }

        if (!empty($notes)) {
            $this->setSuccessFlash('Agents settings saved. ' . implode(' ', $notes));
        } elseif ($resetDiscoveryBodyTarget === 'llms' && !$llmsBodyLocked) {
            $this->setSuccessFlash('Agents settings saved. `llms.txt` custom body reset to generated default.');
        } elseif ($resetDiscoveryBodyTarget === 'commerce' && !$commerceBodyLocked) {
            $this->setSuccessFlash('Agents settings saved. `commerce.txt` custom body reset to generated default.');
        } else {
            $this->setSuccessFlash('Agents settings saved.');
        }

        return $this->redirectToPostedUrl(null, 'agents/settings');
    }

    public function actionPrewarmDiscovery(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $target = strtolower(trim((string)$this->request->getBodyParam('target', 'all')));
        if (!in_array($target, ['all', 'llms', 'llms-full', 'commerce'], true)) {
            $target = 'all';
        }

        try {
            $result = Plugin::getInstance()->getDiscoveryTxtService()->prewarm($target);
            $documents = implode(', ', array_keys((array)($result['documents'] ?? [])));
            $suffix = $documents !== '' ? ' Generated: ' . $documents . '.' : '';
            $this->setSuccessFlash(sprintf('Discovery prewarm complete for `%s`.%s', $target, $suffix));
        } catch (Throwable $e) {
            $this->setFailFlash('Discovery prewarm failed: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/dashboard/discovery');
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

        return $this->redirectToPostedUrl(null, 'agents/dashboard/overview');
    }

    public function actionClearDiscoveryCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            Plugin::getInstance()->getDiscoveryTxtService()->invalidateAllCaches();
            $this->setSuccessFlash('Discovery caches cleared.');
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to clear discovery caches: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/dashboard/discovery');
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
                    return $this->redirectToPostedUrl(null, 'agents/dashboard/security');
                }

                $event = $service->replayDeadLetterEvent($id);
                if (!is_array($event)) {
                    $this->setFailFlash('Dead-letter event not found.');
                    return $this->redirectToPostedUrl(null, 'agents/dashboard/security');
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

        return $this->redirectToPostedUrl(null, 'agents/dashboard/security');
    }

    public function actionCreateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $handle = (string)$this->request->getBodyParam('credentialHandle', '');
        $displayName = (string)$this->request->getBodyParam('credentialDisplayName', '');
        $token = (string)$this->request->getBodyParam('credentialToken', '');
        $scopes = $this->parseScopesInput($this->request->getBodyParam('credentialScopes', ''));
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
                $token,
                $scopes,
                $defaultScopes,
                $webhookSubscriptions,
                $expiryPolicy,
                $networkPolicy
            );
            $credential = (array)($result['credential'] ?? []);
            $this->storeRevealedCredential([
                'token' => (string)($result['token'] ?? ''),
                'handle' => (string)($credential['handle'] ?? ''),
                'displayName' => (string)($credential['displayName'] ?? ''),
                'action' => 'created',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->setSuccessFlash(sprintf('Agent `%s` created. Copy the API token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to create agent: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionUpdateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        $displayName = (string)$this->request->getBodyParam('credentialDisplayName', '');
        $scopes = $this->parseScopesInput($this->request->getBodyParam('credentialScopes', ''));
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
            $this->setFailFlash('Missing agent ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $credential = $plugin->getCredentialService()->updateManagedCredential(
                $credentialId,
                $displayName,
                $scopes,
                $defaultScopes,
                $webhookSubscriptions,
                $expiryPolicy,
                $networkPolicy
            );
            if (!is_array($credential)) {
                $this->setFailFlash('Agent not found.');
                return $this->redirectToPostedUrl(null, 'agents/credentials');
            }

            $this->setSuccessFlash(sprintf('Agent `%s` updated.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to update agent: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionRotateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_ROTATE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);

        if ($credentialId <= 0) {
            $this->setFailFlash('Missing agent ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $result = $plugin->getCredentialService()->rotateManagedCredential($credentialId, $defaultScopes);
            if (!is_array($result)) {
                $this->setFailFlash('Agent not found.');
                return $this->redirectToPostedUrl(null, 'agents/credentials');
            }

            $credential = (array)($result['credential'] ?? []);
            $this->storeRevealedCredential([
                'token' => (string)($result['token'] ?? ''),
                'handle' => (string)($credential['handle'] ?? ''),
                'displayName' => (string)($credential['displayName'] ?? ''),
                'action' => 'rotated',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->setSuccessFlash(sprintf('Agent `%s` rotated. Copy the new API token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to rotate agent token: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionRevokeCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_REVOKE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing agent ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $revoked = Plugin::getInstance()->getCredentialService()->revokeManagedCredential($credentialId);
            if (!$revoked) {
                $this->setFailFlash('Agent not found.');
            } else {
                $this->setSuccessFlash('Agent token revoked.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to revoke agent token: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionPauseCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing agent ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $paused = Plugin::getInstance()->getCredentialService()->pauseManagedCredential($credentialId);
            if (!$paused) {
                $this->setFailFlash('Agent could not be paused. It may be revoked, already paused, or migrations are not up to date.');
            } else {
                $this->setSuccessFlash('Agent paused.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to pause agent: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionResumeCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing agent ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $resumed = Plugin::getInstance()->getCredentialService()->resumeManagedCredential($credentialId);
            if (!$resumed) {
                $this->setFailFlash('Agent could not be resumed. It may be revoked or migrations are not up to date.');
            } else {
                $this->setSuccessFlash('Agent resumed.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to resume agent: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionRevokeAndRotateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_ROTATE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);

        if ($credentialId <= 0) {
            $this->setFailFlash('Missing agent ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $result = $plugin->getCredentialService()->rotateManagedCredential($credentialId, $defaultScopes);
            if (!is_array($result)) {
                $this->setFailFlash('Agent not found.');
                return $this->redirectToPostedUrl(null, 'agents/credentials');
            }

            $credential = (array)($result['credential'] ?? []);
            $this->storeRevealedCredential([
                'token' => (string)($result['token'] ?? ''),
                'handle' => (string)($credential['handle'] ?? ''),
                'displayName' => (string)($credential['displayName'] ?? ''),
                'action' => 'revoked and rotated',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->setSuccessFlash(sprintf('Agent `%s` revoked and rotated. Old token is now invalid. Copy the new API token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to revoke and rotate agent token: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionDeleteCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_DELETE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing agent ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $deleted = Plugin::getInstance()->getCredentialService()->deleteManagedCredential($credentialId);
            if (!$deleted) {
                $this->setFailFlash('Agent not found.');
            } else {
                $this->setSuccessFlash('Agent deleted.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to delete agent: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionUpsertControlPolicy(): Response
    {
        $this->requireRefundApprovalsExperimentalEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_POLICIES_MANAGE);

        $service = Plugin::getInstance()->getControlPlaneService();

        try {
            $policy = $service->upsertPolicy([
                'handle' => (string)$this->request->getBodyParam('handle', ''),
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

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    public function actionRequestControlApproval(): Response
    {
        $this->requireRefundApprovalsExperimentalEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE);

        $settings = $this->getSettingsModel();
        if (!(bool)$settings->allowCpApprovalRequests) {
            $this->setFailFlash('Manual form is off (agent-first mode). Ask your integration to submit the request via API.');
            return $this->redirectToPostedUrl(null, 'agents/control');
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
                $this->setSuccessFlash(sprintf('Request #%d created (`%s`).', (int)($approval['id'] ?? 0), $status));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to create request: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    public function actionDecideControlApproval(): Response
    {
        $this->requireRefundApprovalsExperimentalEnabled();
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $approvalId = (int)$this->request->getBodyParam('approvalId', 0);
        if ($approvalId <= 0) {
            $this->setFailFlash('Missing request number.');
            return $this->redirectToPostedUrl(null, 'agents/control');
        }

        try {
            $approval = $service->decideApproval(
                $approvalId,
                (string)$this->request->getBodyParam('decision', ''),
                (string)$this->request->getBodyParam('decisionReason', ''),
                $this->buildCpActorContext()
            );

            if (!is_array($approval)) {
                $this->setFailFlash('Request not found.');
                return $this->redirectToPostedUrl(null, 'agents/control');
            }

            $decisionStatus = (string)($approval['status'] ?? 'pending');
            $approvalsRemaining = (int)($approval['approvalsRemaining'] ?? 0);
            if ($decisionStatus === 'pending' && $approvalsRemaining > 0) {
                $this->setSuccessFlash(sprintf(
                    'Request #%d (`%s`) recorded one approval and is still pending (%d more needed).',
                    $approvalId,
                    (string)($approval['actionType'] ?? 'action'),
                    $approvalsRemaining
                ));
            } else {
                $this->setSuccessFlash(sprintf(
                    'Request #%d (`%s`) is now `%s`.',
                    $approvalId,
                    (string)($approval['actionType'] ?? 'action'),
                    $decisionStatus
                ));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to save decision: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    public function actionExecuteControlAction(): Response
    {
        $this->requireRefundApprovalsExperimentalEnabled();
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

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    public function actionSimulateControlAction(): Response
    {
        $this->requireRefundApprovalsExperimentalEnabled();
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

        return $this->redirectToPostedUrl(null, 'agents/control');
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
            $apiBasePath . '/consumers/lag',
            $apiBasePath . '/consumers/checkpoint',
            $apiBasePath . '/schema',
            $apiBasePath . '/capabilities',
            $apiBasePath . '/openapi.json',
        ];
        if ($this->isRefundApprovalsExperimentalEnabled()) {
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

    private function getDiscoveryEndpoints(): array
    {
        return [
            '/llms.txt',
            '/llms-full.txt',
            '/commerce.txt',
        ];
    }

    private function dashboardTabs(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'url' => 'agents/dashboard/overview'],
            ['key' => 'readiness', 'label' => 'Readiness', 'url' => 'agents/dashboard/readiness'],
            ['key' => 'discovery', 'label' => 'Discovery Docs', 'url' => 'agents/dashboard/discovery'],
            ['key' => 'security', 'label' => 'Security', 'url' => 'agents/dashboard/security'],
        ];
    }

    private function resolveDashboardTab(): string
    {
        $request = Craft::$app->getRequest();
        $tabFromQuery = strtolower(trim((string)$request->getQueryParam('tab', '')));
        if (in_array($tabFromQuery, self::DASHBOARD_TABS, true)) {
            return $tabFromQuery;
        }

        $pathInfo = trim((string)$request->getPathInfo(), '/');
        if (preg_match('#^agents/dashboard/(overview|readiness|discovery|security)$#', $pathInfo, $matches) === 1) {
            return (string)$matches[1];
        }

        if (preg_match('#^agents/(overview|readiness|discovery|security)$#', $pathInfo, $matches) === 1) {
            return (string)$matches[1];
        }

        if ($pathInfo === 'agents/health') {
            return 'readiness';
        }

        return 'overview';
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

            $scopes[] = $scope;
        }

        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        return $scopes;
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
            'enableCredentialUsageIndicator' => array_key_exists('enableCredentialUsageIndicator', $config),
            'enableLlmsTxt' => array_key_exists('enableLlmsTxt', $config),
            'enableLlmsFullTxt' => array_key_exists('enableLlmsFullTxt', $config),
            'enableCommerceTxt' => array_key_exists('enableCommerceTxt', $config),
            'llmsTxtBody' => array_key_exists('llmsTxtBody', $config),
            'commerceTxtBody' => array_key_exists('commerceTxtBody', $config),
        ];
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

    private function isRefundApprovalsExperimentalEnabled(): bool
    {
        return Plugin::getInstance()->isRefundApprovalsExperimentalEnabled();
    }

    private function requireRefundApprovalsExperimentalEnabled(): void
    {
        if ($this->isRefundApprovalsExperimentalEnabled()) {
            return;
        }

        throw new NotFoundHttpException('Not found.');
    }
}
