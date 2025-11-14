<?php

namespace App\Command;

use App\Service\PaypalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

#[AsCommand(
    name: 'paypal:connect',
    description: 'Fetch PayPal transactions and show table output.',
)]
class PaypalConnectCommand extends Command
{
    public function __construct(private PaypalService $paypalService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PayPal Connect Command');

        $berlinTz       = new \DateTimeZone('Europe/Berlin');
        $yesterdayBerlin = new \DateTimeImmutable('yesterday', $berlinTz);
        $defaultDateStr  = $yesterdayBerlin->format('Y-m-d');

        $chosenDateStr = $io->ask(
            'Bitte Datum eingeben (Format: YYYY-MM-DD)',
            $defaultDateStr,
            fn ($input) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)
                ? $input
                : throw new \RuntimeException('Ungültiges Datum.')
        );

        $date = new \DateTimeImmutable($chosenDateStr, $berlinTz);

        try {
            $file = $this->paypalService->fetchAndSaveTransactions($date);

            if (!$file) {
                $io->warning('Keine Transaktionen gefunden.');
                return Command::SUCCESS;
            }

            $io->success("CSV-Datei gespeichert unter: $file");

            // ----------------------------------------------------
            // NEW: Convert CSV to table
            // ----------------------------------------------------
            if (!file_exists($file)) {
                $io->error("CSV-Datei nicht gefunden: $file");
                return Command::FAILURE;
            }

            $handle = fopen($file, 'r');
            if (!$handle) {
                $io->error("CSV-Datei konnte nicht geöffnet werden.");
                return Command::FAILURE;
            }

            $headers = fgetcsv($handle, 0, ';');
            $rows = [];

            while (($data = fgetcsv($handle, 0, ';')) !== false) {
                $rows[] = $data;
            }

            fclose($handle);

            if (empty($rows)) {
                $io->warning('CSV-Datei enthält keine Daten.');
                return Command::SUCCESS;
            }

            // Zeichne Tabelle
            $table = new Table($output);
            $table
                ->setHeaders($headers)
                ->setRows($rows);

            $io->section('PayPal Transaktionen');
            $table->render();

        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
