<?php

namespace Klick\Agents;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Element;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\ModelEvent;
use craft\elements\Entry;
use craft\services\UserPermissions;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use Klick\Agents\models\Settings;
use Klick\Agents\services\AdoptionMetricsService;
use Klick\Agents\services\ControlPlaneService;
use Klick\Agents\services\ConsumerLagService;
use Klick\Agents\services\CredentialService;
use Klick\Agents\services\DiagnosticsBundleService;
use Klick\Agents\services\DiscoveryTxtService;
use Klick\Agents\services\ObservabilityMetricsService;
use Klick\Agents\services\ReadinessService;
use Klick\Agents\services\SecurityPolicyService;
use Klick\Agents\services\TemplateCatalogService;
use Klick\Agents\services\WebhookService;

class Plugin extends BasePlugin
{
    public const PERMISSION_CREDENTIALS_VIEW = 'agents-viewCredentials';
    public const PERMISSION_CREDENTIALS_MANAGE = 'agents-manageCredentials';
    public const PERMISSION_CREDENTIALS_ROTATE = 'agents-rotateCredentials';
    public const PERMISSION_CREDENTIALS_REVOKE = 'agents-revokeCredentials';
    public const PERMISSION_CREDENTIALS_DELETE = 'agents-deleteCredentials';
    public const PERMISSION_CONTROL_VIEW = 'agents-viewControlPlane';
    public const PERMISSION_CONTROL_POLICIES_MANAGE = 'agents-manageControlPolicies';
    public const PERMISSION_CONTROL_APPROVALS_MANAGE = 'agents-manageControlApprovals';
    public const PERMISSION_CONTROL_ACTIONS_EXECUTE = 'agents-executeControlActions';

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '0.8.7';

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
            'credentialService' => CredentialService::class,
            'controlPlaneService' => ControlPlaneService::class,
            'consumerLagService' => ConsumerLagService::class,
            'adoptionMetricsService' => AdoptionMetricsService::class,
            'observabilityMetricsService' => ObservabilityMetricsService::class,
            'diagnosticsBundleService' => DiagnosticsBundleService::class,
            'templateCatalogService' => TemplateCatalogService::class,
        ]);
        $this->registerDiscoveryInvalidationHooks();
        $this->registerWebhookEventHooks();
        $this->registerCacheUtilityHooks();
        $this->registerPermissionHooks();
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
        $item['url'] = 'agents';
        $subnav = [
            'dashboard' => [
                'label' => 'Dashboard',
                'url' => 'agents/dashboard',
            ],
        ];
        if ($this->isRefundApprovalsExperimentalEnabled()) {
            $subnav['control'] = [
                'label' => 'Return Requests',
                'url' => 'agents/control',
            ];
        }
        $subnav['credentials'] = [
            'label' => 'Agents',
            'url' => 'agents/credentials',
        ];
        $subnav['settings'] = [
            'label' => 'Settings',
            'url' => 'agents/settings',
        ];
        $item['subnav'] = $subnav;

        return $item;
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event): void {
            $rules = [
                'agents' => 'agents/dashboard/dashboard',
                'agents/overview' => 'agents/dashboard/dashboard',
                'agents/readiness' => 'agents/dashboard/dashboard',
                'agents/discovery' => 'agents/dashboard/dashboard',
                'agents/security' => 'agents/dashboard/dashboard',
                'agents/settings' => 'agents/dashboard/settings',
                'agents/credentials' => 'agents/dashboard/credentials',
                // Legacy aliases retained for backward-compatible deep links.
                'agents/dashboard' => 'agents/dashboard/dashboard',
                'agents/dashboard/overview' => 'agents/dashboard/dashboard',
                'agents/dashboard/readiness' => 'agents/dashboard/dashboard',
                'agents/dashboard/discovery' => 'agents/dashboard/dashboard',
                'agents/dashboard/security' => 'agents/dashboard/dashboard',
                'agents/health' => 'agents/dashboard/health',
            ];
            if ($this->isRefundApprovalsExperimentalEnabled()) {
                $rules['agents/control'] = 'agents/dashboard/control';
            }

            $event->rules = array_merge($event->rules, $rules);
        });
    }

    private function registerSiteRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event): void {
            $rules = [
                'llms.txt' => 'agents/api/llms-txt',
                'llms-full.txt' => 'agents/api/llms-full-txt',
                'commerce.txt' => 'agents/api/commerce-txt',
                'capabilities' => 'agents/api/capabilities',
                'openapi.json' => 'agents/api/openapi',
                'agents/v1/health' => 'agents/api/health',
                'agents/v1/readiness' => 'agents/api/readiness',
                'agents/v1/products' => 'agents/api/products',
                'agents/v1/variants' => 'agents/api/variants',
                'agents/v1/variants/show' => 'agents/api/variant-show',
                'agents/v1/subscriptions' => 'agents/api/subscriptions',
                'agents/v1/subscriptions/show' => 'agents/api/subscription-show',
                'agents/v1/transfers' => 'agents/api/transfers',
                'agents/v1/transfers/show' => 'agents/api/transfer-show',
                'agents/v1/donations' => 'agents/api/donations',
                'agents/v1/donations/show' => 'agents/api/donation-show',
                'agents/v1/orders' => 'agents/api/orders',
                'agents/v1/orders/show' => 'agents/api/order-show',
                'agents/v1/entries' => 'agents/api/entries',
                'agents/v1/entries/show' => 'agents/api/entry-show',
                'agents/v1/assets' => 'agents/api/assets',
                'agents/v1/assets/show' => 'agents/api/asset-show',
                'agents/v1/categories' => 'agents/api/categories',
                'agents/v1/categories/show' => 'agents/api/category-show',
                'agents/v1/tags' => 'agents/api/tags',
                'agents/v1/tags/show' => 'agents/api/tag-show',
                'agents/v1/global-sets' => 'agents/api/global-sets',
                'agents/v1/global-sets/show' => 'agents/api/global-set-show',
                'agents/v1/addresses' => 'agents/api/addresses',
                'agents/v1/addresses/show' => 'agents/api/address-show',
                'agents/v1/content-blocks' => 'agents/api/content-blocks',
                'agents/v1/content-blocks/show' => 'agents/api/content-block-show',
                'agents/v1/users' => 'agents/api/users',
                'agents/v1/users/show' => 'agents/api/user-show',
                'agents/v1/changes' => 'agents/api/changes',
                'agents/v1/sections' => 'agents/api/sections',
                'agents/v1/templates' => 'agents/api/templates',
                'agents/v1/schema' => 'agents/api/schema',
                'agents/v1/capabilities' => 'agents/api/capabilities',
                'agents/v1/openapi.json' => 'agents/api/openapi',
                'agents/v1/auth/whoami' => 'agents/api/auth-whoami',
                'agents/v1/adoption/metrics' => 'agents/api/adoption-metrics',
                'agents/v1/metrics' => 'agents/api/metrics',
                'agents/v1/diagnostics/bundle' => 'agents/api/diagnostics-bundle',
                'agents/v1/consumers/checkpoint' => 'agents/api/consumers-checkpoint',
                'agents/v1/consumers/lag' => 'agents/api/consumers-lag',
                'agents/v1/webhooks/dlq' => 'agents/api/webhook-dlq-list',
                'agents/v1/webhooks/dlq/replay' => 'agents/api/webhook-dlq-replay',
            ];
            if ($this->isRefundApprovalsExperimentalEnabled()) {
                $rules = array_merge($rules, [
                    'agents/v1/control/policies' => 'agents/api/control-policies',
                    'agents/v1/control/policies/upsert' => 'agents/api/control-policy-upsert',
                    'agents/v1/control/approvals' => 'agents/api/control-approvals',
                    'agents/v1/control/approvals/request' => 'agents/api/control-approval-request',
                    'agents/v1/control/approvals/decide' => 'agents/api/control-approval-decide',
                    'agents/v1/control/executions' => 'agents/api/control-executions',
                    'agents/v1/control/policy-simulate' => 'agents/api/control-policy-simulate',
                    'agents/v1/control/actions/execute' => 'agents/api/control-actions-execute',
                    'agents/v1/control/audit' => 'agents/api/control-audit',
                ]);
            }

            $event->rules = array_merge($event->rules, $rules);
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

    public function getCredentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = $this->get('credentialService');
        return $service;
    }

    public function getControlPlaneService(): ControlPlaneService
    {
        /** @var ControlPlaneService $service */
        $service = $this->get('controlPlaneService');
        return $service;
    }

    public function getConsumerLagService(): ConsumerLagService
    {
        /** @var ConsumerLagService $service */
        $service = $this->get('consumerLagService');
        return $service;
    }

    public function getAdoptionMetricsService(): AdoptionMetricsService
    {
        /** @var AdoptionMetricsService $service */
        $service = $this->get('adoptionMetricsService');
        return $service;
    }

    public function getObservabilityMetricsService(): ObservabilityMetricsService
    {
        /** @var ObservabilityMetricsService $service */
        $service = $this->get('observabilityMetricsService');
        return $service;
    }

    public function getDiagnosticsBundleService(): DiagnosticsBundleService
    {
        /** @var DiagnosticsBundleService $service */
        $service = $this->get('diagnosticsBundleService');
        return $service;
    }

    public function getTemplateCatalogService(): TemplateCatalogService
    {
        /** @var TemplateCatalogService $service */
        $service = $this->get('templateCatalogService');
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

    public function isCommercePluginEnabled(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $configuredEnabled = $projectConfig->get('plugins.commerce.enabled');

        if (is_bool($configuredEnabled)) {
            return $configuredEnabled;
        }

        if (is_numeric($configuredEnabled)) {
            return ((int)$configuredEnabled) === 1;
        }

        $normalized = strtolower(trim((string)$configuredEnabled));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function isRefundApprovalsExperimentalEnabled(): bool
    {
        return (bool)App::parseBooleanEnv('$PLUGIN_AGENTS_REFUND_APPROVALS_EXPERIMENTAL');
    }

    public function isUsersApiEnabled(): bool
    {
        return (bool)App::parseBooleanEnv('$PLUGIN_AGENTS_ENABLE_USERS_API');
    }

    public function isAddressesApiEnabled(): bool
    {
        return (bool)App::parseBooleanEnv('$PLUGIN_AGENTS_ENABLE_ADDRESSES_API');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $settingsModel = $settings instanceof Settings ? $settings : new Settings();
        $enabledState = $this->getAgentsEnabledState();

        return Craft::$app->getView()->renderTemplate('agents/settings', [
            'settings' => $settingsModel,
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'refundApprovalsExperimentalEnabled' => $this->isRefundApprovalsExperimentalEnabled(),
        ], View::TEMPLATE_MODE_CP);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('agents/dashboard/overview'));
    }

    public function getReadOnlySettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('agents/dashboard/overview'));
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

    private function registerCacheUtilityHooks(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event): void {
                $event->options[] = [
                    'key' => 'agents-discovery',
                    'label' => Craft::t('app', 'Agents discovery caches'),
                    'info' => Craft::t('app', 'Cached `llms.txt`, `llms-full.txt`, and `commerce.txt` documents generated by Agents'),
                    'action' => function(): void {
                        $this->getDiscoveryTxtService()->invalidateAllCaches();
                    },
                ];
            }
        );
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

    private function registerPermissionHooks(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => 'Agents Access',
                    'permissions' => [
                        self::PERMISSION_CREDENTIALS_VIEW => [
                            'label' => 'View managed agents tab',
                        ],
                        self::PERMISSION_CREDENTIALS_MANAGE => [
                            'label' => 'Create and edit managed agents',
                        ],
                        self::PERMISSION_CREDENTIALS_ROTATE => [
                            'label' => 'Rotate managed agent tokens',
                        ],
                        self::PERMISSION_CREDENTIALS_REVOKE => [
                            'label' => 'Revoke managed agent tokens',
                        ],
                        self::PERMISSION_CREDENTIALS_DELETE => [
                            'label' => 'Delete managed agents',
                        ],
                    ],
                ];
                if ($this->isRefundApprovalsExperimentalEnabled()) {
                    $event->permissions[] = [
                        'heading' => 'Agents Return Requests',
                        'permissions' => [
                            self::PERMISSION_CONTROL_VIEW => [
                                'label' => 'View return requests tab',
                            ],
                            self::PERMISSION_CONTROL_POLICIES_MANAGE => [
                                'label' => 'Create and edit return rules',
                            ],
                            self::PERMISSION_CONTROL_APPROVALS_MANAGE => [
                                'label' => 'Approve and reject return requests',
                            ],
                            self::PERMISSION_CONTROL_ACTIONS_EXECUTE => [
                                'label' => 'Run approved return actions',
                            ],
                        ],
                    ];
                }
            }
        );
    }
}
