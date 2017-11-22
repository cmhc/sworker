# sworker

一个多进程框架。

# 使用方式



## 普通多进程模式

多进程框架相当于一个容器，而你需要做的就是编写你所需要运行的方法，放在这个容器中去运行。以一个测试类Test为例：

    class Test
    {
        static $count = 0;
        /**
         * 测试普通的方法
         * 该方法整体可以看成原子操作，不会被中断，除非使用kill -9强制杀进程
         */
        public function sleepMethod($i)
        {
            echo "process:" . $i ."\tcount: ". self::$count . "\tsleep 1s\n";
            sleep(1);
            self::$count ++;
        }
    }

之后我们可以实例化Process类。
    
    use sworker\Process\Process;
    $process = new Process();
    //加入worker，可以直接使用类的名称
    $process->addWorker('Test', 'sleepMethod');
    //也可以使用实例化之后的类
    $process->addWorker(new Test(), 'sleepMethod');
    //这一步是开始执行
    $process->start();

### 任务执行间隔以及定时执行

可以将Sworker当做是一个crontab，能够让任务在某个时间之后，以每隔一定时间执行一次，只要使用`Process::after()` 和 `Process::interval()`，    
,这两个方法就可以实现定时任务了。代码示例如下。

    class Test
    {
        static $count = 0;
        /**
         * 测试普通的方法
         * 该方法整体可以看成原子操作，不会被中断，除非使用kill -9强制杀进程
         */
        public function method($i)
        {
            echo "process:" . $i ."\tcount: ". self::$count . "\tsleep 1s\n";
            self::$count ++;
        }
    }
    use sworker\Process\Process;
    $process = new Process();
    //通过这两个组合，method进程会在每天的9点1分1s之后执行一次
    $process->addWorker('Test', 'method')->interval(86400)->after(1,1,9);
    //这一步是开始执行
    $process->start();

方法参数

    Process::after($second[, $minute[, int $hour[, $day[, $month[, $year]]]]])

    Process::interval($seconds)

### 进程通信