<?php
/**
 * sworker 一个多进程任务执行框架
 *
 * @author huchao <hu_chao@139.com>
 */
namespace sworker;

abstract class sworkerd
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
	 * 子类自定义的脚本参数
	 * @var string
	 */
	protected $userOpt;

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
	 * 服务端信息
	 * @var array
	 */
	protected $server;

	/**
	 * 调度器信息
	 * 维护clients信息，server信息以及各自的状态
	 * @var array
	 */
	protected $dispatcher = array();

	/**
	 * 由worker维护的连接
	 * @var array
	 */
	protected $connection;

	/**
	 * pid文件保存路径
	 * @var string
	 */
	protected $pidFile;

	/**
	 * worker数组
	 * @var array
	 */
	protected $workers;

	/**
	 * worker总数
	 * 该数量会自动计算
	 * @var int
	 */
	private $workerNum;

	/**
	 * 写入共享内存连接池
	 * @var array
	 */
	private $sendm = array();


	/**
	 * 读取共享内存对象
	 * @var 
	 */
	private $receivem;

	/**
	 * 初始化参数
	 */
	public function __construct()
	{
		/**
		 * option
		 * -s<signal>   需要对 master 进程发送的信号
		 * -d           daemon 模式，默认不会使用daemon
		 * --pid=<pid path> pid保存的路径，默认和程序在同一个目录
		 * -u<username> 使用哪个用户来执行
		 * -t 			开启错误信息
		 * -l  		    测试循环次数，0使用无限循环
		 * -T   		调试worker编号
		 */
		$longOpt = array(
			'pid::', //pid保存位置
			'help', //帮助信息
			'job::',
			);
		$opt = "ds:u:tT:l:" . $this->userOpt;
		$this->option = getopt($opt, $longOpt);
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
		//发送EOF,表示dispatcher已经关闭，不再分发任务
		if (!empty($this->dispatcher)) {
			foreach ($this->dispatcher as $key=>$handle) {
				if ($key != 'server') {
					socket_write($handle, "EOF\r\n", 5);
					socket_shutdown($handle);
					socket_close($handle);
				}
			}
		}

		//关闭worker维护的连接
		if( $this->connection['handle'] ){
			fclose($this->connection['handle']);
		}

		if (!empty($this->receivem)) {
			shmop_delete($this->receivem);
			shmop_close($this->receivem);
		}

		if (!empty($this->sendm)) {
			foreach ($this->sendm as $m) {
				shmop_delete($m);
				shmop_close($m);
			}
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
		$this->checkWorkers();
		$this->initWorkerNum();
		$this->help();

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
		if( method_exists($this, 'beforeWorker') ){
			$this->beforeWorker($this->option['T']);
		}
		
		if (isset($this->option['l'])){
			if ($this->option['l'] > 0) {
				for ($i=0; $i<$this->option['l']; $i++) {
					$this->worker($this->option['T']);
				}
			} else {
				while(1) {
					$this->worker($this->option['T']);
				}
			}
		} else {
			$this->worker($this->option['T']);
		}

		return ;
	}

	public function getOption()
	{
		return $this->option;
	}

	/**
	 * 帮助信息
	 * 在没有参数或者
	 */
	protected function help()
	{
		if (isset($this->option['d']) ||
			isset($this->option['T'])
			) {
			return ;
		}
		$help = "帮助文档
使用 php filename.php [option] 来执行脚本
option列表如下
--help                显示帮助信息
--pid=<pid path>      pid保存的路径，默认和程序在同一个目录
-s<signal>            需要对 master 进程发送的信号,支持stop，restart，forcestop
-u<username>          使用哪个用户来执行
-t                    守护进程模式开启调试信息
-T<num>               需要测试worker编号，将会单次执行指定的worker
-d                    守护进程模式\n";
		$help .= "可以调试的worker编号与对应方法,如php filename.php -T0\n";
		foreach ($this->workers as $k=>$v) {
			$help .= "{$k} => {$v}\n";
		}
		$help .= "使用愉快^_^\n";
		echo $help;
		exit();
	}


	/**
	 * 添加worker
	 * @param  string $name worker名称，需要传递worker的方法名称
	 * @return  int 返回索引
	 */
	protected function addWorker($name)
	{
		$this->workers[] = $name;
		return count($this->workers) - 1;
	}

	/**
	 * 添加需要自定义接收的参数
	 * 需要在父类初始化之前使用
	 * @param string $opt 参见getopt里面的参数
	 */
	protected function addOpt($opt)
	{
		$this->userOpt = $opt;
	}

	/**
	 * worker执行执行的初始化工作
	 * 子类可以覆盖此方法
	 * @param  integer $i
	 * @return 
	 */
	protected function beforeWorker($i=0)
	{
		if (isset($this->workers[$i])) {
			$name = $this->workers[$i] . 'Init';
			if( method_exists($this, $name) ){
				$this->$name();
			}else{
				$this->vardump("method {$name} not exists");
			}
		} else {
			throw new \Exception("worker not exists", 1);
		}
	}

	/**
	 * worker 方法
	 */
	protected function worker($i=0)
	{
		if( isset($this->workers[$i]) && method_exists($this, $this->workers[$i]) ){
			$name = $this->workers[$i];
			$this->$name($i);
		}else{
			throw new \Exception("process {$this->workers[$i]} not exists", 1);
		}
	}

	/**
	 * 自定义进程名称
	 */
	protected function customProcessName($i)
	{
		if( isset($this->workers[$i]) ){
			return $this->workers[$i] . $i;
		}

		return 'worker'.$i;
	}

	/**
	 * 检查workers是否已经定义
	 */
	protected function checkWorkers()
	{
		if (empty($this->workers)) {
			throw new \Exception('The worker is not initialized, please initialized $this->workers in the inheritance class', 1);
		}
	}

	/**
	 * 初始化worker数量
	 * worker数量能够在进程通信的是否进行连接判断
	 */
	protected function initWorkerNum()
	{
		$this->workerNum = count($this->workers);
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
		 * master 进程 fork n 个worker进程来执行任务，n为子进程定义的worker
		 */
		for ($i=0; $i<$this->workerNum; $i++) {
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

			//强制杀死子进程
			pcntl_signal(SIGTERM, function(){
				if( !empty($this->process) ){
					//按照进程编号排序，数字越大越先结束
					uasort($this->process, function($a, $b){
						if( $a == $b ){
							return 0;
						}
						return ($a > $b) ? 1 : -1;
					});
					foreach( $this->process as $cpid=>$v ){
						posix_kill($cpid, SIGKILL);
						//pcntl_waitpid($cpid, $status, WUNTRACED);
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

				case 'forcestop':
					posix_kill( $pid, SIGTERM);
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

		//自定义的等待信号，让出处理器
		pcntl_signal(SIGUSR1, function(){
			//不做任何处理
		});

		$this->log("{$this->processName}: worker process start");

		//在子进程中自定义进程名称
		if( method_exists($this, 'customProcessName') ){
			$workerName = $this->customProcessName($i);
		}

		if( empty($workerName) ){
			$workerName = 'worker'.$i;
		}
		$this->connection = array('name'=>$workerName, 'handle'=>false);

		$this->setProcessTitle("{$this->processName} Worker : $workerName");
		
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
		if ($pid < 0) {
			$this->vardump("fork error, task name {$this->workers[$i]}");
		}
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
		exec("/usr/local/php/bin/php {$script} -d", $status);
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

    public function stop()
    {
		$pid = file_get_contents( $this->pidFile );
		posix_kill( $pid, SIGHUP);
    }

    /**
     * 记录进程运行日志
     * @param  string $content 
     * @return 
     */
    protected function log($content)
    {
    	$content = '[' . date('Y-m-d H:i:s') . "] {$content}\n";
    	$this->vardump($content);
		file_put_contents(__DIR__ . "/client.log", $content, FILE_APPEND|LOCK_EX);
    }




    /* --------------------------- 
     * 共享内存通信方式
     * --------------------------- */

    /**
     * 发送数据
     * @param   $dest 
     * @param   $data 
     * @return
     */
    final protected function sendData($dest, $data)
    {
    	//共享调度进程pid
    	//if (!isset($this->dispatcherPid)) {
    	//	$this->dispatcherPid = $this->setDispatcherPid();
    	//}
    	
    	//不是第一次执行，则需要等待执行者进程进程就绪
    	//if (!isset($this->notFirstSend)) {
    	//	pcntl_sigwaitinfo(array(SIGUSR1), $info);
    	//	$this->notFirstSend = true;
    	//}

    	$pid = $this->getDestPid($dest);
    	$this->vardump($pid, "目标pid");

    	if (!$pid) {
    		return false;
    	}

       	if (!isset($this->sendm[$dest])) {
       		$key = 'data' . $dest;
    		$token = crc32($key);
    		if (!($this->sendm[$key] = shmop_open($token, 'c', 0644, 8192))) {
    			throw new Exception("创建共享内存失败", 1);
    		}
    	}
    	//检测是否可以写入
    	$canWrite = shmop_read($this->sendm[$key], 0, 1);
    	if ($canWrite != '0') {
    		$data = '0' . $data;
	    	if (shmop_write($this->sendm[$key], $data, 0)) {
	    		$this->vardump($data, '写入数据');
	    		//给执行者发送信号，表示可以读取了
	    		posix_kill($pid, SIGUSR1);
	    		return true;
	    	}
    	}

    	return false;

    }


    /**
     * 数据接收
     * @param  $i
     * @return 
     */
    final protected function receiveData($i)
    {
    	//共享自己的pid
    	if (!isset($this->issetPid)) {
    		$this->issetPid = $this->setDestPid($i);
    	}
    	//等待信号
    	pcntl_sigwaitinfo(array(SIGUSR1), $info);
    	//从共享内存读取数据
    	if (!isset($this->receivem)) {
    		$token = crc32('data' . $dest);
    		$this->receivem = shmop_open($token, 'w', 0644, 8192);
    	}
		$info = shmop_read($this->receivem, 1, 8192);
		$this->vardump($info, "收到数据");
		shmop_write($this->receivem, '1', 0);//表示调度进程可以往共享内存里面写数据
		if (!$info) {
			return false;
		}
		return $info;
    }

    /**
     * 获取子进程的pid
     * 通过共享内存方式获得
     */
    protected function getDestPid($dest)
    {
    	$key = 'pid' . $dest;
    	if (empty($this->sendm[$key])) {
    		$token = crc32($key);
    		$this->sendm[$key] = shmop_open($token, 'a', 0644, 4);
		}
		if (!$this->sendm[$key]) {
			return false;
		}
		$info = shmop_read($this->sendm[$key], 0, 16);
		if (!$info) {
			return false;
		}
		//return $info;
    	$pid = unpack('n', $info);
    	return $pid[1];
    }

    /**
     * 写入pid到共享内存中
     * @param  int $i i为进程序号
     */
    protected function setDestPid($i)
    {
    	$key = 'pid' . $this->customProcessName($i);
		$token = crc32($key);
		$shm = shmop_open($token, 'c', 0644, 16);
		if (!$shm) {
			throw new Exception("创建共享内存失败", 1);
		}
		$pid = posix_getpid();
		$this->vardump($pid, "接收进程pid");
		$info = shmop_write($shm, pack('L', $pid), 0);
		return $info;
    }


    /*
	**********************************************************
    以下为进程通信部分
    使用socket通信方式
    心跳包包采用服务端向客户端发送的方式，间隔为30s
	**********************************************************
    */
   
   	final protected function setServer($server) {
   		$this->server = $server;
   	}

    /**
     * dispather 和 executor 之间通信
     * @param  string $dest 目的进程名称
     * @param  string $data 数据
     */
    final protected function sendMessage($dest, $data)
    {
    	$this->vardump($data, "{$dest}原数据");
    	
    	//创建服务器，只会生成一个$this->dispatcher['server']
    	$this->createServer();

    	//循环发送队列里面的信息
    	//该方法会检测是否有链接到达，以及是否存在可写的socket
		$r = $w = $this->dispatcher;
    	$e = null;
    	socket_select($r, $w, $e, null);
    	if (isset($r['server'])) {
    		$this->acceptExecutor($r['server']);
    	}
    	if (isset($w[$dest])) {
    		$data .= "\r\n";
    		if (!socket_write($w[$dest], $data, strlen($data))) {
    			return false;
    		}
    	}

		return true;
    }

    /**
     * 接收worker发送的数据
     * 在缓冲区有数据的时候，该方法会返回一条数据,无数据或者超时之后会返回false
     * 
     * 业务处理的时候，应该一直调用该方法接收数据，直到返回false为止
     * 服务端不会保存数据，客户端处理不当会有丢失数据的风险
     * @return string
     */
    final protected function receiveMessage()
    {
    	if (!$this->connection['handle']) {
    		$this->log("{$this->connection['name']}: 连接 server");
			$sock = "tcp://{$this->server['ip']}:{$this->server['port']}";
			if ($this->connection['handle'] = stream_socket_client($sock, $errno, $errstr, -1)) {
    			$w = array($this->connection['handle']);
    			$r = $e = null;
    			stream_select($r, $w, $e, null);
    			fwrite($this->connection['handle'], $this->connection['name']."\n", strlen($this->connection['name'])+1);
    			$this->log("{$this->connection['name']}: 握手成功");
			}else{
				$this->log("client: connection failed {$errstr}({$errno})");
				sleep(1);
				return false;
			}
    	}
    	$r = array($this->connection['handle']);
    	$w = $e = null;
    	stream_select($r, $w, $e, null);
		$content = fgets($r[0], 8192);
		if ($content == false) {
			$this->connection['handle'] = false; //下一次重新连接
			return false;
		}
		//表示服务端主动关闭
		if ($content == "EOF\r\n") {
			return false;
		}
		return trim($content);
    }

    /**
     * 创建服务器
     */
    protected function createServer()
    {
    	//启动调度服务器
    	if( !isset($this->dispatcher['server']) ){
    		if (!isset($this->server['ip']) || !isset($this->server['port'])) {
    			return false;
    		}

			$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);

			if (!socket_bind($server, $this->server['ip'], $this->server['port'])) {
				throw new \Exception("socket bind error", 1);
			}

			if( !socket_listen($server, 8) ){
				throw new \Exception("socket listen error", 1);
			}
			$this->dispatcher['server'] = $server;
    	}
    }

    /**
     * 接收executor的连接
     */
    protected function acceptExecutor($socket)
    {
    	$handle = socket_accept($socket);
    	$r = array($handle);
    	$w = $e = null;
    	socket_select($r, $w, $e, null);
    	$dest = '';
		while(1){
			$tmp = socket_read($handle, 1);
			if ($tmp == "\n") {
				break;
			}
			$dest .= $tmp;
		}
		$this->dispatcher[$dest] = $handle;
    }

}