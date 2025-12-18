<?php

namespace Xincheng\Health;

use yii\base\BootstrapInterface;
use yii\console\Application as ConsoleApplication;
use yii\web\Application as WebApplication;
use Xincheng\Health\commands\HealthController as ConsoleHealthController;
use Xincheng\Health\controllers\HealthController as WebHealthController;

class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        $config = $this->loadDefaultConfig();

        if (!$app->has('health')) {
            $app->set('health', $config);
        }

        $component = $app->has('health') ? $app->get('health') : null;

        if ($app instanceof ConsoleApplication && !isset($app->controllerMap['health'])) {
            $app->controllerMap['health'] = [
                'class' => ConsoleHealthController::class,
            ];
        }

        if ($app instanceof WebApplication && !isset($app->controllerMap['health'])) {
            $app->controllerMap['health'] = [
                'class' => WebHealthController::class,
            ];
        }

        if ($app instanceof WebApplication && $component instanceof HealthComponent) {
            if ($component->autoRegisterRoutes) {
                $this->attachRoutes($app);
            }
        } elseif ($app instanceof WebApplication && !($component instanceof HealthComponent)) {
            // fallback when component could not be instantiated yet
            $shouldRegister = $config['autoRegisterRoutes'] ?? true;
            if ($shouldRegister) {
                $this->attachRoutes($app);
            }
        }
    }

    protected function loadDefaultConfig(): array
    {
        $file = dirname(__DIR__) . '/config/health.php';
        if (is_file($file)) {
            return require $file;
        }

        return ['class' => HealthComponent::class];
    }

    protected function attachRoutes(WebApplication $app): void
    {
        $rules = [
            'GET livez' => 'health/livez',
            'GET readyz' => 'health/readyz',
            'GET healthz' => 'health/healthz',
            'GET health.json' => 'health/json',
            'GET health/summary' => 'health/summary',
            'GET health' => 'health/index',
        ];

        $app->getUrlManager()->addRules($rules, false);
    }
}
