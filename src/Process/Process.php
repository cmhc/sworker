<?php
/**
 * 基本多进程类
 * 使用该类能够进行一些简单的多进程处理
 */
namespace sworker\Process;

use sworker\Option\Option;
use sworker\Messenger\Messenger;

class Process
{
    protected $options = array();

    protected $workerCount = 0;

    /**
     * 进程pid
     * @var array
     */
    protected $process = array();

    /**
     * pid 文件路径
     * @var string
     */
    protected $pidFile = '';

    /**
     * 主进程pid
     * @var integer
     */
    protected $masterPid = 0;

    /**
     * workers
     * @var array
     */
    protected $workers = array();

    /**
     * 当前进程索引号
     * @var integer
     */
    protected $index;

    public function __construct()
    {
        Option::init();
        $this->options = Option::getAll();
    }

    /**
     * 处理在cs模式下面数据发送未完成的的情况
     */
    public function __descturt()
    {
    }

    /**
     * 进程参数设定
     */
    public function set(array $options = array())
    {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * 运行主方法
     * @param   $class  
     * @param   $method 
     * @param   $args   
     * @return          
     */
    public function start()
    {
        $this->initProcessInfo();
        $this->helpInfo();
        $this->registerCommand();
        // 检查进程是否正在运行
        $this->checkProcess();
        if (isset($this->options['i'])) {
            $this->worker($this->options['i']);
        }
        //没有pcntl，只允许执行一个worker
        if (!extension_loaded('pcntl')) {
            echo "系统没有安装Pcntl扩展，只允许执行第一个worker\n";
            $this->worker(0);
            exit;
        }
        //守护进程
        if (isset($this->options['d'])) {
            $this->daemonize();
        }

        $this->startWorker();
    }

    /**
     * 添加worker
     */
    public function addWorker($class, $method, $args = array())
    {
        $this->workers[] = array(
            'class' => $class,
            'method' => $method,
            'args' => $args,
        );
        $this->workerCount++;
        return $this;
    }

    /**
     * 指定为调度器，负责数据的发送
     */
    public function setDispatcher($ip, $port)
    {
        $this->workers[$this->workerCount-1]['dispatcher'] = 1;
        $this->workers[$this->workerCount-1]['ip'] = $ip;
        $this->workers[$this->workerCount-1]['port'] = $port;        
        return $this;
    }

    /**
     * 指定为执行者，从调度器接收数据
     */
    public function setExecutor($ip, $port)
    {
        $this->workers[$this->workerCount-1]['executor'] = 1;
        $this->workers[$this->workerCount-1]['ip'] = $ip;
        $this->workers[$this->workerCount-1]['port'] = $port;
        return $this;
    }

    /**
     * 设置执行间隔
     * @param $seconds 单位为s
     */
    public function interval($seconds = 1)
    {
        $this->workers[$this->workerCount-1]['interval'] = $seconds;
        return $this;
    }

    /**
     * 初始化一些进程信息
     * 1. pid文件名称
     * 2. 是否显示错误信息
     */
    protected function initProcessInfo()
    {
        $pidPath = dirname(dirname(__DIR__)) . '/pid';
        if (!file_exists($pidPath) && !mkdir($pidPath)) {
            throw new Exception("创建pid文件夹失败", 1);
        }
        $pidName = isset($this->options['pid']) ? $this->options['pid'] : basename($GLOBALS['argv'][0], '.php') . '.pid';
        $this->pidFile = $pidPath . '/' . $pidName;
        if (isset($this->options['e'])) {
            error_reporting(E_ALL);
            ini_set('display_errors', true);
        }
    }

    /**
     * 检查进程是否在执行
     */
    protected function checkProcess()
    {
        if (file_exists($this->pidFile)) {
            throw new Exception("pid文件已经存在", 1);
        }
    }

    /**
     * 设置进程名称
     * @param  string $title 进程名称
     */
    protected function setProcessTitle($title)
    {
        if (function_exists('cli_set_process_title')) {
            // >=php 5.5
            cli_set_process_title($title);
        }else if(extension_loaded('proctitle') && function_exists('setproctitle')) {
            //扩展
            setproctitle($title);
        }
    }

    /**
     * 输出帮助信息
     */
    protected function helpInfo()
    {
        if (!isset($this->options['h']) && !isset($this->options['help'])) {
            return ;
        }
        $help = Option::getHelpString();
        $help .= "== worker索引号 ==\n";
        if (!empty($this->workers)) {
            foreach ($this->workers as $k => $v) {
                if (is_object($v['class'])) {
                    $v['class'] = get_class($v['class']);
                }
                $help .= "[$k]: " . json_encode($v) . "\n";
            }
        }

        $help .= "\n使用愉快 ^_^\n";
        exit($help);
    }

    /**
     * 执行一个worker
     */
    protected function worker($index)
    {
        $this->index = $index;

        if (!isset($this->workers[$index])) {
            throw new Exception("指定的Worker不存在", 1);
        }

        $worker = $this->workers[$index];
        if (is_object($worker['class'])) {
            $obj = $worker['class'];
            $className = get_class($worker['class']);
        } else {
            $obj = new $worker['class'];
            $className = $worker['class'];
        }

        $method = $worker['method'];
        $condition = true;
        $count = 0;
        $loop = isset($this->options['l']) ? $this->options['l'] : 1;
        $this->setProcessTitle('Sworker: '. $className . "::" . $method);
        $dispatcher = isset($worker['dispatcher']) ? true : false;
        $executor = isset($worker['executor']) ? true : false;

        //调度模式
        if ($dispatcher || $executor) {
            $sendStatus = true;
            $this->messenger = new Messenger();
            if ($dispatcher) {
                $this->messenger->serverStart($worker['ip'], $worker['port']);
            }
            if ($executor) {
                $this->messenger->clientStart($method . $index, $worker['ip'], $worker['port']);
            }
        }

        //在执行之前，首先执行workerInit,只执行一次
        $methodInit = $method . 'Init';
        if (method_exists($obj, $methodInit)) {
            $obj->$methodInit($index);
        }

        while ($condition) {
            $count++;

            if ($dispatcher && $sendStatus) {
                //调度进程需要将消息写入msg,发送成功才清空，否则不清空
                $msg = array();
            }

            if ($executor) {
                //执行进程需要获取消息
                $msg = $this->messenger->receive();
            }

            $obj->$method($index, $worker['args'], $msg);

            //如果是调度器，则执行发送消息方法
            if ($dispatcher) {
                $sendStatus = $this->messenger->send($msg[0], $msg[1]);
            }

            if ($loop != 0 && $count >= $loop) {
                $condition = false;
            }

            //executor有消息不终止
            if ($executor && isset($msg) && $msg) {
                $condition = true;
            } else {
                pcntl_signal_dispatch();
            }

            //执行间隔
            if (isset($worker['interval'])) {
                sleep($worker['interval']);
            }

        }
    }



    /**
     * 转为守护进程
     */
    protected function daemonize()
    {
        $this->registerSignal();
        if (($pid = pcntl_fork()) > 0) {
            exit();
        }

        if (($sid = posix_setsid()) < 0) {
            throw new Exception("设置会话组失败", 1);
        }

        $user = isset($this->options['u']) ? $this->options['u'] : 'root';
        $group = posix_getpwnam($user);

        if (!isset($group['gid']) || !isset($group['uid'])) {
            throw new Exception("用户{$user}不存在", 1);
        }

        posix_setgid($group['gid']);
        posix_setuid($group['uid']);

        if (($pid = pcntl_fork()) > 0) {
            exit();
        }

        $this->setProcessTitle("Sworker: master process ({$GLOBALS['argv'][0]})");
        $this->masterPid = posix_getpid();
        file_put_contents($this->pidFile, $this->masterPid);
        $this->redirectOutput();
        chdir('/');
        umask(0);
    }

    /**
     * 启动所有的worker
     */
    protected function startWorker()
    {
        //fork worker进程
        for ($i = 0; $i < $this->workerCount; $i++) {
            if (($pid = pcntl_fork()) == -1) {
                throw new Exception("fork 进程失败", 1);
            }
            if ($pid > 0) {
                $this->process[$pid] = $i;
            } else {
                break;
            }
        }

        if ($pid > 0) {
            while (1) {
                $quitPid = pcntl_wait($status, WNOHANG);
                if ($quitPid > 0) {
                    $quitWorkerIndex = $this->process[$quitPid];
                    unset($this->process[$quitPid]);
                    if (isset($this->options['l']) && $this->options['l'] == 0) {
                        $this->restartWorker($quitWorkerIndex);
                    }
                }
                if (empty($this->process)) {
                    @unlink($this->pidFile);
                    break;
                }
                sleep(1);
                pcntl_signal_dispatch();
            }
        }

        if ($pid == 0) {
            $this->worker($i);
        }
    }

    /**
     * 重新启动指定进程
     */
    protected function restartWorker($i)
    {
        if (($pid = pcntl_fork()) == -1) {
            throw new Exception("fork 进程失败", 1);
        }

        if ($pid == 0) {
            $this->worker($i);
        } else {
            $this->process[$pid] = $i;
        }
    }

    /**
     * 主进程退出
     */
    public function masterQuitSignal()
    {
        if (!$this->isMasterProcess()) {
            return ;
        }
        //按照进程编号排序，数字越大越先结束
        uasort($this->process, function ($a, $b) {
            if ($a == $b) {
                return 0;
            }
            return ($a > $b) ? 1 : -1;
        });

        foreach ($this->process as $pid => $index) {
            posix_kill($pid, SIGINT);
            pcntl_waitpid($pid, $status, WUNTRACED);
            $this->vardump("子进程{$pid}退出");
        }
        @unlink($this->pidFile);
        $this->vardump("主进程退出");
        exit;
    }

    /**
     * 子进程退出
     */
    public function childrenQuitSignal()
    {
        if (!$this->isMasterProcess()) {
            exit;
        }
    }

    /**
     * 打印信息
     */
    public function vardump($msg, $title = '')
    {
        if (!isset($this->options['e'])) {
            return ;
        }

        if ($title != '') {
            echo "=={$title}==\n";
        }

        echo '[' . date('Y-m-d H:i:s') . '] ';
        var_dump($msg);
    }


    /**
     * 判断当前进程号是不是主进程
     * @return boolean
     */
    protected function isMasterProcess()
    {
        return ($this->masterPid == posix_getpid());
    }

    /**
     * 注册信号
     */
    protected function registerSignal()
    {
        pcntl_signal(SIGTERM, array(&$this, 'masterQuitSignal'));
        pcntl_signal(SIGINT, array(&$this, 'childrenQuitSignal'));
    }

    /**
     * 注册指令
     */
    protected function registerCommand()
    {
        if (empty($this->options['s'])) {
            return ;
        }

        if (!file_exists($this->pidFile)) {
            exit("pid file not exist\n");
        }

        $pid = file_get_contents($this->pidFile);

        switch ($this->options['s']) {
            case 'stop':
                posix_kill( $pid, SIGTERM);
                echo "process stopping\n";
                while (file_exists($this->pidFile)) {
                    usleep(100000);
                }
                echo "process stoped\n";
            break;

            default:
                echo "command error\n";
            break;
        }
        exit;
    }

    /**
     * 重定向输出
     */
    protected function redirectOutput()
    {
        global $stdin, $stdout, $stderr;
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $stdin = fopen('/dev/null', 'r');
        $stdout = fopen('/tmp/client.out','a');
        $stderr = fopen('/tmp/client.err','a');
    }
}