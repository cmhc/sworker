# sworker

一个多进程框架。下面介绍sworker的使用方式

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

### 参数

默认情况下，Sworker内置了一些参数，通过-h或者--help参数可以看到

    -d <value>          daemon模式
    -e <value>          开启调试模式
    -h <value>          显示帮助信息
    -i <value>          指定执行某个worker，可以使用-h参数查看worker索引号
    -s <value>          给主进程发送信号，支持stop信号，终止进程
    -l <value>          指定循环次数，0为无限循环
    -u <value>          指定以某个用户来执行该程序
    --pid=<value>       pid路径
    --help=<value>      输出帮助信息

当然，可以扩充这些参数，Sworker提供Option类来扩充参数。在调用Process::addWorker()之前, 使用Option::add()方法来增加自定义的参数

    Option::add(String $name[, Boolean $required = false[, String $description = '']]);

name            参数名称必须以大写字母开头，和系统定义的参数区分开
required        表明是否是必须要有值的参数
description     通过help打印出的描述信息


### 进程通信

在1.1版本之后，Sworker增加了一个Router类，可以创建Router进程来实现进程之间的互相通信。Router进程可以形象的看作是一个路由器，需要通信的进程连接到路由器中即可互相通信，可以在samples/router.php查看路由进程是如何被创建的，可以在messenger.php中查看进程之间是如何通过路由进程互相通信的。




