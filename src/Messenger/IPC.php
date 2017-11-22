<?php
/**
 * 使用IPC进行进程间通信
 */
namespace sworker\Messenger;

use sworker\Base\Base;

class IPC extends Messenger
{
    protected $dispatcher;

    /**
     * executor 连接
     * @var array
     */
    protected $connection = array();

    protected $sock;

    /**
     * 服务端启动
     * @param  string $sock
     * @return        
     */
    public function serverStart($sock = '/tmp/sworker.sock')
    {
        $this->sock = $sock;

        $server = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if (!$server) {
            throw new Exception("socket create error", 1);
        }

        if (!socket_bind($server, $sock)) {
            throw new Exception("socket bind error", 1);
        }

        if( !socket_listen($server, 8) ){
            throw new Exception("socket listen error", 1);
        }
        $this->log("服务启动,sock:{$sock}");
        $this->dispatcher['server'] = $server;
    }

    public function __destruct()
    {
        unlink($this->sock);
    }

    /**
     * 客户端连接服务端
     * @param  string $name 客户端上报自己的名称
     */
    public function clientStart($name, $sock = '/tmp/sworker.sock')
    {
        $this->log("{$name}: 连接server");
        $sock = "unix://{$sock}";
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
