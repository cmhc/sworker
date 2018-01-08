<?php
/**
 * 两个进程之间通信
 * 首先需要启动路由器进程
 */
namespace tests;
require dirname(__DIR__) . '/autoload.php';

use sworker\Client\TCP;
use sworker\Process\Process;

/**
 * 两个互相通信的进程
 */
class Messenger
{

	public function proc1Init()
	{
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
$process->addWorker('tests\Messenger', 'proc1');
$process->addWorker('tests\Messenger', 'proc2');

$process->start();
