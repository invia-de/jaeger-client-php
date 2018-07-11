<?php

namespace Jaeger\Reporter;

use Jaeger\Sender\SenderInterface;
use Jaeger\Span;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RemoteReporter implements ReporterInterface
{
    /**
     * @var SenderInterface
     */
    private $transport;

    /**
     * RemoteReporter constructor.
     *
     * @param SenderInterface $transport
     */
    public function __construct(SenderInterface $transport)
    {
        $this->transport = $transport;
    }

    /**
     * @param Span $span
     */
    public function reportSpan(Span $span)
    {
        $this->transport->append($span);
    }

    public function close()
    {
        $this->transport->flush();
        $this->transport->close();
    }
}
