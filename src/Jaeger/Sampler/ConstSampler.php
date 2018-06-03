<?php

namespace Jaeger\Sampler;
use const Jaeger\SAMPLER_PARAM_TAG_KEY;
use const Jaeger\SAMPLER_TYPE_CONST;
use const Jaeger\SAMPLER_TYPE_TAG_KEY;

/**
 * ConstSampler always returns the same decision.
 */
class ConstSampler implements SamplerInterface
{
    /**
     * @var bool
     */
    private $decision;

    /**
     * @var array
     */
    private $tags = [];

    /**
     * ConstSampler constructor.
     * @param bool $decision
     */
    public function __construct($decision = true)
    {
        $this->tags = [
            SAMPLER_TYPE_TAG_KEY => SAMPLER_TYPE_CONST,
            SAMPLER_PARAM_TAG_KEY => $decision,
        ];
        $this->decision = $decision;
    }

    /**
     * @param $traceId
     * @param string $operation
     * @return array
     */
    public function isSampled($traceId, $operation = '')
    {
        return array($this->decision, $this->tags);
    }

    public function close()
    {
    }
}
