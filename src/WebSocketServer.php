<?php
/**
 * @author Ruslan Yarullin <maestroprog@gmail.com>
 * @license: beerware
 * @year 2014
 */

/**
 * Класс, реализующий работу WebSocket сервера.
 */
class WebSocketServer
{
    /**
     * Хранит список адресов подключенных клиентов.
     *
     * @var array
     */
    public $wsClients = [];

    private $wsConnection;
    private $wsLogLevel = 0;
    private $wsReadTry = 1000;
    private $wsReadTryInterval = 10000;
    private $wsConnectionPing = 60;
    private $wsConnectionTimeout = 10;

    /**
     * Создает WebSocket сервер с указанным портом.
     *
     * @param $port
     * @return bool
     */
    public function createWsServer($port)
    {
        $this->wsLog('CREATING SERVER ON PORT ' . $port, 0);
        // создаем слушающий сокет с указанным портом
        $socket = socket_create_listen($port);
        socket_setopt($socket, SOL_SOCKET, SO_REUSEADDR, true);
        $error = socket_last_error($socket);
        socket_clear_error();
        if (!$socket) {
            $this->wsLog('SERVER CREATING FAILED with errno: ' . $error, 2);
            $this->wsConnection = false;
            return false;
        }
        $this->wsLog('SUCCESSFULLY SERVER CREATED with errno: ' . $error, 1);

        $this->wsConnection = $socket;
        socket_set_nonblock($this->wsConnection);
        return true;
    }

    /**
     * Прекращает работу WebSocket сервера.
     *
     * @return bool
     */
    public function destroyWsServer()
    {
        if (!$this->wsConnection) return true;
        $this->wsLog('destroy the server', 0);
        foreach ($this->wsClients as $client) {
            $this->wsDisconnect($client[0], 1000);
        }
        socket_shutdown($this->wsConnection);
        socket_close($this->wsConnection);
        $this->wsLog('SUCCESSFULLY SERVER DESTROYED', 1);
        return true;
    }

