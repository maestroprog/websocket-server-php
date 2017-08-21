<?php
/**
 * Created by PhpStorm.
 * User: maestroprog
 * Date: 21.08.17
 * Time: 22:31
 */

require_once __DIR__ . '/../src/WebSocketServer.php';

$server = new WebSocketServer();

$server->createWsServer(8898);

while (false !== ($activity = $server->wsListen())) {
    foreach (array_keys($server->wsClients) as $address) {
        if ($data = $server->wsReadFrom($address)) {
            $server->wsSendTo($address, var_export($data, true));
        }
    }
    sleep(1);
}
