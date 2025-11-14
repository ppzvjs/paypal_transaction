<?php

namespace App\Service;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;

class MetricsService
{
    private CollectorRegistry $registry;

    public function __construct()
    {
        Redis::setDefaultOptions([
            'host' => '127.0.0.1',
            'port' => 6379,
        ]);

        $this->registry = new CollectorRegistry(new Redis(), false);
    }

    public function registry(): CollectorRegistry
    {
        return $this->registry;
    }
}
