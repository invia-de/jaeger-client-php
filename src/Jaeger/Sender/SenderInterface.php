<?php

namespace Jaeger\Sender;

use Jaeger\Span;

interface SenderInterface
{
    /**
     * @param JaegerSpan $span
     *
     * @return int the number of flushed spans
     */
    public function append(Span $span): int;

    /**
     * @return int the number of flushed spans
     */
    public function flush(): int;

    public function close();
}
