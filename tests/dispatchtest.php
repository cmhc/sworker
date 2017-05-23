<?php
/**
 * 使用一个进程调度其他进程的例子
 * 可以起到任务分发的效果
 */
require dirname(__DIR__) . '/sworkerd.php';
use sworker\sworkerd;

class dispatchTest extends sworkerd
{
	public function __construct()
	{
		parent::__construct();
		$this->addWorker('dispatcher');
		$this->addWorker('task');
	}

	/**
	 * 初始化任务分配者
	 */
	public function dispatcherInit()
	{
		
	}

	/**
	 * 任务分配进程
	 * 该行为会启动一个子进程，并且会以$data为消息传送过去，除非主动停止子进程，否则子进程会一直阻塞，直到下一次收到消息
	 */
	public function dispatcher($i)
	{
		//$data = 1;
		//$this->doTask(array(&$this, 'task'), $data);
		//sleep(1);
		//投递到空闲的子进程任务
		$data = 'hello' . rand(0,100);
		$this->sendData('task1', $data);
		sleep(2);
	}


	public function task($i)
	{
		$data = $this->receiveData($i); //接收投递的数据
		file_put_contents(__DIR__ . '/tests.log', "{$data}\n", FILE_APPEND);
		usleep(100000);
	}
}

(new dispatchTest)->start();