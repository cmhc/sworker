<?php
/**
 * if you need to use process communication, first need to create a routing process, and all the processes connecting the routing process.
 * 
 * command list 
 * sendto [destination] [message]
 */
namespace sworker\Router;

use sworker\Base\Base;

class Router extends Base
{
    /**
     * process list
     * @var array
     */
    protected $processes;

    /**
     * routing table, recorded all the client information
     * @var array
     */
    protected $routingTable;

    /**
     * activate clients
     * @var array
     */
    protected $activeClients;

    /**
     * router itself
     */
    protected $router;

    /**
     * the message queue
     * @var array
     */
    protected $msgQueue;

    /**
     * last socket error code
     * @var integer
     */
    protected $ecode = 0;

    /**
     * create、bind and listen a port
     * @param string $ip
     * @param integer $port 
     */
    public function __construct($ip, $port)
    {
        $this->listen($ip, $port);
    }

    /**
     * accept and receive 
     */
    public function start()
    {
        $this->accept();
        $this->receive();
    }

    /**
     * listen
     */
    protected function listen($ip, $port)
    {
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($server, $ip, $port)) {
            throw new Exception("socket bind error", 1);
        }

        if (!socket_listen($server, 24)) {
            throw new Exception("socket listen error", 1);
        }

        $this->log("Server started,ip:{$ip} port:{$port}");
        $this->router = $server;
    }

    /**
     * accept the connection
     */
    protected function accept()
    {
        $r = array($this->router);
        $w = $e = null;
        while(isset($r[0])) {
            if (!$this->routingTable) {
                $status = socket_select($r, $w, $e, 1);
            } else {
                $status = socket_select($r, $w, $e, 0);             
            }
            if ($status && isset($r[0])) {
                $this->acceptConnection($r[0]);
            }
        }
    }

    /**
     * receive loop
     */
    protected function receive()
    {
        if (!$this->routingTable) {
            return ;
        }
        $r = $this->routingTable;
        $w = $e = null;
        $status = socket_select($r, $w, $e, 1);
        if ($status == 0 || !$r) {
            return ;
        }
        foreach ($r as $dest=>$socket) {
            $msg = $this->receiveMessage($socket);
            if ($this->ecode > 0) {
                if ($this->ecode == 104) {
                    //disconnect
                    $this->close($dest);
                    $this->ecode = 0;
                    continue;
                }
            }
            if ($msg['cmd'] == 'sendto') {
                if ($dest != $msg['dest']) {
                    if ($this->send($msg['dest'], $msg['msg'])) {
                        $this->sendMessage($socket, true);
                    } else {
                        $this->sendMessage($socket, false);
                    }
                } else {
                    //sender and receiver are the same client
                    $this->sendMessage($socket, true);
                    $this->send($msg['dest'], $msg['msg']);
                }
            } else if ($msg['cmd'] == 'quit') {
                //quit command
                $this->close($dest);
            } else {
                $this->sendMessage($socket, false);
            }
        }
    }

    /**
     * send message to clients
     * @param  string $dest destination process name
     * @param  string $msg  
     * @return
     */
    protected function send($dest, $msg)
    {
        if (!isset($this->routingTable[$dest])) {
            return false;
        }
        $r = $e = null;
        $w = array($this->routingTable[$dest]);
        socket_select($r, $w, $e, 1);
        if (empty($w[0])) {
            return false;
        }
        return $this->sendMessage($this->routingTable[$dest], true, $msg);
    }

    /**
     * close clients
     */
    protected function close($clientName)
    {
        socket_close($this->routingTable[$clientName]);
        unset($this->routingTable[$clientName]);
    }

    /**
     * accept connection
     */
    protected function acceptConnection($socket)
    {
        $handle = socket_accept($socket);
        $r = array($handle);
        $w = $e = null;
        socket_select($r, $w, $e, null);
        $msg = $this->receiveMessage($handle);
        if (!$msg) {
            return false;
        }
        $clientName = $msg['msg'];
        if (isset($this->routingTable[$clientName])) {
            $this->sendMessage($handle, false, "name {$clientName} already used");
            socket_close($handle);
            return false;
        }
        $this->sendMessage($handle, true);
        $this->log("connection [{$clientName}] established");
        $this->routingTable[$clientName] = $handle;
        $this->msgQueue[$clientName] = array();
    }

    /**
     * receive message form client
     * @param   $socket 
     * @return 
     */
    protected function receiveMessage($socket)
    {
        $raw = '';
        while (substr($raw, -1) != "\n") {
            $raw .= socket_read($socket, 8192, PHP_NORMAL_READ);
            $this->ecode = socket_last_error($socket);
            if ($this->ecode > 0) {
                socket_clear_error($socket);
                break;
            }
        }
        if (!$raw) {
            return false;
        }
        $data = explode(" ", $raw);
        $count = count($data);
        $control = array();
        if ($data[0] == 'sendto' && $count == 3) {
            $msg = str_replace(array("%20", "%5C%6E"), array(" ", "\n"), trim($data[2]));
            return array(
                'cmd' => 'sendto',
                'dest' => $data[1],
                'msg' => $msg
            );
        }

        if ($count == 1) {
            return array(
                'cmd' => trim($raw),
                'dest' => '' ,
                'msg' => trim($raw)
            );
        }
        return false;
    }

    /**
     * send message to the socket
     * @param   $status
     * @param   $msg
     * @return  
     */
    protected function sendMessage($socket, $status = true, $msg = '')
    {
        if ($status) {
            $statusText = "OK";
        } else {
            $statusText = "ERR";
        }
        if ($msg) {
            $msg = str_replace(array(" ", "\n"), array("%20", "%5C%6E"), $msg);
            $msg = $statusText . " " . $msg . "\n";
        } else {
            $msg = $statusText . "\n";
        }
        $len = strlen($msg);
        while (true) {
            $sent = socket_write($socket, $msg, $len);
            if ($sent === false) {
                return false;
            }
            if ($sent < $len) {
                $msg = substr($msg, $sent);
                $len -= $sent;
            } else {
                break;
            }
        }
        return true;
    }

}