<?php

namespace Xincheng\Health\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use Xincheng\Health\HealthComponent;

class HealthController extends Controller
{
    public $enableCsrfValidation = false;
    public $layout = false;

    public function getViewPath()
    {
        return dirname(__DIR__) . '/views';
    }

    /**
     * Kubernetes liveness probe: process is alive, no dependency checks.
     */
    public function actionLivez()
    {
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->statusCode = 200;
        Yii::$app->response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return 'ok';
    }

    /**
     * Kubernetes readiness probe: runs checks and only returns 200/503.
     * Use /healthz?verbose=1 when you need details.
     */
    public function actionReadyz(int $full = 0, ?string $checks = null)
    {
        /** @var HealthComponent $component */
        $component = Yii::$app->get('health');
        $originalAutoDiscover = $component->autoDiscover;
        if (!$full) {
            $component->autoDiscover = false;
        }
        try {
            $report = $checks ? $component->runChecksByIds($this->parseChecks($checks)) : $component->runChecks();
        } finally {
            $component->autoDiscover = $originalAutoDiscover;
        }

        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->statusCode = $component->getHttpStatusCode($report['status']);
        Yii::$app->response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $report['status'] === 'critical' ? 'not-ready' : 'ready';
    }

    /**
     * Kubernetes-style health endpoint.
     * - Default: minimal JSON
     * - verbose=1 or on failures: includes checks[]
     */
    public function actionHealthz(int $verbose = 0, int $full = 0, ?string $checks = null)
    {
        /** @var HealthComponent $component */
        $component = Yii::$app->get('health');
        $originalAutoDiscover = $component->autoDiscover;
        if (!$full) {
            $component->autoDiscover = false;
        }
        try {
            $report = $checks ? $component->runChecksByIds($this->parseChecks($checks)) : $component->runChecks();
        } finally {
            $component->autoDiscover = $originalAutoDiscover;
        }
        $statusCode = $component->getHttpStatusCode($report['status']);

        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = $statusCode;

        $payload = [
            'status' => $report['status'],
            'ready' => $statusCode === 200,
            'timestamp' => $report['timestamp'],
            'duration' => $report['duration'],
        ];

        if ($verbose || $statusCode >= 400) {
            $payload['checks'] = $report['checks'];
        }

        return $payload;
    }

    public function actionIndex()
    {
        /** @var HealthComponent $component */
        $component = Yii::$app->get('health');
        $report = $component->runChecks();
        Yii::$app->response->statusCode = $component->getHttpStatusCode($report['status']);

        return $this->render('index', [
            'report' => $report,
        ]);
    }

    public function actionJson()
    {
        /** @var HealthComponent $component */
        $component = Yii::$app->get('health');
        $report = $component->runChecks();

        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = $component->getHttpStatusCode($report['status']);

        return $report;
    }

    public function actionSummary()
    {
        /** @var HealthComponent $component */
        $component = Yii::$app->get('health');
        $report = $component->runChecks();

        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->statusCode = $component->getHttpStatusCode($report['status']);

        return $report['status'];
    }

    private function parseChecks(string $checks): array
    {
        return preg_split('/\\s*,\\s*/', trim($checks), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
