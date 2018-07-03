<?php

namespace Jaeger\Sender;

use Jaeger\Thrift as JThrift;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Jaeger\Span;

class JaegerThriftSender implements SenderInterface
{
    /**
     * @var Span[]
     */
    private $spans = [];

    /**
     * @var int
     */
    private $batchSize;

    /**
     * @var JThrift\Agent\AgentClient
     */
    private $client;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * UdpSender constructor.
     * @param JThrift\Agent\AgentClient $client
     * @param int $batchSize
     * @param LoggerInterface $logger
     */
    public function __construct(
        JThrift\Agent\AgentClient $client,
        int $batchSize = 10,
        LoggerInterface $logger = null
    ) {
        $this->client = $client;
        $this->batchSize = $batchSize;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param Span $span
     *
     * @return int the number of flushed spans
     */
    public function append(Span $span): int
    {
        $this->spans[] = $span;

        if (count($this->spans) >= $this->batchSize) {
            return $this->flush();
        }

        return 0;
    }

    /**
     * @return int the number of flushed spans
     */
    public function flush(): int
    {
        $count = count($this->spans);
        if ($count === 0) {
            return 0;
        }

        $batches = $this->createBatch($this->spans);

        try {
            foreach ($batches as $batch) {
                $this->send($batch);
            }
        } catch (\Throwable $e) {
            $this->logger->warning($e->getMessage());
        }

        $this->spans = [];

        return $count;
    }

    public function close()
    {

    }

    private function send(JThrift\Batch $spans)
    {
        $this->client->emitBatch($spans);
    }

    /**
     * @param Span[] $spans
     *
     * @return JThrift\Batch[]
     */
    private function createBatch(array $spans): array
    {
        $tracer = null;
        $thriftSpans = [];
        $processList = [];

        foreach ($spans as $span) {
            /* @var Span $span */
            $tracer = $span->getTracer();

            $timestamp = $span->getStartTime();
            $duration = $span->getEndTime() - $span->getStartTime();

            $thriftSpan = new JThrift\Span([
                'traceIdLow' => (int) $span->getContext()->getTraceId(),
                'traceIdHigh' => 0, // @todo
                'spanId' => (int) $span->getContext()->getSpanId(),
                'parentSpanId' => (int) $span->getContext()->getParentId(),
                'operationName' => $span->getOperationName(),
                'references' => $this->createReferences($span),
                'flags' => (int) $span->getContext()->getFlags(),
                'startTime' => $timestamp,
                'duration' => $duration,
                'tags' => $this->createTags($span->getTags()),
                'logs' => $this->createLogs($span),
            ]);

            $peerServiceName = $span->isRpcClient() && isset($span->peer['service_name']) ? $span->peer['service_name'] : null;

            if ($peerServiceName) {
                $processList[$peerServiceName][] = $thriftSpan;
            } else {
                $thriftSpans[] = $thriftSpan;
            }
        }

        $process = new JThrift\Process([
            'serviceName' => $tracer->getServiceName(),
            'tags' => $this->createTags($tracer->getTags()),
        ]);

        $batchList[] = new JThrift\Batch([
            'process' => $process,
            'spans' => $thriftSpans,
        ]);

        foreach ($processList as $serviceName => $thriftSpans) {
            $batchList[] = new JThrift\Batch([
                'process' => new JThrift\Process([
                    'serviceName' => $serviceName,
                    'tags' => [],
                ]),
                'spans' => $thriftSpans,
            ]);
        }

        return $batchList;
    }

    /**
     * @param Span $span
     *
     * @return array|JThrift\SpanRef[]
     */
    private function createReferences(Span $span): array
    {
        $references = [];

        if ($span->getContext()->getParentId() != 0) {
            $references[] = new JThrift\SpanRef([
                'refType' => JThrift\SpanRefType::CHILD_OF,
                'traceIdLow' => (int) $span->getContext()->getTraceId(),
                'traceIdHigh' => 0, // @todo
                'spanId' => (int) $span->getContext()->getParentId(),
            ]);
        }

        return $references;
    }

    /**
     * @param string $key
     * @param bool|string|null|int|float $value
     *
     * @return JThrift\Tag
     */
    private function createTag(string $key, $value): JThrift\Tag
    {
        if (is_bool($value)) {
            return new JThrift\Tag([
                "key" => $key,
                "vType" => JThrift\TagType::BOOL,
                "vBool" => $value,
            ]);
        } elseif (is_string($value)) {
            return new JThrift\Tag([
                "key" => $key,
                "vType" => JThrift\TagType::STRING,
                "vStr" => $value,
            ]);
        } elseif ($value === null) {
            return new JThrift\Tag([
                "key" => $key,
                "vType" => JThrift\TagType::STRING,
                "vStr" => "",
            ]);
        } elseif (is_int($value)) {
            return new JThrift\Tag([
                "key" => $key,
                "vType" => JThrift\TagType::LONG,
                "vLong" => $value,
            ]);
        } elseif (is_numeric($value)) {
            return new JThrift\Tag([
                "key" => $key,
                "vType" => JThrift\TagType::DOUBLE,
                "vDouble" => $value,
            ]);
        }

        throw new \Exception("Cannot create tag for " . $key . " of type " . gettype($value));
    }

    /**
     * @param array $tagList
     *
     * @return array|JThrift\Tag[]
     */
    protected function createTags(array $tagList): array
    {
        $tags = [];

        foreach ($tagList as $key => $tag) {
            $tags[] = $this->createTag($key, $tag);
        }

        return $tags;
    }

    /**
     * @param Span $span
     *
     * @return array|JThrift\Log[]
     */
    protected function createLogs(Span $span): array
    {
        $logs = [];

        foreach ($span->getLogs() as list('timestamp' => $timestamp, 'fields' => $fields)) {
            $logs[] = new JThrift\Log([
                'timestamp' => $timestamp,
                'fields' => $this->createTags($fields),
            ]);
        }

        return $logs;
    }
}
