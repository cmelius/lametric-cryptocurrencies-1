<?php

declare(strict_types=1);

namespace Crypto;

use Crypto\Exception\CryptoNotFoundException;
use GuzzleHttp\Client as GuzzleClient;
use Predis\Client as PredisClient;

class Price
{
    const DATA_ENDPOINT = 'https://web-api.coinmarketcap.com/v1/cryptocurrency/listings/latest?convert=USD&cryptocurrency_type=all&limit=4999';

    /**
     * @var GuzzleClient
     */
    private GuzzleClient $guzzleClient;

    /**
     * @var PredisClient
     */
    private PredisClient $predisClient;

    /**
     * @var CurrencyCollection
     */
    private CurrencyCollection $collection;

    /**
     * @param GuzzleClient $guzzleClient
     * @param PredisClient $predisClient
     * @param CurrencyCollection $collection
     */
    public function __construct(GuzzleClient $guzzleClient, PredisClient $predisClient, CurrencyCollection $collection)
    {
        $this->guzzleClient = $guzzleClient;
        $this->predisClient = $predisClient;
        $this->collection   = $collection;
    }

    /**
     * @return void
     * @throws CryptoNotFoundException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getValue(): void
    {
        $redisKey = 'lametric:cryptocurrencies';

        $pricesFile = $this->predisClient->get($redisKey);
        $ttl        = $this->predisClient->ttl($redisKey);

        if (!$pricesFile || $ttl < 0) {
            $rawData = $this->fetchData();

            $prices = $this->formatData($rawData);

            // save to redis
            $this->predisClient->set($redisKey, json_encode($prices));
            $this->predisClient->expireat($redisKey, strtotime("+1 minute"));
        } else {
            $prices = json_decode($pricesFile, true);
        }

        /** @var Currency $currency */
        foreach ($this->collection->getCurrencies() as $k => $currency) {
            if (isset($prices[$currency->getCode()])) {
                $currency->setPrice((float)$prices[$currency->getCode()]['price']);
                $currency->setChange((float)$prices[$currency->getCode()]['change']);
            } else {
                throw new CryptoNotFoundException($currency->getCode());
            }
        }
    }

    /**
     * @param $data
     *
     * @return array
     */
    public function formatData($data): array
    {
        $formattedData = [];

        foreach ($data as $currency) {
            $formattedData[$currency['short']] = [
                'price'  => $currency['price'],
                'change' => round($currency['change'], 2),
            ];
        }

        return $formattedData;
    }

    /**
     * @return array
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function fetchData(): array
    {
        $resource = $this->guzzleClient->request('GET', self::DATA_ENDPOINT);

        $sources = json_decode((string)$resource->getBody(), true);

        $data = [];

        foreach ($sources['data'] as $crypto) {
            // quick fix for DOS multiple currency
            if ($crypto['symbol'] === 'DOS' && $crypto['slug'] === 'demos') {
                continue;
            }

            // quick fix for UNI multiple currency
            if ($crypto['symbol'] === 'UNI' && $crypto['slug'] !== 'uniswap') {
                continue;
            }

            // quick fix for COMP multiple currency
            if ($crypto['symbol'] === 'COMP' && $crypto['slug'] === 'compound-coin') {
                continue;
            }

            // quick fix for UNI multiple currency
            if ($crypto['symbol'] === 'GRT' && $crypto['slug'] !== 'the-graph') {
                continue;
            }

            $data[] = [
                'short'  => $crypto['symbol'],
                'price'  => $crypto['quote']['USD']['price'],
                'change' => $crypto['quote']['USD']['percent_change_24h'],
            ];
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
