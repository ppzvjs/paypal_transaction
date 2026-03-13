<?php

namespace App\Command;

use App\Service\PaypalService;
use App\Service\PushMetricsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'paypal:info',
    description: 'Add a short description for your command',
)]
class PaypalInfoCommand extends Command
{

    private array $testkunde;

    public function __construct(
        private PaypalService $paypalService,
        private PushMetricsService $metrics
    ) {
        parent::__construct();
        $this->testkunde[] = [
            'cover' => '2881315',
            'email' => 'achim.wernet@me.com',
            'gen' => 'B-7KD27309AM105724X',
            'payment' => 'B-7KD27309AM105724X'
        ];
        $this->testkunde[] = [
            'cover' => '3098707',
            'email' => 'arekkabat@gmx.de',
            'gen' => '1J367124TH8892010',
            'payment' => ''
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PayPal Info Command');

       /* $cover = $io->ask(
            'Bitte Covernummer eingeben:',
            1234567,
            fn ($input) => preg_match('/^\d{7}$/', $input)
                ? $input
                : throw new \RuntimeException('Ungültige Covernummer.')
        );

        $token = $this->paypalService->getAuthToken();*/

        $transaction_id = '13G092831B027324C';

        $lookback = (new \DateTimeImmutable())->modify('-30 days');
        $results = $this->paypalService->findCustomerTransactions($transaction_id, $lookback);

        if (empty($results['transaction_details'])) {
            $io->warning('Nichts gefunden.');
        } else {
            foreach ($results['transaction_details'] as $detail) {
                var_dump($detail);
                die();
                $info = $detail['transaction_info'];
                $io->writeln(sprintf(
                    'Datum: %s | Betrag: %s %s | Status: %s',
                    $info['transaction_initiation_date'],
                    $info['transaction_amount']['value'],
                    $info['transaction_amount']['currency_code'],
                    $info['transaction_status']
                ));

                // Wenn eine Subscription ID dabei ist, zeigen wir sie an
                if (isset($info['custom_field'])) {
                    $io->note('Custom Field (oft für IDs genutzt): ' . $info['custom_field']);
                }
            }
        }

        return Command::SUCCESS;
    }
}