    /**
     * Заставляет WebSocket сервер слушать входящие соединения
     * в неблокирующем режиме.
     *
     * @return array|bool
     */
    public function wsListen()
    {
        if (!$this->wsConnection) return false;
        $works = [];
        while ($client = socket_accept($this->wsConnection)) {
            socket_set_nonblock($client);
            $this->wsLog('ACCEPTED NEW CONNECTION OF ' . ($address = $this->getWsAddress($client)), 0);

            // reading headers
            $headers = $data = [];
            $buffer = '';
            $try = 0;
            while (true) {
                $byte = socket_read($client, 1);
                if ($byte === false)
                    if ($try++ >= $this->wsReadTry) {
                        $this->wsLog('error while reading', 1);
                        $data[] = $buffer;
                        break;
                    } else {
                        usleep($this->wsReadTryInterval);
                        continue;
                    }
                $try = 0;
                if ($byte === "\r") {
                    // skipping \r
                    continue;
                }
                if ($byte === "\n") {
                    if (empty($buffer)) {
                        // end of reading
                        break;
                    } else {
                        $data[] = $buffer;
                        $buffer = '';
                    }
                } else {
                    $buffer .= $byte;
                }
            }
            $get = false;
            foreach ($data as $key => $value) {
                if ($key === 0) {
                    $get = explode(' ', $value);
                    $get = trim($get[1], '/');
                    continue;
                }
                list($key, $value) = explode(':', $value);
                $headers[$key] = trim($value);
            }
            if (!($headers['Connection'] === 'Upgrade')) {
                $response = "HTTP/1.1 400 Bad Request\r\n\r\n";
                socket_write($client, $response);
                socket_close($client);
                $this->wsLog('BAD REQUEST 1 OF NEW CONNECTION OF ' . $address . print_r($data, true), 2);
                return true;
            }
            if (!($headers['Sec-WebSocket-Version']) == 13) {
                $response = "HTTP/1.1 400 Bad Request\r\n\r\n";
                socket_write($client, $response);
                socket_close($client);
                $this->wsLog('BAD REQUEST 2 OF NEW CONNECTION OF ' . $address . print_r($data, true), 2);
                return true;
            }
            $cookies = isset($headers['Cookie']) ? $headers['Cookie'] : null;
            // end of reading headers
            // sending headers
            $hash = base64_encode(sha1($headers['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                "Upgrade: WebSocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: {$hash}\r\n" .
                (isset($headers['Sec-WebSocket-Protocol']) ? "Sec-WebSocket-Protocol: chat\r\n\r\n" : "\r\n");
            if (!socket_write($client, $response)) {
                $this->wsLog('ERROR HANDSHAKE OF NEW CONNECTION OF ' . $address, 2);
                socket_close($client);
                return true;
            }
            // end of sending headers
            if (isset($this->wsClients[$address])) {
                $this->wsLog('FATALITY ERROR!!!', 5);
//                yii::app()->end();
            }
            $this->wsClients[$address] = [
                $client, time()
            ];
            $works[] = [$address, $get, $cookies];
        }
        return $works;
    }

    public function wsReadFrom($address)
    {
        if (!isset($this->wsClients[$address])) {
            return false;
        }
        $client = $this->wsClients[$address];
        if ($client[1] < (time() - ($this->wsConnectionPing + $this->wsConnectionTimeout))) {
            $this->wsDisconnect($address, 1001);
            $this->wsLog('Timeout while reading from ' . $address, 0);
            return false;
        }
        if ($client[1] < (time() - $this->wsConnectionPing)) {
            $this->doPing($client[0]);
        }
        return $this->readFramesFrom($client[0]);
    }

    public function wsSendTo($address, $data)
    {
        if (!isset($this->wsClients[$address])) return false;
        return $this->sendFrameTo($this->wsClients[$address][0], $data);
    }

    private function readFramesFrom($socket)
    {
        $frames = 0;
        $result = [];
        $i = 0;
        try {
            while (true) {
                do {
                    if ($byte = $this->_readFrom($socket, 1, false)) {
                        $byte = ord($byte);
                    } else {
                        break 2;
                    }
                    $fin = ($byte & 0b10000000) === 128;
                    if (!isset($opcode)) {
                        $opcode = $byte & 0b00001111;
                    }
                    $byte = ord($this->_readFrom($socket, 1));
                    $mask = ($byte & 0b10000000) === 128;
                    $length = ($byte & 0b01111111);

                    if ($length === 126)
                        for ($j = 0, $length = 0; $j < 2; $j++)
                            $length = ($length << 8) | ord($this->_readFrom($socket, 1));
                    elseif ($length === 127)
                        for ($j = 0, $length = 0; $j < 8; $j++)
                            $length = ($length << 8) | ord($this->_readFrom($socket, 1));
                    if (!isset($result[$frames])) {
                        $result[$frames] = '';
                    }
                    if ($mask) {
                        $mask = $this->_readFrom($socket, 4);
                        $i += 4;
                        for ($j = 0; $j < $length; $j += 4) {
                            $result[$frames] .= $this->_readFrom($socket, min($length - $j, 4)) ^ $mask;
                        }
                    } else {
                        $result[$frames] .= $this->_readFrom($socket, $length);
                    }
                } while (!$fin);
                $frames++;
                switch ($opcode) {
                    case 0x1 : //обозначает текстовый фрейм.
                    case 0x2 : //обозначает двоичный фрейм.
                        break;
                    case 0x8 : //обозначает закрытие соединения этим фреймом.
                        $this->wsDisconnect($this->getWsAddress($socket), 1000);
                        $this->wsLog('gived 0x8 disconnect frame', 0);
                        break;
                    case 0x9 : //обозначат PING.
                        $this->sendFrameTo($socket, $result[$frames], 0xA);
                        break;
                    case 0xA : //обозначат PONG.
                        $this->onPong($socket);
                        break;
                    case 0x0 :
                        $this->wsDisconnect($this->getWsAddress($socket), 1002);
                        $this->wsLog('gived 0x0 disconnect frame', 0);
                        return false;
                        break;
                }
                if ($opcode < 1 || $opcode > 2) {
                    unset($result[$frames--]);
                }
            }
        } catch (LengthException $e) {
            $this->wsLog($e->getMessage(), 2);
            return false;
        }
        return $result;
    }

    private function _readFrom($socket, $length, $need = true)
    {
        $buffer = '';
        $tryCount = 0;
        while (($in = socket_read($socket, $length)) || $need) {
            // выжимаем данные из сокета до необходимой длины
            $buffer .= $in;
            if (strlen($in) < $length) {
                if ($tryCount > $this->wsReadTry) {
                    throw new LengthException('failed receive ' . $length . ' buffer data!!! =(');
                    break;
                }
                $tryCount++;
                $length -= strlen($in);
                usleep($this->wsReadTryInterval);
            } else {
                break;
            }
        }
        return strlen($buffer) === 0 ? false : $buffer;
    }

    private function sendFrameTo($socket, $data, $opcode = 0x01)
    {
        $frame = chr((0b10000000 | $opcode));
        $length = strlen($data);
        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126) . chr(($length >> 8) & 0b11111111) . chr($length & 0b11111111);
        } else {
            // чисто для совместимости
            $frame .= chr(127) . chr(($length >> 64) & 0b11111111) . // 1 byte
                chr(($length >> 56) & 0b11111111) . // 2 byte
                chr(($length >> 48) & 0b11111111) . // 3 byte
                chr(($length >> 40) & 0b11111111) . // 4 byte
                chr(($length >> 32) & 0b11111111) . // 5 byte
                chr(($length >> 24) & 0b11111111) . // 6 byte
                chr(($length >> 16) & 0b11111111) . // 7 byte
                chr($length & 0b11111111); // 8 byte
        }
        return $this->_sendTo($socket, $frame . $data);
    }

    private function _sendTo($socket, $data)
    {
        $bytes = 0;
        $length = strlen($data);
        do {
            $result = socket_write($socket, $data);
            if ($result === false) {
                return false;
            } elseif ($result === 0) {
                $this->wsLog('WRITTEN 0 BYTES OF ' . $length, 2);
//                yii::app()->end();
            } else {
                $data = substr($data, $result);
                $bytes += $result;
            }
        } while ($bytes < $length);
        return true;
    }

    private function doPing($socket)
    {
        $this->wsLog('Пингуем ' . $this->getWsAddress($socket), 0);
        $this->sendFrameTo($socket, 'hey', 0x9);
    }

    private function onPong($socket)
    {
        $address = $this->getWsAddress($socket);
        if (!isset($this->wsClients[$address])) {
            $this->wsLog('FATAL ERROR: ON PONG ADDRESS NOT FOUND', 2);
//            yii::app()->end();
        }
        $this->wsLog('Пинг удался ' . $address, 0);
        $this->wsClients[$address][1] = time();
    }

    private function wsDisconnect($address, $code)
    {
        $this->wsLog('manually disconnect ' . $address . ' with code ' . $code, 0);
        if (!isset($this->wsClients[$address])) return false;
        $this->sendFrameTo($this->wsClients[$address][0], $code, 0x8);
        socket_close($this->wsClients[$address][0]);
        unset($this->wsClients[$address]);
        $this->onDisconnect($code);
        return true;
    }

    /**
     * @param $code
     * TODO назначение обработчика
     */
    public function onDisconnect($code)
    {
        //     1000
//Нормальное закрытие .
        //  1001
//Удалённая сторона «исчезла» . Например, процесс сервера убит или браузер перешёл на другую страницу .
        //  1002
//Удалённая сторона завершила соединение в связи с ошибкой протокола .
        //  1003
//Удалённая сторона завершила соединение в связи с тем, что она получила данные, которые не может принять
    }

    private function getWsAddress($socket)
    {
        if (!$socket) {
            return false;
        }
        $address = '';
        $port = 0;
        if (socket_getpeername($socket, $address, $port)) {
            return $address . ':' . $port;
        } else {
            return false;
        }
    }

    private function wsLog($message, $level = 0)
    {
        if (is_array($message)) {
            $_message = '';
            foreach ($message as $key => $string) {
                $_message .= $key . ' : ' . $string . PHP_EOL;
            }
            $message = $_message;
        }
        if ($level >= $this->wsLogLevel) {
            if (method_exists($this, 'log')) {
                $this->log($message, $level);
            } elseif (method_exists($this, 'monitorLog')) {
                $this->monitorLog($message, $level);
            } elseif (method_exists($this, 'stdout')) {
                $this->stdout($message, $level);
            } else {
                fwrite(STDOUT, '[' . date('d.m.Y H:i:s') . '] level:' . $level . '; message: ' . $message . PHP_EOL);
            }
        }
    }
}
