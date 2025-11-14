<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PaypalService
{
    private string $clientId;
    private string $clientSecret;
    private string $apiurl;

    public function __construct(private HttpClientInterface $client)
    {
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

    /**
     * Fetch transactions for a given date (Berlin timezone), save CSV file, return file path.
     */
    public function fetchAndSaveTransactions(\DateTimeImmutable $dateBerlin): ?string
    {
        // STEP 1: Authenticate
        try {
            $response = $this->client->request('POST', $this->apiurl . 'v1/oauth2/token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => ['grant_type' => 'client_credentials'],
            ]);
            $accessToken = $response->toArray()['access_token'];
        } catch (\Exception $e) {
            throw new \RuntimeException('PayPal authentication failed: ' . $e->getMessage());
        }

        // STEP 2: Build date range (UTC)
        $startDate = $dateBerlin->setTime(0, 0, 0)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $endDate = $dateBerlin->setTime(23, 59, 59)
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
                'fields' => 'all',
            ],
        ]);

        $transactions = $transactionsResponse->toArray();

        if (empty($transactions['transaction_details'])) {
            return null;
        }

        // STEP 4: Build rows
        $csvRows = [];
        foreach ($transactions['transaction_details'] as $t) {
            $info = $t['transaction_info'] ?? [];
            $payer = $t['payer_info'] ?? [];

            $datum = 'N/A';
            if (!empty($info['transaction_initiation_date'])) {
                $date = new \DateTimeImmutable($info['transaction_initiation_date'], new \DateTimeZone('UTC'));
                $datum = $date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y');
            }

            $payerName = 'Unbekannt';
            if (!empty($payer)) {
                if (!empty($payer['payer_name']['alternate_full_name'])) {
                    $payerName = $payer['payer_name']['alternate_full_name'];
                } elseif (!empty($payer['payer_name']['given_name']) || !empty($payer['payer_name']['surname'])) {
                    $payerName = trim(($payer['payer_name']['given_name'] ?? '') . ' ' . ($payer['payer_name']['surname'] ?? ''));
                }
            }

            $brutto = (float)($info['transaction_amount']['value'] ?? 0);
            $gebuehr = (float)($info['fee_amount']['value'] ?? 0);
            $netto = (float)($info['net_amount']['value'] ?? 0);
            if ($netto == 0 && $brutto != 0) {
                $netto = $brutto + $gebuehr;
            }

            $rechnungsnummer = $info['invoice_id'] ?? '';
            if ($rechnungsnummer === '' && !empty($info['transaction_subject'])) {
                if (preg_match('/^\d{3,7}_\d{3,7}-\d{3,7}$/', $info['transaction_subject'])) {
                    $rechnungsnummer = $info['transaction_subject'];
                }
            }

            /*if($rechnungsnummer == ''){
                var_dump($t);
            }*/

            $guthaben = (float)($info['ending_balance']['value'] ?? 0);

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

        // STEP 5: Save CSV
        $csvDir = $_ENV['STOREFOLDER'];
        if (!is_dir($csvDir)) {
            mkdir($csvDir, 0775, true);
        }

        $reportDate = $dateBerlin->format('Y-m-d');
        $filename = sprintf('%spaypal-transactions-%s.csv', $csvDir, $reportDate);
        $fp = fopen($filename, 'w');
        fputcsv($fp, ['Datum', 'Name', 'Brutto', 'Gebühr', 'Netto', 'Rechnungsnummer', 'Guthaben'], ';');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row, ';');
        }
        fclose($fp);

        return $filename;
    }
}
