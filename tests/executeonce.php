<?php
namespace tests;
require dirname(__DIR__) . '/src/autoloader.php';

use Sworker\Process\Process;

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
$process->addWorker('tests\Test', 'normal');
$process->addWorker('tests\Test', 'sleepMethod');
$process->start();