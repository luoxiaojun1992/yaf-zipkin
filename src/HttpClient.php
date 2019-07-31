<?php

namespace Lxj\Yaf\Zipkin;

use GuzzleHttp\Client as GuzzleHttpClient;
use Psr\Http\Message\RequestInterface;
use Zipkin\Span;
use const Zipkin\Tags\HTTP_HOST;
use const Zipkin\Tags\HTTP_METHOD;
use const Zipkin\Tags\HTTP_PATH;
use const Zipkin\Tags\HTTP_STATUS_CODE;

/**
 * Class HttpClient
 * @package Lxj\Yaf\Zipkin
 */
class HttpClient extends GuzzleHttpClient
{
    /**
     * Send http request with zipkin trace
     *
     * @param RequestInterface $request
     * @param array $options
     * @param string $spanName
     * @param bool $injectSpanCtx
     * @param bool $traceInConsole
     * @return mixed|\Psr\Http\Message\ResponseInterface|null
     * @throws \Exception
     */
    public function send(RequestInterface $request, array $options = [], $spanName = null, $injectSpanCtx = true, $traceInConsole = false)
    {
        $sendRequest = function () use ($request, $options) {
            try {
                $response = parent::send($request, $options);
                return $response;
            } catch (\Exception $e) {
                Helper::log('CURL ERROR ' . $e->getMessage(), 'error', 'zipkin');
                throw new \Exception('CURL ERROR ' . $e->getMessage());
            }
        };

        if (Helper::runningInConsole() && !$traceInConsole) {
            return call_user_func($sendRequest);
        }

        /** @var Tracer $yafTracer */
        $yafTracer = Tracer::create();
        $path = $request->getUri()->getPath();

        return $yafTracer->clientSpan(
            isset($spanName) ? $spanName : $yafTracer->formatRoutePath($path),
            function (Span $span) use ($request, $sendRequest, $yafTracer, $path, $injectSpanCtx) {
                //Inject trace context to api psr request
                if ($injectSpanCtx) {
                    $yafTracer->injectContextToRequest($span->getContext(), $request);
                }

                if ($span->getContext()->isSampled()) {
                    $yafTracer->addTag($span, HTTP_HOST, $request->getUri()->getHost());
                    $yafTracer->addTag($span, HTTP_PATH, $path);
                    $yafTracer->addTag($span, Tracer::HTTP_QUERY_STRING, (string)$request->getUri()->getQuery());
                    $yafTracer->addTag($span, HTTP_METHOD, $request->getMethod());
                    $httpRequestBodyLen = $request->getBody()->getSize();
                    $yafTracer->addTag($span, Tracer::HTTP_REQUEST_BODY_SIZE, $httpRequestBodyLen);
                    $yafTracer->addTag($span, Tracer::HTTP_REQUEST_BODY, $yafTracer->formatHttpBody($request->getBody()->getContents(), $httpRequestBodyLen));
                    $request->getBody()->seek(0);
                    $yafTracer->addTag($span, Tracer::HTTP_REQUEST_HEADERS, json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE));
                    $yafTracer->addTag(
                        $span,
                        Tracer::HTTP_REQUEST_PROTOCOL_VERSION,
                        $yafTracer->formatHttpProtocolVersion($request->getProtocolVersion())
                    );
                    $yafTracer->addTag($span, Tracer::HTTP_REQUEST_SCHEME, $request->getUri()->getScheme());
                }

                $response = null;
                try {
                    $response = call_user_func($sendRequest);
                    return $response;
                } catch (\Exception $e) {
                    throw $e;
                } finally {
                    if ($response) {
                        if ($span->getContext()->isSampled()) {
                            $yafTracer->addTag($span, HTTP_STATUS_CODE, $response->getStatusCode());
                            $httpResponseBodyLen = $response->getBody()->getSize();
                            $yafTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY_SIZE, $httpResponseBodyLen);
                            $yafTracer->addTag($span, Tracer::HTTP_RESPONSE_BODY, $yafTracer->formatHttpBody($response->getBody()->getContents(), $httpResponseBodyLen));
                            $response->getBody()->seek(0);
                            $yafTracer->addTag($span, Tracer::HTTP_RESPONSE_HEADERS, json_encode($response->getHeaders(), JSON_UNESCAPED_UNICODE));
                            $yafTracer->addTag(
                                $span,
                                Tracer::HTTP_RESPONSE_PROTOCOL_VERSION,
                                $yafTracer->formatHttpProtocolVersion($response->getProtocolVersion())
                            );
                        }
                    }
                }
            });
    }
}
