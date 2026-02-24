<?php

namespace Klick\Agents\controllers;

use Craft;
use Klick\Agents\Plugin;
use craft\helpers\App;
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
        'products:read',
        'orders:read',
        'entries:read',
        'sections:read',
        'capabilities:read',
        'openapi:read',
    ];
    private const ERROR_INVALID_REQUEST = 'INVALID_REQUEST';
    private const ERROR_UNAUTHORIZED = 'UNAUTHORIZED';
    private const ERROR_FORBIDDEN = 'FORBIDDEN';
    private const ERROR_NOT_FOUND = 'NOT_FOUND';
    private const ERROR_METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    private const ERROR_RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    private const ERROR_SERVER_MISCONFIGURED = 'SERVER_MISCONFIGURED';

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

    protected array|int|bool $allowAnonymous = true;
    protected array|bool|int $supportsCsrfValidation = true;

    public function actionLlmsTxt(): Response
    {
        if (($methodResponse = $this->enforceReadRequest()) !== null) {
            return $methodResponse;
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
        ]);

        return $this->jsonResponse($payload);
    }

    public function actionOrders(): Response
    {
        if (($guard = $this->guardRequest('orders:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $includeSensitive = $this->hasScope('orders:read_sensitive');
        $payload = Plugin::getInstance()->getReadinessService()->getOrdersList([
            'status' => $request->getQueryParam('status', 'all'),
            'lastDays' => (int)$request->getQueryParam('lastDays', 30),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'includeSensitive' => $includeSensitive,
            'redactEmail' => !$includeSensitive && $this->shouldRedactEmail(),
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
        ]);

        return $this->respondWithPayload($payload);
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

        return $this->jsonResponse([
            'service' => 'agents',
            'version' => '0.1.2',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'basePath' => '/agents/v1',
            'requestIdHeader' => 'X-Request-Id',
            'authentication' => $this->buildCapabilitiesAuthentication($config),
            'authorization' => [
                'grantedScopes' => $this->getGrantedScopes(),
                'availableScopes' => $this->availableScopes(),
            ],
            'errorCodes' => $this->errorTaxonomy(),
            'endpoints' => [
                ['method' => 'GET', 'path' => '/health', 'requiredScopes' => ['health:read']],
                ['method' => 'GET', 'path' => '/readiness', 'requiredScopes' => ['readiness:read']],
                ['method' => 'GET', 'path' => '/products', 'requiredScopes' => ['products:read']],
                ['method' => 'GET', 'path' => '/orders', 'requiredScopes' => ['orders:read'], 'optionalScopes' => ['orders:read_sensitive']],
                ['method' => 'GET', 'path' => '/orders/show', 'requiredScopes' => ['orders:read'], 'optionalScopes' => ['orders:read_sensitive']],
                ['method' => 'GET', 'path' => '/entries', 'requiredScopes' => ['entries:read'], 'optionalScopes' => ['entries:read_all_statuses']],
                ['method' => 'GET', 'path' => '/entries/show', 'requiredScopes' => ['entries:read'], 'optionalScopes' => ['entries:read_all_statuses']],
                ['method' => 'GET', 'path' => '/sections', 'requiredScopes' => ['sections:read']],
                ['method' => 'GET', 'path' => '/capabilities', 'requiredScopes' => ['capabilities:read']],
                ['method' => 'GET', 'path' => '/openapi.json', 'requiredScopes' => ['openapi:read']],
                ['method' => 'GET', 'path' => '/llms.txt', 'public' => true],
                ['method' => 'GET', 'path' => '/commerce.txt', 'public' => true],
            ],
            'commands' => [
                'agents/product-list',
                'agents/order-list',
                'agents/order-show',
                'agents/entry-list',
                'agents/entry-show',
                'agents/section-list',
                'agents/discovery-prewarm',
            ],
        ]);
    }

    public function actionOpenapi(): Response
    {
        if (($guard = $this->guardRequest('openapi:read')) !== null) {
            return $guard;
        }

        $config = $this->getSecurityConfig();

        return $this->jsonResponse([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Agents API',
                'version' => '0.1.2',
                'description' => 'Read-only agent discovery API for products, orders, entries, and sections.',
            ],
            'servers' => [
                ['url' => '/agents/v1', 'description' => 'Primary API'],
                ['url' => '/', 'description' => 'Site root discovery files'],
            ],
            'paths' => [
                '/health' => ['get' => ['summary' => 'Health summary', 'responses' => ['200' => ['description' => 'OK']], 'x-required-scopes' => ['health:read']]],
                '/readiness' => ['get' => ['summary' => 'Readiness summary', 'responses' => ['200' => ['description' => 'OK']], 'x-required-scopes' => ['readiness:read']]],
                '/products' => ['get' => [
                    'summary' => 'Product snapshot list',
                    'parameters' => [
                        ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                        ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['live', 'pending', 'disabled', 'expired', 'all']]],
                        ['in' => 'query', 'name' => 'sort', 'schema' => ['type' => 'string', 'enum' => ['updatedAt', 'createdAt', 'title']]],
                        ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                        ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'OK']],
                    'x-required-scopes' => ['products:read'],
                ]],
                '/orders' => ['get' => [
                    'summary' => 'Order list',
                    'parameters' => [
                        ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string']],
                        ['in' => 'query', 'name' => 'lastDays', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                        ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ],
                    'responses' => ['200' => ['description' => 'OK']],
                    'x-required-scopes' => ['orders:read'],
                    'x-optional-scopes' => ['orders:read_sensitive'],
                ]],
                '/orders/show' => ['get' => [
                    'summary' => 'Single order by id or number',
                    'parameters' => [
                        ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                        ['in' => 'query', 'name' => 'number', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'OK'], '400' => ['description' => 'Invalid request'], '404' => ['description' => 'Not found']],
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
                    ],
                    'responses' => ['200' => ['description' => 'OK'], '403' => ['description' => 'Missing scope for non-live status access']],
                    'x-required-scopes' => ['entries:read'],
                    'x-optional-scopes' => ['entries:read_all_statuses'],
                ]],
                '/entries/show' => ['get' => [
                    'summary' => 'Single entry by id or slug',
                    'parameters' => [
                        ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                        ['in' => 'query', 'name' => 'slug', 'schema' => ['type' => 'string']],
                        ['in' => 'query', 'name' => 'section', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'OK'], '400' => ['description' => 'Invalid request'], '404' => ['description' => 'Not found']],
                    'x-required-scopes' => ['entries:read'],
                    'x-optional-scopes' => ['entries:read_all_statuses'],
                ]],
                '/sections' => ['get' => ['summary' => 'Section list', 'responses' => ['200' => ['description' => 'OK']], 'x-required-scopes' => ['sections:read']]],
                '/capabilities' => ['get' => ['summary' => 'Feature and command discovery', 'responses' => ['200' => ['description' => 'OK']], 'x-required-scopes' => ['capabilities:read']]],
                '/openapi.json' => ['get' => ['summary' => 'OpenAPI descriptor', 'responses' => ['200' => ['description' => 'OK']], 'x-required-scopes' => ['openapi:read']]],
                '/llms.txt' => ['get' => ['summary' => 'Public llms.txt discovery surface', 'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Disabled']]]],
                '/commerce.txt' => ['get' => ['summary' => 'Public commerce.txt discovery surface', 'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Disabled']]]],
            ],
            'components' => [
                'securitySchemes' => $this->buildOpenApiSecuritySchemes($config),
                'x-error-codes' => $this->errorTaxonomy(),
            ],
            'security' => $this->buildOpenApiSecurity($config),
        ]);
    }

    private function guardRequest(string $requiredScope = ''): ?Response
    {
        if (($methodResponse = $this->enforceReadRequest()) !== null) {
            return $methodResponse;
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

        return [
            'principalFingerprint' => $matchedCredential['principalFingerprint'],
            'credentialId' => $matchedCredential['id'],
            'scopes' => $matchedCredential['scopes'],
            'authMethod' => $providedToken['source'],
        ];
    }

    private function matchCredential(string $providedToken, array $credentials): ?array
    {
        foreach ($credentials as $credential) {
            $token = (string)($credential['token'] ?? '');
            if ($token === '') {
                continue;
            }

            if (!hash_equals($token, $providedToken)) {
                continue;
            }

            return [
                'id' => (string)($credential['id'] ?? 'default'),
                'scopes' => (array)($credential['scopes'] ?? self::DEFAULT_TOKEN_SCOPES),
                'principalFingerprint' => sha1($token),
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
        $request = Craft::$app->getRequest();
        if ($request->getIsGet() || $request->getIsHead()) {
            return null;
        }

        $response = $this->errorResponse(405, self::ERROR_METHOD_NOT_ALLOWED, 'Only GET and HEAD requests are supported.');
        $response->headers->set('Allow', 'GET, HEAD');
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
        return [
            'health:read' => 'Read service health summary.',
            'readiness:read' => 'Read readiness summary and score.',
            'products:read' => 'Read product snapshot endpoints.',
            'orders:read' => 'Read order metadata endpoints.',
            'orders:read_sensitive' => 'Unredacted order PII/financial detail fields.',
            'entries:read' => 'Read live content entry endpoints.',
            'entries:read_all_statuses' => 'Read non-live entries/statuses and unrestricted detail lookup.',
            'sections:read' => 'Read section list endpoint.',
            'capabilities:read' => 'Read capabilities descriptor endpoint.',
            'openapi:read' => 'Read OpenAPI descriptor endpoint.',
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
            ['status' => 503, 'code' => self::ERROR_SERVER_MISCONFIGURED, 'description' => 'Server security configuration is incomplete or invalid.'],
        ];
    }

    private function getSecurityConfig(): array
    {
        if ($this->securityConfig !== null) {
            return $this->securityConfig;
        }

        $environment = strtolower((string)(App::env('ENVIRONMENT') ?: App::env('CRAFT_ENVIRONMENT') ?: 'production'));
        $isProduction = in_array($environment, ['prod', 'production'], true);

        $requireToken = App::parseBooleanEnv('$PLUGIN_AGENTS_REQUIRE_TOKEN');
        if ($requireToken === null) {
            $requireToken = true;
        }

        $allowInsecureNoTokenInProd = App::parseBooleanEnv('$PLUGIN_AGENTS_ALLOW_INSECURE_NO_TOKEN_IN_PROD');
        if ($allowInsecureNoTokenInProd === null) {
            $allowInsecureNoTokenInProd = false;
        }

        $allowQueryToken = App::parseBooleanEnv('$PLUGIN_AGENTS_ALLOW_QUERY_TOKEN');
        if ($allowQueryToken === null) {
            $allowQueryToken = false;
        }

        $failOnMissingTokenInProd = App::parseBooleanEnv('$PLUGIN_AGENTS_FAIL_ON_MISSING_TOKEN_IN_PROD');
        if ($failOnMissingTokenInProd === null) {
            $failOnMissingTokenInProd = true;
        }

        $redactEmail = App::parseBooleanEnv('$PLUGIN_AGENTS_REDACT_EMAIL');
        if ($redactEmail === null) {
            $redactEmail = true;
        }

        $tokenScopes = $this->parseScopes((string)App::env('PLUGIN_AGENTS_TOKEN_SCOPES'));
        if (empty($tokenScopes)) {
            $tokenScopes = self::DEFAULT_TOKEN_SCOPES;
        }

        $tokenRequirementForcedInProd = false;
        if (!$requireToken && $isProduction && !$allowInsecureNoTokenInProd) {
            $requireToken = true;
            $tokenRequirementForcedInProd = true;
        }

        $credentials = $this->parseCredentials((string)App::env('PLUGIN_AGENTS_API_CREDENTIALS'), $tokenScopes);

        $primaryApiToken = trim((string)App::env('PLUGIN_AGENTS_API_TOKEN'));
        if ($primaryApiToken !== '') {
            $credentials[] = [
                'id' => 'default',
                'token' => $primaryApiToken,
                'scopes' => $tokenScopes,
            ];
        }

        $credentials = $this->deduplicateCredentials($credentials, $tokenScopes);

        $rateLimitPerMinute = (int)App::env('PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE');
        if ($rateLimitPerMinute <= 0) {
            $rateLimitPerMinute = 60;
        }

        $rateLimitWindowSeconds = (int)App::env('PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS');
        if ($rateLimitWindowSeconds <= 0) {
            $rateLimitWindowSeconds = 60;
        }

        $this->securityConfig = [
            'environment' => $environment,
            'isProduction' => $isProduction,
            'requireToken' => $requireToken,
            'allowInsecureNoTokenInProd' => $allowInsecureNoTokenInProd,
            'tokenRequirementForcedInProd' => $tokenRequirementForcedInProd,
            'allowQueryToken' => $allowQueryToken,
            'failOnMissingTokenInProd' => $failOnMissingTokenInProd,
            'credentials' => $credentials,
            'primaryApiTokenConfigured' => $primaryApiToken !== '',
            'tokenScopes' => $tokenScopes,
            'rateLimitPerMinute' => $rateLimitPerMinute,
            'rateLimitWindowSeconds' => $rateLimitWindowSeconds,
            'redactEmail' => $redactEmail,
        ];

        return $this->securityConfig;
    }

    private function parseScopes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', strtolower($raw)) ?: [];
        $scopes = [];
        foreach ($parts as $part) {
            $scope = trim($part);
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

    private function parseCredentials(string $raw, array $defaultScopes): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Craft::warning('Unable to parse `PLUGIN_AGENTS_API_CREDENTIALS`; expected JSON array/object.', __METHOD__);
            return [];
        }

        $credentials = [];
        $index = 0;
        foreach ($decoded as $key => $value) {
            $index++;
            $id = is_string($key) ? trim($key) : '';
            $token = '';
            $rawScopes = null;

            if (is_array($value)) {
                $token = trim((string)($value['token'] ?? $value['value'] ?? ''));
                $rawScopes = $value['scopes'] ?? null;
                if ($id === '') {
                    $id = trim((string)($value['id'] ?? $value['name'] ?? ''));
                }
            } else {
                $token = trim((string)$value);
            }

            if ($token === '') {
                continue;
            }

            if ($id === '') {
                $id = 'credential-' . $index;
            }

            $id = strtolower((string)(preg_replace('/[^a-z0-9:_-]+/i', '-', $id) ?: 'credential-' . $index));
            $id = trim($id, '-');
            if ($id === '') {
                $id = 'credential-' . $index;
            }

            $scopes = $this->normalizeScopes($rawScopes, $defaultScopes);

            $credentials[] = [
                'id' => $id,
                'token' => $token,
                'scopes' => $scopes,
            ];
        }

        return $credentials;
    }

    private function normalizeScopes(mixed $rawScopes, array $defaultScopes): array
    {
        if (is_array($rawScopes)) {
            $parts = [];
            foreach ($rawScopes as $scope) {
                if (!is_string($scope) && !is_numeric($scope)) {
                    continue;
                }
                $parts[] = (string)$scope;
            }

            $parsed = $this->parseScopes(implode(' ', $parts));
            if (!empty($parsed)) {
                return $parsed;
            }
        } elseif (is_string($rawScopes)) {
            $parsed = $this->parseScopes($rawScopes);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        return $defaultScopes;
    }

    private function deduplicateCredentials(array $credentials, array $defaultScopes): array
    {
        $deduped = [];
        $seen = [];
        foreach ($credentials as $credential) {
            $token = (string)($credential['token'] ?? '');
            if ($token === '') {
                continue;
            }

            $tokenHash = sha1($token);
            if (isset($seen[$tokenHash])) {
                continue;
            }
            $seen[$tokenHash] = true;

            $deduped[] = [
                'id' => (string)($credential['id'] ?? 'default'),
                'token' => $token,
                'scopes' => $this->normalizeScopes($credential['scopes'] ?? null, $defaultScopes),
            ];
        }

        return $deduped;
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

    private function unauthorizedResponse(string $message): Response
    {
        return $this->errorResponse(401, self::ERROR_UNAUTHORIZED, $message);
    }

    private function misconfiguredResponse(string $message): Response
    {
        return $this->errorResponse(503, self::ERROR_SERVER_MISCONFIGURED, $message);
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
        return $this->attachRequestId($this->asJson($payload));
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
        $response->setStatusCode($statusCode);
        return $this->attachRequestId($response);
    }

    private function attachRequestId(Response $response): Response
    {
        $response->headers->set('X-Request-Id', $this->getRequestId());
        return $response;
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
