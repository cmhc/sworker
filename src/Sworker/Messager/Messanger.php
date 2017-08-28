<?php
/**
 * 进程间消息通信
 */
namespace Sworker\Messanger;

class Messanger
{
    protected $server;

    /**
     * 设置通信服务信息
     */
    public function setServerInfo($ip, $port)
    {

    }

    public function startServer($ip, $port)
    {

    }

    public function startClient()
    {

    }

    /**
     * 消息发送
     */
    public function send()
    {
        stream_socket_server("tcp://{$ip}:{$port}", $errno, $errstr);
    }

    /**
     * 消息接收
     */
    public function receive()
    {
        stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr);
    }
}