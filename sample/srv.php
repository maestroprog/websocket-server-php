<?php
/**
 * Created by PhpStorm.
 * User: maestroprog
 * Date: 21.08.17
 * Time: 22:31
 */

require_once __DIR__ . '/../src/WebSocketServer.php';

$server = new WebSocketServer(8898);

while (false !== ($activity = $server->listen())) {
    foreach (array_keys($server->clients) as $address) {
        if ($data = $server->readFrom($address)) {
            $server->sendTo($address, var_export($data, true));
        }
    }
    sleep(1);
}
