<?php

namespace Klick\Agents\controllers;

use Craft;
use Klick\Agents\Plugin;
use Klick\Agents\services\ObservabilityMetricsService;
use craft\web\Controller;
use yii\caching\CacheInterface;
use yii\mutex\Mutex;
use yii\web\Response;

class ApiController extends Controller
{
    private const DEFAULT_TOKEN_SCOPES = [
        'health:read',
        'readiness:read',
        'auth:read',
        'adoption:read',
        'metrics:read',
        'lifecycle:read',
        'diagnostics:read',
        'products:read',
        'variants:read',
        'subscriptions:read',
        'transfers:read',
        'donations:read',
        'orders:read',
        'entries:read',
        'assets:read',
        'categories:read',
        'tags:read',
        'globalsets:read',
        'addresses:read',
        'contentblocks:read',
        'changes:read',
        'sections:read',
        'users:read',
        'syncstate:read',
        'consumers:read',
        'templates:read',
        'schema:read',
        'capabilities:read',
        'openapi:read',
        'control:policies:read',
        'control:approvals:read',
        'control:executions:read',
        'control:audit:read',
        'webhooks:dlq:read',
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

    protected array|int|bool $allowAnonymous = true;
    // Token-authenticated machine endpoints do not use session-bound CSRF tokens.
    public $enableCsrfValidation = false;
    // Keep explicit support flag for parity checks and future controller compatibility.
    protected array|bool|int $supportsCsrfValidation = false;

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
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $statusError = $this->validateEnumQueryParam('status', ['live', 'pending', 'disabled', 'expired', 'all', 'any']);
        if ($statusError !== null) {
            $errors[] = $statusError;
        }
        $sortError = $this->validateEnumQueryParam('sort', ['updatedAt', 'createdAt', 'title']);
        if ($sortError !== null) {
            $errors[] = $sortError;
        }
        $lowStockError = $this->validateBooleanQueryParam('lowStock');
        if ($lowStockError !== null) {
            $errors[] = $lowStockError;
        }
        $lowStockThresholdError = $this->validateIntegerQueryParam('lowStockThreshold', 0, 1000000);
        if ($lowStockThresholdError !== null) {
            $errors[] = $lowStockThresholdError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $lowStock = $this->parseBooleanQueryParam('lowStock', false);

        $payload = Plugin::getInstance()->getReadinessService()->getProductsSnapshot([
            'q' => $request->getQueryParam('q'),
            'status' => $request->getQueryParam('status', 'live'),
            'sort' => $request->getQueryParam('sort', 'updatedAt'),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
            'lowStock' => $lowStock,
            'lowStockThreshold' => (int)$request->getQueryParam('lowStockThreshold', 10),
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

        $payload = $this->applyListProjectionAndFilters($payload);
        return $this->jsonResponse($payload);
    }

    public function actionVariants(): Response
    {
        if (($guard = $this->guardRequest('variants:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $productIdError = $this->validateIntegerQueryParam('productId', 1, null);
        if ($productIdError !== null) {
            $errors[] = $productIdError;
        }
        $statusError = $this->validateEnumQueryParam('status', ['live', 'pending', 'disabled', 'expired', 'all', 'any']);
        if ($statusError !== null) {
            $errors[] = $statusError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getVariantsList([
            'status' => $request->getQueryParam('status', 'live'),
            'q' => $request->getQueryParam('q', ''),
            'sku' => $request->getQueryParam('sku', ''),
            'productId' => (int)$request->getQueryParam('productId', 0),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionVariantShow(): Response
    {
        if (($guard = $this->guardRequest('variants:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawSku = trim((string)$request->getQueryParam('sku', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }
        $productIdError = $this->validateIntegerQueryParam('productId', 1, null);
        if ($productIdError !== null) {
            $errors[] = $productIdError;
        }

        $hasId = $rawId !== '';
        $hasSku = $rawSku !== '';
        if (($hasId ? 1 : 0) + ($hasSku ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `sku`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getVariantByIdOrSku([
            'id' => (int)$request->getQueryParam('id', 0),
            'sku' => (string)$request->getQueryParam('sku', ''),
            'productId' => (int)$request->getQueryParam('productId', 0),
        ]);

        return $this->respondWithPayload($payload, true, 'Variant not found.');
    }

    public function actionSubscriptions(): Response
    {
        if (($guard = $this->guardRequest('subscriptions:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $userIdError = $this->validateIntegerQueryParam('userId', 1, null);
        if ($userIdError !== null) {
            $errors[] = $userIdError;
        }
        $planIdError = $this->validateIntegerQueryParam('planId', 1, null);
        if ($planIdError !== null) {
            $errors[] = $planIdError;
        }
        $statusError = $this->validateEnumQueryParam('status', ['active', 'expired', 'suspended', 'canceled', 'all', 'any']);
        if ($statusError !== null) {
            $errors[] = $statusError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getSubscriptionsList([
            'status' => $request->getQueryParam('status', 'active'),
            'q' => $request->getQueryParam('q', ''),
            'reference' => $request->getQueryParam('reference', ''),
            'userId' => (int)$request->getQueryParam('userId', 0),
            'planId' => (int)$request->getQueryParam('planId', 0),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionSubscriptionShow(): Response
    {
        if (($guard = $this->guardRequest('subscriptions:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawReference = trim((string)$request->getQueryParam('reference', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }

        $hasId = $rawId !== '';
        $hasReference = $rawReference !== '';
        if (($hasId ? 1 : 0) + ($hasReference ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `reference`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getSubscriptionByIdOrReference([
            'id' => (int)$request->getQueryParam('id', 0),
            'reference' => (string)$request->getQueryParam('reference', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Subscription not found.');
    }

    public function actionTransfers(): Response
    {
        if (($guard = $this->guardRequest('transfers:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $originError = $this->validateIntegerQueryParam('originLocationId', 1, null);
        if ($originError !== null) {
            $errors[] = $originError;
        }
        $destinationError = $this->validateIntegerQueryParam('destinationLocationId', 1, null);
        if ($destinationError !== null) {
            $errors[] = $destinationError;
        }
        $statusError = $this->validatePatternQueryParam('status', '/^[a-zA-Z0-9_-]+$/', 'status');
        if ($statusError !== null) {
            $errors[] = $statusError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getTransfersList([
            'status' => $request->getQueryParam('status', 'all'),
            'q' => $request->getQueryParam('q', ''),
            'originLocationId' => (int)$request->getQueryParam('originLocationId', 0),
            'destinationLocationId' => (int)$request->getQueryParam('destinationLocationId', 0),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionTransferShow(): Response
    {
        if (($guard = $this->guardRequest('transfers:read')) !== null) {
            return $guard;
        }

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            return $this->invalidQueryResponse([$idError]);
        }

        $request = Craft::$app->getRequest();
        $id = (int)$request->getQueryParam('id', 0);
        if ($id <= 0) {
            return $this->invalidQueryResponse(['Provide `id` with a positive integer value.']);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getTransferById([
            'id' => $id,
        ]);

        return $this->respondWithPayload($payload, true, 'Transfer not found.');
    }

    public function actionDonations(): Response
    {
        if (($guard = $this->guardRequest('donations:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $statusError = $this->validateEnumQueryParam('status', ['live', 'pending', 'disabled', 'expired', 'all', 'any']);
        if ($statusError !== null) {
            $errors[] = $statusError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getDonationsList([
            'status' => $request->getQueryParam('status', 'live'),
            'q' => $request->getQueryParam('q', ''),
            'sku' => $request->getQueryParam('sku', ''),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionDonationShow(): Response
    {
        if (($guard = $this->guardRequest('donations:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawSku = trim((string)$request->getQueryParam('sku', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }

        $hasId = $rawId !== '';
        $hasSku = $rawSku !== '';
        if (($hasId ? 1 : 0) + ($hasSku ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `sku`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getDonationByIdOrSku([
            'id' => (int)$request->getQueryParam('id', 0),
            'sku' => (string)$request->getQueryParam('sku', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Donation not found.');
    }

    public function actionOrders(): Response
    {
        if (($guard = $this->guardRequest('orders:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $lastDaysError = $this->validateIntegerQueryParam('lastDays', 0, 3650);
        if ($lastDaysError !== null) {
            $errors[] = $lastDaysError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

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

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionOrderShow(): Response
    {
        if (($guard = $this->guardRequest('orders:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawNumber = trim((string)$request->getQueryParam('number', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }

        $hasId = $rawId !== '';
        $hasNumber = $rawNumber !== '';
        if (($hasId ? 1 : 0) + ($hasNumber ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `number`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

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
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $statusError = $this->validateEnumQueryParam('status', ['live', 'pending', 'disabled', 'expired', 'all', 'any']);
        if ($statusError !== null) {
            $errors[] = $statusError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

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

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionChanges(): Response
    {
        if (($guard = $this->guardRequest('changes:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

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

        $payload = $this->applyListProjectionAndFilters($payload);
        return $this->jsonResponse($payload);
    }

    public function actionEntryShow(): Response
    {
        if (($guard = $this->guardRequest('entries:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawSlug = trim((string)$request->getQueryParam('slug', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }

        $hasId = $rawId !== '';
        $hasSlug = $rawSlug !== '';
        if (($hasId ? 1 : 0) + ($hasSlug ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `slug`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $allowAllStatuses = $this->hasScope('entries:read_all_statuses');
        $payload = Plugin::getInstance()->getReadinessService()->getEntryByIdOrSlug([
            'id' => (int)$request->getQueryParam('id', 0),
            'slug' => (string)$request->getQueryParam('slug', ''),
            'section' => (string)$request->getQueryParam('section', ''),
            'includeAllStatuses' => $allowAllStatuses,
        ]);

        return $this->respondWithPayload($payload, true, 'Entry not found.');
    }

    public function actionAssets(): Response
    {
        if (($guard = $this->guardRequest('assets:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $volumeError = $this->validatePatternQueryParam('volume', '/^[a-zA-Z0-9_-]+$/', 'volume');
        if ($volumeError !== null) {
            $errors[] = $volumeError;
        }
        $kindError = $this->validatePatternQueryParam('kind', '/^[a-zA-Z0-9_-]+$/', 'kind');
        if ($kindError !== null) {
            $errors[] = $kindError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getAssetsList([
            'q' => $request->getQueryParam('q'),
            'volume' => $request->getQueryParam('volume', ''),
            'kind' => $request->getQueryParam('kind', ''),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionAssetShow(): Response
    {
        if (($guard = $this->guardRequest('assets:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawFilename = trim((string)$request->getQueryParam('filename', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }
        $volumeError = $this->validatePatternQueryParam('volume', '/^[a-zA-Z0-9_-]+$/', 'volume');
        if ($volumeError !== null) {
            $errors[] = $volumeError;
        }

        $hasId = $rawId !== '';
        $hasFilename = $rawFilename !== '';
        if (($hasId ? 1 : 0) + ($hasFilename ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `filename`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getAssetByIdOrFilename([
            'id' => (int)$request->getQueryParam('id', 0),
            'filename' => (string)$request->getQueryParam('filename', ''),
            'volume' => (string)$request->getQueryParam('volume', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Asset not found.');
    }

    public function actionCategories(): Response
    {
        if (($guard = $this->guardRequest('categories:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $groupError = $this->validatePatternQueryParam('group', '/^[a-zA-Z0-9_-]+$/', 'group');
        if ($groupError !== null) {
            $errors[] = $groupError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getCategoriesList([
            'q' => $request->getQueryParam('q'),
            'group' => $request->getQueryParam('group', ''),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionCategoryShow(): Response
    {
        if (($guard = $this->guardRequest('categories:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawSlug = trim((string)$request->getQueryParam('slug', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }
        $groupError = $this->validatePatternQueryParam('group', '/^[a-zA-Z0-9_-]+$/', 'group');
        if ($groupError !== null) {
            $errors[] = $groupError;
        }

        $hasId = $rawId !== '';
        $hasSlug = $rawSlug !== '';
        if (($hasId ? 1 : 0) + ($hasSlug ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `slug`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getCategoryByIdOrSlug([
            'id' => (int)$request->getQueryParam('id', 0),
            'slug' => (string)$request->getQueryParam('slug', ''),
            'group' => (string)$request->getQueryParam('group', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Category not found.');
    }

    public function actionTags(): Response
    {
        if (($guard = $this->guardRequest('tags:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $groupError = $this->validatePatternQueryParam('group', '/^[a-zA-Z0-9_-]+$/', 'group');
        if ($groupError !== null) {
            $errors[] = $groupError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getTagsList([
            'q' => $request->getQueryParam('q'),
            'group' => $request->getQueryParam('group', ''),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionTagShow(): Response
    {
        if (($guard = $this->guardRequest('tags:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawSlug = trim((string)$request->getQueryParam('slug', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }
        $groupError = $this->validatePatternQueryParam('group', '/^[a-zA-Z0-9_-]+$/', 'group');
        if ($groupError !== null) {
            $errors[] = $groupError;
        }

        $hasId = $rawId !== '';
        $hasSlug = $rawSlug !== '';
        if (($hasId ? 1 : 0) + ($hasSlug ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `slug`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getTagByIdOrSlug([
            'id' => (int)$request->getQueryParam('id', 0),
            'slug' => (string)$request->getQueryParam('slug', ''),
            'group' => (string)$request->getQueryParam('group', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Tag not found.');
    }

    public function actionGlobalSets(): Response
    {
        if (($guard = $this->guardRequest('globalsets:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getGlobalSetsList([
            'q' => $request->getQueryParam('q'),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionGlobalSetShow(): Response
    {
        if (($guard = $this->guardRequest('globalsets:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawHandle = trim((string)$request->getQueryParam('handle', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }
        $handleError = $this->validatePatternQueryParam('handle', '/^[a-zA-Z0-9_-]+$/', 'handle');
        if ($handleError !== null) {
            $errors[] = $handleError;
        }

        $hasId = $rawId !== '';
        $hasHandle = $rawHandle !== '';
        if (($hasId ? 1 : 0) + ($hasHandle ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `handle`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getGlobalSetByIdOrHandle([
            'id' => (int)$request->getQueryParam('id', 0),
            'handle' => (string)$request->getQueryParam('handle', ''),
        ]);

        return $this->respondWithPayload($payload, true, 'Global set not found.');
    }

    public function actionAddresses(): Response
    {
        if (($guard = $this->guardAddressesApiEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('addresses:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $ownerIdError = $this->validateIntegerQueryParam('ownerId', 1, null);
        if ($ownerIdError !== null) {
            $errors[] = $ownerIdError;
        }
        $countryCodeError = $this->validatePatternQueryParam('countryCode', '/^[a-zA-Z]{2}$/', 'countryCode');
        if ($countryCodeError !== null) {
            $errors[] = $countryCodeError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $includeSensitive = $this->hasScope('addresses:read_sensitive');
        $payload = Plugin::getInstance()->getReadinessService()->getAddressesList([
            'q' => $request->getQueryParam('q'),
            'ownerId' => (int)$request->getQueryParam('ownerId', 0),
            'countryCode' => (string)$request->getQueryParam('countryCode', ''),
            'postalCode' => (string)$request->getQueryParam('postalCode', ''),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
            'includeSensitive' => $includeSensitive,
            'redactSensitive' => !$includeSensitive && $this->shouldRedactEmail(),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionAddressShow(): Response
    {
        if (($guard = $this->guardAddressesApiEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('addresses:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawUid = trim((string)$request->getQueryParam('uid', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }
        $ownerIdError = $this->validateIntegerQueryParam('ownerId', 1, null);
        if ($ownerIdError !== null) {
            $errors[] = $ownerIdError;
        }
        $uidError = $this->validatePatternQueryParam('uid', '/^[a-zA-Z0-9-]+$/', 'uid');
        if ($uidError !== null) {
            $errors[] = $uidError;
        }

        $hasId = $rawId !== '';
        $hasUid = $rawUid !== '';
        if (($hasId ? 1 : 0) + ($hasUid ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `uid`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $includeSensitive = $this->hasScope('addresses:read_sensitive');
        $payload = Plugin::getInstance()->getReadinessService()->getAddressByIdOrUid([
            'id' => (int)$request->getQueryParam('id', 0),
            'uid' => (string)$request->getQueryParam('uid', ''),
            'ownerId' => (int)$request->getQueryParam('ownerId', 0),
            'includeSensitive' => $includeSensitive,
            'redactSensitive' => !$includeSensitive && $this->shouldRedactEmail(),
        ]);

        return $this->respondWithPayload($payload, true, 'Address not found.');
    }

    public function actionContentBlocks(): Response
    {
        if (($guard = $this->guardRequest('contentblocks:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $ownerIdError = $this->validateIntegerQueryParam('ownerId', 1, null);
        if ($ownerIdError !== null) {
            $errors[] = $ownerIdError;
        }
        $fieldIdError = $this->validateIntegerQueryParam('fieldId', 1, null);
        if ($fieldIdError !== null) {
            $errors[] = $fieldIdError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getContentBlocksList([
            'q' => $request->getQueryParam('q'),
            'ownerId' => (int)$request->getQueryParam('ownerId', 0),
            'fieldId' => (int)$request->getQueryParam('fieldId', 0),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionContentBlockShow(): Response
    {
        if (($guard = $this->guardRequest('contentblocks:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawUid = trim((string)$request->getQueryParam('uid', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }
        $ownerIdError = $this->validateIntegerQueryParam('ownerId', 1, null);
        if ($ownerIdError !== null) {
            $errors[] = $ownerIdError;
        }
        $fieldIdError = $this->validateIntegerQueryParam('fieldId', 1, null);
        if ($fieldIdError !== null) {
            $errors[] = $fieldIdError;
        }
        $uidError = $this->validatePatternQueryParam('uid', '/^[a-zA-Z0-9-]+$/', 'uid');
        if ($uidError !== null) {
            $errors[] = $uidError;
        }

        $hasId = $rawId !== '';
        $hasUid = $rawUid !== '';
        if (($hasId ? 1 : 0) + ($hasUid ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `uid`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $payload = Plugin::getInstance()->getReadinessService()->getContentBlockByIdOrUid([
            'id' => (int)$request->getQueryParam('id', 0),
            'uid' => (string)$request->getQueryParam('uid', ''),
            'ownerId' => (int)$request->getQueryParam('ownerId', 0),
            'fieldId' => (int)$request->getQueryParam('fieldId', 0),
        ]);

        return $this->respondWithPayload($payload, true, 'Content block not found.');
    }

    public function actionSections(): Response
    {
        if (($guard = $this->guardRequest('sections:read')) !== null) {
            return $guard;
        }

        $payload = Plugin::getInstance()->getReadinessService()->getSectionsList();
        return $this->respondWithPayload($payload);
    }

    public function actionUsers(): Response
    {
        if (($guard = $this->guardUsersApiEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('users:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $errors = [];
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        $statusError = $this->validateEnumQueryParam('status', ['active', 'inactive', 'pending', 'suspended', 'locked', 'credentialed', 'all', 'any']);
        if ($statusError !== null) {
            $errors[] = $statusError;
        }
        $errors = array_merge($errors, $this->validateProjectionAndFilterQueryParams());
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $includeSensitive = $this->hasScope('users:read_sensitive');
        $payload = Plugin::getInstance()->getReadinessService()->getUsersList([
            'status' => $request->getQueryParam('status', 'active'),
            'group' => $request->getQueryParam('group', ''),
            'q' => $request->getQueryParam('q', ''),
            'limit' => (int)$request->getQueryParam('limit', 50),
            'cursor' => $request->getQueryParam('cursor'),
            'updatedSince' => $request->getQueryParam('updatedSince'),
            'includeSensitive' => $includeSensitive,
            'redactEmail' => !$includeSensitive && $this->shouldRedactEmail(),
        ]);

        return $this->respondWithPayload($this->applyListProjectionAndFilters($payload));
    }

    public function actionUserShow(): Response
    {
        if (($guard = $this->guardUsersApiEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('users:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $rawId = trim((string)$request->getQueryParam('id', ''));
        $rawUsername = trim((string)$request->getQueryParam('username', ''));
        $errors = [];

        $idError = $this->validateIntegerQueryParam('id', 1, null);
        if ($idError !== null) {
            $errors[] = $idError;
        }

        $hasId = $rawId !== '';
        $hasUsername = $rawUsername !== '';
        if (($hasId ? 1 : 0) + ($hasUsername ? 1 : 0) !== 1) {
            $errors[] = 'Provide exactly one identifier: `id` or `username`.';
        }

        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $includeSensitive = $this->hasScope('users:read_sensitive');
        $payload = Plugin::getInstance()->getReadinessService()->getUserByIdOrUsername([
            'id' => (int)$request->getQueryParam('id', 0),
            'username' => (string)$request->getQueryParam('username', ''),
            'includeSensitive' => $includeSensitive,
            'redactEmail' => !$includeSensitive && $this->shouldRedactEmail(),
        ]);

        return $this->respondWithPayload($payload, true, 'User not found.');
    }

    public function actionTemplates(): Response
    {
        if (($guard = $this->guardRequest('templates:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $templateId = strtolower(trim((string)$request->getQueryParam('id', '')));
        $service = Plugin::getInstance()->getTemplateCatalogService();

        if ($templateId !== '') {
            $template = $service->getTemplateById($templateId, '/agents/v1');
            if ($template === null) {
                return $this->errorResponse(404, self::ERROR_NOT_FOUND, sprintf('Unknown template `%s`.', $templateId));
            }

            return $this->jsonResponse([
                'version' => $this->resolvePluginVersion(),
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'template' => $template,
            ]);
        }

        return $this->jsonResponse($service->getCatalog('/agents/v1'));
    }

    public function actionStarterPacks(): Response
    {
        if (($guard = $this->guardRequest('templates:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $templateId = strtolower(trim((string)$request->getQueryParam('id', '')));
        $service = Plugin::getInstance()->getStarterPackService();

        if ($templateId !== '') {
            $starterPack = $service->getStarterPackById($templateId, '/agents/v1');
            if ($starterPack === null) {
                return $this->errorResponse(404, self::ERROR_NOT_FOUND, sprintf('Unknown starter pack `%s`.', $templateId));
            }

            return $this->jsonResponse([
                'version' => $this->resolvePluginVersion(),
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'starterPack' => $starterPack,
            ]);
        }

        return $this->jsonResponse($service->getCatalog('/agents/v1'));
    }

    public function actionSchema(): Response
    {
        if (($guard = $this->guardRequest('schema:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $versionFormatError = $this->validatePatternQueryParam('version', '/^[a-zA-Z0-9._-]+$/', 'version');
        if ($versionFormatError !== null) {
            return $this->invalidQueryResponse([$versionFormatError]);
        }

        $version = strtolower(trim((string)$request->getQueryParam('version', 'v1')));
        if ($version === '') {
            $version = 'v1';
        }

        $catalogs = $this->versionedSchemaCatalogs();
        if (!isset($catalogs[$version]) || !is_array($catalogs[$version])) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, sprintf('Unsupported schema version `%s`.', $version));
        }
        $runtimeProfile = $this->buildRuntimeProfileMetadata($this->getSecurityConfig());

        $schemas = $catalogs[$version];
        $endpoint = strtolower(trim((string)$request->getQueryParam('endpoint', '')));
        $endpointFormatError = $this->validatePatternQueryParam('endpoint', '/^[a-zA-Z0-9._-]+$/', 'endpoint');
        if ($endpointFormatError !== null) {
            return $this->invalidQueryResponse([$endpointFormatError]);
        }
        if ($endpoint !== '') {
            if (!isset($schemas[$endpoint])) {
                return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, sprintf('Unknown schema endpoint `%s` for version `%s`.', $endpoint, $version));
            }

            return $this->jsonResponse([
                'version' => $version,
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'runtimeProfile' => $runtimeProfile,
                'endpoint' => $endpoint,
                'schema' => $schemas[$endpoint],
            ]);
        }

        return $this->jsonResponse([
            'version' => $version,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'runtimeProfile' => $runtimeProfile,
            'count' => count($schemas),
            'schemas' => $schemas,
        ]);
    }

    public function actionCapabilities(): Response
    {
        if (($guard = $this->guardRequest('capabilities:read')) !== null) {
            return $guard;
        }

        $config = $this->getSecurityConfig();
        $pluginVersion = $this->resolvePluginVersion();
        $externalRegistry = Plugin::getInstance()->getExternalResourceRegistryService();
        $endpoints = [
            ['method' => 'GET', 'path' => '/health', 'requiredScopes' => ['health:read']],
            ['method' => 'GET', 'path' => '/readiness', 'requiredScopes' => ['readiness:read']],
            ['method' => 'GET', 'path' => '/auth/whoami', 'requiredScopes' => ['auth:read']],
            ['method' => 'GET', 'path' => '/adoption/metrics', 'requiredScopes' => ['adoption:read']],
            ['method' => 'GET', 'path' => '/metrics', 'requiredScopes' => ['metrics:read']],
            ['method' => 'GET', 'path' => '/incidents', 'requiredScopes' => ['incidents:read']],
            ['method' => 'GET', 'path' => '/lifecycle', 'requiredScopes' => ['lifecycle:read']],
            ['method' => 'GET', 'path' => '/diagnostics/bundle', 'requiredScopes' => ['diagnostics:read']],
            ['method' => 'GET', 'path' => '/products', 'requiredScopes' => ['products:read']],
            ['method' => 'GET', 'path' => '/variants', 'requiredScopes' => ['variants:read']],
            ['method' => 'GET', 'path' => '/variants/show', 'requiredScopes' => ['variants:read']],
            ['method' => 'GET', 'path' => '/subscriptions', 'requiredScopes' => ['subscriptions:read']],
            ['method' => 'GET', 'path' => '/subscriptions/show', 'requiredScopes' => ['subscriptions:read']],
            ['method' => 'GET', 'path' => '/transfers', 'requiredScopes' => ['transfers:read']],
            ['method' => 'GET', 'path' => '/transfers/show', 'requiredScopes' => ['transfers:read']],
            ['method' => 'GET', 'path' => '/donations', 'requiredScopes' => ['donations:read']],
            ['method' => 'GET', 'path' => '/donations/show', 'requiredScopes' => ['donations:read']],
            ['method' => 'GET', 'path' => '/orders', 'requiredScopes' => ['orders:read'], 'optionalScopes' => ['orders:read_sensitive']],
            ['method' => 'GET', 'path' => '/orders/show', 'requiredScopes' => ['orders:read'], 'optionalScopes' => ['orders:read_sensitive']],
            ['method' => 'GET', 'path' => '/entries', 'requiredScopes' => ['entries:read'], 'optionalScopes' => ['entries:read_all_statuses']],
            ['method' => 'GET', 'path' => '/entries/show', 'requiredScopes' => ['entries:read'], 'optionalScopes' => ['entries:read_all_statuses']],
            ['method' => 'GET', 'path' => '/assets', 'requiredScopes' => ['assets:read']],
            ['method' => 'GET', 'path' => '/assets/show', 'requiredScopes' => ['assets:read']],
            ['method' => 'GET', 'path' => '/categories', 'requiredScopes' => ['categories:read']],
            ['method' => 'GET', 'path' => '/categories/show', 'requiredScopes' => ['categories:read']],
            ['method' => 'GET', 'path' => '/tags', 'requiredScopes' => ['tags:read']],
            ['method' => 'GET', 'path' => '/tags/show', 'requiredScopes' => ['tags:read']],
            ['method' => 'GET', 'path' => '/global-sets', 'requiredScopes' => ['globalsets:read']],
            ['method' => 'GET', 'path' => '/global-sets/show', 'requiredScopes' => ['globalsets:read']],
            ['method' => 'GET', 'path' => '/addresses', 'requiredScopes' => ['addresses:read'], 'optionalScopes' => ['addresses:read_sensitive']],
            ['method' => 'GET', 'path' => '/addresses/show', 'requiredScopes' => ['addresses:read'], 'optionalScopes' => ['addresses:read_sensitive']],
            ['method' => 'GET', 'path' => '/content-blocks', 'requiredScopes' => ['contentblocks:read']],
            ['method' => 'GET', 'path' => '/content-blocks/show', 'requiredScopes' => ['contentblocks:read']],
            ['method' => 'GET', 'path' => '/changes', 'requiredScopes' => ['changes:read']],
            ['method' => 'GET', 'path' => '/sections', 'requiredScopes' => ['sections:read']],
            ['method' => 'GET', 'path' => '/users', 'requiredScopes' => ['users:read'], 'optionalScopes' => ['users:read_sensitive']],
            ['method' => 'GET', 'path' => '/users/show', 'requiredScopes' => ['users:read'], 'optionalScopes' => ['users:read_sensitive']],
            ['method' => 'GET', 'path' => '/sync-state/lag', 'requiredScopes' => ['syncstate:read'], 'optionalScopes' => ['consumers:read']],
            ['method' => 'POST', 'path' => '/sync-state/checkpoint', 'requiredScopes' => ['syncstate:write'], 'optionalScopes' => ['consumers:write']],
            ['method' => 'GET', 'path' => '/consumers/lag', 'requiredScopes' => ['consumers:read'], 'optionalScopes' => ['syncstate:read'], 'deprecated' => true, 'replacedBy' => '/sync-state/lag'],
            ['method' => 'POST', 'path' => '/consumers/checkpoint', 'requiredScopes' => ['consumers:write'], 'optionalScopes' => ['syncstate:write'], 'deprecated' => true, 'replacedBy' => '/sync-state/checkpoint'],
            ['method' => 'GET', 'path' => '/templates', 'requiredScopes' => ['templates:read']],
            ['method' => 'GET', 'path' => '/starter-packs', 'requiredScopes' => ['templates:read']],
            ['method' => 'GET', 'path' => '/schema', 'requiredScopes' => ['schema:read']],
            ['method' => 'GET', 'path' => '/capabilities', 'requiredScopes' => ['capabilities:read']],
            ['method' => 'GET', 'path' => '/openapi.json', 'requiredScopes' => ['openapi:read']],
            ['method' => 'GET', 'path' => '/control/policies', 'requiredScopes' => ['control:policies:read']],
            ['method' => 'POST', 'path' => '/control/policies/upsert', 'requiredScopes' => ['control:policies:write']],
            ['method' => 'GET', 'path' => '/control/approvals', 'requiredScopes' => ['control:approvals:read']],
            ['method' => 'POST', 'path' => '/control/approvals/request', 'requiredScopes' => ['control:approvals:request'], 'optionalScopes' => ['control:approvals:write']],
            ['method' => 'POST', 'path' => '/control/approvals/decide', 'requiredScopes' => ['control:approvals:decide'], 'optionalScopes' => ['control:approvals:write']],
            ['method' => 'GET', 'path' => '/control/executions', 'requiredScopes' => ['control:executions:read']],
            ['method' => 'POST', 'path' => '/control/policy-simulate', 'requiredScopes' => ['control:actions:simulate']],
            ['method' => 'POST', 'path' => '/control/actions/execute', 'requiredScopes' => ['control:actions:execute'], 'optionalScopes' => ['policy.config.requiredScope']],
            ['method' => 'GET', 'path' => '/control/audit', 'requiredScopes' => ['control:audit:read']],
            ['method' => 'GET', 'path' => '/webhooks/dlq', 'requiredScopes' => ['webhooks:dlq:read']],
            ['method' => 'POST', 'path' => '/webhooks/dlq/replay', 'requiredScopes' => ['webhooks:dlq:replay']],
        ];
        if (!$this->isWritesExperimentalEnabled()) {
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn(array $endpoint): bool => !str_starts_with((string)($endpoint['path'] ?? ''), '/control/')
            ));
        }
        if (!$this->isUsersApiEnabled()) {
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn(array $endpoint): bool => !str_starts_with((string)($endpoint['path'] ?? ''), '/users')
            ));
        }
        if (!$this->isAddressesApiEnabled()) {
            $endpoints = array_values(array_filter(
                $endpoints,
                static fn(array $endpoint): bool => !str_starts_with((string)($endpoint['path'] ?? ''), '/addresses')
            ));
        }
        $externalResources = $externalRegistry->getCapabilitiesResources();
        $endpoints = array_merge($endpoints, $externalRegistry->getCapabilityEndpoints());

        return $this->jsonResponse([
            'service' => 'agents',
            'version' => $pluginVersion,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'basePath' => '/agents/v1',
            'discoveryAliases' => [
                '/capabilities' => '/agents/v1/capabilities',
                '/openapi.json' => '/agents/v1/openapi.json',
            ],
            'runtimeProfile' => $this->buildRuntimeProfileMetadata($config),
            'requestIdHeader' => 'X-Request-Id',
            'authentication' => $this->buildCapabilitiesAuthentication($config),
            'authorization' => [
                'grantedScopes' => $this->getGrantedScopes(),
                'availableScopes' => $this->availableScopes(),
            ],
            'externalResources' => $externalResources,
            'errorCodes' => $this->errorTaxonomy(),
            'endpoints' => $endpoints,
            'commands' => [
                'agents/product-list',
                'agents/order-list',
                'agents/order-show',
                'agents/entry-list',
                'agents/entry-show',
                'agents/section-list',
                'agents/auth-check',
                'agents/readiness-check',
                'agents/reliability-check',
                'agents/lifecycle-report',
                'agents/template-catalog',
                'agents/starter-packs',
                'agents/diagnostics-bundle',
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
            '/adoption/metrics' => ['get' => [
                'summary' => 'Adoption instrumentation snapshot for first-success funnel and credential usage',
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['adoption:read'],
            ]],
            '/metrics' => ['get' => [
                'summary' => 'Observability metrics snapshot for runtime, queue, webhook, and integration lag health',
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['metrics:read'],
            ]],
            '/incidents' => ['get' => [
                'summary' => 'Redacted runtime incident snapshot for health monitoring',
                'parameters' => [
                    ['in' => 'query', 'name' => 'severity', 'schema' => ['type' => 'string', 'enum' => ['all', 'warn', 'critical'], 'default' => 'all']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['incidents:read'],
            ]],
            '/lifecycle' => ['get' => [
                'summary' => 'Agent lifecycle governance snapshot (ownership, stale usage, expiry, rotation, risk factors)',
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['lifecycle:read'],
            ]],
            '/diagnostics/bundle' => ['get' => [
                'summary' => 'One-click diagnostics bundle for support and operations triage',
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['diagnostics:read'],
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
                    ['in' => 'query', 'name' => 'lowStock', 'schema' => ['type' => 'boolean'], 'description' => 'When true, only products at or below the low-stock threshold are returned (full sync mode only).'],
                    ['in' => 'query', 'name' => 'lowStockThreshold', 'schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000], 'description' => 'Low-stock threshold used when `lowStock=true`. Default: 10.'],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request (e.g. malformed cursor/updatedSince).'],
                ]),
                'x-required-scopes' => ['products:read'],
            ]],
            '/variants' => ['get' => [
                'summary' => 'Variant list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['live', 'pending', 'disabled', 'expired', 'all']]],
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'sku', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'productId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['variants:read'],
            ]],
            '/variants/show' => ['get' => [
                'summary' => 'Single variant by id or sku',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'sku', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'productId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['variants:read'],
            ]],
            '/subscriptions' => ['get' => [
                'summary' => 'Subscription list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['active', 'expired', 'suspended', 'canceled', 'all']]],
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'reference', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'userId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'planId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['subscriptions:read'],
            ]],
            '/subscriptions/show' => ['get' => [
                'summary' => 'Single subscription by id or reference',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'reference', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['subscriptions:read'],
            ]],
            '/transfers' => ['get' => [
                'summary' => 'Transfer list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'originLocationId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'destinationLocationId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['transfers:read'],
            ]],
            '/transfers/show' => ['get' => [
                'summary' => 'Single transfer by id',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['transfers:read'],
            ]],
            '/donations' => ['get' => [
                'summary' => 'Donation list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['live', 'pending', 'disabled', 'expired', 'all']]],
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'sku', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['donations:read'],
            ]],
            '/donations/show' => ['get' => [
                'summary' => 'Single donation by id or sku',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'sku', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['donations:read'],
            ]],
            '/orders' => ['get' => [
                'summary' => 'Order list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'lastDays', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
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
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
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
                    ['in' => 'query', 'name' => 'types', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated list: products,variants,subscriptions,transfers,donations,orders,entries,assets,categories,tags,globalsets,addresses,contentblocks,users'],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
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
            '/assets' => ['get' => [
                'summary' => 'Asset list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'volume', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'kind', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['assets:read'],
            ]],
            '/assets/show' => ['get' => [
                'summary' => 'Single asset by id or filename',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'filename', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'volume', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['assets:read'],
            ]],
            '/categories' => ['get' => [
                'summary' => 'Category list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'group', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['categories:read'],
            ]],
            '/categories/show' => ['get' => [
                'summary' => 'Single category by id or slug',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'slug', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'group', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['categories:read'],
            ]],
            '/tags' => ['get' => [
                'summary' => 'Tag list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'group', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['tags:read'],
            ]],
            '/tags/show' => ['get' => [
                'summary' => 'Single tag by id or slug',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'slug', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'group', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['tags:read'],
            ]],
            '/global-sets' => ['get' => [
                'summary' => 'Global set list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['globalsets:read'],
            ]],
            '/global-sets/show' => ['get' => [
                'summary' => 'Single global set by id or handle',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'handle', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['globalsets:read'],
            ]],
            '/addresses' => ['get' => [
                'summary' => 'Address list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'ownerId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'countryCode', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'postalCode', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Endpoint not available'],
                ]),
                'x-required-scopes' => ['addresses:read'],
                'x-optional-scopes' => ['addresses:read_sensitive'],
            ]],
            '/addresses/show' => ['get' => [
                'summary' => 'Single address by id or uid',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'uid', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'ownerId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found or endpoint unavailable'],
                ]),
                'x-required-scopes' => ['addresses:read'],
                'x-optional-scopes' => ['addresses:read_sensitive'],
            ]],
            '/content-blocks' => ['get' => [
                'summary' => 'Content block list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'ownerId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'fieldId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['contentblocks:read'],
            ]],
            '/content-blocks/show' => ['get' => [
                'summary' => 'Single content block by id or uid',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'uid', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'ownerId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'fieldId', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found'],
                ]),
                'x-required-scopes' => ['contentblocks:read'],
            ]],
            '/sections' => ['get' => ['summary' => 'Section list', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['sections:read']]],
            '/users' => ['get' => [
                'summary' => 'User list',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['active', 'inactive', 'pending', 'suspended', 'locked', 'credentialed', 'all']]],
                    ['in' => 'query', 'name' => 'group', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'q', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
                    ['in' => 'query', 'name' => 'cursor', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'updatedSince', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['in' => 'query', 'name' => 'fields', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated projection list (supports dot-paths).'],
                    ['in' => 'query', 'name' => 'filter', 'schema' => ['type' => 'string'], 'description' => 'Optional comma-separated `path:value` filters. Use `~value` for contains and `*` wildcard.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Endpoint not available'],
                ]),
                'x-required-scopes' => ['users:read'],
                'x-optional-scopes' => ['users:read_sensitive'],
            ]],
            '/users/show' => ['get' => [
                'summary' => 'Single user by id or username',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['in' => 'query', 'name' => 'username', 'schema' => ['type' => 'string']],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Not found or endpoint unavailable'],
                ]),
                'x-required-scopes' => ['users:read'],
                'x-optional-scopes' => ['users:read_sensitive'],
            ]],
            '/sync-state/lag' => ['get' => [
                'summary' => 'List sync-state lag by integration/resource',
                'parameters' => [
                    ['in' => 'query', 'name' => 'integrationKey', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'resourceType', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]],
                ],
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['syncstate:read'],
                'x-legacy-scopes' => ['consumers:read'],
            ]],
            '/sync-state/checkpoint' => ['post' => [
                'summary' => 'Record latest sync-state checkpoint/cursor for lag tracking',
                'requestBody' => $this->openApiJsonObjectRequestBody(),
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['syncstate:write'],
                'x-legacy-scopes' => ['consumers:write'],
            ]],
            '/consumers/lag' => ['get' => [
                'summary' => 'List consumer checkpoint lag by integration/resource (deprecated alias)',
                'deprecated' => true,
                'parameters' => [
                    ['in' => 'query', 'name' => 'integrationKey', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'resourceType', 'schema' => ['type' => 'string']],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]],
                ],
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['consumers:read'],
                'x-alternative-scopes' => ['syncstate:read'],
                'x-replaced-by' => '/sync-state/lag',
            ]],
            '/consumers/checkpoint' => ['post' => [
                'summary' => 'Record latest consumer checkpoint/cursor for lag tracking (deprecated alias)',
                'deprecated' => true,
                'requestBody' => $this->openApiJsonObjectRequestBody(),
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['consumers:write'],
                'x-alternative-scopes' => ['syncstate:write'],
                'x-replaced-by' => '/sync-state/checkpoint',
            ]],
            '/templates' => ['get' => [
                'summary' => 'Canonical integration templates derived from schema/openapi contracts',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'string'], 'description' => 'Optional template id to return a single template.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '404' => ['description' => 'Unknown template id'],
                ]),
                'x-required-scopes' => ['templates:read'],
            ]],
            '/starter-packs' => ['get' => [
                'summary' => 'Copy/paste integration starter packs derived from canonical templates',
                'parameters' => [
                    ['in' => 'query', 'name' => 'id', 'schema' => ['type' => 'string'], 'description' => 'Optional template id to return one starter pack.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '404' => ['description' => 'Unknown starter pack id'],
                ]),
                'x-required-scopes' => ['templates:read'],
            ]],
            '/schema' => ['get' => [
                'summary' => 'Versioned machine-readable endpoint schemas',
                'parameters' => [
                    ['in' => 'query', 'name' => 'version', 'schema' => ['type' => 'string'], 'description' => 'Schema catalog version. Defaults to `v1`.'],
                    ['in' => 'query', 'name' => 'endpoint', 'schema' => ['type' => 'string'], 'description' => 'Optional endpoint key to return a single schema.'],
                ],
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid version or endpoint'],
                ]),
                'x-required-scopes' => ['schema:read'],
            ]],
            '/capabilities' => ['get' => ['summary' => 'Feature and command discovery', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['capabilities:read']]],
            '/openapi.json' => ['get' => ['summary' => 'OpenAPI descriptor', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['openapi:read']]],
            '/control/policies' => ['get' => ['summary' => 'List control policies', 'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]), 'x-required-scopes' => ['control:policies:read']]],
            '/control/policies/upsert' => ['post' => [
                'summary' => 'Create or update a control policy',
                'requestBody' => $this->openApiJsonObjectRequestBody(),
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
                'requestBody' => $this->openApiJsonObjectRequestBody(),
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['control:approvals:request'],
                'x-optional-scopes' => ['control:approvals:write'],
            ]],
            '/control/approvals/decide' => ['post' => [
                'summary' => 'Approve or reject approval request',
                'requestBody' => $this->openApiJsonObjectRequestBody(),
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
            '/control/policy-simulate' => ['post' => [
                'summary' => 'Dry-run policy/approval evaluation for a proposed action',
                'requestBody' => $this->openApiJsonObjectRequestBody(),
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                ]),
                'x-required-scopes' => ['control:actions:simulate'],
            ]],
            '/control/actions/execute' => ['post' => [
                'summary' => 'Execute an idempotent control action',
                'requestBody' => $this->openApiJsonObjectRequestBody(),
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '403' => ['description' => 'Missing required execution scope'],
                ]),
                'x-required-scopes' => ['control:actions:execute'],
                'x-optional-scopes' => ['policy.config.requiredScope'],
                'x-action-payloads' => [
                    'entry.updateDraft' => [
                        'requiredScope' => 'entries:write:draft',
                        'description' => 'Create/update an entry draft without publishing.',
                        'payload' => [
                            'type' => 'object',
                            'required' => ['entryId'],
                            'properties' => [
                                'entryId' => ['type' => 'integer', 'minimum' => 1],
                                'siteId' => ['type' => 'integer', 'minimum' => 1],
                                'draftId' => ['type' => 'integer', 'minimum' => 1],
                                'title' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'draftName' => ['type' => 'string'],
                                'draftNotes' => ['type' => 'string'],
                                'fields' => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
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
            '/webhooks/dlq' => ['get' => [
                'summary' => 'List dead-letter webhook events',
                'parameters' => [
                    ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'string', 'enum' => ['failed', 'queued']]],
                    ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]],
                ],
                'responses' => $this->openApiGuardedResponses(['200' => ['description' => 'OK']]),
                'x-required-scopes' => ['webhooks:dlq:read'],
            ]],
            '/webhooks/dlq/replay' => ['post' => [
                'summary' => 'Replay dead-letter webhook events',
                'requestBody' => $this->openApiJsonObjectRequestBody(),
                'responses' => $this->openApiGuardedResponses([
                    '200' => ['description' => 'OK'],
                    '400' => ['description' => 'Invalid request'],
                    '404' => ['description' => 'Dead-letter event not found'],
                ]),
                'x-required-scopes' => ['webhooks:dlq:replay'],
            ]],
        ];
        if (!$this->isWritesExperimentalEnabled()) {
            foreach ($this->controlOpenApiPaths() as $path) {
                unset($paths[$path]);
            }
        }
        if (!$this->isUsersApiEnabled()) {
            foreach ($this->usersOpenApiPaths() as $path) {
                unset($paths[$path]);
            }
        }
        if (!$this->isAddressesApiEnabled()) {
            foreach ($this->addressesOpenApiPaths() as $path) {
                unset($paths[$path]);
            }
        }
        $paths = array_merge(
            $paths,
            Plugin::getInstance()->getExternalResourceRegistryService()->buildOpenApiPaths(
                fn(array $responses): array => $this->openApiGuardedResponses($responses)
            )
        );

        $apiServerUrl = rtrim((string)Craft::$app->getRequest()->getHostInfo(), '/') . '/agents/v1';

        return $this->jsonResponse([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Agents API',
                'version' => $pluginVersion,
                'description' => 'Governed agent access and control-plane API for read surfaces plus policy/approval/action workflows.',
            ],
            'servers' => [
                ['url' => $apiServerUrl, 'description' => 'Absolute API base (GPT/Actions-friendly)'],
                ['url' => '/agents/v1', 'description' => 'Primary API'],
            ],
            'x-discovery-aliases' => [
                '/capabilities' => '/agents/v1/capabilities',
                '/openapi.json' => '/agents/v1/openapi.json',
            ],
            'x-runtime-profile' => $this->buildRuntimeProfileMetadata($config),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => $this->buildOpenApiSecuritySchemes($config),
                'x-error-codes' => $this->errorTaxonomy(),
            ],
            'security' => $this->buildOpenApiSecurity($config),
        ]);
    }

    public function actionExternalResourceIndex(string $pluginHandle, string $resourceHandle): Response
    {
        $resource = Plugin::getInstance()->getExternalResourceRegistryService()->getResource($pluginHandle, $resourceHandle);
        if ($resource === null) {
            return $this->errorResponse(404, self::ERROR_NOT_FOUND, sprintf('Unknown external resource `%s/%s`.', $pluginHandle, $resourceHandle));
        }

        /** @var \Klick\Agents\external\ExternalResourceDefinition $definition */
        $definition = $resource['definition'];
        $pluginHandle = strtolower(trim($pluginHandle));
        $resourceHandle = strtolower(trim($resourceHandle));
        $scope = $definition->scopeKey($pluginHandle);
        if (($guard = $this->guardRequest($scope)) !== null) {
            return $guard;
        }

        try {
            $result = $resource['provider']->fetchResourceList($definition->handle, $this->externalResourceQueryParams());
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Failed to resolve external resource list: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to read external plugin resource.');
        }

        return $this->jsonResponse($this->buildExternalResourcePayload(
            $pluginHandle,
            $resourceHandle,
            $scope,
            $result
        ));
    }

    public function actionExternalResourceShow(string $pluginHandle, string $resourceHandle, string $resourceId): Response
    {
        $resource = Plugin::getInstance()->getExternalResourceRegistryService()->getResource($pluginHandle, $resourceHandle);
        if ($resource === null) {
            return $this->errorResponse(404, self::ERROR_NOT_FOUND, sprintf('Unknown external resource `%s/%s`.', $pluginHandle, $resourceHandle));
        }

        /** @var \Klick\Agents\external\ExternalResourceDefinition $definition */
        $definition = $resource['definition'];
        if (!$definition->supportsDetail()) {
            return $this->errorResponse(404, self::ERROR_NOT_FOUND, sprintf('External resource `%s/%s` does not expose item lookup.', $pluginHandle, $resourceHandle));
        }

        $pluginHandle = strtolower(trim($pluginHandle));
        $resourceHandle = strtolower(trim($resourceHandle));
        $scope = $definition->scopeKey($pluginHandle);
        if (($guard = $this->guardRequest($scope)) !== null) {
            return $guard;
        }

        try {
            $item = $resource['provider']->fetchResourceItem($definition->handle, trim($resourceId), $this->externalResourceQueryParams());
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Failed to resolve external resource item: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to read external plugin resource.');
        }

        if ($item === null) {
            return $this->errorResponse(404, self::ERROR_NOT_FOUND, sprintf('External resource item `%s` was not found.', $resourceId));
        }

        return $this->jsonResponse($this->buildExternalResourcePayload(
            $pluginHandle,
            $resourceHandle,
            $scope,
            ['data' => $item]
        ));
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

    public function actionAdoptionMetrics(): Response
    {
        if (($guard = $this->guardRequest('adoption:read')) !== null) {
            return $guard;
        }

        $snapshot = Plugin::getInstance()->getAdoptionMetricsService()->getSnapshot($this->defaultTokenScopes());
        return $this->jsonResponse($snapshot);
    }

    public function actionMetrics(): Response
    {
        if (($guard = $this->guardRequest('metrics:read')) !== null) {
            return $guard;
        }

        $snapshot = Plugin::getInstance()->getObservabilityMetricsService()->getMetricsSnapshot();
        return $this->jsonResponse($snapshot);
    }

    public function actionIncidents(): Response
    {
        if (($guard = $this->guardRequest('incidents:read')) !== null) {
            return $guard;
        }

        $severityError = $this->validateEnumQueryParam('severity', ['all', 'warn', 'critical']);
        $limitError = $this->validateIntegerQueryParam('limit', 1, 200);
        $errors = [];
        if ($severityError !== null) {
            $errors[] = $severityError;
        }
        if ($limitError !== null) {
            $errors[] = $limitError;
        }
        if (!empty($errors)) {
            return $this->invalidQueryResponse($errors);
        }

        $request = Craft::$app->getRequest();
        $severity = strtolower(trim((string)$request->getQueryParam('severity', 'all')));
        if ($severity === '') {
            $severity = 'all';
        }
        $limit = (int)$request->getQueryParam('limit', 50);

        $snapshot = Plugin::getInstance()->getIncidentFeedService()->getSnapshot($severity, $limit);
        return $this->jsonResponse($snapshot);
    }

    public function actionLifecycle(): Response
    {
        if (($guard = $this->guardRequest('lifecycle:read')) !== null) {
            return $guard;
        }

        $snapshot = Plugin::getInstance()->getLifecycleGovernanceService()->getSnapshot();
        return $this->jsonResponse($snapshot);
    }

    public function actionDiagnosticsBundle(): Response
    {
        if (($guard = $this->guardRequest('diagnostics:read')) !== null) {
            return $guard;
        }

        try {
            $bundle = Plugin::getInstance()->getDiagnosticsBundleService()->getBundle([
                'source' => 'api',
                'requestId' => $this->getRequestId(),
            ]);
        } catch (\Throwable $e) {
            Craft::error('Unable to generate diagnostics bundle: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to build diagnostics bundle.');
        }

        return $this->jsonResponse($bundle);
    }

    public function actionConsumersLag(): Response
    {
        if (($guard = $this->guardRequestAnyScopes(['syncstate:read', 'consumers:read'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $limitError = $this->validateIntegerQueryParam('limit', 1, 1000);
        if ($limitError !== null) {
            return $this->invalidQueryResponse([$limitError]);
        }

        $service = Plugin::getInstance()->getConsumerLagService();
        $rows = $service->getConsumerLag([
            'integrationKey' => (string)$request->getQueryParam('integrationKey', ''),
            'resourceType' => (string)$request->getQueryParam('resourceType', ''),
        ], (int)$request->getQueryParam('limit', 200));

        return $this->jsonResponse([
            'data' => $rows,
            'meta' => [
                'count' => count($rows),
            ],
        ]);
    }

    public function actionConsumersCheckpoint(): Response
    {
        if (($guard = $this->guardRequestAnyScopes(['syncstate:write', 'consumers:write'], ['POST'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getConsumerLagService();

        try {
            $checkpoint = $service->upsertCheckpoint([
                'credentialId' => (string)($this->authContext['credentialId'] ?? ''),
                'credentialCount' => (int)($this->authContext['credentialCount'] ?? 0),
                'integrationKey' => $request->getBodyParam('integrationKey', ''),
                'resourceType' => $request->getBodyParam('resourceType', ''),
                'cursor' => $request->getBodyParam('cursor', ''),
                'updatedSince' => $request->getBodyParam('updatedSince', ''),
                'checkpointAt' => $request->getBodyParam('checkpointAt', ''),
                'metadata' => $request->getBodyParam('metadata', []),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->misconfiguredResponse($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Unable to record consumer checkpoint: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to record consumer checkpoint.');
        }

        return $this->jsonResponse([
            'data' => $checkpoint,
        ]);
    }

    public function actionControlPolicies(): Response
    {
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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

    public function actionControlPolicySimulate(): Response
    {
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
            return $guard;
        }

        if (($guard = $this->guardRequest('control:actions:simulate', ['POST'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getControlPlaneService();

        try {
            $simulation = $service->simulateAction([
                'actionType' => (string)$request->getBodyParam('actionType', ''),
                'actionRef' => (string)$request->getBodyParam('actionRef', ''),
                'approvalId' => (int)$request->getBodyParam('approvalId', 0),
                'payload' => $request->getBodyParam('payload', []),
                'forceHumanApproval' => (bool)($this->authContext['forceHumanApproval'] ?? false),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->misconfiguredResponse($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Control policy simulation failed: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to simulate control action.');
        }

        return $this->jsonResponse([
            'data' => $simulation,
        ]);
    }

    public function actionControlActionsExecute(): Response
    {
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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
                'forceHumanApproval' => (bool)($this->authContext['forceHumanApproval'] ?? false),
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
        if (($guard = $this->guardWritesExperimentalEnabled()) !== null) {
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

    public function actionWebhookDlqList(): Response
    {
        if (($guard = $this->guardRequest('webhooks:dlq:read')) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getWebhookService();
        $rows = $service->getDeadLetterEvents([
            'status' => (string)$request->getQueryParam('status', ''),
        ], (int)$request->getQueryParam('limit', 100));

        return $this->jsonResponse([
            'data' => $rows,
            'meta' => [
                'count' => count($rows),
            ],
        ]);
    }

    public function actionWebhookDlqReplay(): Response
    {
        if (($guard = $this->guardRequest('webhooks:dlq:replay', ['POST'])) !== null) {
            return $guard;
        }

        $request = Craft::$app->getRequest();
        $service = Plugin::getInstance()->getWebhookService();
        $id = (int)$request->getBodyParam('id', 0);
        $mode = strtolower(trim((string)$request->getBodyParam('mode', '')));

        try {
            if ($mode === 'all') {
                $limit = (int)$request->getBodyParam('limit', 25);
                $result = $service->replayDeadLetterEvents($limit);
                return $this->jsonResponse(['data' => $result]);
            }

            if ($id <= 0) {
                return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, 'Provide `id` or `mode=all`.');
            }

            $event = $service->replayDeadLetterEvent($id);
            if (!is_array($event)) {
                return $this->errorResponse(404, self::ERROR_NOT_FOUND, 'Dead-letter event not found.');
            }

            return $this->jsonResponse(['data' => $event]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->misconfiguredResponse($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Webhook DLQ replay failed: ' . $e->getMessage(), __METHOD__);
            return $this->errorResponse(500, self::ERROR_INTERNAL, 'Failed to replay dead-letter event.');
        }
    }

    private function guardRequest(string $requiredScope = '', array $allowedMethods = ['GET', 'HEAD']): ?Response
    {
        if (($methodResponse = $this->enforceRequestMethod($allowedMethods)) !== null) {
            return $methodResponse;
        }

        if (!Plugin::getInstance()->isAgentsEnabled()) {
            return $this->serviceDisabledResponse();
        }

        $this->incrementRuntimeCounter(ObservabilityMetricsService::COUNTER_REQUESTS);

        $config = $this->getSecurityConfig();

        $preAuthIdentity = $this->buildPreAuthIdentity();
        $preAuthRateLimitResponse = $this->applyRateLimit('preauth', $preAuthIdentity);
        if ($preAuthRateLimitResponse !== null) {
            return $preAuthRateLimitResponse;
        }

        if (($writeContractResponse = $this->enforceWriteRequestContract()) !== null) {
            return $writeContractResponse;
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

        $ipAllowlist = $this->normalizeIpAllowlist($matchedCredential['ipAllowlist'] ?? []);
        if (!empty($ipAllowlist) && !$this->isIpAllowedByCidrs($this->getClientIp(), $ipAllowlist)) {
            return [
                'errorResponse' => $this->unauthorizedResponse('Token is not allowed from this IP address.'),
            ];
        }

        $managedCredentialId = (int)($matchedCredential['managedCredentialId'] ?? 0);
        if ($managedCredentialId > 0) {
            try {
                Plugin::getInstance()->getCredentialService()->recordCredentialUse(
                    $managedCredentialId,
                    (string)($providedToken['source'] ?? 'unknown'),
                    $this->getClientIp(),
                    (string)Craft::$app->getRequest()->getMethod()
                );
            } catch (\Throwable $e) {
                Craft::warning('Unable to update managed credential last-used metadata: ' . $e->getMessage(), __METHOD__);
            }
        }

        return [
            'principalFingerprint' => $matchedCredential['principalFingerprint'],
            'credentialId' => $matchedCredential['id'],
            'credentialCount' => count($credentials),
            'credentialSource' => (string)($matchedCredential['source'] ?? 'env'),
            'managedCredentialId' => isset($matchedCredential['managedCredentialId']) ? (int)$matchedCredential['managedCredentialId'] : null,
            'forceHumanApproval' => (bool)($matchedCredential['forceHumanApproval'] ?? false),
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
                  'scopes' => (array)($credential['scopes'] ?? $this->defaultTokenScopes()),
                  'principalFingerprint' => $principalFingerprint,
                  'source' => (string)($credential['source'] ?? 'env'),
                  'managedCredentialId' => isset($credential['managedCredentialId']) ? (int)$credential['managedCredentialId'] : 0,
                'forceHumanApproval' => (bool)($credential['forceHumanApproval'] ?? false),
                'ipAllowlist' => $this->normalizeIpAllowlist($credential['ipAllowlist'] ?? []),
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
            $this->incrementRuntimeCounter(ObservabilityMetricsService::COUNTER_RATE_LIMIT);
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

    private function enforceWriteRequestContract(): ?Response
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsGet() || $request->getIsHead()) {
            return null;
        }

        $queryToken = trim((string)$request->getQueryParam('apiToken', ''));
        if ($queryToken !== '') {
            return $this->errorResponse(
                400,
                self::ERROR_INVALID_REQUEST,
                'Write requests must authenticate via Authorization or X-Agents-Token headers. Query-token auth is limited to GET/HEAD requests.'
            );
        }

        if (!$this->requestHasJsonContentType()) {
            return $this->errorResponse(
                400,
                self::ERROR_INVALID_REQUEST,
                'Write requests must use Content-Type: application/json.'
            );
        }

        return null;
    }

    private function requestHasJsonContentType(): bool
    {
        $contentType = strtolower(trim((string)Craft::$app->getRequest()->getContentType()));
        if ($contentType === '') {
            return false;
        }

        $mediaType = trim(explode(';', $contentType, 2)[0] ?? '');
        if ($mediaType === 'application/json') {
            return true;
        }

        return str_ends_with($mediaType, '+json');
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

    private function normalizeIpAllowlist(mixed $raw): array
    {
        $tokens = [];
        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $tokens[] = (string)$value;
            }
        } elseif (is_string($raw) || is_numeric($raw)) {
            $stringValue = trim((string)$raw);
            $decoded = json_decode($stringValue, true);
            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    if (!is_string($value) && !is_numeric($value)) {
                        continue;
                    }
                    $tokens[] = (string)$value;
                }
            } else {
                $tokens[] = $stringValue;
            }
        }

        $cidrs = [];
        foreach ($tokens as $token) {
            $chunks = preg_split('/[\s,]+/', trim($token)) ?: [];
            foreach ($chunks as $chunk) {
                $candidate = trim((string)$chunk);
                if ($candidate === '') {
                    continue;
                }
                $cidrs[] = $candidate;
            }
        }

        $normalized = [];
        foreach ($cidrs as $cidr) {
            if (str_contains($cidr, '/')) {
                [$ip, $prefixRaw] = explode('/', $cidr, 2);
                $ip = trim($ip);
                $prefixRaw = trim($prefixRaw);
            } else {
                $ip = trim($cidr);
                $prefixRaw = '';
            }

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $isIpv6 = str_contains($ip, ':');
            if ($prefixRaw === '') {
                $prefix = $isIpv6 ? 128 : 32;
            } elseif (preg_match('/^\d+$/', $prefixRaw) === 1) {
                $prefix = (int)$prefixRaw;
            } else {
                continue;
            }

            $maxPrefix = $isIpv6 ? 128 : 32;
            if ($prefix < 0 || $prefix > $maxPrefix) {
                continue;
            }

            $normalized[] = sprintf('%s/%d', $ip, $prefix);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    private function isIpAllowedByCidrs(string $ip, array $cidrs): bool
    {
        if (empty($cidrs)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            if (!is_string($cidr)) {
                continue;
            }

            if ($this->cidrContainsIp($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function cidrContainsIp(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$network, $prefixRaw] = explode('/', $cidr, 2);
        $network = trim($network);
        $prefixRaw = trim($prefixRaw);
        if (preg_match('/^\d+$/', $prefixRaw) !== 1) {
            return false;
        }

        $prefix = (int)$prefixRaw;
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false) {
            return false;
        }

        if (strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $maxBits = strlen($networkBinary) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        $ipByte = ord($ipBinary[$fullBytes]);
        $networkByte = ord($networkBinary[$fullBytes]);

        return ($ipByte & $mask) === ($networkByte & $mask);
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

        $requiredScope = strtolower(trim($scope));
        if ($requiredScope === '') {
            return true;
        }

        $scopes = [];
        foreach ((array)($this->authContext['scopes'] ?? []) as $grantedScope) {
            if (!is_string($grantedScope) && !is_numeric($grantedScope)) {
                continue;
            }

            $normalizedGrantedScope = strtolower(trim((string)$grantedScope));
            if ($normalizedGrantedScope === '') {
                continue;
            }
            $scopes[] = $normalizedGrantedScope;
        }

        $scopes = array_values(array_unique($scopes));
        if (in_array('*', $scopes, true) || in_array($requiredScope, $scopes, true)) {
            return true;
        }

        if ($requiredScope === 'entries:write:draft' && in_array('entries:write', $scopes, true)) {
            return true;
        }

        if ($requiredScope === 'entries:write' && in_array('entries:write:draft', $scopes, true)) {
            return true;
        }

        return false;
    }

    private function getGrantedScopes(): array
    {
        $scopes = $this->authContext['scopes'] ?? $this->getSecurityConfig()['tokenScopes'];
        $normalized = [];
        foreach ((array)$scopes as $scope) {
            if (!is_string($scope) && !is_numeric($scope)) {
                continue;
            }

            $value = strtolower(trim((string)$scope));
            if ($value === '') {
                continue;
            }

            if ($value === 'entries:write') {
                $value = 'entries:write:draft';
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        if (!$this->isWritesExperimentalEnabled()) {
            $normalized = array_values(array_filter(
                $normalized,
                fn(string $scope): bool => !in_array($scope, $this->governedWriteScopeKeys(), true)
            ));
        }
        if (!$this->isUsersApiEnabled()) {
            $normalized = array_values(array_filter(
                $normalized,
                fn(string $scope): bool => !in_array($scope, $this->userScopeKeys(), true)
            ));
        }
        if (!$this->isAddressesApiEnabled()) {
            $normalized = array_values(array_filter(
                $normalized,
                fn(string $scope): bool => !in_array($scope, $this->addressesScopeKeys(), true)
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
            'adoption:read' => 'Read adoption instrumentation snapshot (`/adoption/metrics`).',
            'metrics:read' => 'Read observability metrics snapshot (`/metrics`).',
            'incidents:read' => 'Read redacted runtime incident snapshot (`/incidents`).',
            'lifecycle:read' => 'Read agent lifecycle governance snapshot (`/lifecycle`).',
            'diagnostics:read' => 'Read one-click diagnostics support bundle (`/diagnostics/bundle`).',
            'products:read' => 'Read product snapshot endpoints.',
            'variants:read' => 'Read variant list and lookup endpoints.',
            'subscriptions:read' => 'Read subscription list and lookup endpoints.',
            'transfers:read' => 'Read transfer list and lookup endpoints.',
            'donations:read' => 'Read donation list and lookup endpoints.',
            'orders:read' => 'Read order metadata endpoints.',
            'orders:read_sensitive' => 'Unredacted order PII/financial detail fields.',
            'entries:read' => 'Read live content entry endpoints.',
            'entries:read_all_statuses' => 'Read non-live entries/statuses and unrestricted detail lookup.',
            'entries:write:draft' => 'Create/update entry drafts through governed control actions (for example `entry.updateDraft`).',
            'entries:write' => 'Deprecated alias for `entries:write:draft`.',
            'assets:read' => 'Read asset list and lookup endpoints.',
            'categories:read' => 'Read category list and lookup endpoints.',
            'tags:read' => 'Read tag list and lookup endpoints.',
            'globalsets:read' => 'Read global set list and lookup endpoints.',
            'addresses:read' => 'Read address list and lookup endpoints.',
            'addresses:read_sensitive' => 'Unredacted address PII detail fields.',
            'contentblocks:read' => 'Read content block list and lookup endpoints.',
            'changes:read' => 'Read unified cross-resource incremental changes feed.',
            'sections:read' => 'Read section list endpoint.',
            'users:read' => 'Read user list and lookup endpoints.',
            'users:read_sensitive' => 'Unredacted user email/profile detail fields.',
            'syncstate:read' => 'Read per-integration sync-state lag/checkpoint status.',
            'syncstate:write' => 'Record per-integration sync-state checkpoints for lag tracking.',
            'consumers:read' => 'Deprecated alias for `syncstate:read`.',
            'consumers:write' => 'Deprecated alias for `syncstate:write`.',
            'templates:read' => 'Read canonical integration templates derived from schema/openapi contracts.',
            'schema:read' => 'Read machine-readable endpoint schemas for a specific version.',
            'capabilities:read' => 'Read capabilities descriptor endpoint.',
            'openapi:read' => 'Read OpenAPI descriptor endpoint.',
            'control:policies:read' => 'Read control action policies.',
            'control:policies:write' => 'Create and update control action policies.',
            'control:approvals:read' => 'Read approval request queue.',
            'control:approvals:request' => 'Create control approval requests (agent/orchestrator flow).',
            'control:approvals:decide' => 'Approve or reject pending control approvals (human sign-off flow).',
            'control:approvals:write' => 'Legacy combined scope for request+decide control approvals.',
            'control:executions:read' => 'Read control action execution ledger.',
            'control:actions:simulate' => 'Run dry-run policy and approval evaluation without execution.',
            'control:actions:execute' => 'Execute idempotent control actions.',
            'control:audit:read' => 'Read immutable control-plane audit log.',
            'webhooks:dlq:read' => 'Read failed webhook dead-letter events.',
            'webhooks:dlq:replay' => 'Replay failed webhook dead-letter events.',
        ];
        if (!$this->isWritesExperimentalEnabled()) {
            foreach ($this->governedWriteScopeKeys() as $scope) {
                unset($scopes[$scope]);
            }
        }
        if (!$this->isUsersApiEnabled()) {
            foreach ($this->userScopeKeys() as $scope) {
                unset($scopes[$scope]);
            }
        }
        if (!$this->isAddressesApiEnabled()) {
            foreach ($this->addressesScopeKeys() as $scope) {
                unset($scopes[$scope]);
            }
        }
        if (!$this->isCommerceApiEnabled()) {
            foreach ($this->commerceScopeKeys() as $scope) {
                unset($scopes[$scope]);
            }
        }
        $scopes = array_merge(
            $scopes,
            Plugin::getInstance()->getExternalResourceRegistryService()->getCapabilityScopes()
        );
        ksort($scopes);

        return $scopes;
    }

    private function isWritesExperimentalEnabled(): bool
    {
        return Plugin::getInstance()->isWritesExperimentalEnabled();
    }

    private function isUsersApiEnabled(): bool
    {
        return Plugin::getInstance()->isUsersApiEnabled();
    }

    private function isAddressesApiEnabled(): bool
    {
        return Plugin::getInstance()->isAddressesApiEnabled();
    }

    private function isCommerceApiEnabled(): bool
    {
        return Plugin::getInstance()->isCommercePluginEnabled();
    }

    private function guardWritesExperimentalEnabled(): ?Response
    {
        if ($this->isWritesExperimentalEnabled()) {
            return null;
        }

        return $this->errorResponse(404, self::ERROR_NOT_FOUND, 'Endpoint is not available.');
    }

    private function guardUsersApiEnabled(): ?Response
    {
        if ($this->isUsersApiEnabled()) {
            return null;
        }

        return $this->errorResponse(404, self::ERROR_NOT_FOUND, 'Endpoint is not available.');
    }

    private function guardAddressesApiEnabled(): ?Response
    {
        if ($this->isAddressesApiEnabled()) {
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
            '/control/policy-simulate',
            '/control/actions/execute',
            '/control/audit',
        ];
    }

    private function usersOpenApiPaths(): array
    {
        return [
            '/users',
            '/users/show',
        ];
    }

    private function addressesOpenApiPaths(): array
    {
        return [
            '/addresses',
            '/addresses/show',
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
            'control:actions:simulate',
            'control:actions:execute',
            'control:audit:read',
        ];
    }

    private function governedWriteScopeKeys(): array
    {
        return array_merge(
            [
                'entries:write:draft',
                'entries:write',
            ],
            $this->controlScopeKeys()
        );
    }

    private function userScopeKeys(): array
    {
        return [
            'users:read',
            'users:read_sensitive',
        ];
    }

    private function addressesScopeKeys(): array
    {
        return [
            'addresses:read',
            'addresses:read_sensitive',
        ];
    }

    private function commerceScopeKeys(): array
    {
        return [
            'products:read',
            'variants:read',
            'subscriptions:read',
            'transfers:read',
            'donations:read',
            'orders:read',
            'orders:read_sensitive',
            'addresses:read',
            'addresses:read_sensitive',
        ];
    }

    private function defaultTokenScopes(): array
    {
        return array_values((array)($this->getSecurityConfig()['tokenScopes'] ?? self::DEFAULT_TOKEN_SCOPES));
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
            'environment' => (string)($config['environment'] ?? ''),
            'environmentProfile' => (string)($config['environmentProfile'] ?? ''),
            'environmentProfileSource' => (string)($config['environmentProfileSource'] ?? ''),
            'profileDefaultsApplied' => (bool)($config['profileDefaultsApplied'] ?? false),
            'effectivePolicyVersion' => (string)($config['effectivePolicyVersion'] ?? ''),
            'methods' => [],
        ];

        if (!$config['requireToken']) {
            return $auth;
        }

        $auth['methods'][] = 'Authorization: Bearer <token>';
        $auth['methods'][] = 'X-Agents-Token: <token>';
        if ($config['allowQueryToken']) {
            $auth['queryParam'] = 'apiToken';
            $auth['queryParamAllowedMethods'] = ['GET', 'HEAD'];
        }

        return $auth;
    }

    private function buildRuntimeProfileMetadata(array $config): array
    {
        return [
            'environment' => (string)($config['environment'] ?? ''),
            'environmentProfile' => (string)($config['environmentProfile'] ?? ''),
            'environmentProfileSource' => (string)($config['environmentProfileSource'] ?? ''),
            'profileDefaultsApplied' => (bool)($config['profileDefaultsApplied'] ?? false),
            'profileDefaultsAppliedFields' => array_values((array)($config['profileDefaultsAppliedFields'] ?? [])),
            'effectivePolicyVersion' => (string)($config['effectivePolicyVersion'] ?? ''),
        ];
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
            $schemes['queryToken'] = [
                'type' => 'apiKey',
                'in' => 'query',
                'name' => 'apiToken',
                'description' => 'Read-only transport for GET/HEAD requests only when query-token auth is enabled.',
                'x-allowed-methods' => ['GET', 'HEAD'],
            ];
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

        return $this->openApiResponseMap($responses, $guardResponses);
    }

    private function openApiJsonObjectRequestBody(bool $required = true): array
    {
        return [
            'required' => $required,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
        ];
    }

    private function openApiResponseMap(array ...$responseMaps): array
    {
        $merged = [];
        foreach ($responseMaps as $map) {
            foreach ($map as $statusCode => $definition) {
                $normalizedDefinition = is_array($definition)
                    ? $definition
                    : ['description' => (string)$definition];
                $merged[(string)$statusCode] = $normalizedDefinition;
            }
        }

        return $merged;
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
            'managedCredentialId' => isset($this->authContext['managedCredentialId']) ? (int)$this->authContext['managedCredentialId'] : null,
            'requestId' => $this->getRequestId(),
            'ipAddress' => $this->getClientIp(),
        ];
    }

    private function unauthorizedResponse(string $message): Response
    {
        $this->incrementRuntimeCounter(ObservabilityMetricsService::COUNTER_AUTH_FAILURES);
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
        $this->incrementRuntimeCounter(ObservabilityMetricsService::COUNTER_FORBIDDEN);
        return $this->errorResponse(403, self::ERROR_FORBIDDEN, $message, [
            'requiredScope' => $requiredScope,
        ]);
    }

    private function invalidQueryResponse(array $errors): Response
    {
        $details = [];
        foreach ($errors as $error) {
            $message = trim((string)$error);
            if ($message === '') {
                continue;
            }
            $details[] = $message;
        }
        $details = array_values(array_unique($details));
        $message = $details[0] ?? 'Invalid query parameters.';

        return $this->errorResponse(400, self::ERROR_INVALID_REQUEST, $message, [
            'details' => $details,
        ]);
    }

    private function validateProjectionAndFilterQueryParams(): array
    {
        $request = Craft::$app->getRequest();
        $errors = [];

        $rawFields = (string)$request->getQueryParam('fields', '');
        if (trim($rawFields) !== '') {
            $hasField = false;
            $tokens = preg_split('/[\s,]+/', trim($rawFields)) ?: [];
            foreach ($tokens as $token) {
                $field = trim((string)$token);
                if ($field === '') {
                    continue;
                }

                $hasField = true;
                if (preg_match('/^[a-zA-Z0-9_.-]+$/', $field) !== 1) {
                    $errors[] = sprintf('Invalid `fields` token `%s`; allowed pattern `[a-zA-Z0-9_.-]+`.', $field);
                }
            }

            if (!$hasField) {
                $errors[] = '`fields` must include at least one field path.';
            }
        }

        $rawFilter = (string)$request->getQueryParam('filter', '');
        if (trim($rawFilter) !== '') {
            $hasFilter = false;
            $tokens = preg_split('/\s*,\s*/', trim($rawFilter)) ?: [];
            foreach ($tokens as $token) {
                $candidate = trim((string)$token);
                if ($candidate === '') {
                    $errors[] = 'Invalid `filter` expression; empty segments are not allowed.';
                    continue;
                }

                if (!str_contains($candidate, ':')) {
                    $errors[] = sprintf('Invalid `filter` expression `%s`; expected `path:value`.', $candidate);
                    continue;
                }

                [$path, $value] = explode(':', $candidate, 2);
                $path = trim($path);
                $value = trim($value);

                if ($path === '' || $value === '') {
                    $errors[] = sprintf('Invalid `filter` expression `%s`; expected non-empty `path:value`.', $candidate);
                    continue;
                }

                if (preg_match('/^[a-zA-Z0-9_.-]+$/', $path) !== 1) {
                    $errors[] = sprintf('Invalid `filter` path `%s`; allowed pattern `[a-zA-Z0-9_.-]+`.', $path);
                    continue;
                }

                $hasFilter = true;
            }

            if (!$hasFilter) {
                $errors[] = '`filter` must include at least one valid `path:value` expression.';
            }
        }

        return $errors;
    }

    private function validateIntegerQueryParam(string $name, int $min, ?int $max): ?string
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getQueryParam($name, null);
        if ($raw === null) {
            return null;
        }

        if (is_array($raw) || is_object($raw)) {
            return sprintf('Invalid `%s`; expected integer value.', $name);
        }

        $valueRaw = trim((string)$raw);
        if ($valueRaw === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $valueRaw) !== 1) {
            return sprintf('Invalid `%s`; expected integer value.', $name);
        }

        $value = (int)$valueRaw;
        if ($value < $min) {
            if ($max !== null) {
                return sprintf('Invalid `%s`; expected integer between %d and %d.', $name, $min, $max);
            }

            return sprintf('Invalid `%s`; expected integer >= %d.', $name, $min);
        }

        if ($max !== null && $value > $max) {
            return sprintf('Invalid `%s`; expected integer between %d and %d.', $name, $min, $max);
        }

        return null;
    }

    private function validateBooleanQueryParam(string $name): ?string
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getQueryParam($name, null);
        if ($raw === null) {
            return null;
        }

        if (is_array($raw) || is_object($raw)) {
            return sprintf('Invalid `%s`; expected boolean value.', $name);
        }

        if (is_bool($raw)) {
            return null;
        }

        $valueRaw = strtolower(trim((string)$raw));
        if ($valueRaw === '') {
            return null;
        }

        if (!in_array($valueRaw, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return sprintf('Invalid `%s`; expected boolean value (`1|0|true|false|yes|no|on|off`).', $name);
        }

        return null;
    }

    private function parseBooleanQueryParam(string $name, bool $default = false): bool
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getQueryParam($name, null);
        if ($raw === null) {
            return $default;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $valueRaw = strtolower(trim((string)$raw));
        if ($valueRaw === '') {
            return $default;
        }

        return in_array($valueRaw, ['1', 'true', 'yes', 'on'], true);
    }

    private function validateEnumQueryParam(string $name, array $allowedValues): ?string
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getQueryParam($name, null);
        if ($raw === null) {
            return null;
        }

        if (is_array($raw) || is_object($raw)) {
            return sprintf('Invalid `%s`; expected scalar value.', $name);
        }

        $valueRaw = trim((string)$raw);
        if ($valueRaw === '') {
            return null;
        }

        $allowedMap = [];
        foreach ($allowedValues as $allowedValue) {
            $allowedMap[strtolower((string)$allowedValue)] = true;
        }

        if (!isset($allowedMap[strtolower($valueRaw)])) {
            return sprintf(
                'Invalid `%s` value `%s`. Allowed values: %s.',
                $name,
                $valueRaw,
                implode(', ', $allowedValues)
            );
        }

        return null;
    }

    private function validatePatternQueryParam(string $name, string $pattern, string $label): ?string
    {
        $request = Craft::$app->getRequest();
        $raw = $request->getQueryParam($name, null);
        if ($raw === null) {
            return null;
        }

        if (is_array($raw) || is_object($raw)) {
            return sprintf('Invalid `%s`; expected scalar value.', $label);
        }

        $valueRaw = trim((string)$raw);
        if ($valueRaw === '') {
            return null;
        }

        if (preg_match($pattern, $valueRaw) !== 1) {
            return sprintf('Invalid `%s`; allowed characters are letters, digits, `.`, `_`, and `-`.', $label);
        }

        return null;
    }

    private function applyListProjectionAndFilters(array $payload, string $dataKey = 'data'): array
    {
        $request = Craft::$app->getRequest();
        $fields = $this->parseFieldsParam((string)$request->getQueryParam('fields', ''));
        $filters = $this->parseFilterParam((string)$request->getQueryParam('filter', ''));

        if (empty($fields) && empty($filters)) {
            return $payload;
        }

        if (!array_key_exists($dataKey, $payload) || !is_array($payload[$dataKey])) {
            return $payload;
        }

        $data = $payload[$dataKey];
        if ($this->isListArray($data)) {
            $processed = [];
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (!$this->matchesFilterExpressions($row, $filters)) {
                    continue;
                }

                $processed[] = $this->projectRowByFields($row, $fields);
            }
            $payload[$dataKey] = $processed;

            if (isset($payload['meta']) && is_array($payload['meta'])) {
                $payload['meta']['count'] = count($processed);
            }
            if (isset($payload['page']) && is_array($payload['page'])) {
                $payload['page']['count'] = count($processed);
            }

            return $payload;
        }

        if (!$this->matchesFilterExpressions($data, $filters)) {
            $payload[$dataKey] = null;
            return $payload;
        }

        $payload[$dataKey] = $this->projectRowByFields($data, $fields);
        return $payload;
    }

    private function parseFieldsParam(string $raw): array
    {
        $tokens = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $fields = [];
        foreach ($tokens as $token) {
            $field = trim((string)$token);
            if ($field === '') {
                continue;
            }

            if (preg_match('/^[a-zA-Z0-9_.-]+$/', $field) !== 1) {
                continue;
            }

            $fields[] = $field;
        }

        $fields = array_values(array_unique($fields));
        sort($fields);
        return $fields;
    }

    private function parseFilterParam(string $raw): array
    {
        $tokens = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $filters = [];
        foreach ($tokens as $token) {
            $candidate = trim((string)$token);
            if ($candidate === '' || !str_contains($candidate, ':')) {
                continue;
            }

            [$path, $value] = explode(':', $candidate, 2);
            $path = trim($path);
            $value = trim($value);
            if ($path === '' || $value === '') {
                continue;
            }

            if (preg_match('/^[a-zA-Z0-9_.-]+$/', $path) !== 1) {
                continue;
            }

            $filters[] = ['path' => $path, 'value' => $value];
        }

        return $filters;
    }

    private function matchesFilterExpressions(array $row, array $filters): bool
    {
        if (empty($filters)) {
            return true;
        }

        foreach ($filters as $filter) {
            $path = (string)($filter['path'] ?? '');
            $expected = (string)($filter['value'] ?? '');
            if ($path === '' || $expected === '') {
                continue;
            }

            $exists = false;
            $actual = $this->getPathValue($row, $path, $exists);
            if (!$exists) {
                return false;
            }

            if (!$this->matchesFilterValue($actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function matchesFilterValue(mixed $actual, string $expected): bool
    {
        if (is_array($actual) || is_object($actual)) {
            return false;
        }

        $actualString = strtolower(trim((string)$actual));
        $expectedNormalized = strtolower(trim($expected));
        if ($expectedNormalized === '') {
            return true;
        }

        if (str_starts_with($expectedNormalized, '~')) {
            $needle = substr($expectedNormalized, 1);
            return $needle === '' || str_contains($actualString, $needle);
        }

        if (str_contains($expectedNormalized, '*')) {
            $escaped = preg_quote($expectedNormalized, '/');
            $regex = '/^' . str_replace('\\*', '.*', $escaped) . '$/i';
            return preg_match($regex, (string)$actual) === 1;
        }

        if (in_array($expectedNormalized, ['true', 'false'], true)) {
            return ((bool)$actual) === ($expectedNormalized === 'true');
        }

        if (is_numeric($actual) && is_numeric($expectedNormalized)) {
            return (float)$actual === (float)$expectedNormalized;
        }

        return $actualString === $expectedNormalized;
    }

    private function projectRowByFields(array $row, array $fields): array
    {
        if (empty($fields)) {
            return $row;
        }

        $projected = [];
        foreach ($fields as $fieldPath) {
            $exists = false;
            $value = $this->getPathValue($row, $fieldPath, $exists);
            if (!$exists) {
                continue;
            }

            $this->setPathValue($projected, $fieldPath, $value);
        }

        return $projected;
    }

    private function getPathValue(array $row, string $path, bool &$exists): mixed
    {
        $segments = array_filter(explode('.', $path), static fn(string $segment): bool => $segment !== '');
        if (empty($segments)) {
            $exists = false;
            return null;
        }

        $current = $row;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $exists = false;
                return null;
            }

            $current = $current[$segment];
        }

        $exists = true;
        return $current;
    }

    private function setPathValue(array &$target, string $path, mixed $value): void
    {
        $segments = array_filter(explode('.', $path), static fn(string $segment): bool => $segment !== '');
        if (empty($segments)) {
            return;
        }

        $pointer = &$target;
        foreach ($segments as $index => $segment) {
            $isLast = $index === (count($segments) - 1);
            if ($isLast) {
                $pointer[$segment] = $value;
                continue;
            }

            if (!isset($pointer[$segment]) || !is_array($pointer[$segment])) {
                $pointer[$segment] = [];
            }

            $pointer = &$pointer[$segment];
        }
    }

    private function isListArray(array $value): bool
    {
        return array_is_list($value);
    }

    private function versionedSchemaCatalogs(): array
    {
        $catalogs = [
            'v1' => [
                'products.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/products',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'q' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'sort' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'lowStock' => ['type' => 'boolean'],
                            'lowStockThreshold' => ['type' => 'integer'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => ['type' => 'integer'],
                                        'title' => ['type' => 'string'],
                                        'slug' => ['type' => 'string'],
                                        'status' => ['type' => 'string'],
                                        'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                                        'hasUnlimitedStock' => ['type' => 'boolean'],
                                        'totalStock' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'variants.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/variants',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'q' => ['type' => 'string'],
                            'sku' => ['type' => 'string'],
                            'productId' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => ['type' => 'integer'],
                                        'productId' => ['type' => 'integer'],
                                        'sku' => ['type' => 'string'],
                                        'title' => ['type' => 'string'],
                                        'status' => ['type' => 'string'],
                                        'stock' => ['type' => 'integer'],
                                        'hasUnlimitedStock' => ['type' => 'boolean'],
                                        'isAvailable' => ['type' => 'boolean'],
                                        'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                                    ],
                                ],
                            ],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'variants.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/variants/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'sku' => ['type' => 'string'],
                            'productId' => ['type' => 'integer'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'subscriptions.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/subscriptions',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'q' => ['type' => 'string'],
                            'reference' => ['type' => 'string'],
                            'userId' => ['type' => 'integer'],
                            'planId' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'subscriptions.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/subscriptions/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'reference' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'transfers.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/transfers',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'q' => ['type' => 'string'],
                            'originLocationId' => ['type' => 'integer'],
                            'destinationLocationId' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'transfers.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/transfers/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'donations.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/donations',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'q' => ['type' => 'string'],
                            'sku' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'donations.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/donations/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'sku' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'orders.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/orders',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'lastDays' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'orders.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/orders/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'number' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'entries.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/entries',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'section' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'search' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'assets.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/assets',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'q' => ['type' => 'string'],
                            'volume' => ['type' => 'string'],
                            'kind' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'assets.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/assets/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'filename' => ['type' => 'string'],
                            'volume' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'categories.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/categories',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'q' => ['type' => 'string'],
                            'group' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'categories.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/categories/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'slug' => ['type' => 'string'],
                            'group' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'tags.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/tags',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'q' => ['type' => 'string'],
                            'group' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'tags.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/tags/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'slug' => ['type' => 'string'],
                            'group' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'globalsets.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/global-sets',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'q' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'globalsets.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/global-sets/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'handle' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'addresses.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/addresses',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'q' => ['type' => 'string'],
                            'ownerId' => ['type' => 'integer'],
                            'countryCode' => ['type' => 'string'],
                            'postalCode' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'addresses.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/addresses/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'uid' => ['type' => 'string'],
                            'ownerId' => ['type' => 'integer'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'contentblocks.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/content-blocks',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'q' => ['type' => 'string'],
                            'ownerId' => ['type' => 'integer'],
                            'fieldId' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'contentblocks.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/content-blocks/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'uid' => ['type' => 'string'],
                            'ownerId' => ['type' => 'integer'],
                            'fieldId' => ['type' => 'integer'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'users.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/users',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'group' => ['type' => 'string'],
                            'q' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'users.show' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/users/show',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'username' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'auth.whoami' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/auth/whoami',
                    'query' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'service' => ['type' => 'string'],
                            'version' => ['type' => 'string'],
                            'principal' => ['type' => 'object'],
                            'authorization' => ['type' => 'object'],
                            'runtimeProfile' => ['type' => 'object'],
                        ],
                    ],
                ],
                'syncstate.checkpoint' => [
                    'method' => 'POST',
                    'path' => '/agents/v1/sync-state/checkpoint',
                    'body' => [
                        'type' => 'object',
                        'properties' => [
                            'integrationKey' => ['type' => 'string'],
                            'resourceType' => ['type' => 'string'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'checkpointAt' => ['type' => 'string', 'format' => 'date-time'],
                            'metadata' => ['type' => 'object'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                        ],
                    ],
                ],
                'consumers.checkpoint' => [
                    'method' => 'POST',
                    'path' => '/agents/v1/consumers/checkpoint',
                    'deprecated' => true,
                    'body' => [
                        'type' => 'object',
                        'properties' => [
                            'integrationKey' => ['type' => 'string'],
                            'resourceType' => ['type' => 'string'],
                            'cursor' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'checkpointAt' => ['type' => 'string', 'format' => 'date-time'],
                            'metadata' => ['type' => 'object'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                        ],
                    ],
                ],
                'changes.feed' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/changes',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'types' => ['type' => 'string'],
                            'updatedSince' => ['type' => 'string', 'format' => 'date-time'],
                            'cursor' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                            'fields' => ['type' => 'string'],
                            'filter' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'page' => ['type' => 'object'],
                        ],
                    ],
                ],
                'schema.catalog' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/schema',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'version' => ['type' => 'string'],
                            'endpoint' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'version' => ['type' => 'string'],
                            'runtimeProfile' => ['type' => 'object'],
                            'count' => ['type' => 'integer'],
                            'endpoint' => ['type' => 'string'],
                            'schema' => ['type' => 'object'],
                            'schemas' => ['type' => 'object'],
                        ],
                    ],
                ],
                'templates.catalog' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/templates',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'service' => ['type' => 'string'],
                            'version' => ['type' => 'string'],
                            'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'basePath' => ['type' => 'string'],
                            'contracts' => ['type' => 'object'],
                            'count' => ['type' => 'integer'],
                            'templates' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'template' => ['type' => 'object'],
                        ],
                    ],
                ],
                'starterpacks.catalog' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/starter-packs',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'service' => ['type' => 'string'],
                            'version' => ['type' => 'string'],
                            'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'basePath' => ['type' => 'string'],
                            'contracts' => ['type' => 'object'],
                            'count' => ['type' => 'integer'],
                            'starterPacks' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'starterPack' => ['type' => 'object'],
                        ],
                    ],
                ],
                'control.approvals.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/control/approvals',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'actionType' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'control.approvals.request' => [
                    'method' => 'POST',
                    'path' => '/agents/v1/control/approvals/request',
                    'body' => [
                        'type' => 'object',
                        'properties' => [
                            'actionType' => ['type' => 'string'],
                            'actionRef' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'metadata' => ['type' => 'object'],
                            'payload' => ['type' => 'object'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                        ],
                    ],
                ],
                'control.approvals.decide' => [
                    'method' => 'POST',
                    'path' => '/agents/v1/control/approvals/decide',
                    'body' => [
                        'type' => 'object',
                        'properties' => [
                            'approvalId' => ['type' => 'integer'],
                            'decision' => ['type' => 'string'],
                            'decisionReason' => ['type' => 'string'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                        ],
                    ],
                ],
                'control.actions.execute' => [
                    'method' => 'POST',
                    'path' => '/agents/v1/control/actions/execute',
                    'body' => [
                        'type' => 'object',
                        'required' => ['actionType', 'idempotencyKey'],
                        'properties' => [
                            'actionType' => ['type' => 'string'],
                            'actionRef' => ['type' => 'string'],
                            'approvalId' => ['type' => 'integer'],
                            'idempotencyKey' => ['type' => 'string'],
                            'payload' => ['type' => 'object'],
                        ],
                        'xActionPayloads' => [
                            'entry.updateDraft' => [
                                'requiredScope' => 'entries:write:draft',
                                'description' => 'Create/update an entry draft without publishing.',
                                'payload' => [
                                    'type' => 'object',
                                    'required' => ['entryId'],
                                    'properties' => [
                                        'entryId' => ['type' => 'integer', 'minimum' => 1],
                                        'siteId' => ['type' => 'integer', 'minimum' => 1],
                                        'draftId' => ['type' => 'integer', 'minimum' => 1],
                                        'title' => ['type' => 'string'],
                                        'slug' => ['type' => 'string'],
                                        'draftName' => ['type' => 'string'],
                                        'draftNotes' => ['type' => 'string'],
                                        'fields' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object'],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'control.executions.list' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/control/executions',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'actionType' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'metrics.snapshot' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/metrics',
                    'query' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'service' => ['type' => 'string'],
                            'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'format' => ['type' => 'string'],
                            'metrics' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ],
                ],
                'incidents.snapshot' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/incidents',
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'severity' => ['type' => 'string', 'enum' => ['all', 'warn', 'critical']],
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                        ],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'service' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'status' => ['type' => 'string'],
                            'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
                'lifecycle.snapshot' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/lifecycle',
                    'query' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'service' => ['type' => 'string'],
                            'version' => ['type' => 'string'],
                            'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'status' => ['type' => 'string'],
                            'runtime' => ['type' => 'object'],
                            'thresholds' => ['type' => 'object'],
                            'summary' => ['type' => 'object'],
                            'topRisks' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'agents' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ],
                ],
                'diagnostics.bundle' => [
                    'method' => 'GET',
                    'path' => '/agents/v1/diagnostics/bundle',
                    'query' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                    'response' => [
                        'type' => 'object',
                        'properties' => [
                            'service' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'version' => ['type' => 'string'],
                            'generatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'summary' => ['type' => 'object'],
                            'checks' => ['type' => 'object'],
                            'snapshots' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];

        if (!$this->isUsersApiEnabled()) {
            unset($catalogs['v1']['users.list'], $catalogs['v1']['users.show']);
        }
        if (!$this->isAddressesApiEnabled()) {
            unset($catalogs['v1']['addresses.list'], $catalogs['v1']['addresses.show']);
        }
        if (!$this->isWritesExperimentalEnabled()) {
            unset(
                $catalogs['v1']['control.approvals.list'],
                $catalogs['v1']['control.approvals.request'],
                $catalogs['v1']['control.approvals.decide'],
                $catalogs['v1']['control.actions.execute'],
                $catalogs['v1']['control.executions.list']
            );
        }
        $catalogs['v1'] = array_merge(
            $catalogs['v1'],
            Plugin::getInstance()->getExternalResourceRegistryService()->buildSchemaCatalog('/agents/v1')
        );
        ksort($catalogs['v1']);

        return $catalogs;
    }

    private function externalResourceQueryParams(): array
    {
        $queryParams = Craft::$app->getRequest()->getQueryParams();
        if (!is_array($queryParams)) {
            return [];
        }

        unset($queryParams['apiToken']);
        return $queryParams;
    }

    private function buildExternalResourcePayload(string $pluginHandle, string $resourceHandle, string $scope, array $result): array
    {
        $payload = [
            'service' => 'agents',
            'version' => $this->resolvePluginVersion(),
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'provider' => [
                'plugin' => strtolower(trim($pluginHandle)),
                'resource' => strtolower(trim($resourceHandle)),
                'scope' => strtolower(trim($scope)),
            ],
            'data' => $result['data'] ?? [],
            'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
        ];

        if (isset($result['page']) && is_array($result['page'])) {
            $payload['page'] = $result['page'];
        }

        return $payload;
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

    private function jsonResponse(array $payload): Response
    {
        $response = $this->asJson($payload);
        $this->applyNoStoreHeaders($response);
        return $this->attachRequestId($response);
    }

    private function errorResponse(int $statusCode, string $code, string $message, array $extra = []): Response
    {
        if ($statusCode >= 500) {
            $this->incrementRuntimeCounter(ObservabilityMetricsService::COUNTER_ERRORS_5XX);
        }

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

    private function incrementRuntimeCounter(string $cacheKey, int $delta = 1): void
    {
        if ($cacheKey === '' || $delta === 0) {
            return;
        }

        $cache = Craft::$app->getCache();
        if (!$cache instanceof CacheInterface) {
            return;
        }

        $ttl = 60 * 60 * 24 * 30;
        if (method_exists($cache, 'increment') && method_exists($cache, 'add')) {
            $incremented = $cache->increment($cacheKey, $delta);
            if ($incremented !== false) {
                return;
            }

            if ($cache->add($cacheKey, $delta, $ttl)) {
                return;
            }

            $cache->increment($cacheKey, $delta);
            return;
        }

        $current = (int)($cache->get($cacheKey) ?: 0);
        $cache->set($cacheKey, $current + $delta, $ttl);
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

            $schemaVersion = trim((string)$plugin->schemaVersion);
            if ($schemaVersion !== '') {
                return $schemaVersion;
            }
        }

        return '0.9.2';
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
