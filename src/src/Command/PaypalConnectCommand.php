<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'paypal:connect',
    description: 'Fetch PayPal transactions, enrich with payer info, and save CSV report.',
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

        // STEP 2: Ask for date (Berlin time, default = yesterday)
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        $yesterdayBerlin = new \DateTimeImmutable('yesterday', $berlinTz);
        $defaultDateStr = $yesterdayBerlin->format('Y-m-d');

        $chosenDateStr = $io->ask(
            'Bitte Datum eingeben (Format: YYYY-MM-DD)',
            $defaultDateStr,
            function ($input) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
                    throw new \RuntimeException('Ungültiges Datum. Bitte im Format YYYY-MM-DD eingeben.');
                }
                return $input;
            }
        );

        try {
            $chosenDate = new \DateTimeImmutable($chosenDateStr, $berlinTz);
        } catch (\Exception $e) {
            $io->error('Ungültiges Datum eingegeben. Verwende Standardwert (gestern).');
            $chosenDate = $yesterdayBerlin;
        }

        // Convert to UTC for PayPal API
        $startDate = $chosenDate->setTime(0, 0, 0)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $endDate = $chosenDate->setTime(23, 59, 59)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        $io->note(sprintf(
            'Abruf der Transaktionen für %s (Berlin-Zeit)',
            $chosenDate->format('d.m.Y')
        ));

        // STEP 3: Fetch transactions
        $transactionsResponse = $this->client->request('GET', $this->apiurl . 'v1/reporting/transactions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'fields' => 'all',
            ],
        ]);

        $transactions = $transactionsResponse->toArray();

        if (empty($transactions['transaction_details'])) {
            $io->warning('Keine Transaktionen für den angegebenen Tag gefunden.');
            return Command::SUCCESS;
        }

        $rows = [];
        $csvRows = [];

        foreach ($transactions['transaction_details'] as $t) {
            $info = $t['transaction_info'] ?? [];
            $payer = $t['payer_info'] ?? [];

            // Convert UTC → Berlin, only date
            $datum = 'N/A';
            if (!empty($info['transaction_initiation_date'])) {
                $date = new \DateTimeImmutable($info['transaction_initiation_date'], new \DateTimeZone('UTC'));
                $datum = $date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y');
            }

            // Extract payer name and email
            $payerName = 'Unbekannt';
            $payerEmail = '';
            if (!empty($payer)) {
                if (!empty($payer['payer_name']['alternate_full_name'])) {
                    $payerName = $payer['payer_name']['alternate_full_name'];
                } elseif (!empty($payer['payer_name']['given_name']) || !empty($payer['payer_name']['surname'])) {
                    $payerName = trim(($payer['payer_name']['given_name'] ?? '') . ' ' . ($payer['payer_name']['surname'] ?? ''));
                }

                if (!empty($payer['email_address'])) {
                    $payerEmail = $payer['email_address'];
                }
            }

            // Financials (numeric only)
            $brutto = (float)($info['transaction_amount']['value'] ?? 0);
            $gebuehr = (float)($info['fee_amount']['value'] ?? 0);
            $netto = (float)($info['net_amount']['value'] ?? 0);
            if ($netto == 0 && $brutto != 0) {
                $netto = $brutto + $gebuehr;
            }

            $rechnungsnummer = $info['invoice_id'] ?? '';
            if ($rechnungsnummer === '' && !empty($info['transaction_subject'])) {
                if (preg_match('/^\d{3,7}-\d{3,7}_\d{3,7}$/', $info['transaction_subject'])) {
                    $rechnungsnummer = $info['transaction_subject'];
                }
            }

            $guthaben = (float)($info['ending_balance']['value'] ?? 0);

            // Add to table (with email)
            $rows[] = [
                $datum,
                $payerName,
                $payerEmail,
                number_format($brutto, 2, ',', ''),
                number_format($gebuehr, 2, ',', ''),
                number_format($netto, 2, ',', ''),
                $rechnungsnummer,
                number_format($guthaben, 2, ',', ''),
            ];

            // Add to CSV (without email)
            $csvRows[] = [
                $datum,
                $payerName,
                number_format($brutto, 2, ',', ''),
                number_format($gebuehr, 2, ',', ''),
                number_format($netto, 2, ',', ''),
                $rechnungsnummer,
                number_format($guthaben, 2, ',', ''),
            ];
        }

        // STEP 7: Display in console (with email)
        $io->table(
            ['Datum', 'Name', 'E-Mail', 'Brutto', 'Gebühr', 'Netto', 'Rechnungsnummer', 'Guthaben'],
            $rows
        );

        // STEP 8: Save as CSV (without email)
        $csvDir = $_ENV['STOREFOLDER'];
        if (!is_dir($csvDir)) {
            mkdir($csvDir, 0775, true);
        }

        $reportDate = $chosenDate->format('Y-m-d');
        $filename = sprintf('%spaypal-transactions-%s.csv', $csvDir, $reportDate);
        $fp = fopen($filename, 'w');

        fputcsv($fp, ['Datum', 'Name', 'Brutto', 'Gebühr', 'Netto', 'Rechnungsnummer', 'Guthaben'], ';');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row, ';');
        }

        fclose($fp);
        $io->success("CSV-Datei erfolgreich gespeichert unter: $filename");

        return Command::SUCCESS;
    }
}
