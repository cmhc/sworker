<?php
/**
 * 进程间消息通信
 */
namespace sworker\Messenger;

use sworker\Base\Base;

class Messenger extends Base
{
    protected $dispatcher;

    /**
     * executor 连接
     * @var array
     */
    protected $connection = array();

    public function serverStart($ip, $port)
    {
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($server, $ip, $port)) {
            throw new Exception("socket bind error", 1);
        }

        if( !socket_listen($server, 8) ){
            throw new Exception("socket listen error", 1);
        }
        $this->log("服务启动,ip:{$ip} port:{$port}");
        $this->dispatcher['server'] = $server;
    }

    /**
     * 客户端连接服务端
     */
    public function clientStart($name, $ip, $port)
    {
        $this->log("{$name}: 连接server");
        $sock = "tcp://{$ip}:{$port}";
        //有可能客户端比服务端先启动，因此需要失败自动重试，直到连接成功
        while (!($this->connection['handle'] = stream_socket_client($sock, $errno, $errstr, -1))) {
            $this->log("client: connection failed {$errstr}({$errno})");
            sleep(1);
        }

        $w = array($this->connection['handle']);
        $r = $e = null;
        stream_select($r, $w, $e, null);
        fwrite($this->connection['handle'], $name."\n", strlen($name)+1);
        $this->log("{$name}: 握手成功");
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
        $r = $w = $this->dispatcher;
        $e = null;
        socket_select($r, $w, $e, null);
        if (isset($r['server'])) {
            $this->acceptExecutor($r['server']);
        }

        if (isset($w[$dest])) {
            $data .= "\r\n";
            if (socket_write($w[$dest], $data, strlen($data))) {
                return true;
            } else {
                return false;
            }
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

        $content = fgets($r[0], 8192);
        if ($content == false) {
            $this->connection['handle'] = false; //下一次重新连接
            return false;
        }

        return trim($content);
    }
}
