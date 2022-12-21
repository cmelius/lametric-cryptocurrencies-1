<?php

require __DIR__ . '/../vendor/autoload.php';

$parameters = require_once __DIR__ . '/../config/parameters.php';

use GuzzleHttp\Client as GuzzleClient;
use Predis\Client as PredisClient;

$http = new GuzzleClient();

$allCurrencies = [];

for ($i = 1; $i <= 25; $i++) {

    echo 'Page ' . $i . PHP_EOL;

    if (isset($parameters['proxies']) && count($parameters['proxies']) > 0) {
        $totalOfProxies = count($parameters['proxies']);
        $headers = [
            'proxy' => $parameters['proxies'][rand(0, $totalOfProxies - 1)],
            'force_ip_resolve' => 'v4',
        ];
    } else {
        $headers = [];
    }

    $response = $http->request(
        'GET',
        'https://api.coingecko.com/api/v3/coins/markets?vs_currency=USD&per_page=250&page=' . $i,
        $headers
    );

    $currencies = json_decode(strval($response->getBody()), true);

    foreach ($currencies as $currency) {
        $symbol = strtoupper($currency['symbol']);
        $price = $currency['current_price'];
        $percent = $currency['price_change_percentage_24h'];

        if(!isset($allCurrencies[$symbol])) {
            $allCurrencies[$symbol] = [
                'price' => $price,
                'change' => $percent
            ];
        }
    }

    // avoid 429 errors
    sleep(1);
}

$redisKey = 'lametric:cryptocurrencies';

$predis = new PredisClient();

$redisObject = $predis->get($redisKey);

$predis->set($redisKey, json_encode($allCurrencies));

exit(0);
