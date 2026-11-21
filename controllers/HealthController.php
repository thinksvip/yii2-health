<?php

namespace Xincheng\Health\controllers;

use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;
use Xincheng\Health\CheckResult;
use Xincheng\Health\HealthComponent;

class HealthController extends Controller
{
    public $enableCsrfValidation = false;
    public $layout = false;

    public function getViewPath()
    {
        return dirname(__DIR__) . '/views';
    }

    public function runAction($id, $params = [])
    {
        try {
            return parent::runAction($id, $params);
        } catch (BadRequestHttpException $e) {
            if ($id !== 'readyz' && $id !== 'healthz') {
                throw $e;
            }

            // Some API hosts wrap exceptions in HTTP 200, which would pass a probe.
            $response = Yii::$app->response;
            $response->statusCode = 400;
            if ($id === 'readyz') {
                $response->format = Response::FORMAT_RAW;
                $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
                return 'not-ready';
            }

            $response->format = Response::FORMAT_JSON;
            return ['status' => CheckResult::STATUS_CRITICAL, 'ready' => false, 'error' => $e->getMessage()];
        }
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
        $report = $this->runProbeChecks($component, $full, $checks);

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
        $report = $this->runProbeChecks($component, $full, $checks);
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
        Yii::$app->response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $report['status'];
    }

    private function runProbeChecks(HealthComponent $component, int $full, ?string $checks): array
    {
        if ($checks !== null) {
            $ids = preg_split('/\\s*,\\s*/', trim($checks), -1, PREG_SPLIT_NO_EMPTY);
            if (!$ids) {
                throw new BadRequestHttpException('Specify at least one check ID.');
            }
            $report = $component->runChecksByIds($ids);
            $missing = array_diff($ids, array_column($report['checks'], 'id'));
            if ($missing) {
                throw new BadRequestHttpException('Unknown health checks: ' . implode(', ', $missing));
            }
            return $report;
        }

        $originalAutoDiscover = $component->autoDiscover;
        if (!$full) {
            $component->autoDiscover = false;
        }
        try {
            return $component->runChecks();
        } finally {
            $component->autoDiscover = $originalAutoDiscover;
        }
    }
}
