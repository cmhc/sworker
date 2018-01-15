<?php
/**
 * 开启一个路由器进程
 * 可以让路由器进行消息的转发
 *
 * 用法
 * telnet 127.0.0.1 13000
 */
namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Process\Process;
use sworker\Router\Router;

class RouterProcess
{
	protected $router;

    public function routerInit()
    {
    	$this->router = new Router('0.0.0.0', 13000);
    }

    public function router()
    {
    	$this->router->start();
    }
}

$process = new Process();
$process->addWorker('tests\RouterProcess', 'router');
$process->start();