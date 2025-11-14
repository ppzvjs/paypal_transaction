<?php

namespace App\Service;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\PushGateway;

class PushMetricsService
{
    private CollectorRegistry $registry;
    private PushGateway $pushGateway;

    public function __construct()
    {
        $this->registry = new CollectorRegistry(new InMemory(), false);
        $this->pushGateway = new PushGateway($_ENV['METRICS_SERVER_URL']);
    }

    public function registry(): CollectorRegistry
    {
        return $this->registry;
    }

    public function push(string $jobName, array $groupingKeys = []): void
    {
        $this->pushGateway->push($this->registry, $jobName, $groupingKeys);
    }
}
