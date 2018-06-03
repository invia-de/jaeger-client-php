<?php

namespace Jaeger;

use Jaeger\ThriftGen\AnnotationType;
use Jaeger\ThriftGen\BinaryAnnotation;
use OpenTracing\Span as OTSpan;

use const OpenTracing\Tags\COMPONENT;
use const OpenTracing\Tags\PEER_HOST_IPV4;
use const OpenTracing\Tags\PEER_PORT;
use const OpenTracing\Tags\PEER_SERVICE;
use const OpenTracing\Tags\SPAN_KIND;
use const OpenTracing\Tags\SPAN_KIND_RPC_CLIENT;
use const OpenTracing\Tags\SPAN_KIND_RPC_SERVER;

class Span implements OTSpan
{
    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * @var SpanContext
     */
    private $context;

    /**
     * @var string
     */
    private $operationName;

    /**
     * @var float
     */
    private $startTime;

    /**
     * @var float
     */
    private $endTime;

    /**
     * SPAN_RPC_CLIENT
     * @var null|string
     */
    private $kind;

    /**
     * @var array|null
     */
    public $peer;

    private $component;

    private $logs;

    /**
     * @var BinaryAnnotation[]
     */
    public $tags = [];

    /**
     * @var bool
     */
    private $debug = false;

    /**
     * Span constructor.
     * @param SpanContext $context
     * @param Tracer $tracer
     * @param string $operationName
     * @param array $tags
     * @param float|null $startTime
     */
    public function __construct(
        SpanContext $context,
        Tracer $tracer,
        string $operationName,
        array $tags = [],
        float $startTime = null
    )
    {
        $this->context = $context;
        $this->tracer = $tracer;

        $this->operationName = $operationName;
        $this->startTime = $startTime ?? $this->timestampMicro();
        $this->endTime = null;
        $this->kind = null;
        $this->peer = null;
        $this->component = null;

        $this->logs = [];
        foreach ($tags as $key => $value) {
            $this->setTag($key, $value);
        }
    }

    /**
     * @return Tracer
     */
    public function getTracer()
    {
        return $this->tracer;
    }

    /**
     * @return bool
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * @return float|null
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * @return float|null
     */
    public function getEndTime()
    {
        return $this->endTime;
    }

    /**
     * @return string
     */
    public function getOperationName()
    {
        return $this->operationName;
    }

    /** @return mixed */
    public function getComponent()
    {
        // TODO
        return $this->component;
    }

    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * {@inheritdoc}
     */
    public function finish($finishTime = null, array $logRecords = [])
    {
        if (!$this->isSampled()) {
            return;
        }

        foreach ($logRecords as $logRecord) {
            $this->log($logRecord);
        }

        $this->endTime = $finishTime ?? $this->timestampMicro();
        $this->tracer->reportSpan($this);
    }

    public function isSampled(): bool
    {
        return $this->getContext()->getFlags() & SAMPLED_FLAG == SAMPLED_FLAG;
    }

    /**
     * {@inheritdoc}
     */
    public function overwriteOperationName($newOperationName)
    {
        // TODO log warning
        $this->operationName = $newOperationName;
    }

    /**
     * {@inheritdoc}
     */
    public function setTags(array $tags)
    {
        foreach ($tags as $key => $value) {
            $this->setTag($key, $value);
        }
    }

    /**
     * @param string $key
     * @param bool|float|int|string $value
     * @return Span
     */
    public function setTag($key, $value)
    {
//        if ($key == SAMPLING_PRIORITY) {
//        }

        if ($this->isSampled()) {
            $special = self::SPECIAL_TAGS[$key] ?? null;
            $handled = false;

            if ($special !== null && is_callable([$this, $special])) {
                $handled = $this->$special($value);
            }

            if (!$handled) {
                $tag = $this->makeStringTag($key, (string) $value);
                $this->tags[$key] = $tag;
            }
        }

        return $this;
    }

    const SPECIAL_TAGS = [
        PEER_SERVICE => 'setPeerService',
        PEER_HOST_IPV4 => 'setPeerHostIpv4',
        PEER_PORT => 'setPeerPort',
        SPAN_KIND => 'setSpanKind',
        COMPONENT => 'setComponent',
    ];

    /**
     * @param $value
     * @return bool
     */
    private function setComponent($value)
    {
        $this->component = $value;
        return true;
    }

    /**
     * @param $value
     * @return bool
     */
    private function setSpanKind($value)
    {
        if ($value === null || $value === SPAN_KIND_RPC_CLIENT || $value === SPAN_KIND_RPC_SERVER) {
            $this->kind = $value;
            return true;
        }
        return false;
    }

    /**
     * @param $value
     * @return bool
     */
    private function setPeerPort($value)
    {
        if ($this->peer === null) {
            $this->peer = ['port' => $value];
        } else {
            $this->peer['port'] = $value;
        }
        return true;
    }

    /**
     * @param $value
     * @return bool
     */
    private function setPeerHostIpv4($value)
    {
        if ($this->peer === null) {
            $this->peer = ['ipv4' => $value];
        } else {
            $this->peer['ipv4'] = $value;
        }
        return true;
    }

    /**
     * @param $value
     * @return bool
     */
    private function setPeerService($value)
    {
        if ($this->peer === null) {
            $this->peer = ['service_name' => $value];
        } else {
            $this->peer['service_name'] = $value;
        }
        return true;
    }

    /**
     * @return bool
     */
    public function isRpc()
    {
        return $this->kind == SPAN_KIND_RPC_CLIENT || $this->kind == SPAN_KIND_RPC_SERVER;
    }

    /**
     * @return bool
     */
    public function isRpcClient()
    {
        return $this->kind == SPAN_KIND_RPC_CLIENT;
    }

    /**
     * {@inheritdoc}
     */
    public function log(array $fields = [], $timestamp = null)
    {
        // TODO: Implement log() method.
    }

    /**
     * {@inheritdoc}
     */
    public function addBaggageItem($key, $value)
    {
        $this->context = $this->context->withBaggageItem($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
        return $this->context->getBaggageItem($key);
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    private function timestampMicro(): int
    {
        return round(microtime(true) * 1000000);
    }

    private function makeStringTag(string $key, string $value): BinaryAnnotation
    {
        if (strlen($value) > 256) {
            $value = substr($value, 0, 256);
        }
        return new BinaryAnnotation([
            'key' => $key,
            'value' => $value,
            'annotation_type' => AnnotationType::STRING,
        ]);
    }
}
