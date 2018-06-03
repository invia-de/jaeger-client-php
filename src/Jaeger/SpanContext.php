<?php

namespace Jaeger;

use ArrayIterator;
use OpenTracing\SpanContext as OTSpanContext;

class SpanContext implements OTSpanContext
{
    private $traceId;
    private $spanId;
    private $parentId;
    private $flags;
    private $baggage;
    private $debugId;

    /**
     * SpanContext constructor.
     * @param $traceId
     * @param $spanId
     * @param $parentId
     * @param $flags
     * @param array $baggage
     */
    public function __construct($traceId, $spanId, $parentId, $flags, $baggage = [])
    {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentId = $parentId;
        $this->flags = $flags;
        $this->baggage = $baggage;
        $this->debugId = null;
    }

    public static function withDebugId($debugId)
    {
        $ctx = new SpanContext(null, null, null, null);
        $ctx->debugId = $debugId;

        return $ctx;
    }

    public function getIterator()
    {
        return new ArrayIterator($this->baggage);
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
        // Not implemented
    }

    /**
     * {@inheritdoc}
     */
    public function withBaggageItem($key, $value)
    {
        // Not implemented
    }

    /**
     * @return int
     */
    public function getTraceId()
    {
        return $this->traceId;
    }

    /**
     * @return int|null
     */
    public function getParentId()
    {
        return $this->parentId;
    }

    /**
     * @return int
     */
    public function getSpanId()
    {
        return $this->spanId;
    }

    public function getFlags()
    {
        return $this->flags;
    }

    public function getBaggage()
    {
        return $this->baggage;
    }

    public function getDebugId()
    {
        return $this->debugId;
    }

    public function isDebugIdContainerOnly(): bool
    {
        return ($this->traceId === null) && ($this->debugId !== null);
    }
}
