<?php

namespace agentreadiness\controllers;

use Craft;
use agentreadiness\Plugin;
use craft\web\Controller;
use craft\helpers\App;
use yii\caching\CacheInterface;
use yii\web\Response;

class ApiController extends Controller
{
    public function init(): void
    {
        parent::init();
        if (!Craft::$app->request->getIsGet()) {
            $this->requireAcceptsJson();
        }
    }

    protected array|int|bool $allowAnonymous = true;
    protected array|bool|int $supportsCsrfValidation = false;

    public function actionHealth(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        return $this->asJson(Plugin::getInstance()->getReadinessService()->getHealthSummary());
    }

    public function actionReadiness(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        return $this->asJson(Plugin::getInstance()->getReadinessService()->getReadinessSummary());
    }

    public function actionProducts(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
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

        return $this->asJson($payload);
    }

    public function actionOrders(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $payload = Plugin::getInstance()->getReadinessService()->getOrdersList([
            'status' => $request->getQueryParam('status', 'all'),
            'lastDays' => (int)$request->getQueryParam('lastDays', 30),
            'limit' => (int)$request->getQueryParam('limit', 50),
        ]);

        return $this->respondWithPayload($payload);
    }

    public function actionOrderShow(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $payload = Plugin::getInstance()->getReadinessService()->getOrderByIdOrNumber([
            'id' => (int)$request->getQueryParam('id', 0),
            'number' => (string)$request->getQueryParam('number', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Order not found.');
    }

    public function actionEntries(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $payload = Plugin::getInstance()->getReadinessService()->getEntriesList([
            'section' => $request->getQueryParam('section', ''),
            'type' => $request->getQueryParam('type', ''),
            'status' => $request->getQueryParam('status', 'live'),
            'search' => $request->getQueryParam('search', $request->getQueryParam('q', '')),
            'limit' => (int)$request->getQueryParam('limit', 50),
        ]);

        return $this->respondWithPayload($payload);
    }

    public function actionEntryShow(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $payload = Plugin::getInstance()->getReadinessService()->getEntryByIdOrSlug([
            'id' => (int)$request->getQueryParam('id', 0),
            'slug' => (string)$request->getQueryParam('slug', ''),
            'section' => (string)$request->getQueryParam('section', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Entry not found.');
    }

    public function actionSections(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        $payload = Plugin::getInstance()->getReadinessService()->getSectionsList();
        return $this->respondWithPayload($payload);
    }

    public function actionCapabilities(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        return $this->asJson([
            'service' => 'agents',
            'version' => '0.1.1',
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'basePath' => '/agents/v1',
            'authentication' => [
                'headers' => [
                    'Authorization: Bearer <token>',
                    'X-Agents-Token: <token>',
                ],
                'queryParam' => 'apiToken',
            ],
            'endpoints' => [
                ['method' => 'GET', 'path' => '/health'],
                ['method' => 'GET', 'path' => '/readiness'],
                ['method' => 'GET', 'path' => '/products'],
                ['method' => 'GET', 'path' => '/orders'],
                ['method' => 'GET', 'path' => '/orders/show'],
                ['method' => 'GET', 'path' => '/entries'],
                ['method' => 'GET', 'path' => '/entries/show'],
                ['method' => 'GET', 'path' => '/sections'],
                ['method' => 'GET', 'path' => '/capabilities'],
                ['method' => 'GET', 'path' => '/openapi.json'],
            ],
            'commands' => [
                'agents/product-list',
                'agents/order-list',
                'agents/order-show',
                'agents/entry-list',
                'agents/entry-show',
                'agents/section-list',
            ],
        ]);
    }

    public function actionOpenapi(): Response
    {
        if (($guard = $this->guardRequest()) !== null) {
            return $guard;
        }

        return $this->asJson([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Agents API',
                'version' => '0.1.1',
                'description' => 'Read-only agent discovery API for products, orders, entries, and sections.',
            ],
            'servers' => [
                ['url' => '/agents/v1', 'description' => 'Primary'],
            ],
            'paths' => [
                '/health' => ['get' => ['summary' => 'Health summary', 'responses' => ['200' => ['description' => 'OK']]]],
                '/readiness' => ['get' => ['summary' => 'Readiness summary', 'responses' => ['200' => ['description' => 'OK']]]],
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
                ]],
                '/orders' => ['get' => [
                    'summary' => 'Order list',
                    'parameters' => [
                        ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string']],
                        ['in' => 'query', 'name' => 'lastDays', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                        ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ],
                    'responses' => ['200' => ['description' => 'OK']],
                ]],
                '/orders/show' => ['get' => [
                    'summary' => 'Single order by id or number',
                    'parameters' => [
                        ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                        ['in' => 'query', 'name' => 'number', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'OK'], '400' => ['description' => 'Invalid request'], '404' => ['description' => 'Not found']],
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
                    'responses' => ['200' => ['description' => 'OK']],
                ]],
                '/entries/show' => ['get' => [
                    'summary' => 'Single entry by id or slug',
                    'parameters' => [
                        ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                        ['in' => 'query', 'name' => 'slug', 'schema' => ['type' => 'string']],
                        ['in' => 'query', 'name' => 'section', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => ['200' => ['description' => 'OK'], '400' => ['description' => 'Invalid request'], '404' => ['description' => 'Not found']],
                ]],
                '/sections' => ['get' => ['summary' => 'Section list', 'responses' => ['200' => ['description' => 'OK']]]],
                '/capabilities' => ['get' => ['summary' => 'Feature and command discovery', 'responses' => ['200' => ['description' => 'OK']]]],
                '/openapi.json' => ['get' => ['summary' => 'OpenAPI descriptor', 'responses' => ['200' => ['description' => 'OK']]]],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'agentsToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Agents-Token'],
                    'queryToken' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'apiToken'],
                ],
            ],
            'security' => [
                ['bearerAuth' => []],
                ['agentsToken' => []],
                ['queryToken' => []],
            ],
        ]);
    }

    private function guardRequest(): ?Response
    {
        $authError = $this->requireAuthToken();
        if ($authError !== null) {
            return $authError;
        }

        $rateLimit = $this->applyRateLimit();
        if ($rateLimit !== null) {
            return $rateLimit;
        }

        return null;
    }

    private function requireAuthToken(): ?Response
    {
        $expectedToken = (string)App::env('PLUGIN_AGENTS_API_TOKEN');
        if ($expectedToken === '') {
            return null;
        }

        $request = Craft::$app->getRequest();
        $providedToken = (string)$this->getBearerToken($request->getHeaders()->get('Authorization'));
        if ($providedToken === '') {
            $providedToken = (string)$request->getParam('apiToken');
        }
        if ($providedToken === '') {
            $providedToken = (string)$request->getHeaders()->get('X-Agents-Token');
        }

        if (!hash_equals($expectedToken, $providedToken)) {
            $response = $this->asJson([
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid token.',
            ]);
            $response->setStatusCode(401);
            return $response;
        }

        return null;
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

    private function applyRateLimit(): ?Response
    {
        $limit = (int)App::env('PLUGIN_AGENTS_RATE_LIMIT_PER_MINUTE');
        if ($limit <= 0) {
            $limit = 60;
        }

        $windowSeconds = (int)App::env('PLUGIN_AGENTS_RATE_LIMIT_WINDOW_SECONDS');
        if ($windowSeconds <= 0) {
            $windowSeconds = 60;
        }

        $request = Craft::$app->getRequest();
        $cache = Craft::$app->getCache();
        if (!$cache instanceof CacheInterface) {
            return null;
        }

        $userIp = $request->getUserIP();
        $identity = $request->getIsConsoleRequest() ? 'console' : ($userIp ?: 'unknown');
        $bucketStart = (int)(time() / $windowSeconds) * $windowSeconds;
        $bucketToken = sha1($identity . '|' . $userIp);
        $cacheKey = 'agents:rl:' . $bucketStart . ':' . $bucketToken;

        $current = (int)($cache->get($cacheKey) ?: 0);
        if ($current >= $limit) {
            $resetAt = $bucketStart + $windowSeconds;
            $response = $this->asJson([
                'error' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests.',
                'retryAfter' => $resetAt,
            ]);
            $response->setStatusCode(429);
            $response->headers->set('X-RateLimit-Limit', (string)$limit);
            $response->headers->set('X-RateLimit-Remaining', '0');
            $response->headers->set('X-RateLimit-Reset', (string)$resetAt);
            return $response;
        }

        $current++;
        $cache->set($cacheKey, $current, $windowSeconds + 1);

        $this->response->headers->set('X-RateLimit-Limit', (string)$limit);
        $this->response->headers->set('X-RateLimit-Remaining', (string)max(0, $limit - $current));
        $this->response->headers->set('X-RateLimit-Reset', (string)($bucketStart + $windowSeconds));

        return null;
    }

    private function respondWithPayload(array $payload, bool $expectSingle = false, string $notFoundMessage = 'Resource not found.'): Response
    {
        $errors = $payload['meta']['errors'] ?? [];
        if (!empty($errors)) {
            $response = $this->asJson([
                'error' => 'INVALID_REQUEST',
                'message' => (string)$errors[0],
                'details' => array_values($errors),
            ]);
            $response->setStatusCode(400);
            return $response;
        }

        if ($expectSingle && (($payload['data'] ?? null) === null)) {
            $response = $this->asJson([
                'error' => 'NOT_FOUND',
                'message' => $notFoundMessage,
            ]);
            $response->setStatusCode(404);
            return $response;
        }

        return $this->asJson($payload);
    }
}
