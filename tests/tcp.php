<?php
/**
 * 调度器模式
 * 使用一个调度器发送信息，几个executor接受信息
 * 验证信息是否接受完全
 */

namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Process\Process;

class Test
{

    protected $sendMaxNum = 1000;

    protected $data = 0;

    /**
     * 发送信息
     */
    public function dispatcher($i, $args, &$msg)
    {
        //发送到了最大的数值就不发送了
        if ($this->data > $this->sendMaxNum) {
            echo "数据发送完毕\n";
            sleep(1);
            return ;
        }

        ++$this->data;
        $dest = rand(1, 2);

        //空的参数表示发送成功，否则发送失败
        if (empty($msg)) {
            $msg = array("executor" . $dest, $this->data);
        } else {
            $this->data = $msg[1];
        }

        echo "dispatcher发送数据: $this->data\n";
        usleep(100000);
    }

    /**
     * 接收信息
     */
    public function executor($i, $args, &$msg)
    {
        file_put_contents('/tmp/tmp'.$i, "executor{$i}接收到的数据: {$msg}\n", FILE_APPEND);
        usleep(100000);
        //接受的最后一个消息应该是1000
    }
}

$process = new Process();

//加入worker，可以直接使用类的名称
$process->addWorker('tests\Test', 'dispatcher')->setDispatcher(array('ip'=>'0.0.0.0', 'port' => 10001));
$process->addWorker('tests\Test', 'executor')->setExecutor(array('ip'=>'127.0.0.1', 'port' => 10001));
$process->addWorker('tests\Test', 'executor')->setExecutor(array('ip'=>'127.0.0.1', 'port' => 10001));


//这一步是开始执行
$process->start();























































