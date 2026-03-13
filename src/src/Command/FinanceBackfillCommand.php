<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\StripeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'finance:backfill',
    description: 'Füllt die Datenbank mit Transaktionen für ein bestimmtes Jahr.',
)]
class FinanceBackfillCommand extends Command
{
    public function __construct(
        private PaypalService $paypalService,
        private StripeService $stripeService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('year', InputArgument::OPTIONAL, 'Das Jahr für den Backfill (z.B. 2025)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        $today = new \DateTimeImmutable('today', $berlinTz);

        // 1. JAHR ERMITTELN
        $year = $input->getArgument('year');
        if (!$year) {
            $year = $io->ask('Für welches Jahr soll der Backfill laufen?', $today->format('Y'));
        }

        if (!preg_match('/^\d{4}$/', $year)) {
            $io->error('Ungültiges Jahr angegeben.');
            return Command::FAILURE;
        }

        $io->title("Starte Backfill für das Jahr $year");

        // 2. ZEITRAUM BERECHNEN
        $startDate = new \DateTimeImmutable("$year-01-01 00:00:00", $berlinTz);
        $endDate = new \DateTimeImmutable("$year-12-31 23:59:59", $berlinTz);

        // Falls das gewählte Jahr das aktuelle Jahr ist, laufen wir nur bis gestern
        if ($endDate >= $today) {
            $endDate = $today->modify('-1 day')->setTime(23, 59, 59);
            $io->note("Hinweis: Da $year das aktuelle Jahr ist, läuft der Backfill bis gestern (" . $endDate->format('d.m.Y') . ").");
        }

        $totalDays = $startDate->diff($endDate)->days + 1;
        if ($totalDays <= 0) {
            $io->warning('Keine Tage zum Verarbeiten gefunden (Datum in der Zukunft?).');
            return Command::SUCCESS;
        }

        $io->progressStart($totalDays);
        $currentDate = $startDate;

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');

            // --- PAYPAL ---
            try {
                $this->paypalService->fetchAndSaveTransactions($currentDate);
            } catch (\Throwable $e) {
                $io->error("\n[PayPal] Fehler am $dateStr: " . $e->getMessage());
            }

            // Pause zwischen Providern (Burst-Schutz)
            usleep(500000);

            // --- STRIPE ---
            try {
                $startTs = $currentDate->setTime(0, 0, 0)->getTimestamp();
                $endTs = $currentDate->setTime(23, 59, 59)->getTimestamp();

                $data = $this->stripeService->fetchTransactions($startTs, $endTs);
                if (!empty($data['data'])) {
                    $csvData = $this->stripeService->generateData($data);
                    $this->stripeService->saveCsv($csvData, $currentDate);
                }
            } catch (\Throwable $e) {
                $io->error("\n[Stripe] Fehler am $dateStr: " . $e->getMessage());
            }

            // --- NÄCHSTER TAG & PAUSE ---
            $currentDate = $currentDate->modify('+1 day');
            $io->progressAdvance();

            // 1 Sekunde Pause, um Rate-Limits der APIs sicher zu umgehen
            sleep(1);
        }

        $io->progressFinish();
        $io->success("Backfill für $year abgeschlossen!");

        return Command::SUCCESS;
    }
}
