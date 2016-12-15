<?php
/**
 * sworker 只是一个多进程框架
 * 1. 二逼模式 : 多个worker执行同样的任务
 * 2. 普通模式 : 多个worker执行不同的任务
 * 4. 牛逼模式 : 开启一个生产者和多个消费者，由dispatcher分发任务给指定的worker执行
 * 5. 上天模式 : 还没想好
 *
 * @author huchao <hu_chao@139.com>
 */
namespace cmhc\sworker;

class sworkerd
{
	/**
	 * 主要用作调试
	 * @var
	 */
	protected $echo;

	/**
	 * 传来的选项数组
	 * @var 
	 */
	protected $option;

	/**
	 * 进程名称，用作保存pid
	 */
	protected $processName;

	/**
	 * 进程号数组
	 * @var array
	 */
	protected $process;

	/**
	 * 由dispatcher维护的各个client连接
	 * 用作进程之间的通信
	 * @var array
	 */
	protected $clients;

	/**
	 * 由worker维护的连接
	 * @var array
	 */
	protected $connection;

	/**
	 * 发送缓冲队列
	 * @var array
	 */
	protected $sendBuffer = array();


	/**
	 * pid文件保存路径
	 * @var string
	 */
	protected $pidFile;

	/**
	 * 初始化参数
	 */
	public function __construct()
	{
		/**
		 * option
		 * -n<num>      需要启动的 worker 进程数
		 * --job=<job name> 作业名称
		 * -s<signal>   需要对 master 进程发送的信号
		 * -d           daemon 模式，默认不会使用daemon
		 * --pid=<pid path> pid保存的路径，默认和程序在同一个目录
		 * -u<username> 使用哪个用户来执行
		 * -t 			打开测试，将会输出一些调试的信息
		 */
		$long = array(
			'pid::', //pid保存位置
			'job::' //作业名称
			);
		$this->option = getopt("dn:s:u:t", $long);
		var_dump($this->option);
		$this->echo = false;
		$this->processName = strtolower(get_class($this));
		if( empty($this->option['pid']) || !file_exists($this->option['pid'])){
			$this->option['pid'] = __DIR__ . '/pid';
			if( !file_exists($this->option['pid']) ){
				mkdir($this->option['pid']);
			}
		}

		if( empty($this->option['u']) ){
			$this->option['u'] = 'root';
		}
		$this->pidFile = $this->option['pid'] . '/' . $this->processName . '.pid';		
		$this->doSignal();
		$this->checkStatus();
	}

	/**
	 * 进程结束需要进行的结尾工作
	 */
	public function __destruct()
	{

		//发送一个长度为0的数据,表示dispatcher已经关闭，不再分发任务
		if( !empty($this->clients) ){
			foreach ($this->clients as $handle) {
				socket_write($handle['handle'], pack('n',0), 2);
			}
		}

		//关闭worker维护的连接
		if( $this->connection['handle'] ){
			fclose($this->connection['handle']);
			unlink( dirname($this->pidFile) . '/' . $this->connection['name'] . '.sock' );
		}

	}

	/**
	 * 进程入口文件
	 * 必须在继承的类中执行该方法
	 * 继承的类中必须要有worker方法
	 * @return
	 */
	public function start()
	{

		if( isset($this->option['d']) ){
			if( isset($this->option['t']) ){
				ini_set("display_errors",true);
				error_reporting(E_ALL);
				$this->echo = true;
			}
			$this->daemon();
			return ;
		}

		/**
		 * 不加任何参数表示使用debug模式
		 */
		ini_set("display_errors",true);
		error_reporting(E_ALL);
		$this->echo = true;
		$this->worker(0);
		return ;

	}

