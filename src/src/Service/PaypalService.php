<?php

namespace App\Service;

use App\Entity\Transactions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PaypalService
{
    private string $clientId;
    private string $clientSecret;
    private string $apiurl;

    public function __construct(
        private HttpClientInterface $client,
        private EntityManagerInterface $em
    ) {
        $mode = $_ENV['MODUS'] ?? 'SANDBOX';
        $this->clientId = $_ENV[$mode . '_API_ID'];
        $this->clientSecret = $_ENV[$mode . '_API_SECRET'];
        $this->apiurl = rtrim($_ENV[$mode . '_API_URL'], '/') . '/';
    }

    public function fetchAndSaveTransactions(\DateTimeImmutable $dateBerlin): ?array
    {
        $accessToken = $this->getAuthToken();

        $startDate = $dateBerlin->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $endDate = $dateBerlin->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $response = $this->client->request('GET', $this->apiurl . 'v1/reporting/transactions', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'query' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'fields' => 'all',
                'page_size' => 500
            ],
        ]);

        $data = $response->toArray();
        if (empty($data['transaction_details'])) {
            return null;
        }

        return $this->processAndSave($data['transaction_details'], $dateBerlin);
    }

    private function processAndSave(array $details, \DateTimeImmutable $dateBerlin): array
    {
        $csvRows = [];
        $stats = ['entries' => 0, 'missing_invoice' => 0];

        $csvDir = $_ENV['STOREFOLDER'] ?? 'var/storage/paypal/';
        if (!is_dir($csvDir)) mkdir($csvDir, 0775, true);

        $filename = sprintf('%spaypal-transactions-%s.csv', $csvDir, $dateBerlin->format('Y-m-d'));
        $fp = fopen($filename, 'w');
        fputcsv($fp, ['Datum', 'Name', 'Brutto', 'Gebühr', 'Netto', 'Rechnungsnummer', 'Guthaben'], ';');

        foreach ($details as $t) {
            $info = $t['transaction_info'] ?? [];
            $payer = $t['payer_info'] ?? [];

            // Mapping
            $rawDate = $info['transaction_initiation_date'] ?? 'now';
            $dt = new \DateTimeImmutable($rawDate, new \DateTimeZone('UTC'));
            $berlinDate = $dt->setTimezone(new \DateTimeZone('Europe/Berlin'));

            $payerName = trim(($payer['payer_name']['given_name'] ?? '') . ' ' . ($payer['payer_name']['surname'] ?? '')) ?: 'Unbekannt';
            $brutto = (float)($info['transaction_amount']['value'] ?? 0);
            $gebuehr = (float)($info['fee_amount']['value'] ?? 0);
            $netto = (float)($info['net_amount']['value'] ?? 0);
            $invoice = $info['invoice_id'] ?? '';
            $guthaben = (float)($info['ending_balance']['value'] ?? 0);

            // CSV Row
            $row = [$berlinDate->format('d.m.Y'), $payerName, $brutto, $gebuehr, $netto, $invoice, $guthaben];
            fputcsv($fp, $row, ';');

            // DB Entity
            $transaction = new Transactions();
            $transaction->setCreated(\DateTime::createFromImmutable($berlinDate));
            $transaction->setName($payerName);
            $transaction->setType('Paypal');
            $transaction->setBrutto($brutto);
            $transaction->setGebuehr($gebuehr);
            $transaction->setNetto($netto);
            $transaction->setAmount($guthaben);
            $transaction->setDescription($invoice ?: 'No Invoice');

            $this->em->persist($transaction);

            $stats['entries']++;
            if (empty($invoice)) $stats['missing_invoice']++;
        }

        $this->em->flush();
        fclose($fp);

        return $stats;
    }

    private function getAuthToken(): string
    {
        $response = $this->client->request('POST', $this->apiurl . 'v1/oauth2/token', [
            'auth_basic' => [$this->clientId, $this->clientSecret],
            'body' => ['grant_type' => 'client_credentials'],
        ]);
        return $response->toArray()['access_token'];
    }
}
