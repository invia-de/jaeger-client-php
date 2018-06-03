<?php

namespace Jaeger;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Jaeger\Codec\BinaryCodec;
use Jaeger\Codec\CodecInterface;
use Jaeger\Codec\TextCodec;
use Jaeger\Codec\ZipkinCodec;
use Jaeger\Reporter\ReporterInterface;
use Jaeger\Sampler\SamplerInterface;

use OpenTracing\Tracer as OTTracer;
use OpenTracing\SpanContext as OTSpanContext;
use OpenTracing\Reference;
use OpenTracing\StartSpanOptions;
use const OpenTracing\Formats\BINARY;
use const OpenTracing\Formats\HTTP_HEADERS;
use const OpenTracing\Formats\TEXT_MAP;
use const OpenTracing\Tags\SPAN_KIND;
use const OpenTracing\Tags\SPAN_KIND_RPC_SERVER;

class Tracer implements OTTracer
{
    /**
     * @var string
     */
    private $serviceName;

    /**
     * @var ReporterInterface
     */
    private $reporter;

    /**
     * @var SamplerInterface
     */
    private $sampler;

    private $ipAddress;

    private $metricsFactory;

    private $metrics;

    /**
     * @var string
     */
    private $debugIdHeader;

    /**
     * @var CodecInterface[]
     */
    private $codecs;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $oneSpanPerRpc;

    private $tags;

    /**
     * @var ScopeManager
     */
    private $scopeManager;

