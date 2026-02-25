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
}
