<?php
namespace tests;
require dirname(__DIR__) . '/src/autoloader.php';

use Sworker\Process\Process;

class Test
{

    static $count = 0;

    /**
     * 测试普通的方法
     * 该方法整体可以看成原子操作，不会被中断，除非使用kill -9强制杀进程
     */
    public function sleepMethod($i)
    {
        echo "process:" . $i ."\tcount: ". self::$count . "\tsleep 1s\n";
        sleep(1);
        self::$count ++;
    }

}


$process = new Process();

//一直循环
$process->set(array(
        'l' => 0
    )
);
$process->addWorker('tests\Test', 'sleepMethod');
$process->addWorker('tests\Test', 'sleepMethod');
$process->start();