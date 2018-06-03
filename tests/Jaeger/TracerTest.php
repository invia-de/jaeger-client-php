<?php

namespace Jaeger;

use Jaeger\Reporter\ReporterInterface;
use Jaeger\Sampler\SamplerInterface;
use PHPUnit\Framework\TestCase;

class TracerTest extends TestCase
{
    /**
     * @var ReporterInterface
     */
    private $reporter;

    /**
     * @var SamplerInterface
     */
    private $sampler;

    /**
     * @var Tracer
     */
    private $tracer;

    function setUp()
    {
        $this->reporter = $this->createMock(ReporterInterface::class);
        $this->sampler = $this->createMock(SamplerInterface::class);

        $this->tracer = new Tracer('test-service', $this->reporter, $this->sampler);
    }

    function testStartSpan()
    {
        $span = $this->tracer->startSpan('test-operation');
    }
}
