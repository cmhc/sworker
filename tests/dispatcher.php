<?php
/**
 * 调度器模式
 * 使用一个调度器发送信息，几个executor接受信息
 */

namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Process\Process;

class Test
{

    /**
     * 发送信息
     */
    public function dispatcher($i, $args, &$msg)
    {
        //空的参数表示发送成功，否则发送失败
        $dest = rand(1,2);
        $data = 'test'.mt_rand(1,255);
        if (empty($msg)) {
            $msg = array("executor".$dest, $data);
        } else {
            $data = $msg[1];
        }
        echo "dispatcher发送数据: $data\n";
        sleep(1);
    }

    /**
     * 接收信息
     */
    public function executor($i, $args, &$msg)
    {
        echo "executor{$i}接收到的数据: {$msg}\n";
        sleep(3);
    }
}

$process = new Process();

//加入worker，可以直接使用类的名称
$process->addWorker('tests\Test', 'dispatcher')->setDispatcher('0.0.0.0', 10001);

$process->addWorker('tests\Test', 'executor')->setExecutor('127.0.0.1', 10001);

$process->addWorker('tests\Test', 'executor')->setExecutor('127.0.0.1', 10001);


//这一步是开始执行
$process->start();























































