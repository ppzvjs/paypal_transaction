<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\PushMetricsService;
use App\Service\StripeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stripe:connect',
    description: 'Add a short description for your command',
)]
class StripeConnectCommand extends Command
{
    public function __construct(
        private StripeService $stripeService,
        private PushMetricsService $metrics
    ) {
        parent::__construct();
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Stripe Connect Command');

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

// Start des Tages 00:00:00
        $startOfDay = new \DateTimeImmutable($chosenDateStr . ' 00:00:00', $berlinTz);
        $startTimestamp = $startOfDay->getTimestamp();

// Ende des Tages 23:59:59
        $endOfDay = new \DateTimeImmutable($chosenDateStr . ' 23:59:59', $berlinTz);
        $endTimestamp = $endOfDay->getTimestamp();

// Optional: Ausgabe zum Testen
        $io->writeln("Start Timestamp: $startTimestamp");
        $io->writeln("End Timestamp:   $endTimestamp");

        $data = $this->stripeService->fetchTransactions($startTimestamp, $endTimestamp);
        $csvData = $this->stripeService->generateData($data);
        $this->stripeService->saveCsv($csvData, $startOfDay);


        return Command::SUCCESS;
    }
}
