<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'paypal:connect',
    description: 'Fetch PayPal transactions (with payer info, safe rate-limited enrichment)',
)]
class PaypalConnectCommand extends Command
{
    private string $clientId;
    private string $clientSecret;
    private string $apiurl;

    public function __construct(private HttpClientInterface $client)
    {
        parent::__construct();

        if ($_ENV['MODUS'] === 'LIVE') {
            $this->clientId = $_ENV['LIVE_API_ID'];
            $this->clientSecret = $_ENV['LIVE_API_SECRET'];
            $this->apiurl = rtrim($_ENV['LIVE_API_URL'], '/') . '/';
        } else {
            $this->clientId = $_ENV['SANDBOX_API_ID'];
            $this->clientSecret = $_ENV['SANDBOX_API_SECRET'];
            $this->apiurl = rtrim($_ENV['SANDBOX_API_URL'], '/') . '/';
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PayPal Connect Command');

        // STEP 1: Authenticate
        $response = $this->client->request('POST', $this->apiurl . 'v1/oauth2/token', [
            'auth_basic' => [$this->clientId, $this->clientSecret],
            'body' => ['grant_type' => 'client_credentials'],
        ]);

        $data = $response->toArray();
        $accessToken = $data['access_token'];
        $io->note('Access token erhalten.');

        // STEP 2: Date range (Berlin → UTC)
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        $yesterdayBerlin = new \DateTimeImmutable('yesterday', $berlinTz);
        $startDate = $yesterdayBerlin->setTime(0, 0, 0)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $endDate = $yesterdayBerlin->setTime(23, 59, 59)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        // STEP 3: Fetch transactions
        $transactionsResponse = $this->client->request('GET', $this->apiurl . 'v1/reporting/transactions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);

        $transactions = $transactionsResponse->toArray();

        // STEP 4: Build table
        if (empty($transactions['transaction_details'])) {
            $io->warning('Keine Transaktionen für gestern gefunden.');
            return Command::SUCCESS;
        }

        $rows = [];
        $lookupCache = [];

        foreach ($transactions['transaction_details'] as $t) {
            $info = $t['transaction_info'] ?? [];

            // Convert UTC → Berlin
            $datum = 'N/A';
            if (!empty($info['transaction_initiation_date'])) {
                $date = new \DateTimeImmutable($info['transaction_initiation_date'], new \DateTimeZone('UTC'));
                $datum = $date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');
            }

            // Defaults
            $name = $info['transaction_subject'] ?? $info['invoice_id'] ?? 'Unbekannt';
            $email = '';
            $agreementId = $info['paypal_reference_id'] ?? null;
            $transactionId = $info['transaction_id'] ?? null;



            // Extract payer info from any format
            if (!empty($extra)) {
                if (isset($extra['payer']['payer_info'])) {
                    // Old billing-agreement format
                    $payer = $extra['payer']['payer_info'];
                    $payerName = trim(($payer['first_name'] ?? '') . ' ' . ($payer['last_name'] ?? ''));
                    $payerEmail = $payer['email'] ?? '';
                } elseif (isset($extra['subscriber'])) {
                    // New subscription format
                    $payer = $extra['subscriber'];
                    $payerName = trim(($payer['name']['given_name'] ?? '') . ' ' . ($payer['name']['surname'] ?? ''));
                    $payerEmail = $payer['email_address'] ?? '';
                } elseif (isset($extra['payer']['payer_info']['email'])) {
                    // /v1/payments/payment/ response
                    $payer = $extra['payer']['payer_info'];
                    $payerName = trim(($payer['first_name'] ?? '') . ' ' . ($payer['last_name'] ?? ''));
                    $payerEmail = $payer['email'] ?? '';
                } else {
                    $payerName = null;
                    $payerEmail = null;
                }

                if (!empty($payerName)) $name = $payerName;
                if (!empty($payerEmail)) $email = $payerEmail;
            }

            // STEP 6: Financials
            $brutto = (float)($info['transaction_amount']['value'] ?? 0);
            $waehrung = $info['transaction_amount']['currency_code'] ?? '';
            $gebuehr = (float)($info['fee_amount']['value'] ?? 0);
            $netto = (float)($info['net_amount']['value'] ?? 0);
            if ($netto == 0 && $brutto != 0) {
                $netto = $brutto + $gebuehr;
            }

            $rechnungsnummer = $info['invoice_id'] ?? $info['transaction_id'] ?? 'N/A';
            $guthaben = $info['ending_balance']['value'] ?? '';

            $rows[] = [
                $datum,
                $name . ($email ? " ({$email})" : ''),
                sprintf('%.2f %s', $brutto, $waehrung),
                sprintf('%.2f %s', $gebuehr, $waehrung),
                sprintf('%.2f %s', $netto, $waehrung),
                $rechnungsnummer,
                $guthaben ? sprintf('%s %s', $guthaben, $waehrung) : '—',
            ];
        }

        $io->table(
            ['Datum', 'Name', 'Brutto', 'Gebühr', 'Netto', 'Rechnungsnummer', 'Guthaben'],
            $rows
        );

        return Command::SUCCESS;
    }
}
