<?php

declare(strict_types=1);

namespace Crypto;

use Crypto\Exception\CryptoNotFoundException;
use GuzzleHttp\Client as GuzzleClient;
use Predis\Client as PredisClient;

class Price
{
    const DATA_ENDPOINT = 'https://web-api.coinmarketcap.com/v1/cryptocurrency/listings/latest?cryptocurrency_type=all&limit=4999&convert=';

    /**
     * @param GuzzleClient       $guzzleClient
     * @param PredisClient       $predisClient
     * @param CurrencyCollection $collection
     */
    public function __construct(
        private GuzzleClient $guzzleClient,
        private PredisClient $predisClient,
        private CurrencyCollection $collection
    )
    {
    }

    /**
     * @param string $currencyToShow
     * @return void
     *
     * @throws CryptoNotFoundException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getValue(string $currencyToShow): void
    {
        $redisKey = 'lametric:cryptocurrencies:' . strtolower($currencyToShow);

        $pricesFile = $this->predisClient->get($redisKey);
        $ttl        = $this->predisClient->ttl($redisKey);

        if (!$pricesFile || $ttl < 0) {
            $prices = $this->fetchData($currencyToShow);

            // save to redis
            $this->predisClient->set($redisKey, json_encode($prices));
            $this->predisClient->expireat($redisKey, strtotime('+5 minutes'));

            // manage error on results
            if (count($prices) === 0) {
                $this->getValue($currencyToShow);
            }
        } else {
            $prices = json_decode($pricesFile, true);
        }

        /** @var Currency $currency */
        foreach ($this->collection->getCurrencies() as $currency) {
            if (isset($prices[$currency->getCode()])) {
                $currency->setPrice((float) $prices[$currency->getCode()]['price']);
                $currency->setChange((float) $prices[$currency->getCode()]['change']);
            } else {
                throw new CryptoNotFoundException($currency->getCode());
            }
        }
    }

    /**
     * @param string $currencyToShow
     *
     * @return array
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function fetchData(string $currencyToShow): array
    {
        $endpoint = self::DATA_ENDPOINT . $currencyToShow;
        $resource = $this->guzzleClient->request('GET', $endpoint);

        $sources = json_decode((string) $resource->getBody(), true);

        $data = [];

        foreach ($sources['data'] as $crypto) {
            // manage multiple currencies with the same symbol
            // & override VAL value
            if (!isset($data[$crypto['symbol']]) || $crypto['symbol'] === 'VAL') {

                // manage error on results // maybe next time?
                if (!isset($crypto['quote'][$currencyToShow]['price'])) {
                    exit;
                }

                $data[$crypto['symbol']] = [
                    'short'  => $crypto['symbol'],
                    'price'  => $crypto['quote'][$currencyToShow]['price'],
                    'change' => round((float) $crypto['quote'][$currencyToShow]['percent_change_24h'], 2),
                ];
            }
        }

        return $data;
    }

    /**
     * @return CurrencyCollection
     */
    public function getCollection(): CurrencyCollection
    {
        return $this->collection;
    }
}
