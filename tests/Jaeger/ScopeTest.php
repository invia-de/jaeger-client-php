<?php

namespace Jaeger;

use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase
{
    /**
     * @var ScopeManager
     */
    private $scopeManager;

    /**
     * @var Span
     */
    private $span;

    function setUp()
    {
        $this->scopeManager = new ScopeManager();
        $this->span = $this->createMock(Span::class);
    }

    function testCloseDoNotFinishSpanOnClose()
    {
        $scope = new Scope($this->scopeManager, $this->span, false);
    }

    function testCloseFinishSpanOnClose()
    {
        $scope = new Scope($this->scopeManager, $this->span, true);
    }

    function testGetSpan()
    {
        $scope = new Scope($this->scopeManager, $this->span, false);

        $this->assertEquals($this->span, $scope->getSpan());
    }
}
