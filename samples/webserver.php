<?php
/**
 * 一个webserver的例子
 * 监听端口，响应发送的请求
 * 使用reuseaddr和reuseport达到多进程复用ip和端口的目的
 */
namespace tests;

require dirname(__DIR__) . '/autoload.php';

use sworker\Process\Process;

class WebServer
{
	public function mainInit()
	{
		$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!$server) {
			throw new Exception("socket create error: " . socket_strerror(socket_last_error()), 1);
		}
		if (!socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1)) {
			throw new Exception("reuse addr error: " . socket_strerror(socket_last_error()), 1);
		}
		if (!socket_set_option($server, SOL_SOCKET, SO_REUSEPORT, 1)) {
			throw new Exception("reuse port error: " . socket_strerror(socket_last_error()), 1);
		}
		if (!socket_bind($server, '127.0.0.1', 9999)) {
			throw new Exception("bind 127.0.0.1:9999 error" . socket_strerror(socket_last_error()), 1);
		}
		socket_listen($server);
		$this->server = $server;
	}

	/**
	 * 主循环
	 */
	public function main()
	{
		$client = socket_accept($this->server);
		$r = array($client);
		$e = $w = null;
		socket_select($r, $w, $e, null);

		if ($r) {
			foreach ($r as $read) {
				$buffer = null;
				$msg = socket_recv($read, $buffer, 8, MSG_DONTWAIT);
				echo "收到消息" . $buffer;
			}
		}

	}
}

$process = new Process();
$process->addWorker('tests\WebServer', 'main');
$process->addWorker('tests\WebServer', 'main');
$process->start();