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
                'commerceEnabled' => (bool)$settings->enableCommerceTxt,
                'llmsTtl' => (int)$settings->llmsTxtCacheTtl,
                'commerceTtl' => (int)$settings->commerceTxtCacheTtl,
            ],
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

        return $this->renderCpTemplate('agents/settings-tab', [
            'settings' => $this->getSettingsModel(),
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'refundApprovalsExperimentalEnabled' => $plugin->isRefundApprovalsExperimentalEnabled(),
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

        return $this->renderCpTemplate('agents/credentials', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'securityPosture' => $posture,
            'managedCredentials' => $managedCredentials,
            'defaultScopes' => $defaultScopes,
            'revealedCredential' => $this->pullRevealedCredential(),
            'canManageCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE),
            'canRotateCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_ROTATE),
            'canRevokeCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_REVOKE),
            'canDeleteCredentials' => $this->canCredentialPermission(Plugin::PERMISSION_CREDENTIALS_DELETE),
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

        $settingsData = get_object_vars($settings);
        $settingsData['enabled'] = (bool)$enabledState['locked']
            ? (bool)$enabledState['enabled']
            : $this->parseBooleanBodyParam('enabled', (bool)$settings->enabled);
        $settingsData['allowCpApprovalRequests'] = $plugin->isRefundApprovalsExperimentalEnabled()
            ? $this->parseBooleanBodyParam('allowCpApprovalRequests', (bool)$settings->allowCpApprovalRequests)
            : false;
        $settingsData['enableLlmsTxt'] = $this->parseBooleanBodyParam('enableLlmsTxt', (bool)$settings->enableLlmsTxt);
        $settingsData['enableCommerceTxt'] = $this->parseBooleanBodyParam('enableCommerceTxt', (bool)$settings->enableCommerceTxt);

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, $settingsData);
        if (!$saved) {
            $this->setFailFlash('Couldn’t save Agents settings.');
            return $this->redirectToPostedUrl(null, 'agents/settings');
        }

        if ((bool)$enabledState['locked']) {
            $this->setSuccessFlash('Agents settings saved. `enabled` remains controlled by `PLUGIN_AGENTS_ENABLED`.');
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
        if (!in_array($target, ['all', 'llms', 'commerce'], true)) {
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

    public function actionCreateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $handle = (string)$this->request->getBodyParam('credentialHandle', '');
        $displayName = (string)$this->request->getBodyParam('credentialDisplayName', '');
        $scopes = $this->parseScopesInput($this->request->getBodyParam('credentialScopes', ''));

        try {
            $result = $plugin->getCredentialService()->createManagedCredential($handle, $displayName, $scopes, $defaultScopes);
            $credential = (array)($result['credential'] ?? []);
            $this->storeRevealedCredential([
                'token' => (string)($result['token'] ?? ''),
                'handle' => (string)($credential['handle'] ?? ''),
                'displayName' => (string)($credential['displayName'] ?? ''),
                'action' => 'created',
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->setSuccessFlash(sprintf('API key `%s` created. Copy the token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to create API key: ' . $e->getMessage());
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

        if ($credentialId <= 0) {
            $this->setFailFlash('Missing API key ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $credential = $plugin->getCredentialService()->updateManagedCredential($credentialId, $displayName, $scopes, $defaultScopes);
            if (!is_array($credential)) {
                $this->setFailFlash('API key not found.');
                return $this->redirectToPostedUrl(null, 'agents/credentials');
            }

            $this->setSuccessFlash(sprintf('API key `%s` updated.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to update API key: ' . $e->getMessage());
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
            $this->setFailFlash('Missing API key ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $result = $plugin->getCredentialService()->rotateManagedCredential($credentialId, $defaultScopes);
            if (!is_array($result)) {
                $this->setFailFlash('API key not found.');
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
            $this->setSuccessFlash(sprintf('API key `%s` rotated. Copy the new token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to rotate API key: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionRevokeCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_REVOKE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing API key ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $revoked = Plugin::getInstance()->getCredentialService()->revokeManagedCredential($credentialId);
            if (!$revoked) {
                $this->setFailFlash('API key not found.');
            } else {
                $this->setSuccessFlash('API key revoked.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to revoke API key: ' . $e->getMessage());
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
            $this->setFailFlash('Missing API key ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $result = $plugin->getCredentialService()->rotateManagedCredential($credentialId, $defaultScopes);
            if (!is_array($result)) {
                $this->setFailFlash('API key not found.');
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
            $this->setSuccessFlash(sprintf('API key `%s` revoked and rotated. Old token is now invalid. Copy the new token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to revoke and rotate API key: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionDeleteCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_DELETE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing API key ID.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $deleted = Plugin::getInstance()->getCredentialService()->deleteManagedCredential($credentialId);
            if (!$deleted) {
                $this->setFailFlash('API key not found.');
            } else {
                $this->setSuccessFlash('API key deleted.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to delete API key: ' . $e->getMessage());
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
            $this->setSuccessFlash(sprintf(
                'Request #%d (`%s`) is now `%s`.',
                $approvalId,
                (string)($approval['actionType'] ?? 'action'),
                $decisionStatus
            ));
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

    private function getApiEndpoints(): array
    {
        $apiBasePath = '/agents/v1';
        $endpoints = [
            $apiBasePath . '/health',
            $apiBasePath . '/readiness',
            $apiBasePath . '/products',
            $apiBasePath . '/orders',
            $apiBasePath . '/entries',
            $apiBasePath . '/changes',
            $apiBasePath . '/sections',
            $apiBasePath . '/capabilities',
            $apiBasePath . '/openapi.json',
        ];
        if ($this->isRefundApprovalsExperimentalEnabled()) {
            $endpoints = array_merge($endpoints, [
                $apiBasePath . '/control/policies',
                $apiBasePath . '/control/approvals',
                $apiBasePath . '/control/executions',
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
            '/commerce.txt',
        ];
    }

    private function dashboardTabs(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'url' => 'agents/dashboard/overview'],
            ['key' => 'readiness', 'label' => 'Readiness', 'url' => 'agents/dashboard/readiness'],
            ['key' => 'discovery', 'label' => 'Discovery', 'url' => 'agents/dashboard/discovery'],
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

    private function parseBooleanBodyParam(string $name, bool $default = false): bool
    {
        $raw = $this->request->getBodyParam($name);
        if ($raw === null) {
            return $default;
        }

        $value = strtolower(trim((string)$raw));
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
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

    private function storeRevealedCredential(array $credential): void
    {
        Craft::$app->getSession()->set(self::SESSION_REVEALED_CREDENTIAL, $credential);
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
