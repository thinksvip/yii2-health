<?php

namespace Xincheng\Health\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use Xincheng\Health\CheckResult;
use Xincheng\Health\HealthComponent;

class HealthController extends Controller
{
    public $format = 'text';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['format']);
    }

    public function optionAliases()
    {
        return [
            'f' => 'format',
        ];
    }

    public function actionRun(): int
    {
        /** @var HealthComponent $component */
        $component = Yii::$app->get('health');
        $report = $component->runChecks();

        if ($this->format === 'json') {
            $this->stdout(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } else {
            $this->stdout($component->formatText($report) . PHP_EOL);
        }

        return $report['status'] === CheckResult::STATUS_CRITICAL ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
