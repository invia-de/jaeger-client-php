<?php

namespace Jaeger;

use PHPUnit\Framework\TestCase;

class ScopeManagerTest extends TestCase
{
    /**
     * @var ScopeManager
     */
    private $scopeManager;

    function setUp()
    {
        $this->scopeManager = new ScopeManager();
    }

    function testAbleGetActiveScope()
    {
        $span = $this->createMock(Span::class);

        $this->assertNull($this->scopeManager->getActive());
        $scope = $this->scopeManager->activate($span, false);

        $this->assertEquals($scope, $this->scopeManager->getActive());
    }

    function testScopeClosingDeactivates()
    {
        $span = $this->createMock(Span::class);

        $scope = $this->scopeManager->activate($span, false);
        $scope->close();

        $this->assertNull($this->scopeManager->getActive());
    }
}
