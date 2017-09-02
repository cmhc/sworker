<?php
namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Process\Process;

class Test
{

    public function normal($i)
    {
        echo "ok\n";
    }

    /**
     * 测试普通的方法
     * 该方法整体可以看成原子操作，不会被中断，除非使用kill -9强制杀进程
     */
    public function sleepMethod($i)
    {
        echo "sleep 1s\n";
        sleep(1);
    }

}

$process = new Process();

//循环一次自动退出
$process->set(array(
        'l' => 1
    )
);

//加入worker，可以直接使用类的名称
$process->addWorker('tests\Test', 'normal');
//也可以使用实例化之后的类
$process->addWorker(new \tests\Test, 'sleepMethod');
//这一步是开始执行
$process->start();