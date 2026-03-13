<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\PushMetricsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'paypal:connect',
    description: 'Fetch PayPal transactions, sync DB, and show table.',
)]
class PaypalConnectCommand extends Command
{
    public function __construct(
        private PaypalService $paypalService,
        private PushMetricsService $metrics
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('date', InputArgument::OPTIONAL, 'Das Datum im Format YYYY-MM-DD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PayPal Connect & Sync');

        $berlinTz = new \DateTimeZone('Europe/Berlin');

        // 1. DATUM ERMITTELN (Argument oder Interaktiv)
        $dateArg = $input->getArgument('date');

        if (!$dateArg) {
            $yesterday = new \DateTimeImmutable('yesterday', $berlinTz);
            $dateArg = $io->ask(
                'Bitte Datum eingeben (Format: YYYY-MM-DD)',
                $yesterday->format('Y-m-d'),
                fn ($answer) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer)
                    ? $answer
                    : throw new \RuntimeException('Ungültiges Format.')
            );
        }

        try {
            $date = new \DateTimeImmutable($dateArg, $berlinTz);
        } catch (\Exception $e) {
            $io->error('Ungültiges Datum angegeben.');
            return Command::FAILURE;
        }

        // 2. VERARBEITUNG ÜBER SERVICE
        try {
            $result = $this->paypalService->fetchAndSaveTransactions($date);

            if (!$result) {
                $io->warning('Keine Transaktionen für dieses Datum gefunden.');
                $this->updateMetrics(0, 0, 'success');
                return Command::SUCCESS;
            }


            // 4. METRIKEN
            $this->updateMetrics($result['entries'], $result['missing_invoice'], 'success');

        } catch (\Throwable $e) {
            $io->error("Fehler: " . $e->getMessage());
            $this->updateMetrics(0, 0, 'failure');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function renderPreviewTable(OutputInterface $output, string $filePath): void
    {
        if (!file_exists($filePath)) return;

        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle, 0, ';');
        $rows = [];
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $rows[] = $data;
        }
        fclose($handle);

        $table = new Table($output);
        $table->setHeaders($headers)->setRows($rows);
        $table->render();
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
