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
use craft\events\ModelEvent;
use craft\elements\Entry;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use Klick\Agents\events\RegisterExternalResourceProvidersEvent;
use Klick\Agents\models\Settings;
use Klick\Agents\services\AdoptionMetricsService;
use Klick\Agents\services\ControlPlaneService;
use Klick\Agents\services\ConsumerLagService;
use Klick\Agents\services\CredentialService;
use Klick\Agents\services\DiagnosticsBundleService;
use Klick\Agents\services\ExternalResourceRegistryService;
use Klick\Agents\services\IncidentFeedService;
use Klick\Agents\services\LifecycleGovernanceService;
use Klick\Agents\services\NotificationService;
use Klick\Agents\services\OnboardingStateService;
use Klick\Agents\services\ObservabilityMetricsService;
use Klick\Agents\services\ReadinessService;
use Klick\Agents\services\ReliabilitySignalService;
use Klick\Agents\services\SecurityPolicyService;
use Klick\Agents\services\StarterPackService;
use Klick\Agents\services\TemplateCatalogService;
use Klick\Agents\services\TargetSetService;
use Klick\Agents\services\WebhookService;
use Klick\Agents\services\WebhookProbeService;
use Klick\Agents\services\WebhookTestSinkService;
use Klick\Agents\services\WorkflowService;

class Plugin extends BasePlugin
{
    public const EVENT_REGISTER_EXTERNAL_RESOURCE_PROVIDERS = 'registerExternalResourceProviders';

    public const PERMISSION_CREDENTIALS_VIEW = 'agents-viewCredentials';
    public const PERMISSION_CREDENTIALS_MANAGE = 'agents-manageCredentials';
    public const PERMISSION_CREDENTIALS_ROTATE = 'agents-rotateCredentials';
    public const PERMISSION_CREDENTIALS_REVOKE = 'agents-revokeCredentials';
    public const PERMISSION_CREDENTIALS_DELETE = 'agents-deleteCredentials';
    public const PERMISSION_WORKFLOWS_VIEW = 'agents-viewWorkflows';
    public const PERMISSION_WORKFLOWS_MANAGE = 'agents-manageWorkflows';
    public const PERMISSION_CONTROL_VIEW = 'agents-viewControlPlane';
    public const PERMISSION_CONTROL_POLICIES_MANAGE = 'agents-manageControlPolicies';
    public const PERMISSION_CONTROL_APPROVALS_MANAGE = 'agents-manageControlApprovals';
    public const PERMISSION_CONTROL_ACTIONS_EXECUTE = 'agents-executeControlActions';

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '0.29.0';

