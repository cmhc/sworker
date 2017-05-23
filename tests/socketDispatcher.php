<?php
/**
 * 使用一个进程调度其他进程的例子
 * 可以起到任务分发的效果
 */
require dirname(__DIR__) . '/sworkerd.php';
use sworker\sworkerd;

class socketDispatcher extends sworkerd
{
	public function __construct()
	{
		parent::__construct();
		$this->addWorker('dispatcher');
		$this->addWorker('task');
		$this->addWorker('task');
		$this->addWorker('task');
		$this->addWorker('task');

	}

	/**
	 * 初始化任务分配者
	 */
	public function dispatcherInit()
	{
		$this->setServer(array('ip'=>'0.0.0.0', 'port'=>13000));
	}

	/**
	 * 任务分配进程
	 * 该行为会启动一个子进程，并且会以$data为消息传送过去，除非主动停止子进程，否则子进程会一直阻塞，直到下一次收到消息
	 */
	public function dispatcher($i)
	{
		for ($j = 0; $j < 50; $j++) {
			for ($i = 1; $i<=4; $i++) {
				$data = 'hello' . $j;
				$this->vardump($data, "应用端{$i}发送的数据");
				$this->sendMessage('task'.$i, $data);
			}
			sleep(1);
		}

		$this->stop();
	}

	public function taskInit()
	{
		$this->setServer(array('ip'=>'0.0.0.0', 'port'=>13000));
	}

	public function task($i)
	{
		do {
			$data = $this->receiveMessage(); //接收投递的数据
			$this->vardump($data, "应用端{$i}接收到的数据");
			if ($i==1) {
				usleep(1);
			}
			if ($i==2) {
				usleep(1);
			}
			if ($i ==3) {
				usleep(500000);
			}
			if ($i ==4 ){
				//usleep(200000);
			}
		} while($data !== false);
		sleep(1);
		
	}
}

(new socketDispatcher)->start();