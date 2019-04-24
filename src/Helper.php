<?php

namespace Lxj\Yaf\Zipkin;

class Helper
{
    public static function log($message, $level, $category)
    {
        if ($zipkinLog = \Yaf_Registry::get('zipkin_log')) {
            $zipkinLog->log($message, $level, $category);
        }
    }

    public static function sapi()
    {
        return php_sapi_name();
    }
}
