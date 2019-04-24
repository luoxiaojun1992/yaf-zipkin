<?php

use Mockery as M;

class TracerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Testing in web container
     *
     * @throws Exception
     */
    public function testWebTrace()
    {
        //Mock Helper
        $helper = M::mock('alias:' . \Lxj\Yaf\Zipkin\Helper::class)->makePartial();
        $helper->shouldReceive('sapi')
            ->andReturn('fcgi');

        $tracer = new \Lxj\Yaf\Zipkin\Tracer(['samplerate' => 1]);

        $this->assertTrue($tracer->rootSpan('unit-test', function (\Zipkin\Span $span) use ($tracer) {
            $this->assertTrue($tracer->span('unit-test-sub', function (\Zipkin\Span $span) {
                return true;
            }, $span->getContext(), \Zipkin\Kind\CLIENT));

            return true;
        }, null, \Zipkin\Kind\SERVER, true));
    }

    /**
     * Testing in console environment
     *
     * @throws Exception
     */
    public function testConsoleTrace()
    {
        $tracer = new \Lxj\Yaf\Zipkin\Tracer(['samplerate' => 1]);

        $this->assertTrue($tracer->rootSpan('unit-test', function (\Zipkin\Span $span) use ($tracer) {
            $this->assertTrue($tracer->span('unit-test-sub', function (\Zipkin\Span $span) {
                return true;
            }, $span->getContext(), \Zipkin\Kind\CLIENT));

            return true;
        }, null, \Zipkin\Kind\SERVER, true));
    }
}
