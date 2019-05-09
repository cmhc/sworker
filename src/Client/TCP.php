<?php
namespace sworker\Client;

class TCP
{
    protected $connection;

    /**
     * connect to the router
     * @param  string  $ip
     * @param  integer $port
     * @param  string  $name the client name
     * @return boolean
     */
    public function connect($ip, $port, $name)
    {
        if (!($this->connection = stream_socket_client("tcp://{$ip}:{$port}"))) {
            throw new Exception("connection failed", 1);
        }
        $this->setName($name);
    }

    public function close()
    {
        fclose($this->connection);
    }

    /**
     * send message
     * @param  string $to
     * @param  string $msg
     * @return 
     */
    public function send($to, $msg)
    {
        if (!$msg) {
            return false;
        }
        $msg = str_replace(array(" ", "\n"), array("%20", "%5C%6E"), $msg);
        $msg = "sendto {$to} {$msg}\n";
        fwrite($this->connection, $msg);
        $response = $this->receive();
        if (isset($response['status']) && $response['status'] == 'OK') {
            return true;
        }
        return false;
    }

    /**
     * receive message
     */
    public function receive()
    {
        $msg = fgets($this->connection);
        if ($msg === false) {
            return false;
        }
        $data = explode(" ", trim($msg));
        if (isset($data[1])) {
            $msg = str_replace(array("%20", "%5C%6E"), array(" ", "\n"), $data[1]);
        } else {
            $msg = '';
        }
        return array(
            'status' => $data[0],
            'msg' => $msg
        );
    }

    /**
     * set client name
     * @param string $name
     */
    protected function setName($name)
    {
        $msg = $name . "\n";
        fwrite($this->connection, $msg);
        $data = $this->receive();
        if (!$data || $data['status'] == 'ERR') {
            throw new Exception($data['msg'], 1);
        }
    }

}