<?php

namespace agentreadiness;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Element;
use craft\base\Model;
use craft\helpers\App;
use craft\events\RegisterUrlRulesEvent;
use craft\elements\Entry;
use craft\web\UrlManager;
use yii\base\Event;
use agentreadiness\models\Settings;
use agentreadiness\services\DiscoveryTxtService;
use agentreadiness\services\ReadinessService;

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
        ]);
        $this->registerDiscoveryInvalidationHooks();
        $this->logSecurityConfigurationWarnings();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'agentreadiness\\console\\controllers';
            Craft::$app->controllerMap['agents'] = 'agentreadiness\\console\\controllers\\AgentsController';
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
            'dashboard' => [
                'label' => 'Dashboard',
                'url' => 'agents/dashboard',
            ],
            'health' => [
                'label' => 'Health',
                'url' => 'agents/health',
            ],
        ];

        return $item;
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event): void {
            $event->rules = array_merge($event->rules, [
                'agents' => 'agents/dashboard/index',
                'agents/dashboard' => 'agents/dashboard/index',
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

    private function logSecurityConfigurationWarnings(): void
    {
        $requireToken = App::parseBooleanEnv('$PLUGIN_AGENTS_REQUIRE_TOKEN');
        if ($requireToken === null) {
            $requireToken = true;
        }

        $allowQueryToken = App::parseBooleanEnv('$PLUGIN_AGENTS_ALLOW_QUERY_TOKEN');
        if ($allowQueryToken === null) {
            $allowQueryToken = false;
        }

        $failOnMissingTokenInProd = App::parseBooleanEnv('$PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD');
        if ($failOnMissingTokenInProd === null) {
            $failOnMissingTokenInProd = true;
        }

        $apiToken = trim((string)App::env('PLUGIN_AGENTS_API_TOKEN'));
        $environment = strtolower((string)(App::env('ENVIRONMENT') ?: App::env('CRAFT_ENVIRONMENT') ?: 'production'));
        $isProduction = in_array($environment, ['prod', 'production'], true);

        if (!$requireToken) {
            Craft::warning('Agents API token enforcement is disabled (`PLUGIN_AGENTS_REQUIRE_TOKEN=false`).', __METHOD__);
        }

        if ($allowQueryToken) {
            Craft::warning('Agents API query token transport is enabled (`PLUGIN_AGENTS_ALLOW_QUERY_TOKEN=true`).', __METHOD__);
        }

        if ($requireToken && $apiToken === '') {
            $message = 'Agents API token is required but `PLUGIN_AGENTS_API_TOKEN` is empty.';
            if ($isProduction && $failOnMissingTokenInProd) {
                Craft::error($message . ' Requests will fail-closed in production.', __METHOD__);
                return;
            }

            Craft::warning($message . ' Set `PLUGIN_AGENTS_REQUIRE_TOKEN=false` for explicit local-only bypass.', __METHOD__);
        }
    }
}
