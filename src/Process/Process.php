<?php
/**
 * 基本多进程类
 * 使用该类能够进行一些简单的多进程处理
 */
namespace sworker\Process;

use sworker\Option\Option;
use sworker\Messenger;
use sworker\Base\Base;

class Process extends Base
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
     * master进程执行的方法
     * @var array
     */
    protected $master = array();

    /**
     * current worker
     * @var array
     */
    protected $worker = array();

    public function __construct()
    {
        Option::init();
        $this->options = Option::getAll();
    }

    /**
     * executed before the peocess exits
     */
    public function __destruct()
    {
        if (!$this->worker) {
            return ;
        }
        $methodStopped = $this->worker['method'] . 'Stopped';
        if (method_exists($this->worker['instance'], $methodStopped)) {
            $this->worker['instance']->$methodStopped($this->worker['index']);
        }
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
     */
    public function start()
    {
        $this->initProcessInfo();
        $this->helpInfo();
        $this->registerCommand();
 
        if (isset($this->options['i'])) {
            $this->worker($this->options['i']);
            return ;
        }

        //守护进程
        if (isset($this->options['d'])) {
            //检查进程是否正在运行
            $this->checkProcess();
            $this->daemonize();
        }

        $this->startWorker();
    }

    /**
     * 添加master进程做的事情，不可以阻塞
     * 比如在写webserver的时候，可以使用addMaster添加监听，然后各个子进程都会监听
     */
    public function addMaster($class, $method)
    {
        $this->master = array(
            'class' => $class,
            'method' => $method
        );
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
     * 设置执行间隔
     * @param $seconds 单位为s
     */
    public function interval($seconds = 1)
    {
        $this->workers[$this->workerCount-1]['interval'] = $seconds;
        return $this;
    }

    /**
     * 设置某个时间之后才会运行
     * 当前时间比这个时间要小的话，则会进入下一个周期运行，使用interval设定运行周期
     * @param  string $time 美国英语日期格式的字符串，比如 2017-10-10 01:01:00
     */
    public function after($time)
    {
        if ($time = strtotime($time)) {
            $this->workers[$this->workerCount-1]['after'] = $time;
        }
        return $this;
    }

    /**
     * 初始化一些进程信息
     * 1. pid文件名称
     * 2. 是否显示错误信息
     * @return  void
     */
    protected function initProcessInfo()
    {
        if (isset($this->options['pid'])) {
            $this->pidFile = $this->options['pid'];
        } else {
            $this->pidFile = dirname(dirname(__DIR__)) . '/pid/' . basename($GLOBALS['argv'][0], '.php') . '.pid';
        }
        $pidPath = dirname($this->pidFile);
        if (!file_exists($pidPath) && !mkdir($pidPath)) {
            throw new Exception("创建pid文件夹失败", 1);
        }
        if (isset($this->options['e'])) {
            error_reporting(E_ALL);
            ini_set('display_errors', true);
        }
    }

    /**
     * 检查进程是否在执行
     * @return  void
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
     * @return  void
     */
    protected function setProcessTitle($title)
    {
        if (function_exists('cli_set_process_title')) {
            // >=php 5.5
            cli_set_process_title($title);
        } else if(extension_loaded('proctitle') && function_exists('setproctitle')) {
            //extension
            setproctitle($title);
        }
    }

    /**
     * 输出帮助信息
     * @return void
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
     * @param  int $index worker索引号
     * @return  void
     */
    protected function worker($index)
    {
        if (!isset($this->workers[$index])) {
            throw new Exception("指定的Worker不存在", 1);
        }

        if (extension_loaded('pcntl')) {
            $pcntlExists = true;
        } else {
            $pcntlExists = false;
        }

        $worker = $this->workers[$index];
        if (is_object($worker['class'])) {
            $instance = $worker['class'];
            $className = get_class($worker['class']);
        } else {
            $instance = new $worker['class'];
            $className = $worker['class'];
        }
        $method = $worker['method'];
        $this->worker = array(
            'instance' => $instance,
            'class' => $className,
            'method' => $worker['method'],
            'index' => $index,
        );
        $condition = true;
        $count = 0;
        $loop = isset($this->options['l']) ? $this->options['l'] : 1;
        $processTitle = sprintf('Sworker: %s::%s[%d]', str_replace('\\', '_', $className), $method, $index);
        $this->setProcessTitle($processTitle);

        //判断是否有延后设置
        if (isset($worker['after'])) {
            $now = time();
            if ($worker['after'] < $now) {
                $interval = $worker['interval'] ? $worker['interval'] : 1;
                while ($worker['after'] < $now) {
                    $worker['after'] += $interval;
                }
            }
            $this->log("定时在" .($worker['after'] - $now) . "s 之后运行");
            sleep($worker['after'] - $now);
        }

        //在执行之前，首先执行workerInit,只执行一次
        $methodInit = $method . 'Init';
        if (method_exists($instance, $methodInit)) {
            $instance->$methodInit($index);
        }

        while ($condition) {
            $pcntlExists && pcntl_signal_dispatch();
            //worker方法
            $instance->$method($index, $worker['args']);

            if ($loop != 0) {
                $count++;
                if ($count >= $loop) {
                    $condition = false;
                }
            }

            //判断是否有执行间隔
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
        if ($this->master) {
            if (is_object($this->master['class'])) {
                $instance = $this->master['class'];
            } else {
                $instance = new $this->master['class'];
            }
            $method = $this->master['method'];
            $instance->$method();
        }
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
            $result = posix_kill($pid, SIGINT);
            $this->log("KILL -SIGINT {$pid}, result: {$result}");
            pcntl_waitpid($pid, $status, WUNTRACED);
            $this->log("子进程{$pid}退出, status: {$status}");
        }
        @unlink($this->pidFile);
        $this->log("主进程退出");
        exit;
    }

    /**
     * 子进程退出
     */
    public function childrenQuitSignal()
    {
        if (!$this->isMasterProcess()) {
            exit();
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
     * 中断检查
     */
    public static function breakCheck()
    {
        pcntl_signal_dispatch();
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
                posix_kill($pid, SIGTERM);
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
        if (isset($this->options['e'])) {
            if (isset($this->options['stdout']) && (false != $outlog = $this->options['stdout'])) {
                $stdout = fopen($outlog, 'a');
            } else {
                $stdout = fopen('/tmp/client.out', 'a');
            }
            if (isset($this->options['stderr']) && (false != $errlog = $this->options['stderr'])) {
                $stderr = fopen($errlog, 'a');
            } else {
                $stderr = fopen('/tmp/client.err','a');
            }
        } else {
            $stdout = fopen('/dev/null','a');
            $stderr = fopen('/dev/null','a');
        }

    }
}