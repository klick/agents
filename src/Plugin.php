<?php

namespace Klick\Agents;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Element;
use craft\base\Model;
use craft\helpers\App;
use craft\events\RegisterUrlRulesEvent;
use craft\events\ModelEvent;
use craft\elements\Entry;
use craft\web\UrlManager;
use yii\base\Event;
use Klick\Agents\models\Settings;
use Klick\Agents\services\DiscoveryTxtService;
use Klick\Agents\services\ReadinessService;
use Klick\Agents\services\SecurityPolicyService;
use Klick\Agents\services\WebhookService;

class Plugin extends BasePlugin
{
    public bool $hasCpSection = true;
    public string $schemaVersion = '0.1.1';

    public static ?self $plugin = null;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;
        $this->setComponents([
            'readinessService' => ReadinessService::class,
            'discoveryTxtService' => DiscoveryTxtService::class,
            'securityPolicyService' => SecurityPolicyService::class,
            'webhookService' => WebhookService::class,
        ]);
        $this->registerDiscoveryInvalidationHooks();
        $this->registerWebhookEventHooks();
        $this->logSecurityConfigurationWarnings();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'Klick\\Agents\\console\\controllers';
            Craft::$app->controllerMap['agents'] = 'Klick\\Agents\\console\\controllers\\AgentsController';
            return;
        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpRoutes();
            return;
        }

        $this->registerSiteRoutes();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Agents';
        $item['subnav'] = [
            'overview' => [
                'label' => 'Overview',
                'url' => 'agents/overview',
            ],
            'readiness' => [
                'label' => 'Readiness',
                'url' => 'agents/readiness',
            ],
            'discovery' => [
                'label' => 'Discovery',
                'url' => 'agents/discovery',
            ],
            'security' => [
                'label' => 'Security',
                'url' => 'agents/security',
            ],
        ];

        return $item;
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event): void {
            $event->rules = array_merge($event->rules, [
                'agents' => 'agents/dashboard/overview',
                'agents/overview' => 'agents/dashboard/overview',
                'agents/readiness' => 'agents/dashboard/readiness',
                'agents/discovery' => 'agents/dashboard/discovery',
                'agents/security' => 'agents/dashboard/security',
                // Legacy aliases retained for backward-compatible deep links.
                'agents/dashboard' => 'agents/dashboard/dashboard',
                'agents/health' => 'agents/dashboard/health',
            ]);
        });
    }

    private function registerSiteRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event): void {
            $event->rules = array_merge($event->rules, [
                'llms.txt' => 'agents/api/llms-txt',
                'commerce.txt' => 'agents/api/commerce-txt',
                'agents/v1/health' => 'agents/api/health',
                'agents/v1/readiness' => 'agents/api/readiness',
                'agents/v1/products' => 'agents/api/products',
                'agents/v1/orders' => 'agents/api/orders',
                'agents/v1/orders/show' => 'agents/api/order-show',
                'agents/v1/entries' => 'agents/api/entries',
                'agents/v1/entries/show' => 'agents/api/entry-show',
                'agents/v1/changes' => 'agents/api/changes',
                'agents/v1/sections' => 'agents/api/sections',
                'agents/v1/capabilities' => 'agents/api/capabilities',
                'agents/v1/openapi.json' => 'agents/api/openapi',
            ]);
        });
    }

    public function getReadinessService(): ReadinessService
    {
        /** @var ReadinessService $service */
        $service = $this->get('readinessService');
        return $service;
    }

    public function getDiscoveryTxtService(): DiscoveryTxtService
    {
        /** @var DiscoveryTxtService $service */
        $service = $this->get('discoveryTxtService');
        return $service;
    }

    public function getWebhookService(): WebhookService
    {
        /** @var WebhookService $service */
        $service = $this->get('webhookService');
        return $service;
    }

    public function getSecurityPolicyService(): SecurityPolicyService
    {
        /** @var SecurityPolicyService $service */
        $service = $this->get('securityPolicyService');
        return $service;
    }

    public function isAgentsEnabled(): bool
    {
        return (bool)$this->getAgentsEnabledState()['enabled'];
    }

    public function getAgentsEnabledState(): array
    {
        $envEnabled = App::parseBooleanEnv('$PLUGIN_AGENTS_ENABLED');
        if ($envEnabled !== null) {
            return [
                'enabled' => (bool)$envEnabled,
                'source' => 'env',
                'locked' => true,
            ];
        }

        $settings = $this->getSettings();
        $settingsEnabled = $settings instanceof Settings ? (bool)$settings->enabled : true;

        return [
            'enabled' => $settingsEnabled,
            'source' => 'settings',
            'locked' => false,
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function registerDiscoveryInvalidationHooks(): void
    {
        $this->attachInvalidationHandlers(Entry::class);
        foreach ([
            'craft\\commerce\\elements\\Product',
            'craft\\commerce\\elements\\Variant',
        ] as $className) {
            if (!class_exists($className)) {
                continue;
            }

            $this->attachInvalidationHandlers($className);
        }
    }

    private function attachInvalidationHandlers(string $className): void
    {
        Event::on($className, Element::EVENT_AFTER_SAVE, function(): void {
            $this->getDiscoveryTxtService()->invalidateAllCaches();
        });

        Event::on($className, Element::EVENT_AFTER_DELETE, function(): void {
            $this->getDiscoveryTxtService()->invalidateAllCaches();
        });
    }

    private function registerWebhookEventHooks(): void
    {
        foreach ([
            Entry::class,
            'craft\\commerce\\elements\\Product',
            'craft\\commerce\\elements\\Variant',
            'craft\\commerce\\elements\\Order',
        ] as $className) {
            if (!class_exists($className)) {
                continue;
            }

            $this->attachWebhookHandlers($className);
        }
    }

    private function attachWebhookHandlers(string $className): void
    {
        Event::on($className, Element::EVENT_AFTER_SAVE, function(ModelEvent $event): void {
            $element = $event->sender;
            if (!$element instanceof Element) {
                return;
            }

            $this->getWebhookService()->queueElementChange($element, 'saved', (bool)$event->isNew);
        });

        Event::on($className, Element::EVENT_AFTER_DELETE, function(Event $event): void {
            $element = $event->sender;
            if (!$element instanceof Element) {
                return;
            }

            $this->getWebhookService()->queueElementChange($element, 'deleted', false);
        });
    }

    private function logSecurityConfigurationWarnings(): void
    {
        foreach ($this->getSecurityPolicyService()->getWarnings() as $warning) {
            $level = (string)($warning['level'] ?? 'warning');
            $message = (string)($warning['message'] ?? '');
            if ($message === '') {
                continue;
            }

            if ($level === 'error') {
                Craft::error($message, __METHOD__);
                continue;
            }

            Craft::warning($message, __METHOD__);
        }
    }
}
