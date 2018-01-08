<?php
/**
 * 定时任务测试
 */
namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Process\Process;

class Test
{
    public function testsleep()
    {
    	echo "sleep 60s\n";
        sleep(60);
    }

    public function cron($i)
    {
        echo date('Y-m-d H:i:s') . " cron任务已经执行,下次执行时间" . date('Y-m-d H:i:s', time() + 60) . "\n";
    }
}

$process = new Process();
$process->addWorker('tests\Test', 'testsleep');
//在当前时间的30s之后，每隔60s执行一次
$process->addWorker('tests\Test', 'cron')->interval(60)->after(date('Y-m-d H:i:s', time() + 30));
//这一步是开始执行
$process->start();