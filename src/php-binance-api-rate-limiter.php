<?php

/*
 * ============================================================
 * @package php-binance-api
 * @link https://github.com/jaggedsoft/php-binance-api
 * ============================================================
 * @copyright 2017-20201
 * @author Jon Eyrick
 * @license MIT License
 * ============================================================
 * A curl HTTP REST wrapper for the binance currency exchange
 */
namespace Binance;

// PHP version check
if (version_compare(phpversion(), '7.0', '<=')) {
    fwrite(STDERR, "Hi, PHP " . phpversion() . " support will be removed very soon as part of continued development.\n");
    fwrite(STDERR, "Please consider upgrading.\n");
}

/**
 * Wrapper/Decorator for the binance api, providing rate limiting
 *
 * Eg. Usage:
 * require 'vendor/autoload.php';
 * $api = new Binance\\API();
 * $api = new Binance\\RateLimiter($api);
 */

class RateLimiter
{
    /**
     * @var API
     */
    private $api = null;

    /**
     * @var array<string,int>
     */
    private $weights = [];
    
    /**
     * @var array<string>
     */
    private array $ordersfunctions = [];
    
    /**
     * @var float
     */
    private float $exchangeRequestsRateLimit = 10;
    
    /**
     * @var float
     */
    private float $exchangeOrdersRateLimit = 10;
    
    /**
     * @var float
     */
    private float $exchangeOrdersDailyLimit = 10;
    
    /**
     * @var int[]
     */
    private $requestsQueue = array();
    
    /**
     * @var int[]
     */
    private $ordersQueue = array();
    
    /**
     * @var int[]
     */
    private $ordersDayQueue = array();

    /**
     * @param API $api
     */
    public function __construct(API $api)
    {
        $this->api = $api;

        $this->weights = array(
            'account' => 5,
            'addToTransfered' => 0,
            'aggTrades' => 1,
            'balances' => 1,
            'bookPrices' => 1,
            'buy' => 1,
            'buyTest' => 1,
            'cancel' => 1,
            'candlesticks' => 1,
            'chart' => 0,
            'cumulative' => 0,
            'depositAddress' => 1,
            'depositHistory' => 1,
            'assetDetail' => 1,
            'depth' => 1,
            'depthCache' => 1,
            'displayDepth' => 1,
            'exchangeInfo' => 1,
            'first' => 0,
            'getProxyUriString' => 0,
            'getRequestCount' => 0,
            'getTransfered' => 0,
            'highstock' => 1,
            'history' => 5,
            'keepAlive' => 0,
            'kline' => 1,
            'last' => 0,
            'marketBuy' => 1,
            'marketBuyTest' => 1,
            'marketSell' => 1,
            'marketSellTest' => 1,
            'miniTicker' => 1,
            'openOrders' => 2,
            'order' => 1,
            'orders' => 5,
            'orderStatus' => 1,
            'prevDay' => 2,
            'prices' => 1,
            'report' => 0,
            'sell' => 1,
            'sellTest' => 1,
            'setProxy' => 0,
            'sortDepth' => 1,
            'terminate' => 0,
            'ticker' => 1,
            'time' => 1,
            'trades' => 5,
            'userData' => 1,
            'useServerTime' => 1,
            'withdraw' => 1,
            'withdrawFee' => 1,
            'withdrawHistory' => 1,
        );

        $this->ordersfunctions = array(
            'buy',
            'buyTest',
            'cancel',
            'history',
            'marketBuy',
            'marketBuyTest',
            'marketSell',
            'marketSellTest',
            'openOrders',
            'order',
            'orders',
            'orderStatus',
            'sell',
            'sellTest',
            'trades',
        );

        $this->init();
    }

    /**
     * @return void
     */
    private function init(): void
    {
        $exchangeLimits = $this->api->exchangeInfo()['rateLimits'];

        if (is_array($exchangeLimits) === false) {
            print "Problem getting exchange limits\n";
            return;
        }

        foreach ($exchangeLimits as $exchangeLimit) {
            switch ($exchangeLimit['rateLimitType']) {
                case "REQUESTS":
                    $this->exchangeRequestsRateLimit = round($exchangeLimit['limit'] * 0.95);
                    break;
                case "ORDERS":
                    if ($exchangeLimit['interval'] === "SECOND") {
                        $this->exchangeOrdersRateLimit = round($exchangeLimit['limit'] * 0.9);
                    }
                    if ($exchangeLimit['interval'] === "DAY") {
                        $this->exchangeOrdersDailyLimit = round($exchangeLimit['limit'] * 0.98);
                    }
                    break;
            }
        }
    }

