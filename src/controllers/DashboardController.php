<?php

namespace agentreadiness\controllers;

use agentreadiness\Plugin;
use craft\web\Controller;
use yii\web\Response;

class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        $model = Plugin::getInstance()->getReadinessService()->getDashboardModel();

        return $this->renderTemplate('agents/dashboard', [
            'readinessVersion' => $model['readinessVersion'],
            'buildDate' => $model['buildDate'],
            'status' => $model['status'],
            'summary' => $model['summary'],
            'summaryJson' => json_encode($model['summary'], JSON_PRETTY_PRINT),
        ]);
    }

    public function actionHealth(): Response
    {
        return $this->asJson([
            'status' => 'ok',
            'service' => 'agents',
            'version' => '0.1.1',
            'buildDate' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
    }
}
