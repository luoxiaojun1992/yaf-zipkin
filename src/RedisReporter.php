<?php

namespace Lxj\Yaf\Zipkin;

use RuntimeException;
use Zipkin\Recording\Span;
use Zipkin\Reporter;
use Zipkin\Reporters\Metrics;
use Zipkin\Reporters\NoopMetrics;

final class RedisReporter implements Reporter
{
    const DEFAULT_OPTIONS = [
        'queue_name' => 'queue:zipkin:span',
        'connection' => 'zipkinRedis',
    ];

    /**
     * @var array
     */
    private $options;

    /**
     * @var Metrics
     */
    private $reportMetrics;

    public function __construct(
        array $options = [],
        Metrics $reporterMetrics = null
    ) {
        $this->options = array_merge(self::DEFAULT_OPTIONS, $options);
        $this->reportMetrics = $reporterMetrics ?: new NoopMetrics();
    }

    /**
     * @param Span[] $spans
     * @return void
     */
    public function report(array $spans)
    {
        if (!$spans) {
            return;
        }

        $payload = json_encode(array_map(function (Span $span) {
            return $span->toArray();
        }, $spans));

        $this->reportMetrics->incrementSpans(count($spans));
        $this->reportMetrics->incrementMessages();

        $payloadLength = strlen($payload);
        $this->reportMetrics->incrementSpanBytes($payloadLength);
        $this->reportMetrics->incrementMessageBytes($payloadLength);

        try {
            $this->enqueue($payload);
        } catch (RuntimeException $e) {
            $this->reportMetrics->incrementSpansDropped(count($spans));
            $this->reportMetrics->incrementMessagesDropped($e);
        }
    }

    private function enqueue($payload)
    {
        $redisClient = $this->getRedisClient();
        if (is_null($redisClient)) {
            Helper::log('Zipkin report error: redis client is null', 'error', 'zipkin');
            return;
        }

        if (empty($this->options['queue_name'])) {
            Helper::log('Zipkin report error: redis queue name is empty', 'error', 'zipkin');
            return;
        }

        $redisClient->listPush($this->options['queue_name'], $payload);
    }

    private function getRedisClient()
    {
        if (!empty($this->options['connection'])) {
            $connectionName = $this->options['connection'];
            if ($redisClient = \Yaf_Registry::get($connectionName)) {
                return $redisClient;
            }
        }

        return null;
    }
}
