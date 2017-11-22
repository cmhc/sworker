<?php
/**
 * 进程间消息通信
 */
namespace sworker\Messenger;

use sworker\Base\Base;

abstract class Messenger extends Base
{
    protected $dispatcher;

    /**
     * executor 连接
     * @var array
     */
    protected $connection = array();

    public function __destruct()
    {

    }

   /**
     * 消息发送
     * @param  integer $dest 目的地序号，实际上就是worker序号
     * @param  mixed $data 数据
     */
    public function send($dest, $data)
    {
        //循环发送队列里面的信息
        //该方法会检测是否有链接到达，以及是否存在可写的socket
        $r = array($this->dispatcher['server']);
        if (isset($this->dispatcher[$dest])) {
            $w = array($this->dispatcher[$dest]);
        } else {
            $w = null;
        }
        $e = null;
        socket_select($r, $w, $e, 1);
        
        //判断是否有新的连接
        if (isset($r[0])) {
            $this->acceptExecutor($r[0]);
        }

        //判断是否可写
        if (isset($w[0])) {
            $data = str_replace("\n", '%5C6E', $data); //换行转义
            $data .= "\n";
            $len = strlen($data);
            while (true) {
                $sent = socket_write($w[0], $data, $len);
                if ($sent === false) {
                    return false;
                }
                if ($sent < $len) {
                    $data = substr($data, $sent);
                    $len -= $sent;
                } else {
                    break;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * 接受从executor传送的连接
     * executor需要上报序号，否则executor不知道是哪个execotur
     * @param  source $socket dispatcher socket
     * @return
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
        $this->log("{$dest}: 连接已经建立");
        $this->dispatcher[$dest] = $handle;
    }

    /**
     * 消息接收
     */
    public function receive()
    {
        if($this->connection['handle'] == false) {
            return false;
        }
        
        $r = array($this->connection['handle']);
        $w = $e = null;
        stream_select($r, $w, $e, null);
        
        if (!is_resource($r[0])) {
            $this->connection['handle'] = false; //下一次重新连接
            return false;
        }

        $content = fgets($r[0], 65535);
        if ($content == false) {
            $this->connection['handle'] = false; //下一次重新连接
            return false;
        }

        return trim(str_replace('%5C6E', "\n", $content));
    }
}
