<?php

namespace Klick\Agents\controllers;

use Craft;
use Klick\Agents\Plugin;
use craft\web\Controller;
use yii\caching\CacheInterface;
use yii\mutex\Mutex;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ApiController extends Controller
{
    private const DEFAULT_TOKEN_SCOPES = [
        'health:read',
        'readiness:read',
        'auth:read',
        'products:read',
        'orders:read',
        'entries:read',
        'changes:read',
        'sections:read',
        'capabilities:read',
        'openapi:read',
        'control:policies:read',
        'control:approvals:read',
        'control:executions:read',
        'control:audit:read',
    ];
    private const ERROR_INVALID_REQUEST = 'INVALID_REQUEST';
    private const ERROR_UNAUTHORIZED = 'UNAUTHORIZED';
    private const ERROR_FORBIDDEN = 'FORBIDDEN';
    private const ERROR_NOT_FOUND = 'NOT_FOUND';
    private const ERROR_METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    private const ERROR_RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    private const ERROR_SERVICE_DISABLED = 'SERVICE_DISABLED';
    private const ERROR_SERVER_MISCONFIGURED = 'SERVER_MISCONFIGURED';
    private const ERROR_INTERNAL = 'INTERNAL_ERROR';

    private ?array $authContext = null;
    private ?array $securityConfig = null;
    private ?string $requestId = null;

    public function init(): void
    {
        parent::init();
        $this->requestId = $this->resolveRequestId();
        $this->response->headers->set('X-Request-Id', $this->getRequestId());
        $request = Craft::$app->request;
        if (!$request->getIsGet() && !$request->getIsHead()) {
            $this->requireAcceptsJson();
        }
    }

    public function beforeAction($action): bool
    {
        if (is_object($action) && str_starts_with((string)$action->id, 'control-')) {
            // Token-authenticated machine endpoints do not use session-bound CSRF tokens.
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    protected array|int|bool $allowAnonymous = true;
    protected array|bool|int $supportsCsrfValidation = true;

    public function actionLlmsTxt(): Response
    {
        if (($methodResponse = $this->enforceReadRequest()) !== null) {
            return $methodResponse;
        }

        if (!Plugin::getInstance()->isAgentsEnabled()) {
            return $this->serviceDisabledResponse();
        }

        $document = Plugin::getInstance()->getDiscoveryTxtService()->getLlmsTxtDocument();
        if ($document === null) {
            throw new NotFoundHttpException('Not found.');
        }

        return $this->respondWithDiscoveryDocument($document);
    }

    public function actionCommerceTxt(): Response
    {
        if (($methodResponse = $this->enforceReadRequest()) !== null) {
            return $methodResponse;
        }

        if (!Plugin::getInstance()->isAgentsEnabled()) {
            return $this->serviceDisabledResponse();
        }

        $document = Plugin::getInstance()->getDiscoveryTxtService()->getCommerceTxtDocument();
        if ($document === null) {
            throw new NotFoundHttpException('Not found.');
        }

        return $this->respondWithDiscoveryDocument($document);
    }

    public function actionHealth(): Response
    {
        if (($guard = $this->guardRequest('health:read')) !== null) {
            return $guard;
        }

        return $this->jsonResponse(Plugin::getInstance()->getReadinessService()->getHealthSummary());
    }

    public function actionReadiness(): Response
    {
        if (($guard = $this->guardRequest('readiness:read')) !== null) {
            return $guard;
        }

        return $this->jsonResponse(Plugin::getInstance()->getReadinessService()->getReadinessSummary());
    }

    public function actionProducts(): Response
    {
        if (($guard = $this->guardRequest('products:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $payload = Plugin::getInstance()->getReadinessService()->getProductsSnapshot([
            'q' => $request->getQueryParam('q'),
            'status' => $request->getQueryParam('status', 'live'),
            'sort' => $request->getQueryParam('sort', 'updatedAt'),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        $errors = $payload['page']['errors'] ?? [];
        if (!empty($errors)) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, (string)$errors[0], [
                'details' => array_values($errors),
            ]);
        }

        if (isset($payload['page']['errors'])) {
            unset($payload['page']['errors']);
        }

        return $this->jsonResponse($payload);
    }

    public function actionOrders(): Response
    {
        if (($guard = $this->guardRequest('orders:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $cursor = (string)$request->getQueryParam('cursor', '');
        $updatedSince = (string)$request->getQueryParam('updatedSince', '');
        $isIncremental = ($cursor !== '' || $updatedSince !== '');
        $lastDaysDefault = $isIncremental ? 0 : 30;
        $includeSensitive = $this->hasScope('orders:read_sensitive');
        $payload = Plugin::getInstance()->getReadinessService()->getOrdersList([
            'status' => $request->getQueryParam('status', 'all'),
            'lastDays' => (int)$request->getQueryParam('lastDays', $lastDaysDefault),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'includeSensitive' => $includeSensitive,
            'redactEmail' => !$includeSensitive && $this->shouldRedactEmail(),
            'cursor' => $cursor,
            'updatedSince' => $updatedSince,
        ]);

        return $this->respondWithPayload($payload);
    }

    public function actionOrderShow(): Response
    {
        if (($guard = $this->guardRequest('orders:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $includeSensitive = $this->hasScope('orders:read_sensitive');
        $payload = Plugin::getInstance()->getReadinessService()->getOrderByIdOrNumber([
            'id' => (int)$request->getQueryParam('id', 0),
            'number' => (string)$request->getQueryParam('number', ''),
            'includeSensitive' => $includeSensitive,
            'redactEmail' => !$includeSensitive && $this->shouldRedactEmail(),
        ]);

        return $this->respondWithPayload($payload, true, 'Order not found.');
    }

    public function actionEntries(): Response
    {
        if (($guard = $this->guardRequest('entries:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $requestedStatus = (string)$request->getQueryParam('status', 'live');
        $allowAllStatuses = $this->hasScope('entries:read_all_statuses');

        if (!$allowAllStatuses && $this->isNonLiveStatusRequest($requestedStatus)) {
            return $this->forbiddenResponse('entries:read_all_statuses', 'The requested entry status requires elevated scope.');
        }

        $payload = Plugin::getInstance()->getReadinessService()->getEntriesList([
            'section' => $request->getQueryParam('section', ''),
            'type' => $request->getQueryParam('type', ''),
            'status' => $allowAllStatuses ? $requestedStatus : 'live',
            'search' => $request->getQueryParam('search', $request->getQueryParam('q', '')),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($payload);
    }

    public function actionChanges(): Response
    {
        if (($guard = $this->guardRequest('changes:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $payload = Plugin::getInstance()->getReadinessService()->getChangesFeed([
            'types' => $request->getQueryParam('types', ''),
            'updatedSince' => $request->getQueryParam('updatedSince'),
            'cursor' => $request->getQueryParam('cursor'),
            'limit' => (int)$request->getQueryParam('limit', 50),
        ]);

        $errors = $payload['meta']['errors'] ?? [];
        if (!empty($errors)) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, (string)$errors[0], [
                'details' => array_values($errors),
            ]);
        }

        unset($payload['meta']);

        return $this->jsonResponse($payload);
    }

    public function actionEntryShow(): Response
    {
        if (($guard = $this->guardRequest('entries:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $allowAllStatuses = $this->hasScope('entries:read_all_statuses');
        $payload = Plugin::getInstance()->getReadinessService()->getEntryByIdOrSlug([
            'id' => (int)$request->getQueryParam('id', 0),
            'slug' => (string)$request->getQueryParam('slug', ''),
            'section' => (string)$request->getQueryParam('section', ''),
            'includeAllStatuses' => $allowAllStatuses,
        ]);

        return $this->respondWithPayload($payload, true, 'Entry not found.');
    }

    public function actionSections(): Response
    {
        if (($guard = $this->guardRequest('sections:read')) !== null) {
            return $guard;
        }

        $payload = Plugin::getInstance()->getReadinessService()->getSectionsList();
        return $this->respondWithPayload($payload);
    }

    public function actionCapabilities(): Response
    {
        if (($guard = $this->guardRequest('capabilities:read')) !== null) {
            return $guard;
        }

        $config = $this->getSecurityConfig();
        $pluginVersion = $this->resolvePluginVersion();
        $endpoints = [
            ['method' => 'GET', 'path' => '/health', 'requiredScopes' => ['health:read']],
            ['method' => 'GET', 'path' => '/readiness', 'requiredScopes' => ['readiness:read']],
            ['method' => 'GET', 'path' => '/auth/whoami', 'requiredScopes' => ['auth:read']],
            ['method' => 'GET', 'path' => '/products', 'requiredScopes' => ['products:read']],
            ['method' => 'GET', 'path' => '/orders', 'requiredScopes' => ['orders:read'], 'optionalScopes' => ['orders:read_sensitive']],
            ['method' => 'GET', 'path' => '/orders/show', 'requiredScopes' => ['orders:read'], 'optionalScopes' => ['orders:read_sensitive']],
            ['method' => 'GET', 'path' => '/entries', 'requiredScopes' => ['entries:read'], 'optionalScopes' => ['entries:read_all_statuses']],
            ['method' => 'GET', 'path' => '/entries/show', 'requiredScopes' => ['entries:read'], 'optionalScopes' => ['entries:read_all_statuses']],
            ['method' => 'GET', 'path' => '/changes', 'requiredScopes' => ['changes:read']],
            ['method' => 'GET', 'path' => '/sections', 'requiredScopes' => ['sections:read']],
            ['method' => 'GET', 'path' => '/capabilities', 'requiredScopes' => ['capabilities:read']],
            ['method' => 'GET', 'path' => '/openapi.json', 'requiredScopes' => ['openapi:read']],
            ['method' => 'GET', 'path' => '/control/policies', 'requiredScopes' => ['control:policies:read']],
            ['method' => 'POST', 'path' => '/control/policies/upsert', 'requiredScopes' => ['control:policies:write']],
            ['method' => 'GET', 'path' => '/control/approvals', 'requiredScopes' => ['control:approvals:read']],
            ['method' => 'POST', 'path' => '/control/approvals/request', 'requiredScopes' => ['control:approvals:request'], 'optionalScopes' => ['control:approvals:write']],
            ['method' => 'POST', 'path' => '/control/approvals/decide', 'requiredScopes' => ['control:approvals:decide'], 'optionalScopes' => ['control:approvals:write']],
            ['method' => 'GET', 'path' => '/control/executions', 'requiredScopes' => ['control:executions:read']],
            ['method' => 'POST', 'path' => '/control/actions/execute', 'requiredScopes' => ['control:actions:execute'], 'optionalScopes' => ['policy.config.requiredScope']],
            ['method' => 'GET', 'path' => '/control/audit', 'requiredScopes' => ['control:audit:read']],
            ['method' => 'GET', 'path' => '/llms.txt', 'public' => true],
            ['method' => 'GET', 'path' => '/commerce.txt', 'public' => true],
        ];
        if (!$this->isRefundApprovalsExperimentalEnabled()) {
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn(array $endpoint): bool => !str_starts_with((string)($endpoint['path'] ?? ''), '/control/')
            ));
        }

        return $this->jsonResponse([
            'service' => 'agents',
            'version' => $pluginVersion,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'basePath' => '/agents/v1',
            'discoveryAliases' => [
                '/capabilities' => '/agents/v1/capabilities',
                '/openapi.json' => '/agents/v1/openapi.json',
            ],
            'requestIdHeader' => 'X-Request-Id',
            'authentication' => $this->buildCapabilitiesAuthentication($config),
            'authorization' => [
                'grantedScopes' => $this->getGrantedScopes(),
                'availableScopes' => $this->availableScopes(),
            ],
            'errorCodes' => $this->errorTaxonomy(),
            'endpoints' => $endpoints,
            'commands' => [
                'agents/product-list',
                'agents/order-list',
                'agents/order-show',
                'agents/entry-list',
                'agents/entry-show',
                'agents/section-list',
                'agents/discovery-prewarm',
                'agents/auth-check',
                'agents/discovery-check',
                'agents/readiness-check',
                'agents/smoke',
            ],
        ]);
    }

    public function actionOpenapi(): Response
    {
        if (($guard = $this->guardRequest('openapi:read')) !== null) {
            return $guard;
        }

        $config = $this->getSecurityConfig();
        $pluginVersion = $this->resolvePluginVersion();
        $paths = [
            '/health' => ['get' => ['summary' => 'Health summary', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['health:read']]],
            '/readiness' => ['get' => ['summary' => 'Readiness summary', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['readiness:read']]],
            '/auth/whoami' => ['get' => [
                'summary' => 'Authenticated caller diagnostics',
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['auth:read'],
            ]],
            '/products' => ['get' => [
                'summary' => 'Product snapshot list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['live', 'pending', 'disabled', 'expired', 'all']]],
                    ['in' => 'query', 'name' => 'sort', 'schema' => ['type' => 'string', 'enum' => ['updatedAt', 'createdAt', 'title']]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request (e.g. malformed cursor/updatedSince).'],
                ]),
                'x-required-scopes' => ['products:read'],
            ]],
            '/orders' => ['get' => [
                'summary' => 'Order list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'lastDays', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request.'],
                ]),
                'x-required-scopes' => ['orders:read'],
                'x-optional-scopes' => ['orders:read_sensitive'],
            ]],
            '/orders/show' => ['get' => [
                'summary' => 'Single order by id or number',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'number', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['orders:read'],
                'x-optional-scopes' => ['orders:read_sensitive'],
            ]],
            '/entries' => ['get' => [
                'summary' => 'Entry list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'section', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'type', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['live', 'pending', 'disabled', 'expired', 'all']]],
                    ['in' => 'query', 'name' => 'search', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '403' => ['description' => 'Missing scope for non-live status access'],
                ]),
                'x-required-scopes' => ['entries:read'],
                'x-optional-scopes' => ['entries:read_all_statuses'],
            ]],
            '/changes' => ['get' => [
                'summary' => 'Unified incremental changes feed',
                'parameters' => [
                    ['in' => 'query', 'name' => 'types', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated list: products,orders,entries'],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['changes:read'],
            ]],
            '/entries/show' => ['get' => [
                'summary' => 'Single entry by id or slug',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'slug', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'section', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['entries:read'],
                'x-optional-scopes' => ['entries:read_all_statuses'],
            ]],
            '/sections' => ['get' => ['summary' => 'Section list', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['sections:read']]],
            '/capabilities' => ['get' => ['summary' => 'Feature and command discovery', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['capabilities:read']]],
            '/openapi.json' => ['get' => ['summary' => 'OpenAPI descriptor', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['openapi:read']]],
            '/control/policies' => ['get' => ['summary' => 'List control policies', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['control:policies:read']]],
            '/control/policies/upsert' => ['post' => [
                'summary' => 'Create or update a control policy',
                'requestBody' => ['required' => true],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['control:policies:write'],
            ]],
            '/control/approvals' => ['get' => [
                'summary' => 'List approval requests',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'actionType', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                ],
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['control:approvals:read'],
            ]],
            '/control/approvals/request' => ['post' => [
                'summary' => 'Create approval request',
                'requestBody' => ['required' => true],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['control:approvals:request'],
                'x-optional-scopes' => ['control:approvals:write'],
            ]],
            '/control/approvals/decide' => ['post' => [
                'summary' => 'Approve or reject approval request',
                'requestBody' => ['required' => true],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['control:approvals:decide'],
                'x-optional-scopes' => ['control:approvals:write'],
            ]],
            '/control/executions' => ['get' => [
                'summary' => 'List action execution ledger',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'actionType', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                ],
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['control:executions:read'],
            ]],
            '/control/actions/execute' => ['post' => [
                'summary' => 'Execute an idempotent control action',
                'requestBody' => ['required' => true],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '403' => ['description' => 'Missing required execution scope'],
                ]),
                'x-required-scopes' => ['control:actions:execute'],
                'x-optional-scopes' => ['policy.config.requiredScope'],
            ]],
            '/control/audit' => ['get' => [
                'summary' => 'List control-plane audit events',
                'parameters' => [
                    ['in' => 'query', 'name' => 'category', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'actorId', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                ],
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['control:audit:read'],
            ]],
            '/llms.txt' => ['get' => ['summary' => 'Public llms.txt discovery surface', 'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Disabled'], '503' => ['description' => 'Service disabled']]]],
            '/commerce.txt' => ['get' => ['summary' => 'Public commerce.txt discovery surface', 'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Disabled'], '503' => ['description' => 'Service disabled']]]],
        ];
        if (!$this->isRefundApprovalsExperimentalEnabled()) {
            foreach ($this->controlOpenApiPaths() as $path) {
                unset($paths[$path]);
            }
        }

        return $this->jsonResponse([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Agents API',
                'version' => $pluginVersion,
                'description' => 'Agent discovery and control-plane API for read surfaces plus governed policy/approval/action workflows.',
            ],
            'servers' => [
                ['url' => '/agents/v1', 'description' => 'Primary API'],
                ['url' => '/', 'description' => 'Site root discovery files'],
            ],
            'x-discovery-aliases' => [
                '/capabilities' => '/agents/v1/capabilities',
                '/openapi.json' => '/agents/v1/openapi.json',
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => $this->buildOpenApiSecuritySchemes($config),
                'x-error-codes' => $this->errorTaxonomy(),
            ],
            'security' => $this->buildOpenApiSecurity($config),
        ]);
    }

    public function actionAuthWhoami(): Response
    {
        if (($guard = $this->guardRequest('auth:read')) !== null) {
            return $guard;
        }

        $config = $this->getSecurityConfig();
        $grantedScopes = $this->getGrantedScopes();
        $rateLimitLimit = (int)$this->response->headers->get('X-RateLimit-Limit', 0);
        $rateLimitRemaining = (int)$this->response->headers->get('X-RateLimit-Remaining', 0);
        $rateLimitReset = (int)$this->response->headers->get('X-RateLimit-Reset', 0);

        return $this->jsonResponse([
            'service' => 'agents',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'requestId' => $this->getRequestId(),
            'principal' => [
                'credentialId' => (string)($this->authContext['credentialId'] ?? 'anonymous'),
                'credentialSource' => (string)($this->authContext['credentialSource'] ?? 'unknown'),
                'managedCredentialId' => isset($this->authContext['managedCredentialId']) ? (int)$this->authContext['managedCredentialId'] : null,
                'authMethod' => (string)($this->authContext['authMethod'] ?? 'unknown'),
                'principalFingerprint' => (string)($this->authContext['principalFingerprint'] ?? ''),
            ],
            'authorization' => [
                'grantedScopes' => $grantedScopes,
                'availableScopes' => $this->availableScopes(),
            ],
            'runtimeSecurity' => [
                'requireToken' => (bool)$config['requireToken'],
                'allowQueryToken' => (bool)$config['allowQueryToken'],
                'redactEmail' => (bool)$config['redactEmail'],
            ],
            'rateLimit' => [
                'limit' => $rateLimitLimit,
                'remaining' => $rateLimitRemaining,
                'resetAtEpoch' => $rateLimitReset,
                'windowSeconds' => (int)($config['rateLimitWindowSeconds'] ?? 60),
                'resetAt' => $rateLimitReset > 0 ? gmdate('Y-m-d\TH:i:s\Z', $rateLimitReset) : null,
            ],
        ]);
    }

    public function actionControlPolicies(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('control:policies:read')) !== null) {
            return $guard;
        }

        $service = Plugin::getInstance()->getControlPlaneService();
        $policies = $service->getPolicies();

        return $this->jsonResponse([
            'data' => $policies,
            'meta' => [
                'count' => count($policies),
            ],
        ]);
    }

    public function actionControlPolicyUpsert(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('control:policies:write', ['POST'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();

        try {
            $policy = $service->upsertPolicy([
                'handle' => (string)$request->getBodyParam('handle', ''),
                'displayName' => (string)$request->getBodyParam('displayName', ''),
                'actionPattern' => (string)$request->getBodyParam('actionPattern', ''),
                'requiresApproval' => $request->getBodyParam('requiresApproval', true),
                'enabled' => $request->getBodyParam('enabled', true),
                'riskLevel' => (string)$request->getBodyParam('riskLevel', 'medium'),
                'config' => $this->resolveBodyArrayParam('config'),
            ], $this->buildControlActorContext());
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->misconfiguredResponse($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Control policy upsert failed: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to upsert policy.');
        }

        return $this->jsonResponse(['data' => $policy]);
    }

    public function actionControlApprovals(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('control:approvals:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();
        $approvals = $service->getApprovals([
            'status' => (string)$request->getQueryParam('status', ''),
            'actionType' => (string)$request->getQueryParam('actionType', ''),
        ], (int)$request->getQueryParam('limit', 50));

        return $this->jsonResponse([
            'data' => $approvals,
            'meta' => [
                'count' => count($approvals),
            ],
        ]);
    }

    public function actionControlApprovalRequest(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequestAnyScopes(['control:approvals:request', 'control:approvals:write'], ['POST'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();
        $metadata = $this->resolveBodyArrayParam('metadata');

        $source = trim((string)($metadata['source'] ?? ''));
        $agentId = trim((string)($metadata['agentId'] ?? ''));
        $traceId = trim((string)($metadata['traceId'] ?? ''));
        if ($source === '' || $agentId === '' || $traceId === '') {
            return $this->errorResponse(
                400,
                self::ERROR_INVALID_REQUEST,
                'metadata.source, metadata.agentId, and metadata.traceId are required for approval requests.'
            );
        }

        try {
            $approval = $service->requestApproval([
                'actionType' => (string)$request->getBodyParam('actionType', ''),
                'actionRef' => (string)$request->getBodyParam('actionRef', ''),
                'reason' => (string)$request->getBodyParam('reason', ''),
                'idempotencyKey' => $this->resolveIdempotencyKey(),
                'payload' => $this->resolveBodyArrayParam('payload'),
                'metadata' => $metadata,
            ], $this->buildControlActorContext());
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->misconfiguredResponse($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Control approval request failed: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to request approval.');
        }

        return $this->jsonResponse(['data' => $approval]);
    }

    public function actionControlApprovalDecide(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequestAnyScopes(['control:approvals:decide', 'control:approvals:write'], ['POST'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();
        $approvalId = (int)$request->getBodyParam('approvalId', 0);
        if ($approvalId <= 0) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, 'approvalId is required.');
        }

        try {
            $approval = $service->decideApproval(
                $approvalId,
                (string)$request->getBodyParam('decision', ''),
                (string)$request->getBodyParam('decisionReason', ''),
                $this->buildControlActorContext()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Control approval decision failed: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to decide approval.');
        }

        if ($approval === null) {
            return $this->errorResponse(404, self::ERROR_NOT_FOUND, 'Approval not found.');
        }

        return $this->jsonResponse(['data' => $approval]);
    }

    public function actionControlExecutions(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('control:executions:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();
        $executions = $service->getExecutions([
            'status' => (string)$request->getQueryParam('status', ''),
            'actionType' => (string)$request->getQueryParam('actionType', ''),
        ], (int)$request->getQueryParam('limit', 50));

        return $this->jsonResponse([
            'data' => $executions,
            'meta' => [
                'count' => count($executions),
            ],
        ]);
    }

    public function actionControlActionsExecute(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('control:actions:execute', ['POST'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();
        $actionType = (string)$request->getBodyParam('actionType', '');
        if (trim($actionType) === '') {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, 'actionType is required.');
        }

        $policy = $service->resolvePolicyForAction($actionType);
        $requiredScope = (string)($policy['requiredScope'] ?? 'control:actions:execute');
        if ($requiredScope !== '' && !$this->hasScope($requiredScope)) {
            return $this->forbiddenResponse($requiredScope, 'The matched action policy requires an additional scope.');
        }

        try {
            $execution = $service->executeAction([
                'actionType' => $actionType,
                'actionRef' => (string)$request->getBodyParam('actionRef', ''),
                'approvalId' => (int)$request->getBodyParam('approvalId', 0),
                'idempotencyKey' => $this->resolveIdempotencyKey(),
                'payload' => $this->resolveBodyArrayParam('payload'),
            ], $this->buildControlActorContext());
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->misconfiguredResponse($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Control execution failed: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to execute action.');
        }

        return $this->jsonResponse([
            'data' => $execution,
            'meta' => [
                'policy' => $policy,
            ],
        ]);
    }

    public function actionControlAudit(): Response
    {
        if (($guard = $this->guardRefundApprovalsExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('control:audit:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();
        $events = $service->getAuditEvents([
            'category' => (string)$request->getQueryParam('category', ''),
            'actorId' => (string)$request->getQueryParam('actorId', ''),
        ], (int)$request->getQueryParam('limit', 100));

        return $this->jsonResponse([
            'data' => $events,
            'meta' => [
                'count' => count($events),
            ],
        ]);
    }

    private function guardRequest(string $requiredScope = '', array $allowedMethods = ['GET', 'HEAD']): ?Response
    {
        if (($methodResponse = $this->enforceRequestMethod($allowedMethods)) !== null) {
            return $methodResponse;
        }

        if (!Plugin::getInstance()->isAgentsEnabled()) {
            return $this->serviceDisabledResponse();
        }

        $config = $this->getSecurityConfig();

        $preAuthIdentity = $this->buildPreAuthIdentity();
        $preAuthRateLimitResponse = $this->applyRateLimit('preauth', $preAuthIdentity);
        if ($preAuthRateLimitResponse !== null) {
            return $preAuthRateLimitResponse;
        }

        $authResult = $this->authenticateRequest($config);
        if (($authResult['errorResponse'] ?? null) instanceof Response) {
            return $authResult['errorResponse'];
        }

        $this->authContext = $authResult;

        $postAuthIdentity = $this->buildPostAuthIdentity($authResult);
        $postAuthRateLimitResponse = $this->applyRateLimit('postauth', $postAuthIdentity);
        if ($postAuthRateLimitResponse !== null) {
            return $postAuthRateLimitResponse;
        }

        if ($requiredScope !== '' && !$this->hasScope($requiredScope)) {
            return $this->forbiddenResponse($requiredScope);
        }

        return null;
    }

    private function guardRequestAnyScopes(array $requiredScopes, array $allowedMethods = ['GET', 'HEAD']): ?Response
    {
        if (($guard = $this->guardRequest('', $allowedMethods)) !== null) {
            return $guard;
        }

        $normalizedScopes = [];
        foreach ($requiredScopes as $requiredScope) {
            if (!is_string($requiredScope) || trim($requiredScope) === '') {
                continue;
            }

            $normalizedScopes[] = trim($requiredScope);
        }

        if (empty($normalizedScopes)) {
            return null;
        }

        foreach ($normalizedScopes as $scope) {
            if ($this->hasScope($scope)) {
                return null;
            }
        }

        return $this->forbiddenResponse(
            implode(' | ', $normalizedScopes),
            sprintf('Missing required scope. Provide one of: %s.', implode(', ', $normalizedScopes))
        );
    }

    private function authenticateRequest(array $config): array
    {
        if (!$config['requireToken']) {
            return [
                'principalFingerprint' => 'token-disabled',
                'scopes' => ['*'],
                'authMethod' => 'none',
            ];
        }

        $credentials = $config['credentials'];
        if (empty($credentials)) {
            $message = 'API credentials are required but not configured.';
            if ($config['isProduction'] && $config['failOnMissingTokenInProd']) {
                $message .= ' Refusing to serve guarded endpoints in production.';
            }

            return [
                'errorResponse' => $this->misconfiguredResponse($message),
            ];
        }

        $providedToken = $this->resolveProvidedToken($config['allowQueryToken']);
        if ($providedToken['value'] === '') {
            return [
                'errorResponse' => $this->unauthorizedResponse('Missing or invalid token.'),
            ];
        }

        $matchedCredential = $this->matchCredential($providedToken['value'], $credentials);
        if ($matchedCredential === null) {
            return [
                'errorResponse' => $this->unauthorizedResponse('Missing or invalid token.'),
            ];
        }

        $managedCredentialId = (int)($matchedCredential['managedCredentialId'] ?? 0);
        if ($managedCredentialId > 0) {
            try {
                Plugin::getInstance()->getCredentialService()->recordCredentialUse(
                    $managedCredentialId,
                    (string)($providedToken['source'] ?? 'unknown'),
                    $this->getClientIp()
                );
            } catch (\Throwable $e) {
                Craft::warning('Unable to update managed credential last-used metadata: ' . $e->getMessage(), __METHOD__);
            }
        }

        return [
            'principalFingerprint' => $matchedCredential['principalFingerprint'],
            'credentialId' => $matchedCredential['id'],
            'credentialSource' => (string)($matchedCredential['source'] ?? 'env'),
            'managedCredentialId' => isset($matchedCredential['managedCredentialId']) ? (int)$matchedCredential['managedCredentialId'] : null,
            'scopes' => $matchedCredential['scopes'],
            'authMethod' => $providedToken['source'],
        ];
    }

    private function matchCredential(string $providedToken, array $credentials): ?array
    {
        $providedTokenHash = hash('sha256', $providedToken);

        foreach ($credentials as $credential) {
            $token = (string)($credential['token'] ?? '');
            $tokenHash = strtolower(trim((string)($credential['tokenHash'] ?? '')));
            $matched = false;
            $principalFingerprint = '';

            if ($token !== '') {
                if (!hash_equals($token, $providedToken)) {
                    continue;
                }
                $matched = true;
                $principalFingerprint = sha1($token);
            } elseif (preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
                if (!hash_equals($tokenHash, $providedTokenHash)) {
                    continue;
                }
                $matched = true;
                $principalFingerprint = sha1($tokenHash);
            } else {
                continue;
            }

            if (!$matched) {
                continue;
            }

            return [
                'id' => (string)($credential['id'] ?? 'default'),
                'scopes' => (array)($credential['scopes'] ?? self::DEFAULT_TOKEN_SCOPES),
                'principalFingerprint' => $principalFingerprint,
                'source' => (string)($credential['source'] ?? 'env'),
                'managedCredentialId' => isset($credential['managedCredentialId']) ? (int)$credential['managedCredentialId'] : 0,
            ];
        }

        return null;
    }

    private function resolveProvidedToken(bool $allowQueryToken): array
    {
        $request = Craft::$app->getRequest();

        $bearerToken = $this->getBearerToken($request->getHeaders()->get('Authorization'));
        if ($bearerToken !== '') {
            return ['value' => $bearerToken, 'source' => 'authorization'];
        }

        $headerToken = trim((string)$request->getHeaders()->get('X-Agents-Token', ''));
        if ($headerToken !== '') {
            return ['value' => $headerToken, 'source' => 'x-agents-token'];
        }

        if ($allowQueryToken) {
            $queryToken = trim((string)$request->getQueryParam('apiToken', ''));
            if ($queryToken !== '') {
                return ['value' => $queryToken, 'source' => 'query'];
            }
        }

        return ['value' => '', 'source' => 'none'];
    }

    private function getBearerToken(?string $authorizationHeader): string
    {
        if ($authorizationHeader === null) {
            return '';
        }

        if (!preg_match('/^Bearer\s+([A-Za-z0-9._~+\-=]+)$/', trim($authorizationHeader), $matches)) {
            return '';
        }

        return $matches[1] ?? '';
    }

    private function applyRateLimit(string $stage, string $identity): ?Response
    {
        $config = $this->getSecurityConfig();
        $limit = $config['rateLimitPerMinute'];
        $windowSeconds = $config['rateLimitWindowSeconds'];

        $cache = Craft::$app->getCache();
        if (!$cache instanceof CacheInterface) {
            return null;
        }

        $bucketStart = (int)(intdiv(time(), $windowSeconds) * $windowSeconds);
        $routeFingerprint = sha1($this->getRouteKey());
        $identityFingerprint = sha1($identity);
        $cacheKey = sprintf('agents:rl:%s:%d:%s:%s', $stage, $bucketStart, $routeFingerprint, $identityFingerprint);

        $current = $this->incrementRateCounter($cache, $cacheKey, $windowSeconds + 1, $limit);
        $resetAt = $bucketStart + $windowSeconds;

        $remaining = max(0, $limit - $current);
        $this->response->headers->set('X-RateLimit-Limit', (string)$limit);
        $this->response->headers->set('X-RateLimit-Remaining', (string)$remaining);
        $this->response->headers->set('X-RateLimit-Reset', (string)$resetAt);

        if ($current > $limit) {
            $response = $this->errorResponse(429, self::ERROR_RATE_LIMIT_EXCEEDED, 'Too many requests.', [
                'retryAfter' => $resetAt,
            ]);
            $response->headers->set('X-RateLimit-Limit', (string)$limit);
            $response->headers->set('X-RateLimit-Remaining', '0');
            $response->headers->set('X-RateLimit-Reset', (string)$resetAt);
            return $response;
        }

        return null;
    }

    private function incrementRateCounter(CacheInterface $cache, string $cacheKey, int $ttl, int $limit): int
    {
        if (method_exists($cache, 'increment') && method_exists($cache, 'add')) {
            $incremented = $cache->increment($cacheKey, 1);
            if ($incremented !== false) {
                return (int)$incremented;
            }

            if ($cache->add($cacheKey, 1, $ttl)) {
                return 1;
            }

            $incremented = $cache->increment($cacheKey, 1);
            if ($incremented !== false) {
                return (int)$incremented;
            }
        }

        $lockKey = 'agents:rl:lock:' . sha1($cacheKey);
        $mutex = Craft::$app->getMutex();
        if ($mutex instanceof Mutex && $mutex->acquire($lockKey, 2)) {
            try {
                $current = (int)($cache->get($cacheKey) ?: 0);
                $current++;
                $cache->set($cacheKey, $current, $ttl);
                return $current;
            } finally {
                $mutex->release($lockKey);
            }
        }

        Craft::warning('Agents rate-limit lock unavailable; failing closed for this request.', __METHOD__);
        return $limit + 1;
    }

    private function enforceReadRequest(): ?Response
    {
        return $this->enforceRequestMethod(['GET', 'HEAD']);
    }

    private function enforceRequestMethod(array $allowedMethods): ?Response
    {
        $request = Craft::$app->getRequest();
        $method = strtoupper((string)$request->getMethod());
        $normalizedAllowed = [];
        foreach ($allowedMethods as $allowedMethod) {
            if (!is_string($allowedMethod) || trim($allowedMethod) === '') {
                continue;
            }
            $normalizedAllowed[] = strtoupper(trim($allowedMethod));
        }

        if (in_array($method, $normalizedAllowed, true)) {
            return null;
        }

        $allowedHeader = implode(', ', $normalizedAllowed);
        $response = $this->errorResponse(405, self::ERROR_METHOD_NOT_ALLOWED, sprintf('Only %s requests are supported.', $allowedHeader));
        $response->headers->set('Allow', $allowedHeader);
        return $response;
    }

    private function buildPreAuthIdentity(): string
    {
        return sprintf('%s|%s', $this->getClientIp(), $this->getRouteKey());
    }

    private function buildPostAuthIdentity(array $authContext): string
    {
        $principal = (string)($authContext['principalFingerprint'] ?? 'unknown');
        return sprintf('%s|%s|%s', $this->getClientIp(), $this->getRouteKey(), $principal);
    }

    private function getClientIp(): string
    {
        return (string)(Craft::$app->getRequest()->getUserIP() ?: 'unknown');
    }

    private function getRouteKey(): string
    {
        $pathInfo = trim((string)Craft::$app->getRequest()->getPathInfo(), '/');
        return $pathInfo !== '' ? $pathInfo : 'root';
    }

    private function shouldRedactEmail(): bool
    {
        return (bool)$this->getSecurityConfig()['redactEmail'];
    }

    private function hasScope(string $scope): bool
    {
        if ($scope === '') {
            return true;
        }

        $scopes = $this->authContext['scopes'] ?? [];
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    private function getGrantedScopes(): array
    {
        $scopes = $this->authContext['scopes'] ?? $this->getSecurityConfig()['tokenScopes'];
        $normalized = array_values(array_unique(array_map('strval', $scopes)));
        if (!$this->isRefundApprovalsExperimentalEnabled()) {
            $normalized = array_values(array_filter(
                $normalized,
                static fn(string $scope): bool => !str_starts_with($scope, 'control:')
            ));
        }
        sort($normalized);
        return $normalized;
    }

    private function isNonLiveStatusRequest(string $status): bool
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '' || $normalized === 'live') {
            return false;
        }

        return in_array($normalized, ['all', 'any', 'pending', 'disabled', 'expired'], true);
    }

    private function availableScopes(): array
    {
        $scopes = [
            'health:read' => 'Read service health summary.',
            'readiness:read' => 'Read readiness summary and score.',
            'auth:read' => 'Read authenticated caller diagnostics (`/auth/whoami`).',
            'products:read' => 'Read product snapshot endpoints.',
            'orders:read' => 'Read order metadata endpoints.',
            'orders:read_sensitive' => 'Unredacted order PII/financial detail fields.',
            'entries:read' => 'Read live content entry endpoints.',
            'entries:read_all_statuses' => 'Read non-live entries/statuses and unrestricted detail lookup.',
            'changes:read' => 'Read unified cross-resource incremental changes feed.',
            'sections:read' => 'Read section list endpoint.',
            'capabilities:read' => 'Read capabilities descriptor endpoint.',
            'openapi:read' => 'Read OpenAPI descriptor endpoint.',
            'control:policies:read' => 'Read control action policies.',
            'control:policies:write' => 'Create and update control action policies.',
            'control:approvals:read' => 'Read approval request queue.',
            'control:approvals:request' => 'Create control approval requests (agent/orchestrator flow).',
            'control:approvals:decide' => 'Approve or reject pending control approvals (human sign-off flow).',
            'control:approvals:write' => 'Legacy combined scope for request+decide control approvals.',
            'control:executions:read' => 'Read control action execution ledger.',
            'control:actions:execute' => 'Execute idempotent control actions.',
            'control:audit:read' => 'Read immutable control-plane audit log.',
        ];
        if (!$this->isRefundApprovalsExperimentalEnabled()) {
            foreach ($this->controlScopeKeys() as $scope) {
                unset($scopes[$scope]);
            }
        }

        return $scopes;
    }

    private function isRefundApprovalsExperimentalEnabled(): bool
    {
        return Plugin::getInstance()->isRefundApprovalsExperimentalEnabled();
    }

    private function guardRefundApprovalsExperimentalEnabled(): ?Response
    {
        if ($this->isRefundApprovalsExperimentalEnabled()) {
            return null;
        }

        return $this->errorResponse(404, self::ERROR_NOT_FOUND, 'Endpoint is not available.');
    }

    private function controlOpenApiPaths(): array
    {
        return [
            '/control/policies',
            '/control/policies/upsert',
            '/control/approvals',
            '/control/approvals/request',
            '/control/approvals/decide',
            '/control/executions',
            '/control/actions/execute',
            '/control/audit',
        ];
    }

    private function controlScopeKeys(): array
    {
        return [
            'control:policies:read',
            'control:policies:write',
            'control:approvals:read',
            'control:approvals:request',
            'control:approvals:decide',
            'control:approvals:write',
            'control:executions:read',
            'control:actions:execute',
            'control:audit:read',
        ];
    }

    private function errorTaxonomy(): array
    {
        return [
            ['status' => 400, 'code' => self::ERROR_INVALID_REQUEST, 'description' => 'Request payload/query validation failed.'],
            ['status' => 401, 'code' => self::ERROR_UNAUTHORIZED, 'description' => 'Missing or invalid API token.'],
            ['status' => 403, 'code' => self::ERROR_FORBIDDEN, 'description' => 'Token is valid but does not include required scope.'],
            ['status' => 404, 'code' => self::ERROR_NOT_FOUND, 'description' => 'Requested resource could not be found.'],
            ['status' => 405, 'code' => self::ERROR_METHOD_NOT_ALLOWED, 'description' => 'Only GET/HEAD requests are supported for these endpoints.'],
            ['status' => 429, 'code' => self::ERROR_RATE_LIMIT_EXCEEDED, 'description' => 'Rate limit bucket exhausted for the current window.'],
            ['status' => 503, 'code' => self::ERROR_SERVICE_DISABLED, 'description' => 'Agents service is disabled by configuration.'],
            ['status' => 503, 'code' => self::ERROR_SERVER_MISCONFIGURED, 'description' => 'Server security configuration is incomplete or invalid.'],
            ['status' => 500, 'code' => self::ERROR_INTERNAL, 'description' => 'Unexpected server error while processing request.'],
        ];
    }

    private function getSecurityConfig(): array
    {
        if ($this->securityConfig !== null) {
            return $this->securityConfig;
        }

        $this->securityConfig = Plugin::getInstance()->getSecurityPolicyService()->getRuntimeConfig();

        return $this->securityConfig;
    }

    private function buildCapabilitiesAuthentication(array $config): array
    {
        $auth = [
            'required' => (bool)$config['requireToken'],
            'allowQueryToken' => (bool)$config['allowQueryToken'],
            'allowInsecureNoTokenInProd' => (bool)$config['allowInsecureNoTokenInProd'],
            'tokenRequirementForcedInProd' => (bool)$config['tokenRequirementForcedInProd'],
            'failOnMissingTokenInProd' => (bool)$config['failOnMissingTokenInProd'],
            'tokenConfigured' => !empty($config['credentials']),
            'credentialCount' => count($config['credentials']),
            'methods' => [],
        ];

        if (!$config['requireToken']) {
            return $auth;
        }

        $auth['methods'][] = 'Authorization: Bearer <token>';
        $auth['methods'][] = 'X-Agents-Token: <token>';
        if ($config['allowQueryToken']) {
            $auth['queryParam'] = 'apiToken';
        }

        return $auth;
    }

    private function buildOpenApiSecuritySchemes(array $config): array
    {
        if (!$config['requireToken']) {
            return [];
        }

        $schemes = [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            'agentsToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Agents-Token'],
        ];

        if ($config['allowQueryToken']) {
            $schemes['queryToken'] = ['type' => 'apiKey', 'in' => 'query', 'name' => 'apiToken'];
        }

        return $schemes;
    }

    private function buildOpenApiSecurity(array $config): array
    {
        if (!$config['requireToken']) {
            return [];
        }

        $security = [
            ['bearerAuth' => []],
            ['agentsToken' => []],
        ];

        if ($config['allowQueryToken']) {
            $security[] = ['queryToken' => []];
        }

        return $security;
    }

    private function openApiGuardedResponses(array $responses): array
    {
        $guardResponses = [
            '401' => ['description' => 'Missing or invalid token.'],
            '403' => ['description' => 'Token is valid but missing required scope.'],
            '429' => ['description' => 'Rate limit exceeded.'],
            '503' => ['description' => 'Service disabled or server misconfigured.'],
        ];

        return array_merge($responses, $guardResponses);
    }

    private function resolveBodyArrayParam(string $param): array
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getBodyParam($param, []);
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function resolveIdempotencyKey(): string
    {
        $request = Craft::$app->getRequest();
        $headerKey = trim((string)$request->getHeaders()->get('X-Idempotency-Key', ''));
        if ($headerKey !== '') {
            return $headerKey;
        }

        return trim((string)$request->getBodyParam('idempotencyKey', ''));
    }

    private function buildControlActorContext(): array
    {
        return [
            'actorType' => 'credential',
            'actorId' => (string)($this->authContext['credentialId'] ?? 'unknown'),
            'requestId' => $this->getRequestId(),
            'ipAddress' => $this->getClientIp(),
        ];
    }

    private function unauthorizedResponse(string $message): Response
    {
        return $this->errorResponse(401, self::ERROR_UNAUTHORIZED, $message);
    }

    private function misconfiguredResponse(string $message): Response
    {
        return $this->errorResponse(503, self::ERROR_SERVER_MISCONFIGURED, $message);
    }

    private function serviceDisabledResponse(): Response
    {
        return $this->errorResponse(503, self::ERROR_SERVICE_DISABLED, 'Agents API is currently disabled by configuration.');
    }

    private function forbiddenResponse(string $requiredScope, string $message = 'Missing required scope.'): Response
    {
        return $this->errorResponse(403, self::ERROR_FORBIDDEN, $message, [
            'requiredScope' => $requiredScope,
        ]);
    }

    private function respondWithPayload(array $payload, bool $expectSingle = false, string $notFoundMessage = 'Resource not found.'): Response
    {
        $errors = $payload['meta']['errors'] ?? [];
        if (!empty($errors)) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, (string)$errors[0], [
                'details' => array_values($errors),
            ]);
        }

        if ($expectSingle && (($payload['data'] ?? null) === null)) {
            return $this->errorResponse(404, self::ERROR_NOT_FOUND, $notFoundMessage);
        }

        return $this->jsonResponse($payload);
    }

    private function respondWithDiscoveryDocument(array $document): Response
    {
        $etagHash = (string)($document['etag'] ?? '');
        if ($etagHash === '') {
            $etagHash = sha1((string)($document['body'] ?? ''));
        }
        $etag = '"' . $etagHash . '"';
        $lastModified = (int)($document['lastModified'] ?? time());
        $maxAge = max(0, (int)($document['maxAge'] ?? 0));

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('ETag', $etag);
        $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        $response->headers->set('Cache-Control', 'public, max-age=' . $maxAge);

        if ($this->isNotModified($etag, $lastModified)) {
            $response->setStatusCode(304);
            $response->content = '';
            return $this->attachRequestId($response);
        }

        $response->setStatusCode(200);
        $response->content = (string)($document['body'] ?? '');
        return $this->attachRequestId($response);
    }

    private function isNotModified(string $etag, int $lastModified): bool
    {
        $request = Craft::$app->getRequest();
        $ifNoneMatch = trim((string)$request->getHeaders()->get('If-None-Match', ''));
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                    return true;
                }
            }
        }

        $ifModifiedSince = trim((string)$request->getHeaders()->get('If-Modified-Since', ''));
        if ($ifModifiedSince !== '') {
            $ifModifiedSinceTs = strtotime($ifModifiedSince);
            if ($ifModifiedSinceTs !== false && $ifModifiedSinceTs >= $lastModified) {
                return true;
            }
        }

        return false;
    }
    private function jsonResponse(array $payload): Response
    {
        $response = $this->asJson($payload);
        $this->applyNoStoreHeaders($response);
        return $this->attachRequestId($response);
    }

    private function errorResponse(int $statusCode, string $code, string $message, array $extra = []): Response
    {
        $payload = array_merge([
            'error' => $code,
            'message' => $message,
            'status' => $statusCode,
            'requestId' => $this->getRequestId(),
        ], $extra);
        $response = $this->asJson($payload);
        $this->applyNoStoreHeaders($response);
        $response->setStatusCode($statusCode);
        return $this->attachRequestId($response);
    }
    private function applyNoStoreHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }

    private function attachRequestId(Response $response): Response
    {
        $response->headers->set('X-Request-Id', $this->getRequestId());
        return $response;
    }
    private function resolvePluginVersion(): string
    {
        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $version = trim((string)$plugin->getVersion());
            if ($version !== '') {
                return $version;
            }
        }

        return '0.3.3';
    }

    private function getRequestId(): string
    {
        if ($this->requestId !== null && $this->requestId !== '') {
            return $this->requestId;
        }

        $this->requestId = $this->resolveRequestId();
        return $this->requestId;
    }

    private function resolveRequestId(): string
    {
        $incoming = trim((string)Craft::$app->getRequest()->getHeaders()->get('X-Request-Id', ''));
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming)) {
            return $incoming;
        }

        try {
            return 'agents-' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return 'agents-' . str_replace('.', '', (string)microtime(true)) . '-' . substr(sha1(uniqid('', true)), 0, 8);
        }
    }
}
