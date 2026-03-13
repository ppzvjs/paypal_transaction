<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\PushMetricsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'paypal:connect:cron')]
class PaypalConnectCronCommand extends Command
{
    public function __construct(
        private PaypalService $paypalService,
        private PushMetricsService $metrics
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->metrics->registry();
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        $yesterday = new \DateTimeImmutable('yesterday', $berlinTz);

        try {
            // Service erledigt API, CSV und DB
            $stats = $this->paypalService->fetchAndSaveTransactions($yesterday);

            if (!$stats) {
                $this->updateMetrics(0, 0, 'success');
                return Command::SUCCESS;
            }

            $this->updateMetrics($stats['entries'], $stats['missing_invoice'], 'success');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            error_log("[PayPal Cron] Error: " . $e->getMessage());
            $this->updateMetrics(0, 0, 'failure');
            return Command::FAILURE;
        }
    }

    private function updateMetrics(int $entries, int $missing, string $status): void
    {
        $registry = $this->metrics->registry();

        $registry->getOrRegisterGauge('paypal', 'job_last_run_timestamp', '...', [])->set(time());
        $registry->getOrRegisterGauge('paypal', 'job_entries_total', '...', [])->set($entries);
        $registry->getOrRegisterGauge('paypal', 'job_invoice_missing_total', '...', [])->set($missing);
        $registry->getOrRegisterCounter('paypal', 'job_success_total', '...', ['status'])->inc([$status]);

        $this->metrics->push('paypal_cronjob');
    }
}
