# WebSocket сервер на PHP

Класс [WebSocketServer](src/WebSocketServer.php) представляет из себя реализацию простого WebSocket сервера на PHP.

## Пример использования

### Поднимаем WebSocket сервер на порту 8898

```php

require_once 'src/WebSocketServer.php';

$server = new WebSocketServer(8898);

// слушаем входящие соединения
while (false !== ($activity = $server->listen())) {
    // каждую секунду читаем поступающие данные от клиентов
    foreach (array_keys($server->wsClients) as $address) {
        if ($data = $server->readFrom($address)) {
            // эмулируем работу эхо-сервера, отправляем полученные данные клиенту
            $server->sendTo($address, var_export($data, true));
        }
    }
    sleep(1);
}

```

Затем запускаем получившийся скрипт из консоли:
    
    $ php -f server.php

### Пишем простенький ws клиент на JavaScript

```html
<html>
<head>
    <script type="text/javascript">
        "use strict";

        window.onload = function () {
            // подключаемся к серверу
            var ws = new WebSocket('ws://127.0.0.1:8898');
            
            // обработаем событие получения сообщения от сервера
            ws.onmessage = function (msg) {
                alert('Получил сообщение: ' + msg.data);
            };
            
            // а при успешном подключении отправим сообщение серверу
            ws.onopen = function () {
                ws.send('HELLO WORLD!');
            };
        };
    </script>
</head>
<body>
<h1>Sample WebSocket server</h1>
</body>
</html>
```

Теперь, когда браузер подключится к WebSocket-серверу, он отправит ему сообщение.
В свою очередь, сервер получит сообщение, прочитает его, и отправит обратно браузеру,
после чего браузер выведет обычный `alert()`, показав _dump_ изначально отправленного сообщения
в диалоговом окне браузера.
 
[Пример в виде набора готовых файлов](sample)

