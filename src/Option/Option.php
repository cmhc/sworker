<?php
/**
 * 进程中的参数定义
 */
namespace sworker\Option;

class Option
{
    protected static $options = array();

    protected static $changed = true;

    protected static $help;
    
    protected static $keys = array();

    protected static $longKeys = array();

    /**
     * 初始化一些默认的定义信息
     * 用户自定义的需要以大写字母开头
     */
    public static function init()
    {
        self::$keys = array(
            'd', // daemon模式，没有值
            'e', // 开启调试信息，debug取第二个字母
            'h', // help 帮助信息
            'i:', // 指定执行某个worker，可以使用h参数查看索引号
            's:', // signal，发送的信号
            'l:', // loop 和e结合使用 指定循环次数
            'u:', // user 以哪个用户来执行程序
        );

        self::$longKeys = array(
            'pid:', // pid保存路径
            'help',  // 帮助信息
        );

        self::$help = array(
            'd' => 'daemon模式',
            'e' => '开启调试模式',
            'h' => '显示帮助信息',
            'i' => '指定执行某个worker，可以使用-h参数查看worker索引号',
            's' => '给主进程发送信号',
            'l' => '和e结合 指定循环次数',
            'u' => '指定以某个用户来执行该程序',
            'pid' => 'pid路径',
            'help' => '输出帮助信息',
        );
    }

    /**
     * 获取参数
     * @param  $key
     * @return 
     */
    public static function get($key)
    {
        if (self::$changed) {
            $opt = implode('', self::$keys);
            self::$options = getopt($opt, self::$longKeys);
        }
        self::$changed = false;
        if (isset(self::$options[$key])) {
            return self::$options[$key];
        }
        return false;
    }

    /**
     * 获取所有参数
     */
    public static function getAll()
    {
        if (self::$changed) {
            $opt = implode('', self::$keys);
            self::$options = getopt($opt, self::$longKeys);
        }
        self::$changed = false;
        return self::$options;
    }

    /**
     * 增加参数
     * 用户增加的参数需要以大写字母开头
     */
    public static function add($key, $requireValue, $helpInfo = '')
    {
        if (ord(substr($key, 0, 1)) > 90) {
            throw new Exception("用户自定义的配置需要使用大写字母或者大写字母开头", 1);
        }
        self::$help[$key] = $helpInfo;
        if ($requireValue) {
            $key .= ':';
        }
        if (in_array($key, self::$keys) || in_array($key, self::$longKeys)) {
            throw new Exception("参数{$key}已经存在", 1);
        }
        if (strlen($key) > 1) {
            self::$longKeys[] = $key;
        } else {
            self::$keys[] = $key;
        }
        self::$changed = true;
    }

    /**
     * 获取帮助信息
     */
    public static function getHelpString()
    {
        $help = "= 使用方法 =\n";
        $help .= "php filename.php -<option name> <option value> or\n";
        $help .= "--<long option name>=<option value>\n\n";
        $help .= "== 参数列表 ==\n";
        foreach (self::$help as $key=>$value) {
            if (strlen($key) > 1) {
                $line = "--{$key}=<value>";
            } else {
                $line = "-{$key} <value>";
            }
            $line = str_pad($line, 20, " ", STR_PAD_RIGHT);
            $line .= $value;
            $help .= $line . "\n";
        }
        $help .= "\n";
        return $help;
    }
}
