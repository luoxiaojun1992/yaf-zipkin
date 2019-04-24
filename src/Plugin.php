<?php

namespace Lxj\Yaf\Zipkin;

use const Zipkin\Kind\SERVER;
use Zipkin\Span;
use const Zipkin\Tags\ERROR;
use const Zipkin\Tags\HTTP_HOST;
use const Zipkin\Tags\HTTP_METHOD;
use const Zipkin\Tags\HTTP_PATH;
use const Zipkin\Tags\HTTP_STATUS_CODE;

class Plugin extends \Yaf_Plugin_Abstract
{
    /** @var Tracer */
    private $tracer;

    private $startMemory = 0;

    /** @var Span */
    private $span;

    private $config;

    public function __construct()
    {
        $this->config = \Yaf_Application::app()->getConfig()->zipkin->config->toArray();

        \Yaf_Registry::set('zipkin', new Tracer($this->config));
    }

    private function needSample(\Yaf_Request_Abstract $yafRequest)
    {
        $apiPrefix = isset($this->config['api_prefix']) ? $this->config['api_prefix'] : '/api';
        return stripos($yafRequest->getRequestUri(), $apiPrefix) === 0;
    }

    public function routerStartup(\Yaf_Request_Abstract $yafRequest, \Yaf_Response_Abstract $response)
    {
        if (!$this->needSample($yafRequest)) {
            return;
        }

        $this->tracer = \Yaf_Registry::get('zipkin');

        $this->startSpan($yafRequest);

        if ($this->span->getContext()->isSampled()) {
            $this->tracer->addTag($this->span, HTTP_HOST, $this->getHttpHost($yafRequest));
            $this->tracer->addTag($this->span, HTTP_PATH, $this->tracer->formatHttpPath($yafRequest->getRequestUri()));
            $this->tracer->addTag($this->span, Tracer::HTTP_QUERY_STRING, (string)$yafRequest->getServer('QUERY_STRING'));
            $this->tracer->addTag($this->span, HTTP_METHOD, $yafRequest->getMethod());
            $httpRequestBody = $this->tracer->convertToStr(file_get_contents('php://input'));
            $httpRequestBodyLen = strlen($httpRequestBody);
            $this->tracer->addTag($this->span, Tracer::HTTP_REQUEST_BODY_SIZE, $httpRequestBodyLen);
            $this->tracer->addTag($this->span, Tracer::HTTP_REQUEST_BODY, $this->tracer->formatHttpBody(
                $httpRequestBody,
                $httpRequestBodyLen
            ));
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (stripos($key, 'HTTP_') === 0) {
                    $headers[strtolower(str_replace('_', '-', str_ireplace('HTTP_', '', $key)))] = [$value];
                }
            }
            $this->tracer->addTag($this->span, Tracer::HTTP_REQUEST_HEADERS, json_encode($headers, JSON_UNESCAPED_UNICODE));
            $this->tracer->addTag(
                $this->span,
                Tracer::HTTP_REQUEST_PROTOCOL_VERSION,
                $this->tracer->formatHttpProtocolVersion($yafRequest->getServer('SERVER_PROTOCOL'))
            );
            $this->tracer->addTag($this->span, Tracer::HTTP_REQUEST_SCHEME, $this->getIsSecureConnection($yafRequest) ? 'https' : 'http');
        }
    }

    /**
     * Start a trace
     *
     * @param \Yaf_Request_Abstract $yafRequest
     */
    private function startSpan(\Yaf_Request_Abstract $yafRequest)
    {
        $parentContext = $this->tracer->getParentContext();

        $this->span = $this->tracer->getSpan($parentContext);
        $this->span->setName(
            $this->tracer->formatRoutePath(
                $this->tracer->formatHttpPath($yafRequest->getRequestUri())
            )
        );
        $this->span->setKind(SERVER);
        $this->span->start();
        $this->tracer->rootContext = $this->span->getContext();

        if ($this->span->getContext()->isSampled()) {
            $this->startMemory = memory_get_usage();
            $this->tracer->beforeSpanTags($this->span);
        }
    }

    /**
     * Add tags before finishing trace
     *
     * @param \Yaf_Request_Abstract $yafRequest
     * @param \Yaf_Response_Abstract $yafResponse
     */
    private function finishSpanTag(\Yaf_Request_Abstract $yafRequest, \Yaf_Response_Abstract $yafResponse)
    {
        if ($yafResponse) {
            $serverProtocol = $yafResponse->getHeader($yafRequest->getServer('SERVER_PROTOCOL'));
            if ($serverProtocol) {
                $serverProtocolArr = explode(' ', $serverProtocol);
                $httpStatusCode = intval($serverProtocolArr[0]);
            } else {
                $httpStatusCode = 200;
            }
            if ($httpStatusCode >= 500 && $httpStatusCode < 600) {
                $this->tracer->addTag($this->span, ERROR, 'server error');
            } elseif ($httpStatusCode >= 400 && $httpStatusCode < 500) {
                $this->tracer->addTag($this->span, ERROR, 'client error');
            }
            $this->tracer->addTag($this->span, HTTP_STATUS_CODE, $httpStatusCode);
            $httpResponseBody = $this->tracer->convertToStr($yafResponse->getBody());
            $httpResponseBodyLen = strlen($httpResponseBody);
            $this->tracer->addTag($this->span, Tracer::HTTP_RESPONSE_BODY_SIZE, $httpResponseBodyLen);
            $this->tracer->addTag($this->span, Tracer::HTTP_RESPONSE_BODY, $this->tracer->formatHttpBody(
                $httpResponseBody,
                $httpResponseBodyLen
            ));
            $this->tracer->addTag(
                $this->span,
                Tracer::HTTP_RESPONSE_PROTOCOL_VERSION,
                $this->tracer->formatHttpProtocolVersion($yafRequest->getServer('SERVER_PROTOCOL'))
            );
        }
        $this->tracer->addTag($this->span, Tracer::RUNTIME_MEMORY, round((memory_get_usage() - $this->startMemory) / 1000000, 2) . 'MB');
        $this->tracer->afterSpanTags($this->span);
    }

    /**
     * Finish a trace
     */
    private function finishSpan()
    {
        $this->span->finish();
        $this->tracer->flushTracer();

    }

    public function dispatchLoopShutdown(\Yaf_Request_Abstract $request, \Yaf_Response_Abstract $response)
    {
        if (!$this->needSample($request)) {
            return;
        }

        if ($this->span && $this->tracer) {
            if ($this->span->getContext()->isSampled()) {
                $this->finishSpanTag($request, $response);
            }
            $this->finishSpan();
        }
    }

    /**
     * @param \Yaf_Request_Abstract $yafRequest
     * @return string
     */
    private function getHttpHost(\Yaf_Request_Abstract $yafRequest)
    {
        if (!$httpHost = $yafRequest->getServer('HTTP_HOST')) {
            if (!$httpHost = $yafRequest->getServer('SERVER_NAME')) {
                $httpHost = $yafRequest->getServer('SERVER_ADDR');
            }
        }

        return $httpHost ?: '';
    }

    /**
     * Return if the request is sent via secure channel (https).
     * @param \Yaf_Request_Abstract $yafRequest
     * @return bool if the request is sent via secure channel (https)
     */
    private function getIsSecureConnection(\Yaf_Request_Abstract $yafRequest)
    {
        $https = $yafRequest->getServer('HTTPS');
        if (isset($https) && (strcasecmp($https, 'on') === 0 || $https == 1)) {
            return true;
        }

        $secureProtocolHeaders = [
            'X-Forwarded-Proto' => ['https'], // Common
            'Front-End-Https' => ['on'], // Microsoft
        ];
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (stripos($key, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', str_ireplace('HTTP_', '', $key)))] = $value;
            }
        }
        foreach ($secureProtocolHeaders as $header => $values) {
            $header = strtolower($header);
            if (($headerValue = isset($headers[$header]) ? $headers[$header] : null) !== null) {
                foreach ($values as $value) {
                    if (strcasecmp($headerValue, $value) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