    public static ?self $plugin = null;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;
        $this->setComponents([
            'readinessService' => ReadinessService::class,
            'securityPolicyService' => SecurityPolicyService::class,
            'webhookService' => WebhookService::class,
            'webhookProbeService' => WebhookProbeService::class,
            'credentialService' => CredentialService::class,
            'workflowService' => WorkflowService::class,
            'targetSetService' => TargetSetService::class,
            'controlPlaneService' => ControlPlaneService::class,
            'consumerLagService' => ConsumerLagService::class,
            'adoptionMetricsService' => AdoptionMetricsService::class,
            'observabilityMetricsService' => ObservabilityMetricsService::class,
            'reliabilitySignalService' => ReliabilitySignalService::class,
            'lifecycleGovernanceService' => LifecycleGovernanceService::class,
            'notificationService' => NotificationService::class,
            'onboardingStateService' => OnboardingStateService::class,
            'diagnosticsBundleService' => DiagnosticsBundleService::class,
            'incidentFeedService' => IncidentFeedService::class,
            'externalResourceRegistryService' => ExternalResourceRegistryService::class,
            'templateCatalogService' => TemplateCatalogService::class,
            'starterPackService' => StarterPackService::class,
            'webhookTestSinkService' => WebhookTestSinkService::class,
        ]);
        Craft::$app->onInit(function (): void {
            $this->refreshExternalResourceProviders();
        });
        $this->registerWebhookEventHooks();
        $this->registerPermissionHooks();
        $this->logSecurityConfigurationWarnings();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'Klick\\Agents\\console\\controllers';
            Craft::$app->controllerMap['agents'] = 'Klick\\Agents\\console\\controllers\\AgentsController';
            return;
        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerSettingsPluginLinkOverride();
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
            'accounts' => [
                'label' => 'Accounts',
                'url' => 'agents/accounts',
            ],
            'jobs' => [
                'label' => 'Jobs',
                'url' => 'agents/workflows',
            ],
        ];
        if ($this->isControlCpEnabled()) {
            $subnav['boundaries'] = [
                'label' => 'Boundaries',
                'url' => 'agents/target-sets',
            ];
            $subnav['approvals'] = [
                'label' => 'Approvals',
                'url' => 'agents/approvals',
            ];
        }
        $subnav['status'] = [
            'label' => 'Status',
            'url' => 'agents/status',
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
                'agents' => 'agents/dashboard/index',
                'agents/start' => 'agents/dashboard/start',
                'agents/status' => 'agents/dashboard/dashboard',
                'agents/settings' => 'agents/dashboard/settings',
                'agents/accounts' => 'agents/dashboard/credentials',
                'agents/workflows' => 'agents/dashboard/workflows',
                'agents/workflows/<workflowId:\\d+>' => 'agents/dashboard/workflows',
            ];
            if ($this->isControlCpEnabled()) {
                $rules['agents/target-sets'] = 'agents/dashboard/target-sets';
                $rules['agents/approvals'] = 'agents/dashboard/control';
                $rules['agents/approvals/approvals'] = 'agents/dashboard/control';
                $rules['agents/approvals/rules'] = 'agents/dashboard/control';
                $rules['agents/approvals/diff'] = 'agents/dashboard/control-diff';
            }

            $event->rules = array_merge($event->rules, $rules);
        });
    }

    private function registerSettingsPluginLinkOverride(): void
    {
        $request = Craft::$app->getRequest();
        $pathInfo = trim((string)$request->getPathInfo(), '/');
        if ($pathInfo !== 'settings/plugins') {
            return;
        }

        $settingsUrl = UrlHelper::cpUrl('agents/settings');
        $encodedSettingsUrl = json_encode($settingsUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedSettingsUrl) || $encodedSettingsUrl === '') {
            return;
        }

        Craft::$app->getView()->registerJs(<<<JS
(() => {
  const row = document.querySelector('tr[data-handle="agents"]');
  const iconLink = row ? row.querySelector('.plugin-infos > a.icon') : null;
  if (!iconLink) {
    return;
  }

  const settingsUrl = {$encodedSettingsUrl};
  iconLink.setAttribute('href', settingsUrl);
  iconLink.setAttribute('title', 'Open Agents settings');
  iconLink.setAttribute('aria-label', 'Open Agents settings');
})();
JS, View::POS_END);
    }

    private function registerSiteRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event): void {
            $rules = [
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
                'agents/v1/workflows' => 'agents/api/workflows',
                'agents/v1/workflows/show' => 'agents/api/workflow-show',
                'agents/v1/workflows/run-report' => 'agents/api/workflow-run-report',
                'agents/v1/templates' => 'agents/api/templates',
                'agents/v1/starter-packs' => 'agents/api/starter-packs',
                'agents/v1/schema' => 'agents/api/schema',
                'agents/v1/capabilities' => 'agents/api/capabilities',
                'agents/v1/openapi.json' => 'agents/api/openapi',
                'agents/v1/plugins/<pluginHandle:[a-zA-Z0-9._-]+>/<resourceHandle:[a-zA-Z0-9._-]+>' => 'agents/api/external-resource-index',
                'agents/v1/plugins/<pluginHandle:[a-zA-Z0-9._-]+>/<resourceHandle:[a-zA-Z0-9._-]+>/<resourceId:[^/]+>' => 'agents/api/external-resource-show',
                'agents/v1/auth/whoami' => 'agents/api/auth-whoami',
                'agents/v1/adoption/metrics' => 'agents/api/adoption-metrics',
                'agents/v1/metrics' => 'agents/api/metrics',
                'agents/v1/incidents' => 'agents/api/incidents',
                'agents/v1/lifecycle' => 'agents/api/lifecycle',
                'agents/v1/diagnostics/bundle' => 'agents/api/diagnostics-bundle',
                'agents/v1/sync-state/checkpoint' => 'agents/api/consumers-checkpoint',
                'agents/v1/sync-state/lag' => 'agents/api/consumers-lag',
                'agents/v1/consumers/checkpoint' => 'agents/api/consumers-checkpoint',
                'agents/v1/consumers/lag' => 'agents/api/consumers-lag',
                'agents/v1/webhooks/dlq' => 'agents/api/webhook-dlq-list',
                'agents/v1/webhooks/dlq/replay' => 'agents/api/webhook-dlq-replay',
                'agents/dev/webhook-test-sink' => 'agents/webhook-test-sink/receive',
            ];
            if ($this->isWritesExperimentalEnabled()) {
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

    public function getWebhookService(): WebhookService
    {
        /** @var WebhookService $service */
        $service = $this->get('webhookService');
        return $service;
    }

    public function getWebhookTestSinkService(): WebhookTestSinkService
    {
        /** @var WebhookTestSinkService $service */
        $service = $this->get('webhookTestSinkService');
        return $service;
    }

    public function getWebhookProbeService(): WebhookProbeService
    {
        /** @var WebhookProbeService $service */
        $service = $this->get('webhookProbeService');
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

    public function getWorkflowService(): WorkflowService
    {
        /** @var WorkflowService $service */
        $service = $this->get('workflowService');
        return $service;
    }

    public function getTargetSetService(): TargetSetService
    {
        /** @var TargetSetService $service */
        $service = $this->get('targetSetService');
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

    public function getReliabilitySignalService(): ReliabilitySignalService
    {
        /** @var ReliabilitySignalService $service */
        $service = $this->get('reliabilitySignalService');
        return $service;
    }

    public function getLifecycleGovernanceService(): LifecycleGovernanceService
    {
        /** @var LifecycleGovernanceService $service */
        $service = $this->get('lifecycleGovernanceService');
        return $service;
    }

    public function getStarterPackService(): StarterPackService
    {
        /** @var StarterPackService $service */
        $service = $this->get('starterPackService');
        return $service;
    }

    public function getDiagnosticsBundleService(): DiagnosticsBundleService
    {
        /** @var DiagnosticsBundleService $service */
        $service = $this->get('diagnosticsBundleService');
        return $service;
    }

    public function getNotificationService(): NotificationService
    {
        /** @var NotificationService $service */
        $service = $this->get('notificationService');
        return $service;
    }

    public function getOnboardingStateService(): OnboardingStateService
    {
        /** @var OnboardingStateService $service */
        $service = $this->get('onboardingStateService');
        return $service;
    }

    public function getIncidentFeedService(): IncidentFeedService
    {
        /** @var IncidentFeedService $service */
        $service = $this->get('incidentFeedService');
        return $service;
    }

    public function getExternalResourceRegistryService(): ExternalResourceRegistryService
    {
        /** @var ExternalResourceRegistryService $service */
        $service = $this->get('externalResourceRegistryService');
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

    public function getWritesExperimentalState(): array
    {
        $writesEnabled = App::parseBooleanEnv('$PLUGIN_AGENTS_WRITES_EXPERIMENTAL');
        if ($writesEnabled !== null) {
            return [
                'enabled' => (bool)$writesEnabled,
                'source' => 'env:PLUGIN_AGENTS_WRITES_EXPERIMENTAL',
                'locked' => true,
            ];
        }

        $settings = $this->getSettings();
        $settingsEnabled = $settings instanceof Settings ? (bool)$settings->enableWritesExperimental : false;

        return [
            'enabled' => $settingsEnabled,
            'source' => 'settings',
            'locked' => false,
        ];
    }

    public function isWritesExperimentalEnabled(): bool
    {
        return (bool)$this->getWritesExperimentalState()['enabled'];
    }

    public function isWritesCpExperimentalEnabled(): bool
    {
        // Control CP visibility follows the governed write-surface toggle.
        return $this->isWritesExperimentalEnabled();
    }

    public function isControlCpEnabled(): bool
    {
        return $this->isWritesCpExperimentalEnabled();
    }

    public function isUsersApiEnabled(): bool
    {
        return (bool)App::parseBooleanEnv('$PLUGIN_AGENTS_ENABLE_USERS_API');
    }

    public function isAddressesApiEnabled(): bool
    {
        return (bool)App::parseBooleanEnv('$PLUGIN_AGENTS_ENABLE_ADDRESSES_API');
    }

    public function refreshExternalResourceProviders(): void
    {
        $registry = $this->getExternalResourceRegistryService();
        $registry->clear();

        $event = new RegisterExternalResourceProvidersEvent();
        Event::trigger(self::class, self::EVENT_REGISTER_EXTERNAL_RESOURCE_PROVIDERS, $event);

        foreach ($event->providers as $provider) {
            try {
                $registry->registerProvider($provider);
            } catch (\Throwable $e) {
                Craft::warning('Skipping external resource provider registration: ' . $e->getMessage(), __METHOD__);
            }
        }
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
        $writesState = $this->getWritesExperimentalState();

        return Craft::$app->getView()->renderTemplate('agents/settings', [
            'settings' => $settingsModel,
            'agentsEnabledLocked' => (bool)$enabledState['locked'],
            'agentsEnabledSource' => (string)$enabledState['source'],
            'writesExperimentalEnabled' => (bool)$writesState['enabled'],
            'writesExperimentalSettingLocked' => (bool)$writesState['locked'],
            'writesExperimentalLockedByEnv' => (bool)$writesState['locked'],
            'writesExperimentalConfigLocked' => false,
            'writesExperimentalLockSource' => (string)($writesState['source'] ?? ''),
            'controlCpEnabled' => $this->isControlCpEnabled(),
        ], View::TEMPLATE_MODE_CP);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('agents/settings'));
    }

    public function getReadOnlySettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('agents/settings'));
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
                            'label' => 'View managed accounts tab',
                        ],
                        self::PERMISSION_CREDENTIALS_MANAGE => [
                            'label' => 'Create and edit managed accounts',
                        ],
                        self::PERMISSION_CREDENTIALS_ROTATE => [
                            'label' => 'Rotate managed account tokens',
                        ],
                        self::PERMISSION_CREDENTIALS_REVOKE => [
                            'label' => 'Revoke managed account tokens',
                        ],
                        self::PERMISSION_CREDENTIALS_DELETE => [
                            'label' => 'Delete managed accounts',
                        ],
                    ],
                ];
                $event->permissions[] = [
                    'heading' => 'Agents Jobs',
                    'permissions' => [
                        self::PERMISSION_WORKFLOWS_VIEW => [
                            'label' => 'View jobs tab',
                        ],
                        self::PERMISSION_WORKFLOWS_MANAGE => [
                            'label' => 'Create and edit jobs',
                        ],
                    ],
                ];
                if ($this->isControlCpEnabled()) {
                    $event->permissions[] = [
                        'heading' => 'Agents Approvals',
                        'permissions' => [
                            self::PERMISSION_CONTROL_VIEW => [
                                'label' => 'View approvals tab',
                            ],
                            self::PERMISSION_CONTROL_POLICIES_MANAGE => [
                                'label' => 'Create and edit approval rules',
                            ],
                            self::PERMISSION_CONTROL_APPROVALS_MANAGE => [
                                'label' => 'Approve and reject governed requests',
                            ],
                            self::PERMISSION_CONTROL_ACTIONS_EXECUTE => [
                                'label' => 'Run approved control actions',
                            ],
                        ],
                    ];
                }
            }
        );
    }
}
