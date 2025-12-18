<?php

namespace Xincheng\Health;

interface HealthCheckInterface
{
    public function run(): CheckResult;

    public function getId(): string;
}
