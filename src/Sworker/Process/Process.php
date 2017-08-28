<?php
/**
 * 基本多进程类
 * 使用该类能够进行一些简单的多进程处理
 */
namespace Sworker\Process;

use Sworker\Option\Option;

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

    public function __construct()
    {
        Option::init();
        $this->options = Option::getAll();
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
     * 指定为调度器
     */
    public function setDispatcher()
    {
        $this->workers[$this->workerCount-1]['dispatcher'] = 1;
    }

    /**
     * 指定为执行者
     */
    public function setExecutor()
    {
        $this->workers[$this->workerCount-1]['executor'] = 1;
    }

    /**
     * 初始化一些进程信息
     * 1. pid文件路径
     * 2. 
     */
    protected function initProcessInfo()
    {
        $pidPath = isset($this->options['pid']) ? $this->options['pid'] : dirname(dirname(dirname(__DIR__))) . '/pid';
        if (!file_exists($pidPath) && !mkdir($pidPath)) {
            throw new \Sworker\Exception\ProcessException("创建pid文件夹失败", 1);
        }
        $this->pidFile = $pidPath . '/' . basename($GLOBALS['argv'][0], '.php') . '.pid';
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
            throw new \Sworker\Exception\ProcessException("pid文件已经存在", 1);
        }
    }

    /**
     * 设置进程名称
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
        foreach ($this->workers as $k => $v) {
            $help .= "[$k]: " . json_encode($v) . "\n";
        }
        $help .= "\n使用愉快 ^_^\n";
        exit($help);
    }

    /**
     * 执行一个worker
     */
    protected function worker($index)
    {
        if (!isset($this->workers[$index])) {
            throw new \Sworker\Exception\ProcessException("指定的Worker不存在", 1);
        }

        $worker = $this->workers[$index];
        $obj = new $worker['class'];
        $method = $worker['method'];
        $condition = true;
        $count = 0;
        $loop = isset($this->options['l']) ? $this->options['l'] : 1;
        $this->setProcessTitle('Sworker: '. $worker['class'] . "::" . $method);
        while ($condition) {
            $count++;
            $obj->$method($index, $worker['args']);
            if ($loop != 0 && $count >= $loop) {
                $condition = false;
            }
            pcntl_signal_dispatch();
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
            throw new \Sworker\Exception\ProcessException("设置会话组失败", 1);
        }

        $user = isset($this->options['u']) ? $this->options['u'] : 'root';
        $group = posix_getpwnam($user);

        if (!isset($group['gid']) || !isset($group['uid'])) {
            throw new \Sworker\Exception\ProcessException("用户{$user}不存在", 1);
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
                throw new \Sworker\Exception\ProcessException("fork 进程失败", 1);
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
                    if ($this->options['l'] == 0) {
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
            throw new \Sworker\Exception\ProcessException("fork 进程失败", 1);
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
     * 打印信息
     */
    protected function vardump($msg, $title = '')
    {
        if (!isset($this->options['e'])) {
            return ;
        }

        if ($title != '') {
            echo "=={$title}==\n";
        }

        var_dump($msg);
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