	/**
	 * 以守护进程方式运行
	 */
	protected function daemon()
	{
		/**
		 * 第一次fork的父进程退出
		 */
		if( pcntl_fork() > 0){
			exit(0);
		}

		/**
		 * 设置会话组组长，目的是脱离终端的控制
		 */
		if( ($sid = posix_setsid()) < 0 ){
			exit("set sid error");
		}

		$group = posix_getpwnam($this->option['u']);
		if( !isset($group['gid']) || !isset($group['uid']) ){
			exit("user error");
		}
		posix_setgid($group['gid']);
		posix_setuid($group['uid']);
		/**
		 * 第二次fork，父进程退出，当前存活的进程为第二子进程，该进程将会作为master进程
		 * fork 成功保存进程号
		 */
		if( ($pid = pcntl_fork()) > 0){
			file_put_contents($this->pidFile, $pid);
			exit(0);
		}

		$this->redirectOutput(); 

		/**
		 * master 进程 fork n 个worker进程来执行任务，n为传入的参数
		 */
		$this->option['n'] = !empty($this->option['n']) ? (int) $this->option['n'] : 1;
		for($i=0; $i<$this->option['n']; $i++){
			$pid = pcntl_fork();
			if( $pid == -1 ){
				echo "worker fork error\n";
				break;
			}
			if( $pid > 0 ){
				$this->process[$pid] = $i;
			}else{
				break;
			}
		}

		/**
		 * 父进程注册信号量，检测是否有信号到达，执行相关操作
		 * 终止父进程首先会停止全部子进程
		 */
		if( $pid > 0 ){
			pcntl_signal(SIGHUP, function(){
				//结束所有子进程
				if( !empty($this->process) ){
					//按照进程编号排序，数字越大越先结束
					uasort($this->process, function($a, $b){
						if( $a == $b ){
							return 0;
						}
						return ($a > $b) ? 1 : -1;
					});
					foreach( $this->process as $cpid=>$v ){
						echo $cpid;
						posix_kill($cpid, SIGHUP);
						pcntl_waitpid($cpid, $status, WUNTRACED);
					}
				}
				$this->log("{$this->processName}: master process stop");
				unlink($this->pidFile);
				exit();
			});

			$this->setProcessTitle($this->processName . ' Master');

			$this->log("{$this->processName}: master process start");

			/**
			 * 每隔1s检测信号是否到达，信号到达执行信号
			 * 同时检测是否有子进程退出，如果有子进程退出则重新拉起子进程
			 */
			while(1){
				$result = pcntl_wait($status, WNOHANG);
				if( $result > 0 ){
					//child quit
					$quitWorkerNum = $this->process[$result];
					unset($this->process[$result]);
					//restart children
					$this->restartWorker($quitWorkerNum);
				}
    			$status = pcntl_signal_dispatch();
				sleep(1);
			}

		}

		/**
		 * 开始worker进程
		 */
		if( $pid == 0 ){
			$this->startWorker($i);
		}
	}

	/**
	 * 根据传入的参数发送进程相应的信号量
	 * 支持stop、reload
	 */
	protected function doSignal()
	{
		if( !empty($this->option['s']) ){

			if( !file_exists($this->pidFile) ){
				exit("pid file not exist");
			}

			$pid = file_get_contents( $this->pidFile );
			switch ($this->option['s']) {

				case 'stop':
					posix_kill( $pid, SIGHUP);
					echo "process stopping\n";
					while( file_exists($this->pidFile) ){
						usleep(100000);
					}
					echo "process stoped\n";
				break;

				case 'restart':

					posix_kill( $pid, SIGHUP);
					echo "process restarting\n";
					while( file_exists($this->pidFile) ){
						usleep(100000);
					}
					$this->restartProcess();
					echo "process restarted\n";
				
				break;

				default:
					echo "command error\n";
				break;
			}

			exit;
		}
	}

	/**
	 * 检查进程状态，防止进程重复被执行
	 */
	protected function checkStatus()
	{
		if( file_exists( $this->pidFile ) ){
			exit("process {$this->processName} running\n");
		}
	}

	/**
	 * 开始 worker 进程
	 * 执行继承类里面worker方法同时注册信号量实现对worker进程的控制
	 */
	protected function startWorker($i)
	{
		pcntl_signal(SIGHUP, function(){
			$this->log("{$this->processName}: worker process stop");
			exit();
		});

		$this->log("{$this->processName}: worker process start");

		//在子进程中自定义进程名称
		if( method_exists($this, 'customProcessName') ){
			$workerName = $this->customProcessName($i);
		}

		if( empty($workerName) ){
			$workerName = 'worker'.$i;
		}
		$this->connection = array('name'=>$workerName,'handle'=>false);

		$this->setProcessTitle($this->processName . ' Worker: '. $workerName);
		
		//在worker之前执行的方法，不能是死循环
		if( method_exists($this, 'beforeWorker') ){
			$this->beforeWorker($i);
		}
		
		while( true ){
			$this->worker($i);	
			pcntl_signal_dispatch();
		}
	}

	/**
	 * 设置进程名称
	 */
	protected function setProcessTitle($title)
	{
		//设置进程名称
        if (function_exists('cli_set_process_title')){
        	// >=php 5.5
            cli_set_process_title($title);
        }else if(extension_loaded('proctitle') && function_exists('setproctitle')){
        	//扩展
            setproctitle($title);
        }
	}

	/**
	 * 重新拉起一个worker
	 * @return
	 */
	protected function restartWorker($i)
	{
		$pid = pcntl_fork();
		if($pid > 0){
			$this->process[$pid] = $i;
		}
		if( $pid == 0 ){
			$this->startWorker($i);
		}
	}

