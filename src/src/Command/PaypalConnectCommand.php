<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\PushMetricsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'paypal:connect',
    description: 'Fetch PayPal transactions, show table, and push Prometheus metrics.',
)]
class PaypalConnectCommand extends Command
{
    public function __construct(
        private PaypalService $paypalService,
        private PushMetricsService $metrics
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PayPal Connect Command');

        // ---------------------------------------------------------
        // PROMETHEUS METRICS REGISTRY
        // ---------------------------------------------------------
        $registry = $this->metrics->registry();

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

        $successCounter = $registry->getOrRegisterCounter(
            'paypal',
            'job_success_total',
            'Number of successful or failed runs',
            ['status']
        );


        // Set run timestamp immediately
        $lastRunGauge->set(time());

        // ---------------------------------------------------------
        // USER DATE INPUT
        // ---------------------------------------------------------
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        $yesterdayBerlin = new \DateTimeImmutable('yesterday', $berlinTz);
        $defaultDateStr = $yesterdayBerlin->format('Y-m-d');

        $chosenDateStr = $io->ask(
            'Bitte Datum eingeben (Format: YYYY-MM-DD)',
            $defaultDateStr,
            fn ($input) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)
                ? $input
                : throw new \RuntimeException('Ungültiges Datum.')
        );

        $date = new \DateTimeImmutable($chosenDateStr, $berlinTz);

        // ---------------------------------------------------------
        // FETCH PAYPAL TRANSACTIONS
        // ---------------------------------------------------------
        try {
            $file = $this->paypalService->fetchAndSaveTransactions($date);

            if (!$file) {
                $io->warning('Keine Transaktionen gefunden.');

                // Prometheus metrics
                $entriesGauge->set(0);
                $missingInvoiceGauge->set(0);
                $successCounter->inc(['failure']);

                // push metrics to gateway
                $this->metrics->push('paypal_cronjob');

                return Command::SUCCESS;
            }

            $io->success("CSV-Datei gespeichert unter: $file");

        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            $successCounter->inc(['failure']);
            $this->metrics->push('paypal_cronjob');

            return Command::FAILURE;
        }

        // ---------------------------------------------------------
        // READ CSV & BUILD TABLE
        // ---------------------------------------------------------
        if (!file_exists($file)) {
            $io->error("CSV-Datei nicht gefunden: $file");

            $successCounter->inc(['failure']);
            $this->metrics->push('paypal_cronjob');

            return Command::FAILURE;
        }

        $handle = fopen($file, 'r');
        if (!$handle) {
            $io->error("CSV-Datei konnte nicht geöffnet werden.");

            $successCounter->inc(['failure']);
            $this->metrics->push('paypal_cronjob');

            return Command::FAILURE;
        }

        $headers = fgetcsv($handle, 0, ';');
        $rows = [];

        $entries = 0;
        $missingInvoices = 0;

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $rows[] = $data;
            $entries++;

            // Column 5 = Rechnungsnummer
            $invoice = $data[5] ?? '';
            if (trim($invoice) === '') {
                $missingInvoices++;
            }
        }

        fclose($handle);

        // ---------------------------------------------------------
        // PROMETHEUS METRICS UPDATE
        // ---------------------------------------------------------
        $entriesGauge->set($entries);
        $missingInvoiceGauge->set($missingInvoices);
        $successCounter->inc(['success']);

        // PUSH TO PUSHGATEWAY
        #$this->metrics->push('paypal_cronjob');

        // ---------------------------------------------------------
        // RENDER TABLE
        // ---------------------------------------------------------
        $io->section('PayPal Transaktionen');

        $table = new Table($output);
        $table->setHeaders($headers)->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }
}
