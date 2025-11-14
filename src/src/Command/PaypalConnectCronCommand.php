<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\PushMetricsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'paypal:connect:cron',
    description: 'Automated PayPal transaction fetch (cronjob) with Prometheus metrics.',
)]
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

        // METRICS
        $lastRunGauge = $registry->getOrRegisterGauge(
            'paypal',
            'job_last_run_timestamp',
            'Unix timestamp of last PayPal job run',
            []
        );

        $entriesGauge = $registry->getOrRegisterGauge(
            'paypal',
            'job_entries_total',
            'Number of PayPal transactions fetched',
            []
        );

        $missingInvoiceGauge = $registry->getOrRegisterGauge(
            'paypal',
            'job_invoice_missing_total',
            'Number of transactions without invoice number',
            []
        );

        $statusCounter = $registry->getOrRegisterCounter(
            'paypal',
            'job_success_total',
            'Number of successful or failed PayPal cron runs',
            ['status']
        );

        // TIMESTAMP setzen
        $lastRunGauge->set(time());

        // YESTERDAY (Berlin timezone)
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        $yesterdayBerlin = new \DateTimeImmutable('yesterday', $berlinTz);
        $dateStr = $yesterdayBerlin->format('Y-m-d');

        try {
            $file = $this->paypalService->fetchAndSaveTransactions($yesterdayBerlin);

            if (!$file) {
                error_log("[PayPal Cron] No transactions for $dateStr");

                $entriesGauge->set(0);
                $missingInvoiceGauge->set(0);
                $statusCounter->inc(['failure']);

                $this->metrics->push('paypal_cronjob');
                return Command::SUCCESS;
            }

        } catch (\Throwable $e) {
            error_log("[PayPal Cron] ERROR: " . $e->getMessage());

            $statusCounter->inc(['failure']);
            $this->metrics->push('paypal_cronjob');

            return Command::FAILURE;
        }

        // READ CSV
        if (!file_exists($file)) {
            error_log("[PayPal Cron] CSV missing: $file");

            $statusCounter->inc(['failure']);
            $this->metrics->push('paypal_cronjob');

            return Command::FAILURE;
        }

        $handle = fopen($file, 'r');
        fgetcsv($handle, 0, ';'); // header

        $entries = 0;
        $missing = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $entries++;

            $invoice = $row[5] ?? '';
            if (trim($invoice) === '') {
                $missing++;
            }
        }

        fclose($handle);

        // METRICS UPDATES
        $entriesGauge->set($entries);
        $missingInvoiceGauge->set($missing);
        $statusCounter->inc(['success']);

        // PUSH TO GATEWAY
        $this->metrics->push('paypal_cronjob');

        error_log("[PayPal Cron] SUCCESS for $dateStr → entries=$entries, missing=$missing");

        return Command::SUCCESS;
    }
}