	/**
	 * 重启进程
	 * 当程序文件更改之后需要执行的操作
	 * @return
	 */
	protected function restartProcess()
	{
		$script = $_SERVER['argv'][0];
		$this->log("master process restart");
		exec("/usr/local/php/bin/php {$script} -d -n{$this->option['n']}", $status);
	}

	/**
	 * 重定向输出
	 * @return
	 */
	protected function redirectOutput()
	{
		global $stdin, $stdout, $stderr;
		fclose(STDIN);
		fclose(STDOUT);
		fclose(STDERR);
		$stdin = fopen('/dev/null', 'r');
		$stdout = fopen('/tmp/client_out','a');
		$stderr = fopen('/tmp/client_err','a');
	}

    /**
     * 输出信息
     */
    protected function vardump($data, $note=''){
        if($this->echo){
        	if( $note != '' ){
        		echo "\n=={$note}==\n";
        	}
            var_dump($data);
        }
    }

    /**
     * 手动指定worker进程的数量
     * 优先级高于 -n 参数，适用于需要指定子进程数量的程序
     */
    protected function setWorkerNum($num)
    {
    	$this->option['n'] = $num;
    }

    /**
     * 中断检测，执行信号
     * @return
     */
    public function breakCheck()
    {
    	pcntl_signal_dispatch();
    }

    /**
     * 记录进程运行日志
     * @param  string $content 
     * @return 
     */
    protected function log($content)
    {
		file_put_contents(__DIR__ . "/client.log", '[' . date('Y-m-d H:i:s') . "] {$content}\n", FILE_APPEND);
    }


    /*
    	**********************************************************
        以下为进程通信部分
        使用socket通信方式
    	**********************************************************
    */

    /**
     * dispather 和 executor 之间通信
     * @param  string $dest 目的进程名称
     * @param  string $data 数据
     */
    protected function sendMessage($dest, $data)
    {
    	//waiting for client connection
    	if( !isset($this->clients[$dest]) || $this->clients[$dest]['status'] == -1 ){
			if( !($server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) ){
				throw new Exception("socket create error", 1);
			}
			if( !socket_bind($server, '127.0.0.1') ){
				throw new Exception("socket bind 127.0.0.1 error", 1);
			}
			if( !socket_listen($server) ){
				throw new Exception("socket listen error", 1);
			}
			socket_getsockname($server, $addr, $port);
			if( !$this->saveWorkerSocket($dest, "tcp://{$addr}:{$port}") ){
				throw new Exception("save {$dest} socket error", 1);
			}
			$this->clients[$dest]['handle'] = socket_accept($server);
			socket_set_option($this->clients[$dest]['handle'], SOL_SOCKET, SO_SNDBUF, 8192 );
			$this->clients[$dest]['status'] = 1;
    	}
    	
    	$write = array($this->clients[$dest]['handle']);
    	socket_select($read, $write, $except, 1);
    	if( !empty($write) ){
    		$data = pack("n",strlen($data)) . $data;
			if( !socket_write($write[0], $data, strlen($data)) ){
				$this->clients[$dest]['status'] = -1;
				return false;
			}
			return true;
    	}else{
    		return false;
    	}
    }




    /**
     * 接收worker发送的数据
     * 在缓冲区有数据的时候，该方法会返回一条数据，当遇到终止数据发送信号时候，该方法会返回false
     * 
     * 业务处理的时候，应该一直调用该方法接收数据，直到返回false为止
     * 服务端不会保存数据，客户端处理不当会有丢失数据的风险
     * @return string
     */
    protected function receiveMessage()
    {
    	while( !$this->connection['handle'] ){
    		if( !($sock = $this->getWorkerSocket($this->connection['name'])) ||
    			!($this->connection['handle'] = stream_socket_client($sock))
			){
				echo "waiting server\n";
    			sleep(1);
    		}
    	}
    	if( !($len = stream_socket_recvfrom($this->connection['handle'], 2)) ){
    		return false;
    	}
		$len = unpack("n",$len);
		//print_r($len);

		//表示数据已经接收完毕，输出false
		if( $len[1] == 0 ){
			return false;
		}

		$content = stream_socket_recvfrom($this->connection['handle'], $len[1]);
		return $content;
    }


    /**
     * 保存worker的socket
     * 目前使用文件的方式
     * @param  string $name
     * @param  string $sock
     * @return
     */
    protected function saveWorkerSocket($name, $sock)
    {
		return file_put_contents(dirname($this->pidFile) . "/{$name}.sock" , $sock);
    }

    /**
     * 获取socket
     * @param  string $name 当前worker名称
     * @return string
     */
    protected function getWorkerSocket($name){
    	if( file_exists(dirname($this->pidFile) . "/{$name}.sock") ){
			return file_get_contents(dirname($this->pidFile) . "/{$name}.sock" );
    	}
    	return false;
    }

}