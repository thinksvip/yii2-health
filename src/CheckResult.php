<?php

namespace Xincheng\Health;

class CheckResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_SKIPPED = 'skipped';

    /** @var string */
    public $id;
    /** @var string */
    public $name;
    /** @var string */
    public $status;
    /** @var array */
    public $meta;
    /** @var float duration in milliseconds */
    public $duration;

    public function __construct(string $id, string $name, string $status, array $meta = [], float $duration = 0.0)
    {
        $this->id = $id;
        $this->name = $name;
        $this->status = $status;
        $this->meta = $meta;
        $this->duration = $duration;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'duration' => $this->duration,
            'meta' => $this->meta,
        ];
    }
}
