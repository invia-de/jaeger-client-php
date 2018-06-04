<?php

namespace Jaeger;

use Jaeger\Reporter\ReporterInterface;
use Jaeger\Sampler\SamplerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ScopeManager
     */
    private $scopeManager;

    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * @var string
     */
    private $serviceName = 'test-service';

    /**
     * @var string
     */
    private $operationName = 'test-operation';

    function setUp()
    {
        $this->scopeManager = $this->createMock(ScopeManager::class);
        $this->sampler = $this->createMock(SamplerInterface::class);
        $this->reporter = $this->createMock(ReporterInterface::class);
        $this->logger = new NullLogger();

        $this->tracer = new Tracer($this->serviceName, $this->reporter, $this->sampler, true, $this->logger, $this->scopeManager);
    }

    function testStartSpan()
    {
        $span = $this->tracer->startSpan($this->operationName);

        $this->assertEquals($this->operationName, $span->getOperationName());
    }

//    function testStartActiveSpan()
//    {
//
//    }

//    function testInject()
//    {
//        $this->tracer->inject();
//    }

//    function testExtract()
//    {
//        $this->tracer->extract();
//    }

    function testGetScopeManager()
    {
        $this->assertEquals($this->scopeManager, $this->tracer->getScopeManager());
    }

    function testGetActiveSpan()
    {
        $span = $this->createMock(Span::class);

        $scope = $this->createMock(Scope::class);
        $scope->expects($this->once())->method('getSpan')->willReturn($span);

        $this->scopeManager->expects($this->once())->method('getActive')->willReturn($scope);

        $this->assertEquals($span, $this->tracer->getActiveSpan());
    }

    function testGetActiveSpanNull()
    {
        $this->scopeManager->expects($this->once())->method('getActive')->willReturn(null);

        $this->assertEquals(null, $this->tracer->getActiveSpan());
    }

    function testFlush()
    {
        $this->reporter->expects($this->once())->method('close');

        $this->tracer->flush();
    }
}
