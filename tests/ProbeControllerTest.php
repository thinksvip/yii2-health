<?php

use PHPUnit\Framework\TestCase;
use Xincheng\Health\Bootstrap;
use Xincheng\Health\HealthComponent;
use yii\caching\ArrayCache;
use yii\db\Connection;
use yii\di\Container;
use yii\web\Application;

class ProbeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        new Application([
            'id' => 'health-probe-test',
            'basePath' => __DIR__,
            'components' => [
                'health' => ['class' => HealthComponent::class, 'checks' => ['db' => ['enabled' => false]]],
                'secondary' => ['class' => Connection::class, 'dsn' => 'missingdriver:test'],
                'request' => ['scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
            ],
        ]);
        if (!isset(Yii::$app->controllerMap['health'])) {
            (new Bootstrap())->bootstrap(Yii::$app);
        }
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
        Yii::$container = new Container();
    }

    public function testExplicitSecondaryDatabaseIsProbedWithoutRunningAllChecks(): void
    {
        $report = Yii::$app->runAction('health/healthz', ['verbose' => '1']);
        $this->assertSame(['db'], array_column($report['checks'], 'id'));
        $this->assertTrue($report['ready']);

        $report = Yii::$app->runAction('health/healthz', ['checks' => 'secondary']);
        $this->assertSame(['secondary'], array_column($report['checks'], 'id'));
        $this->assertFalse($report['ready']);
        $this->assertSame(503, Yii::$app->response->statusCode);
        $this->assertSame('not-ready', Yii::$app->runAction('health/readyz', ['full' => '1']));
        $this->assertTrue(Yii::$app->health->autoDiscover);
    }

    public function testInvalidProbeSelectionsCannotReportReady(): void
    {
        foreach (['', ', ', 'db,missing', ['db']] as $selector) {
            $params = ['checks' => $selector];
            $this->assertSame('not-ready', Yii::$app->runAction('health/readyz', $params));
            $this->assertSame(400, Yii::$app->response->statusCode);
            $report = Yii::$app->runAction('health/healthz', $params);
            $this->assertFalse($report['ready']);
            $this->assertArrayHasKey('error', $report);
            $this->assertSame(400, Yii::$app->response->statusCode);
        }
    }

    public function testProbeRouteWorksWhenTheHostsUrlRuleCacheIsUnavailable(): void
    {
        $cache = new class extends ArrayCache {
            public function get($key)
            {
                throw new RuntimeException('Rule cache offline');
            }
        };
        Yii::$app->request->setPathInfo('livez');
        Yii::$app->set('urlManager', [
            'class' => \yii\web\UrlManager::class,
            'enablePrettyUrl' => true,
            'cache' => $cache,
            'rules' => ['orders' => 'order/index'],
        ]);

        (new Bootstrap())->bootstrap(Yii::$app);
        [$route] = Yii::$app->request->resolve();
        $this->assertSame('health/livez', $route);
        $this->assertSame('ok', Yii::$app->runAction($route));
        Yii::$app->request->setPathInfo('orders');
        [$route] = Yii::$app->request->resolve();
        $this->assertSame('order/index', $route);
    }
}
