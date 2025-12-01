<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class StripeService
{
    private HttpClientInterface $client;
    private string $apiKey;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        $this->apiKey = $_ENV['STRIPE_API_KEY'];
    }

    public function fetchTransactions(int $start, int $end){
        $url = $_ENV['STIPE_API_URL'] . 'v1/balance_transactions?created[gte]=' . $start . '&created[lte]=' . $end . '&limit=500';
        $response = $this->client->request(
            'GET',
            $url,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey
                    ]
            ]);
        return $response->toArray();
    }

    public function generateData(array $data){
        $rows = [];
        foreach($data['data'] as $item){
            $date = (new \DateTimeImmutable())->setTimestamp($item['created'])->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
            $name =  'N/A';
            $gross = number_format($item['amount'] / 100, 2, ',', '.');
            $fee = number_format($item['fee'] / 100, 2, ',', '.');
            $net = number_format($item['net'] / 100, 2, ',', '.');
            $invoice = $item['description'] ?? '';
            $balance = 0;

            $rows[] = [$date, $name, $gross, $fee, $net, $invoice, $balance];
        }
        return $rows;
    }

    public function saveCsv(array $csvRows, \DateTimeImmutable $dateBerlin): void{
        $csvDir = $_ENV['STOREFOLDER_STRIPE'];
        if (!is_dir($csvDir)) {
            mkdir($csvDir, 0775, true);
        }

        $reportDate = $dateBerlin->format('Y-m-d');
        $filename = sprintf('%sstripe-transactions-%s.csv', $csvDir, $reportDate);
        $fp = fopen($filename, 'w');
        fputcsv($fp, ['Datum', 'Name', 'Brutto', 'Gebühr', 'Netto', 'Rechnungsnummer', 'Guthaben'], ';');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row, ';');
        }
        fclose($fp);
    }



}
