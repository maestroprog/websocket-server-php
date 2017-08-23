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
    const KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    const READ_TRY = 1000;
    const READ_TRY_INTERVAL = 10000;
    const CONNECTION_PING = 60;
    const CONNECTION_TIMEOUT = 10;
    /**
     * Хранит список адресов подключенных клиентов.
     *
     * @var array
     */
    public $clients = [];
    public $wsLogLevel = 0;

    private $wsConnection;

    /**
     * Создает WebSocket сервер с указанным портом.
     *
     * @param int $port
     */
    public function __construct($port)
    {
        $this->wsLog('CREATING SERVER ON PORT ' . $port, 0);
        // создаем слушающий сокет с указанным портом
        $socket = socket_create_listen($port);
        socket_setopt($socket, SOL_SOCKET, SO_REUSEADDR, true);
        $error = socket_last_error($socket);
        socket_clear_error();

        if (!$socket) {
            throw new RuntimeException('SERVER CREATING FAILED with errno: ' . $error);
        }
        if ($error) {
            throw new RuntimeException('Error ' . $error);
        }

        $this->wsConnection = $socket;
        socket_set_nonblock($this->wsConnection);
    }

    /**
     * Прекращает работу WebSocket сервера.
     */
    public function __destruct()
    {
        if (!$this->wsConnection) {
            return;
        }
        foreach ($this->clients as $client) {
            $this->disconnectClient($client[0], 1000);
        }
        socket_shutdown($this->wsConnection);
        socket_close($this->wsConnection);
    }

    /**
     * Заставляет WebSocket сервер слушать входящие соединения
     * в неблокирующем режиме.
     *
     * @return array|bool
     */
    public function listen()
    {
        if (!$this->wsConnection) return false;
        $works = [];
        while ($client = socket_accept($this->wsConnection)) {
            socket_set_nonblock($client);
            $address = $this->getAddress($client);

            // reading headers
            $headers = $data = [];
            $buffer = '';
            $try = 0;
            while (true) {
                $byte = socket_read($client, 1);
                if ($byte === false)
                    if ($try++ >= self::READ_TRY) {
                        throw new RuntimeException('error while reading');
                    } else {
                        usleep(self::READ_TRY_INTERVAL);
                        continue;
                    }
                $try = 0;
                if ($byte === "\r") {
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
                throw new UnexpectedValueException('BAD REQUEST 1 OF NEW CONNECTION OF ' . $address . print_r($data, true));
            }
            if (!($headers['Sec-WebSocket-Version']) == 13) {
                $response = "HTTP/1.1 400 Bad Request\r\n\r\n";
                socket_write($client, $response);
                socket_close($client);
                throw new UnexpectedValueException('BAD REQUEST 2 OF NEW CONNECTION OF ' . $address . print_r($data, true));
            }
            $cookies = isset($headers['Cookie']) ? $headers['Cookie'] : null;
            // end of reading headers
            // sending headers
            $hash = base64_encode(sha1($headers['Sec-WebSocket-Key'] . self::KEY, true));
            $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                "Upgrade: WebSocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Accept: {$hash}\r\n" .
                (isset($headers['Sec-WebSocket-Protocol']) ? "Sec-WebSocket-Protocol: chat\r\n\r\n" : "\r\n");
            if (!socket_write($client, $response)) {
                socket_close($client);
                throw new RuntimeException('ERROR HANDSHAKE OF NEW CONNECTION OF ' . $address);
            }
            // end of sending headers
            if (isset($this->clients[$address])) {
                throw new RuntimeException('FATALITY ERROR!!!');
            }
            $this->clients[$address] = [$client, time()];
            $works[] = [$address, $get, $cookies];
        }
        return $works;
    }

    /**
     * Читает фреймы от указанного клиента.
     *
     * @param $address
     * @return array|bool
     */
    public function readFrom($address)
    {
        if (!isset($this->clients[$address])) {
            return false;
        }
        $client = $this->clients[$address];
        if ($client[1] < (time() - (self::CONNECTION_PING + self::CONNECTION_TIMEOUT))) {
            $this->disconnectClient($address, 1001);
            throw new RuntimeException('Timeout while reading from ' . $address);
        }
        if ($client[1] < (time() - self::CONNECTION_PING)) {
            $this->doPing($client[0]);
        }
        return $this->readFramesFrom($client[0]);
    }

    /**
     * Отправляет фрейм клиенту.
     *
     * @param $address
     * @param $data
     * @return bool
     */
    public function sendTo($address, $data)
    {
        if (!isset($this->clients[$address])) {
            return false;
        }
        return $this->sendFrameTo($this->clients[$address][0], $data);
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

                    if ($length === 126) {
                        for ($j = 0, $length = 0; $j < 2; $j++) {
                            $length = ($length << 8) | ord($this->_readFrom($socket, 1));
                        }
                    } elseif ($length === 127) {
                        for ($j = 0, $length = 0; $j < 8; $j++) {
                            $length = ($length << 8) | ord($this->_readFrom($socket, 1));
                        }
                    }
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
                        $this->disconnectClient($this->getAddress($socket), 1000);
                        // $this->wsLog('gived 0x8 disconnect frame', 0);
                        break;
                    case 0x9 : //обозначает PING.
                        $this->sendFrameTo($socket, $result[$frames], 0xA);
                        break;
                    case 0xA : //обозначает PONG.
                        $this->onPong($socket);
                        break;
                    case 0x0 :
                        $this->disconnectClient($this->getAddress($socket), 1002);
                        // $this->wsLog('gived 0x0 disconnect frame', 0);
                        return false;
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
            $buffer .= $in;
            if (strlen($in) < $length) {
                if ($tryCount > self::READ_TRY) {
                    throw new LengthException('failed receive ' . $length . ' buffer data!!! =(');
                }
                $tryCount++;
                $length -= strlen($in);
                usleep(self::READ_TRY_INTERVAL);
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
                // $this->wsLog('WRITTEN 0 BYTES OF ' . $length, 2);
            } else {
                $data = substr($data, $result);
                $bytes += $result;
            }
        } while ($bytes < $length);
        return true;
    }

    private function doPing($socket)
    {
        $this->sendFrameTo($socket, 'hey', 0x9);
    }

    private function onPong($socket)
    {
        $address = $this->getAddress($socket);
        if (!isset($this->clients[$address])) {
            throw new RuntimeException('FATAL ERROR: ON PONG ADDRESS NOT FOUND');
        }
        $this->clients[$address][1] = time();
    }

    private function disconnectClient($address, $code)
    {
        if (!isset($this->clients[$address])) {
            return false;
        }
        $this->sendFrameTo($this->clients[$address][0], $code, 0x8);
        socket_close($this->clients[$address][0]);
        unset($this->clients[$address]);
        $this->onDisconnect($address, $code);
        return true;
    }

    /**
     * @param $code
     * TODO назначение обработчика
     */
    public function onDisconnect($address, $code)
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

    private function getAddress($socket)
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

    public function wsLog($message, $level = 0)
    {
        if (is_array($message)) {
            $_message = '';
            foreach ($message as $key => $string) {
                $_message .= $key . ' : ' . $string . PHP_EOL;
            }
            $message = $_message;
        }
        if ($level >= $this->wsLogLevel) {
            fwrite(STDOUT, '[' . date('d.m.Y H:i:s') . '] level:' . $level . '; message: ' . $message . PHP_EOL);
        }
    }
}
