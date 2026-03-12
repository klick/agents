<?php

namespace Klick\Agents\controllers;

use Craft;
use craft\web\Controller;
use Klick\Agents\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WebhookTestSinkController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;
    protected array|bool|int $supportsCsrfValidation = false;

    public function actionReceive(): Response
    {
        $service = Plugin::getInstance()->getWebhookTestSinkService();
        $config = $service->getConfig();
        if (!(bool)($config['enabled'] ?? false)) {
            throw new NotFoundHttpException('Not found.');
        }

        $request = Craft::$app->getRequest();
        if ($request->getIsGet()) {
            return $this->asJson([
                'ok' => true,
                'service' => 'agents',
                'type' => 'webhook.test-sink',
                'enabled' => true,
                'url' => (string)($config['url'] ?? ''),
                'message' => 'POST signed Agents webhook deliveries to this URL to capture them locally.',
            ]);
        }

        if (!$request->getIsPost()) {
            $response = $this->asJson([
                'ok' => false,
                'error' => 'METHOD_NOT_ALLOWED',
                'message' => 'Use POST to capture webhook deliveries.',
            ]);
            $response->setStatusCode(405);
            return $response;
        }

        try {
            $event = $service->captureRequest($request);
            return $this->asJson([
                'ok' => true,
                'captured' => [
                    'id' => (int)($event['id'] ?? 0),
                    'eventId' => (string)($event['eventId'] ?? ''),
                    'resourceType' => (string)($event['resourceType'] ?? ''),
                    'resourceId' => (string)($event['resourceId'] ?? ''),
                    'action' => (string)($event['action'] ?? ''),
                    'verificationStatus' => (string)($event['verificationStatus'] ?? 'unsigned'),
                    'dateCreated' => (string)($event['dateCreated'] ?? ''),
                ],
            ]);
        } catch (\RuntimeException $e) {
            $response = $this->asJson([
                'ok' => false,
                'error' => 'SINK_UNAVAILABLE',
                'message' => $e->getMessage(),
            ]);
            $response->setStatusCode(503);
            return $response;
        }
    }
}
