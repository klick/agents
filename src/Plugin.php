<?php

namespace agentreadiness;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;
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
        ]);

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
}
