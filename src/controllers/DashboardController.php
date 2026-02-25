<?php

namespace Klick\Agents\controllers;

use Craft;
use Klick\Agents\Plugin;
use Klick\Agents\models\Settings;
use craft\web\Controller;
use yii\web\Response;

class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $model = $plugin->getReadinessService()->getDashboardModel();
        $enabledState = $plugin->getAgentsEnabledState();
        $settings = $plugin->getSettings();
        $settingsModel = $settings instanceof Settings ? $settings : new Settings();
        $webhookConfig = $plugin->getWebhookService()->getWebhookConfig();
        $apiBasePath = '/agents/v1';

        return $this->renderTemplate('agents/dashboard', [
            'readinessVersion' => $model['readinessVersion'],
            'buildDate' => $model['buildDate'],
            'status' => $model['status'],
            'summary' => $model['summary'],
            'summaryJson' => $this->prettyPrintJson($model['summary']),
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'discoveryEnabled' => [
                'llms' => (bool)$settingsModel->enableLlmsTxt,
                'commerce' => (bool)$settingsModel->enableCommerceTxt,
            ],
            'webhookEnabled' => (bool)$webhookConfig['enabled'],
            'apiBasePath' => $apiBasePath,
            'apiEndpoints' => [
                $apiBasePath . '/health',
                $apiBasePath . '/readiness',
                $apiBasePath . '/products',
                $apiBasePath . '/orders',
                $apiBasePath . '/entries',
                $apiBasePath . '/changes',
                $apiBasePath . '/capabilities',
                $apiBasePath . '/openapi.json',
            ],
            'discoveryEndpoints' => [
                '/llms.txt',
                '/commerce.txt',
            ],
        ]);
    }

    public function actionHealth(): Response
    {
        $plugin = Plugin::getInstance();
        $health = $plugin->getReadinessService()->getHealthSummary();
        $readiness = $plugin->getReadinessService()->getReadinessSummary();
        $enabledState = $plugin->getAgentsEnabledState();

        return $this->renderTemplate('agents/health', [
            'health' => $health,
            'readiness' => $readiness,
            'healthJson' => $this->prettyPrintJson($health),
            'readinessJson' => $this->prettyPrintJson($readiness),
            'agentsEnabled' => (bool)$enabledState['enabled'],
            'agentsEnabledSource' => (string)$enabledState['source'],
        ]);
    }

    public function actionToggleEnabled(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $enabledState = $plugin->getAgentsEnabledState();
        if ((bool)$enabledState['locked']) {
            $this->setFailFlash('Agents enabled state is controlled by `PLUGIN_AGENTS_ENABLED` and cannot be changed from the Control Panel.');
            return $this->redirectToPostedUrl(null, 'agents/dashboard');
        }

        $enabledRaw = strtolower(trim((string)$this->request->getBodyParam('enabled', '0')));
        $enabled = in_array($enabledRaw, ['1', 'true', 'on', 'yes'], true);

        $saved = Craft::$app->getPlugins()->savePluginSettings($plugin, [
            'enabled' => $enabled,
        ]);

        if (!$saved) {
            $this->setFailFlash('Couldn’t save Agents settings.');
            return $this->redirectToPostedUrl(null, 'agents/dashboard');
        }

        $this->setSuccessFlash($enabled ? 'Agents API enabled.' : 'Agents API disabled.');
        return $this->redirectToPostedUrl(null, 'agents/dashboard');
    }

    private function prettyPrintJson(array $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT);
        return is_string($encoded) ? $encoded : '{}';
    }
}