    /**
     * magic get for private and protected members
     *
     * @param string $member string the name of the property to return
     * 
     * @return mixed
     */
    public function __get(string $member): mixed
    {
        return property_exists($this->api, $member) ? $this->api->$member : null;
    }

    /**
     * magic set for private and protected members
     *
     * @param string $member string the name of the member property
     * @param mixed $value the value of the member property
     * 
     * @return void
     */
    public function __set(string $member, mixed $value): void
    {
        $this->api->$member = $value;
    }

    /**
     * @return void
     */
    private function requestsPerMinute(): void
    {
        // requests per minute restrictions
        if (count($this->requestsQueue) === 0) {
            return;
        }

        while (count($this->requestsQueue) > $this->exchangeOrdersDailyLimit) {
            $oldest = isset($this->requestsQueue[0]) ? $this->requestsQueue[0] : time();
            while ($oldest < time() - 60) {
                array_shift($this->requestsQueue);
                $oldest = isset($this->requestsQueue[0]) ? $this->requestsQueue[0] : time();
            }
            print "Rate limiting in effect for requests " . PHP_EOL;
            sleep(1);
        }
    }

    /**
     * @return void
     */
    private function ordersPerSecond(): void
    {
        // orders per second restrictions
        if (count($this->ordersQueue) === 0) {
            return;
        }

        while (count($this->ordersQueue) > $this->exchangeOrdersRateLimit) {
            $oldest = isset($this->ordersQueue[0]) ? $this->ordersQueue[0] : time();
            while ($oldest < time() - 1) {
                array_shift($this->ordersQueue);
                $oldest = isset($this->ordersQueue[0]) ? $this->ordersQueue[0] : time();
            }
            print "Rate limiting in effect for orders " . PHP_EOL;
            sleep(1);
        }
    }

    /**
     * @return void
     */
    private function ordersPerDay(): void
    {
        // orders per day restrictions
        if (count($this->ordersDayQueue) === 0) {
            return;
        }

        $yesterday = time() - (24 * 60 * 60);
        while (count($this->ordersDayQueue) > round($this->exchangeOrdersDailyLimit * 0.8)) {
            $oldest = isset($this->ordersDayQueue[0]) ? $this->ordersDayQueue[0] : time();
            while ($oldest < $yesterday) {
                array_shift($this->ordersDayQueue);
                $oldest = isset($this->ordersDayQueue[0]) ? $this->ordersDayQueue[0] : time();
            }
            print "Rate limiting in effect for daily order limits " . PHP_EOL;

            $remainingRequests = round($this->exchangeOrdersDailyLimit * 0.2);
            $remainingSeconds = $this->ordersDayQueue[0] - $yesterday;

            $sleepTime = ($remainingSeconds > $remainingRequests) ? round($remainingSeconds / $remainingRequests) : 1;
            sleep((int) $sleepTime);
        }
    }

    /**
     * magic call to redirect call to the API, capturing and monitoring the weight limit
     *
     * @param $name the function to call
     * @param $arguments the paramters to the function
     */
    /**
     * @param string $name
     * @param array<mixed> $arguments
     * 
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        $weight = $this->weights[$name] ?? false;

        if ($weight && $weight > 0) {
            $this->requestsPerMinute();
            if (in_array($name, $this->ordersfunctions) === true) {
                $this->ordersPerSecond();
                $this->ordersPerDay();
            }

            $c_time = time();

            for ($w = 0; $w < $weight; $w++) {
                $this->requestsQueue[] = $c_time;
            }

            if (in_array($name, $this->ordersfunctions) === true) {
                for ($w = 0; $w < $weight; $w++) {
                    $this->ordersQueue[] = $c_time;
                    $this->ordersDayQueue[] = $c_time;
                }
            }
        }
        
        /**
         * @phpstan-ignore-next-line
         */
        return call_user_func_array(array(&$this->api, $name), $arguments);
    }
}
