<?php
/**
 * 使用TCP进行通信
 */
namespace sworker\Messenger;

use sworker\Base\Base;

class TCP extends Messenger
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

        if( !socket_listen($server, 24) ){
            throw new Exception("socket listen error", 1);
        }
        $this->log("服务启动,ip:{$ip} port:{$port}");
        $this->dispatcher['server'] = $server;
        sleep(1);

        //初次等待客户端连接
        $r = array($this->dispatcher['server']);
        $w = $e = null;
        while(isset($r[0])) {
            socket_select($r, $w, $e, 1);
            //var_dump($r);
            if (isset($r[0])) {
                $this->acceptExecutor($r[0]);
            }
        }
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

}
