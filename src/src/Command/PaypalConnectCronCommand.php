<?php

namespace App\Command;

use App\Service\PaypalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'paypal:connect:cron',
    description: 'Fetch PayPal transactions for yesterday (silent for cronjob).',
)]
class PaypalConnectCronCommand extends Command
{
    public function __construct(private PaypalService $paypalService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        $yesterdayBerlin = new \DateTimeImmutable('yesterday', $berlinTz);

        try {
            $file = $this->paypalService->fetchAndSaveTransactions($yesterdayBerlin);
            if ($file) {
                error_log('[PayPal Cron] CSV created: ' . $file);
            } else {
                error_log('[PayPal Cron] No transactions for ' . $yesterdayBerlin->format('Y-m-d'));
            }
        } catch (\Throwable $e) {
            error_log('[PayPal Cron] ERROR: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
