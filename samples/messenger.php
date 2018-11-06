<?php
/**
 * 使用router的进程间通信例子
 */
namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Client\TCP;
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

/**
 * 两个互相通信的进程
 */
class Messenger
{

	public function proc1Init()
	{
		sleep(1);
		$this->tcp = new TCP;
		$this->tcp->connect('127.0.0.1', 13000, 'proc1');
		echo "connected\n";
	}

	public function proc1()
	{
		for ($i=0;$i<100;$i++) {
			$data = $this->tcp->receive();
			print_r($data);
		}
	}

	public function proc2Init()
	{
		sleep(1);
		$this->tcp = new TCP;
		$this->tcp->connect('127.0.0.1', 13000, 'proc2');
		echo "connected\n";
	}

	public function proc2()
	{
		for ($i=0; $i<100;$i++) {
			$this->tcp->send('proc1', "hello world".$i);
			sleep(1);
		}
	}
}

$process = new Process();
$process->addWorker('tests\RouterProcess', 'router');
$process->addWorker('tests\Messenger', 'proc1');
$process->addWorker('tests\Messenger', 'proc2');

$process->start();
