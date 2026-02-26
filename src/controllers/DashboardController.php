<?php

namespace Klick\Agents\controllers;

use Craft;
use Klick\Agents\Plugin;
use Klick\Agents\models\Settings;
use craft\web\Controller;
use craft\web\View;
use Throwable;
use yii\web\Response;

class DashboardController extends Controller
{
    private const SESSION_REVEALED_CREDENTIAL = 'agents.revealedCredential';

    public function actionIndex(): Response
    {
        return $this->redirect('agents/overview');
    }

    public function actionDashboard(): Response
    {
        return $this->redirect('agents/overview');
    }

    public function actionOverview(): Response
    {
        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $settings = $this->getSettingsModel();
        $readiness = $plugin->getReadinessService()->getReadinessDiagnostics();
        $securityPosture = $plugin->getSecurityPolicyService()->getCpPosture();

        return $this->renderCpTemplate('agents/overview', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'readiness' => $readiness,
            'readinessJson' => $this->prettyPrintJson($readiness['summary'] ?? []),
            'discoveryEnabled' => [
                'llms' => (bool)$settings->enableLlmsTxt,
                'commerce' => (bool)$settings->enableCommerceTxt,
            ],
            'webhookEnabled' => (bool)($securityPosture['webhook']['enabled'] ?? false),
            'securityWarningCounts' => (array)($securityPosture['warningCounts'] ?? []),
            'apiEndpoints' => $this->getApiEndpoints(),
            'discoveryEndpoints' => $this->getDiscoveryEndpoints(),
        ]);
    }

    public function actionReadiness(): Response
    {
        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $health = $plugin->getReadinessService()->getHealthSummary();
        $readiness = $plugin->getReadinessService()->getReadinessSummary();
        $diagnostics = $plugin->getReadinessService()->getReadinessDiagnostics();

        return $this->renderCpTemplate('agents/readiness', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'health' => $health,
            'readiness' => $readiness,
            'diagnostics' => $diagnostics,
            'healthJson' => $this->prettyPrintJson($health),
            'readinessJson' => $this->prettyPrintJson($readiness),
            'diagnosticsJson' => $this->prettyPrintJson($diagnostics),
        ]);
    }

    public function actionDiscovery(): Response
    {
        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $settings = $this->getSettingsModel();
        $discoveryStatus = $plugin->getDiscoveryTxtService()->getDiscoveryStatus();

        return $this->renderCpTemplate('agents/discovery', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'discoveryStatus' => $discoveryStatus,
            'discoveryStatusJson' => $this->prettyPrintJson($discoveryStatus),
            'discoveryConfig' => [
                'llmsEnabled' => (bool)$settings->enableLlmsTxt,
                'commerceEnabled' => (bool)$settings->enableCommerceTxt,
                'llmsTtl' => (int)$settings->llmsTxtCacheTtl,
                'commerceTtl' => (int)$settings->commerceTxtCacheTtl,
            ],
        ]);
    }

    public function actionSecurity(): Response
    {
        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $posture = $plugin->getSecurityPolicyService()->getCpPosture();

        return $this->renderCpTemplate('agents/security', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'securityPosture' => $posture,
            'securityPostureJson' => $this->prettyPrintJson($posture),
        ]);
    }

    public function actionControl(): Response
    {
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_VIEW);

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        $snapshot = $plugin->getControlPlaneService()->getControlPlaneSnapshot(25);

        return $this->renderCpTemplate('agents/control', [
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'controlSummary' => (array)($snapshot['summary'] ?? []),
            'controlPolicies' => (array)($snapshot['policies'] ?? []),
            'controlApprovals' => (array)($snapshot['approvals'] ?? []),
            'controlExecutions' => (array)($snapshot['executions'] ?? []),
            'controlAuditEvents' => (array)($snapshot['audit'] ?? []),
            'controlSnapshotJson' => $this->prettyPrintJson($snapshot),
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
        return $this->redirect('agents/readiness');
    }

    public function actionToggleEnabled(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        if ((bool)$enabledState['locked']) {
            $this->setFailFlash('Agents enabled state is controlled by `PLUGIN_AGENTS_ENABLED` and cannot be changed from the Control Panel.');
            return $this->redirectToPostedUrl(null, 'agents/overview');
        }

        $enabledRaw = strtolower(trim((string)$this->request->getBodyParam('enabled', '0')));
        $enabled = in_array($enabledRaw, ['1', 'true', 'on', 'yes'], true);

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'enabled' => $enabled,
        ]);

        if (!$saved) {
            $this->setFailFlash('Couldn’t save Agents settings.');
            return $this->redirectToPostedUrl(null, 'agents/overview');
        }

        $this->setSuccessFlash($enabled ? 'Agents API enabled.' : 'Agents API disabled.');
        return $this->redirectToPostedUrl(null, 'agents/overview');
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

        return $this->redirectToPostedUrl(null, 'agents/discovery');
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

        return $this->redirectToPostedUrl(null, 'agents/discovery');
    }

    public function actionCreateCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_MANAGE);

        $plugin = Plugin::getInstance();
        $defaultScopes = $this->getDefaultScopes();
        $handle = (string)$this->request->getBodyParam('credentialHandle', '');
        $displayName = (string)$this->request->getBodyParam('credentialDisplayName', '');
        $scopes = $this->parseScopesInput((string)$this->request->getBodyParam('credentialScopes', ''));

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
            $this->setSuccessFlash(sprintf('Credential `%s` created. Copy the token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to create credential: ' . $e->getMessage());
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
        $scopes = $this->parseScopesInput((string)$this->request->getBodyParam('credentialScopes', ''));

        if ($credentialId <= 0) {
            $this->setFailFlash('Missing credential id.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $credential = $plugin->getCredentialService()->updateManagedCredential($credentialId, $displayName, $scopes, $defaultScopes);
            if (!is_array($credential)) {
                $this->setFailFlash('Credential not found.');
                return $this->redirectToPostedUrl(null, 'agents/credentials');
            }

            $this->setSuccessFlash(sprintf('Credential `%s` updated.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to update credential: ' . $e->getMessage());
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
            $this->setFailFlash('Missing credential id.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $result = $plugin->getCredentialService()->rotateManagedCredential($credentialId, $defaultScopes);
            if (!is_array($result)) {
                $this->setFailFlash('Credential not found.');
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
            $this->setSuccessFlash(sprintf('Credential `%s` rotated. Copy the new token now; it will only be shown once.', (string)($credential['handle'] ?? 'credential')));
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to rotate credential: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionRevokeCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_REVOKE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing credential id.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $revoked = Plugin::getInstance()->getCredentialService()->revokeManagedCredential($credentialId);
            if (!$revoked) {
                $this->setFailFlash('Credential not found.');
            } else {
                $this->setSuccessFlash('Credential revoked.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to revoke credential: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionDeleteCredential(): Response
    {
        $this->requirePostRequest();
        $this->requireCredentialPermission(Plugin::PERMISSION_CREDENTIALS_DELETE);

        $credentialId = (int)$this->request->getBodyParam('credentialId', 0);
        if ($credentialId <= 0) {
            $this->setFailFlash('Missing credential id.');
            return $this->redirectToPostedUrl(null, 'agents/credentials');
        }

        try {
            $deleted = Plugin::getInstance()->getCredentialService()->deleteManagedCredential($credentialId);
            if (!$deleted) {
                $this->setFailFlash('Credential not found.');
            } else {
                $this->setSuccessFlash('Credential deleted.');
            }
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to delete credential: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/credentials');
    }

    public function actionUpsertControlPolicy(): Response
    {
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

            $this->setSuccessFlash(sprintf('Policy `%s` saved.', (string)($policy['handle'] ?? 'policy')));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to save control policy: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    public function actionRequestControlApproval(): Response
    {
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $idempotencyKey = trim((string)$this->request->getBodyParam('idempotencyKey', ''));

        try {
            $approval = $service->requestApproval([
                'actionType' => (string)$this->request->getBodyParam('actionType', ''),
                'actionRef' => (string)$this->request->getBodyParam('actionRef', ''),
                'reason' => (string)$this->request->getBodyParam('reason', ''),
                'idempotencyKey' => $idempotencyKey,
                'payload' => $this->parseJsonBodyParam((string)$this->request->getBodyParam('payloadJson', '')),
                'metadata' => $this->parseJsonBodyParam((string)$this->request->getBodyParam('metadataJson', '')),
            ], $this->buildCpActorContext());

            $status = (string)($approval['status'] ?? 'pending');
            $this->setSuccessFlash(sprintf('Approval #%d requested (%s).', (int)($approval['id'] ?? 0), $status));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to request control approval: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    public function actionDecideControlApproval(): Response
    {
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_APPROVALS_MANAGE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $approvalId = (int)$this->request->getBodyParam('approvalId', 0);
        if ($approvalId <= 0) {
            $this->setFailFlash('Missing approval id.');
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
                $this->setFailFlash('Approval not found.');
                return $this->redirectToPostedUrl(null, 'agents/control');
            }

            $this->setSuccessFlash(sprintf('Approval #%d is now `%s`.', $approvalId, (string)($approval['status'] ?? 'pending')));
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to decide approval: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    public function actionExecuteControlAction(): Response
    {
        $this->requirePostRequest();
        $this->requireControlPermission(Plugin::PERMISSION_CONTROL_ACTIONS_EXECUTE);

        $service = Plugin::getInstance()->getControlPlaneService();
        $idempotencyKey = trim((string)$this->request->getBodyParam('idempotencyKey', ''));

        try {
            $execution = $service->executeAction([
                'actionType' => (string)$this->request->getBodyParam('actionType', ''),
                'actionRef' => (string)$this->request->getBodyParam('actionRef', ''),
                'approvalId' => (int)$this->request->getBodyParam('approvalId', 0),
                'idempotencyKey' => $idempotencyKey,
                'payload' => $this->parseJsonBodyParam((string)$this->request->getBodyParam('payloadJson', '')),
            ], $this->buildCpActorContext());

            $status = (string)($execution['status'] ?? 'unknown');
            if ($status === 'succeeded') {
                $this->setSuccessFlash(sprintf('Control action executed successfully (%s).', $idempotencyKey !== '' ? $idempotencyKey : 'no idempotency key'));
            } else {
                $this->setFailFlash(sprintf('Control action recorded with `%s` status. %s', $status, (string)($execution['errorMessage'] ?? '')));
            }
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
        } catch (Throwable $e) {
            $this->setFailFlash('Unable to execute control action: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl(null, 'agents/control');
    }

    private function getApiEndpoints(): array
    {
        $apiBasePath = '/agents/v1';
        return [
            $apiBasePath . '/health',
            $apiBasePath . '/readiness',
            $apiBasePath . '/products',
            $apiBasePath . '/orders',
            $apiBasePath . '/entries',
            $apiBasePath . '/changes',
            $apiBasePath . '/sections',
            $apiBasePath . '/capabilities',
            $apiBasePath . '/openapi.json',
            $apiBasePath . '/control/policies',
            $apiBasePath . '/control/approvals',
            $apiBasePath . '/control/executions',
            $apiBasePath . '/control/actions/execute',
            $apiBasePath . '/control/audit',
        ];
    }

    private function getDiscoveryEndpoints(): array
    {
        return [
            '/llms.txt',
            '/commerce.txt',
        ];
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

    private function parseScopesInput(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', strtolower($raw)) ?: [];
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
}
