<?php
/**
 * 发布订阅解决方案
 * 需要有redis
 */
namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Process\Process;

class Test
{

    public function subInit()
    {
        $this->redis = new \Redis;
        $this->redis->connect('127.0.0.1', 6379);
    }

    public function sub()
    {
        //这个方法会阻塞运行
        $this->redis->subscribe(array('test_channel'), function($redis, $channel, $msg){
            print_r($msg);
            var_dump($channel);
            file_put_contents('/tmp/sub', $msg . "\n", FILE_APPEND);
            Process::breakCheck();
        });
    }

    public function pubInit()
    {
        $this->redis = new \Redis;
        $this->redis->connect('127.0.0.1', 6379);
    }

    /**
     * 每隔1s发布一条消息
     */
    public function pub()
    {
        $this->redis->publish('test_channel', "test");
        sleep(2);
    }
}

$process = new Process();
$process->addWorker('tests\Test', 'pub');
$process->addWorker('tests\Test', 'sub');
//这一步是开始执行
$process->start();