    /**
     * Tracer constructor.
     * @param string $serviceName
     * @param ReporterInterface $reporter
     * @param SamplerInterface $sampler
     * @param bool $oneSpanPerRpc
     * @param LoggerInterface|null $logger
     * @param ScopeManager|null $scopeManager
     * @param string $traceIdHeader
     * @param string $baggageHeaderPrefix
     * @param string $debugIdHeader
     * @param array|null $tags
     */
    public function __construct(
        string $serviceName,
        ReporterInterface $reporter,
        SamplerInterface $sampler,
        bool $oneSpanPerRpc = True,
        LoggerInterface $logger = null,
        ScopeManager $scopeManager = null,
        string $traceIdHeader = TRACE_ID_HEADER,
        string $baggageHeaderPrefix = BAGGAGE_HEADER_PREFIX,
        string $debugIdHeader = DEBUG_ID_HEADER_KEY,
        array $tags = null
    )
    {
        $this->serviceName = $serviceName;
        $this->reporter = $reporter;
        $this->sampler = $sampler;
        $this->oneSpanPerRpc = $oneSpanPerRpc;

        $this->logger = $logger ?? new NullLogger();
        $this->scopeManager = $scopeManager ?? new ScopeManager();

        $this->ipAddress = getHostByName(getHostName());

        $this->debugIdHeader = $debugIdHeader;

        $this->codecs = [
            TEXT_MAP => new TextCodec(
                False,
                $traceIdHeader,
                $baggageHeaderPrefix,
                $debugIdHeader
            ),
            HTTP_HEADERS => new TextCodec(
                True,
                $traceIdHeader,
                $baggageHeaderPrefix,
                $debugIdHeader
            ),
            BINARY => new BinaryCodec(),
            ZIPKIN_SPAN_FORMAT => new ZipkinCodec(),
        ];

        $this->tags = [
            JAEGER_VERSION_TAG_KEY => JAEGER_CLIENT_VERSION,
        ];
        if ($tags !== null) {
            $this->tags = array_merge($this->tags, $tags);
        }

        $hostname = gethostname();
        if ($hostname === FALSE) {
            $this->logger->error('Unable to determine host name');
        } else {
            $this->tags[JAEGER_HOSTNAME_TAG_KEY] = $hostname;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function startSpan($operationName, $options = [])
    {
        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
        }

        $parent = $options['child_of'] ?? null;
        $tags = $options['tags'] ?? null;
        $startTime = $options['startTime'] ?? null;

//        if ($options['references']) {
//            if (is_array($options['references'])) {
//                $references = $options['references'][0];
//            }
//            $parent = $references->referenced_context;
//        }

        if ($parent instanceof Span) {
            /** @var SpanContext $parent */
            $parent = $parent->getContext();
        }

        $rpcServer = ($tags !== null) &&
            ($tags[SPAN_KIND] ?? null) == SPAN_KIND_RPC_SERVER;

        if ($parent === null || $parent->isDebugIdContainerOnly()) {
            $traceId = $this->randomId();
            $spanId = $traceId;
            $parentId = null;
            $flags = 0;
            $baggage = null;
            if ($parent === null) {
                list($sampled, $samplerTags) = $this->sampler->isSampled($traceId, $operationName);
                if ($sampled) {
                    $flags = SAMPLED_FLAG;
                    $tags = $tags ?? [];
                    foreach ($samplerTags as $key => $value) {
                        $tags[$key] = $value;
                    }
                }
            } else {  // have debug id
                $flags = SAMPLED_FLAG | DEBUG_FLAG;
                $tags = $tags ?? [];
                $tags[$this->debugIdHeader] = $parent->getDebugId();
            }
        } else {
            $traceId = $parent->getTraceId();
            if ($rpcServer && $this->oneSpanPerRpc) {
                // Zipkin-style one-span-per-RPC
                $spanId = $parent->getSpanId();
                $parentId = $parent->getParentId();
            } else {
                $spanId = $this->randomId();
                $parentId = $parent->getSpanId();
            }

            $flags = $parent->getFlags();
            $baggage = $parent->getBaggage();
        }

        $spanContext = new SpanContext(
            $traceId,
            $spanId,
            $parentId,
            $flags,
            $baggage
        );

        $span = new Span(
            $spanContext,
            $this,
            $operationName,
            $tags ?? [],
            $startTime
        );

        if (($rpcServer || $parentId === null) && ($flags & SAMPLED_FLAG)) {
            // this is a first-in-process span, and is sampled
            $span->setTags($this->tags);
        }

        return $span;
    }

    /**
     * {@inheritdoc}
     */
    public function inject(OTSpanContext $spanContext, $format, &$carrier)
    {
        if ($spanContext instanceof SpanContext) {
            $setter = $this->getSetterByFormat($format);
            $injector = $this->propagation->getInjector($setter);
            return $injector($spanContext->getContext(), $carrier);
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid span context. Expected Jaeger\SpanContext, got %s.',
            is_object($spanContext) ? get_class($spanContext) : gettype($spanContext)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function extract($format, $carrier)
    {
//        $getter = $this->getGetterByFormat($format);
//        $extractor =  $this->propagation->getExtractor($getter);
//        $extractedContext = $extractor($carrier);
//
//        if ($extractedContext instanceof TraceContext) {
//            return ZipkinOpenTracingContext::fromTraceContext($extractedContext);
//        }
//
//        if ($extractedContext instanceof SamplingFlags) {
//            return ZipkinOpenPartialTracingContext::fromSamplingFlags($extractedContext);
//        }
//
//        throw new \UnexpectedValueException(sprintf(
//            'Invalid extracted context. Expected Zipkin\SamplingFlags, got %s',
//            is_object($extractedContext) ? get_class($extractedContext) : gettype($extractedContext)
//        ));
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->reporter->close();
    }

    public function reportSpan(Span $span)
    {
        $this->reporter->reportSpan($span);
    }

    /**
     * {@inheritdoc}
     */
    public function getScopeManager()
    {
        return $this->scopeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveSpan()
    {
        $activeScope = $this->getScopeManager()->getActive();
        if ($activeScope === null) {
            return null;
        }

        return $activeScope->getSpan();
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $options = [])
    {
        if (!$options instanceof StartSpanOptions) {
            $options = StartSpanOptions::create($options);
        }

        if (!$this->hasParentInOptions($options) && $this->getActiveSpan() !== null) {
            $parent = $this->getActiveSpan()->getContext();
            $options = $options->withParent($parent);
        }

        $span = $this->startSpan($operationName, $options);
        $scope = $this->scopeManager->activate($span, $options->shouldFinishSpanOnClose());

        return $scope;
    }

    /**
     * @param StartSpanOptions $options
     * @return null|OTSpanContext
     */
    private function hasParentInOptions(StartSpanOptions $options)
    {
        $references = $options->getReferences();
        foreach ($references as $ref) {
            if ($ref->isType(Reference::CHILD_OF)) {
                return $ref->getContext();
            }
        }

        return null;
    }

    /**
     * @return int
     * @throws Exception
     */
    private function randomId()
    {
        return random_int(0, PHP_INT_MAX);
    }

    /**
     * @param SamplerInterface $sampler
     * @return $this
     */
    public function setSampler(SamplerInterface $sampler)
    {
        $this->sampler = $sampler;

        return $this;
    }

    /**
     * @return string
     */
    public function getServiceName()
    {
        return $this->serviceName;
    }

    /**
     * @return string
     */
    public function getIpAddress()
    {
        return $this->ipAddress;
    }
}
