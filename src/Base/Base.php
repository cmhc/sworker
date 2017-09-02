<?php
namespace sworker\Base;

class Base
{
    public function log($data)
    {
        $line = '[' . date('Y-m-d H:i:s') . '] | ' . $data . "\n";
        file_put_contents('/tmp/sworker.log', $line, FILE_APPEND);
    }
}