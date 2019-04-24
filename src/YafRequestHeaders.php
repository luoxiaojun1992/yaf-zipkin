<?php

namespace Lxj\Yaf\Zipkin;

use Zipkin\Propagation\Getter;
use Zipkin\Propagation\Setter;

final class YafRequestHeaders implements Getter, Setter
{
    /**
     * {@inheritdoc}
     *
     * @param $carrier
     */
    public function get($carrier, $key)
    {
        $lKey = strtolower($key);
        return isset($carrier[$lKey]) ? $carrier[$lKey] : null;
    }

    /**
     * {@inheritdoc}
     *
     * @param $carrier
     * @throws \InvalidArgumentException for invalid header names or values.
     */
    public function put(&$carrier, $key, $value)
    {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
    }
}
