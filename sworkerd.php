<?php
/**
 * sworker 只是一个多进程框架
 */
namespace cmhc\sworker;

class sworkerd
{


	protected $echo;

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
		 * -s<signal>   需要对 master 进程发送的信号
		 * -d           daemon 模式，默认不会使用daemon
		 * -p<pid path> pid保存的路径，默认和程序在同一个目录
		 * -u<username> 使用哪个用户来执行
		 */
		$this->option = getopt("n:s:dp:u:");//for test
		$this->echo = false;
		$this->processName = get_class($this);
		if( empty($this->option['p']) || !file_exists($this->option['p'])){
			$this->option['p'] = __DIR__ . '/pid';
			if( !file_exists($this->option['p']) ){
				mkdir($this->option['p']);
			}
		}

		if( empty($this->option['u']) ){
			$this->option['u'] = 'root';
		}
		$this->pidFile = $this->option['p'] . '/' . $this->processName . '.pid';		
		$this->doSignal();
		$this->checkStatus();
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
			$this->daemon();
			return ;
		}

		/**
		 * 不加任何参数表示使用debug模式
		 */
		ini_set("display_errors",true);
		error_reporting(E_ALL);
		$this->echo = true;
		$this->worker();
		return ;

	}

	/**
	 * 以守护进程方式运行
	 */
	public function daemon()
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
	public function doSignal()
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
	public function checkStatus()
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
		$worker = 'worker'.$i;
		$this->setProcessTitle($this->processName . ' Worker: '. $worker);
		while( true ){
			if( method_exists($this, $worker) ){
				$this->$worker();
			}else{
				$this->worker();	
			}
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
}