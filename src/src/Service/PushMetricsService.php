<?php

namespace App\Service;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use PrometheusPushGateway\PushGateway;

class PushMetricsService
{
    private CollectorRegistry $registry;
    private PushGateway $pushGateway;

    public function __construct()
    {
        // Create in-memory registry for metrics for this script run
        $this->registry = new CollectorRegistry(new InMemory(), false);

        // Metrics server URL env variable MUST contain: http://IP:9091
        $this->pushGateway = new PushGateway($_ENV['METRICS_SERVER_URL']);
    }

    public function registry(): CollectorRegistry
    {
        return $this->registry;
    }

    public function push(string $jobName, array $groupingKeys = []): void
    {
        //$this->pushGateway->push($this->registry, $jobName, $groupingKeys);
    }
}
