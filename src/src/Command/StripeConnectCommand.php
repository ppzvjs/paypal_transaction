<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\PushMetricsService;
use App\Service\StripeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stripe:connect',
    description: 'Fetch Stripe transactions for a specific date and sync to DB/CSV.',
)]
class StripeConnectCommand extends Command
{
    public function __construct(
        private StripeService $stripeService,
        private PushMetricsService $metrics
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Argument 'date' als optional hinzufügen
        $this->addArgument('date', InputArgument::OPTIONAL, 'Das Datum im Format YYYY-MM-DD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Stripe Connect & Sync');

        $berlinTz = new \DateTimeZone('Europe/Berlin');

        // 1. DATUM ERMITTELN (Argument oder interaktive Abfrage)
        $chosenDateStr = $input->getArgument('date');

        if (!$chosenDateStr) {
            $yesterdayBerlin = new \DateTimeImmutable('yesterday', $berlinTz);
            $defaultDateStr = $yesterdayBerlin->format('Y-m-d');

            $chosenDateStr = $io->ask(
                'Bitte Datum eingeben (Format: YYYY-MM-DD)',
                $defaultDateStr,
                fn ($answer) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer)
                    ? $answer
                    : throw new \RuntimeException('Ungültiges Datum.')
            );
        }

        try {
            // Zeitgrenzen für den gewählten Tag festlegen
            $startOfDay = new \DateTimeImmutable($chosenDateStr . ' 00:00:00', $berlinTz);
            $endOfDay = $startOfDay->setTime(23, 59, 59);

            $startTimestamp = $startOfDay->getTimestamp();
            $endTimestamp = $endOfDay->getTimestamp();

            $io->note("Zeitraum: $chosenDateStr (Timestamps: $startTimestamp - $endTimestamp)");

            // 2. VERARBEITUNG
            $io->info('Rufe Daten von Stripe ab...');
            $data = $this->stripeService->fetchTransactions($startTimestamp, $endTimestamp);

            if (empty($data['data'])) {
                $io->warning('Keine Stripe-Transaktionen für diesen Zeitraum gefunden.');
                return Command::SUCCESS;
            }

            $csvData = $this->stripeService->generateData($data);

            $io->info('Speichere CSV und synchronisiere Datenbank...');
            $this->stripeService->saveCsv($csvData, $startOfDay);

            $io->success(sprintf(
                'Erfolgreich! %d Transaktionen für den %s verarbeitet.',
                count($csvData),
                $chosenDateStr
            ));

        } catch (\Throwable $e) {
            $io->error("Fehler bei der Stripe-Verarbeitung: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
