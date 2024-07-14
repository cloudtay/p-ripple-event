<p align="center">
<img src="https://www.cloudtay.com/static/image/logo-wide.png" width="420" alt="Logo">
</p>
<p align="center">
<a href="#"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.3-blue" alt="Build Status"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/dt/cclilshy/p-ripple-core" alt="Download statistics"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/v/cclilshy/p-ripple-core" alt="Stable version"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/l/cclilshy/p-ripple-core" alt="License"></a>
</p>
<p>
PRipple is a modern, high-performance native PHP coroutine framework designed to solve PHP's challenges in high concurrency, complex network communication and data operations.
The framework uses an innovative architecture and efficient programming model to provide powerful and flexible backend support for modern web and web applications.
By using PRipple, you will experience the advantages of managing tasks from a global view of the system and efficiently handle network traffic and data.</p>

<p align="center">
    <a href="https://github.com/cloudtay/p-ripple-core">English</a>
    ·
    <a href="https://github.com/cloudtay/p-ripple-core/blob/main/README.zh_CN.md">简体中文</a>
    ·
    <a href="https://github.com/cloudtay/p-ripple-core/issues">issues »</a>
</p>

---

> ⚠️This document is an AI machine translation, and there may be inaccuracies in the translation. If there are any
> problems, please feel free to make corrections.

## Install

```bash
composer require cclilshy/p-ripple-core
```

---

## Usage

> All tools of PRipple are provided by Store. Store is a global collection of tools with the namespace of `P\{Module}`
> , you can get all the tools through the Store, such as `P\Net`, `P\IO`, `P\System`, etc.

### Example

```php
\P\IO::File();
\P\IO::Socket();
\P\Net::Http();
\P\Net::WebSocket();
\P\System::Process();
```

### Basic Usage

> In addition, basic syntax sugar is provided, such as `async`, `await`, etc., to simplify asynchronous programming such
> as

```php
# Promise asynchronous mode
$promise1 = \P\promise($r,$d)->then(function(){
    $r('done');
});

# async/await asynchronous mode
$promise2 = \P\async(function($r,$d){
    \P\sleep(1);
    
    $r('done2');
});

\P\async(function() use ($promise1, $promise2){
    $result1 = await($promise1);
    $result2 = await($promise2);
    echo 'result1: ' . $result1 . PHP_EOL;
    echo 'result2: ' . $result2 . PHP_EOL;
});

/**
 * Timer related
 */
# Do something repeatedly until cancel is called
$timerId = \P\repeat(1, function(Closure $cancel){
    echo 'repeat' . PHP_EOL;
});

# Cancel Timer behavior
\P\cancel($timerId);

# Execute something after a specified time
$timerId = \P\delay(1, function(){
    echo 'after' . PHP_EOL;
});

# Cancel the delay statement before it occurs
\P\cancel($timerId);
```

### File Module

> ⚠️This module relies on the Event processor of the `kqueue` mechanism. The event processor under the epoll mechanism
> does not support monitoring of file streams.
> In OSX, `lib-event` uses `kqueue`, in Linux it uses `epoll`, and in Windows it uses `select`. Please make sure your
> system supports `kqueue`
> mechanism,

> To achieve a high level of compatibility, please install the `Ev` extension

#### Install Ev Extension

```bash
pecl install ev
```

```php
# Read file contents
async(function () {
    $fileContent = await(
        P\IO::File()->getContents(__FILE__)
    );

    $hash = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

# Write file content
P\IO::File()->getContents(__FILE__)->then(function ($fileContent) {
    $hash = hash('sha256', $fileContent);
    echo "[async] File content hash: {$hash}" . PHP_EOL;
});

# open a file
$stream = P\IO::File()->open(__FILE__, 'r');
```

### Socket Module

```php
# Establish a Socket connection
/**
 * @param string $uri
 * @param int $flags
 * @param null $context
 * @return Promiss<SocketStream>
 */
P\IO::Socket()->streamSocketClient('tcp://www.baidu.com:80');

# Establish an SSL Socket connection
P\IO::Socket()->streamSocketClientSSL('tcp://www.baidu.com:443');
```

### Net Module

```php
# Initiate 100 requests asynchronously, method 1
for ($i = 0; $i < 100; $i++) {
    P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
}

# Initiate 100 requests asynchronously, method 2
for ($i = 0; $i < 100; $i++) {
    async(function () {
        try {
            $response = await(P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/'));
            echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
        } catch (Throwable $exception) {
            echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
        }
    });
}

# WebSocket connection
$connection         = P\Net::Websocket()->connect('wss://127.0.0.1:8001/wss');
$connection->onOpen = function (Connection $connection) {
    $connection->send('{"action":"sub","data":{"channel":"market:panel@8"}}');

    $timerId = repeat(10, function () use ($connection) {
        $connection->send('{"action":"ping","data":{}}');
    });

    $connection->onClose = function (Connection $connection) use ($timerId) {
        P\cancel($timerId);
    };

    $connection->onMessage = function (string $message, int $opcode, Connection $connection) {
        echo "receive: $message\n";
    };
};

$connection->onError = function (Throwable $throwable) {
    echo "error: {$throwable->getMessage()}\n";
};

run();
```

---

### Parallel Module

#### illustrate

> PRipple provides runtime support for parallel running, relying on multiple processes and abstracting the details of
> multiple processes. Users only need to care about how to use parallel running.
> You can use almost any PHP statement in a closure, but there are still things you need to pay attention to

#### Precautions

* In the child process, all context resources inherit the resources of the parent process, including open files,
  connected databases, connected networks, and global variables
* All defined events such as `onRead`, `onWrite`, etc. will be forgotten in the child process
* You cannot create subprocesses in async such as

```php
async(function(){
    $task = P\System::Process()->task(function(){
        // childProcess
    });
    $task->run();
});
```

> This will throw an exception `ProcessException`

#### USING

```php
$task = P\System::Process()->task(function(){
    sleep(10);
    
    exit(0);
});

$runtime = $task->run();                // Returns a Runtime object

$runtime->stop();                       // Cancel operation (signal SIGTERM)
$runtime->stop(true);                   // Forced termination, equivalent to $runtime->kill()
$runtime->kill();                       // Forced termination (signal SIGKILL)
$runtime->signal(SIGTERM);              // Sending signals provides more granular control
$runtime->then(function($exitCode){});  // The code here will be triggered when the program exits normally, code is the exit code
$runtime->except(function(){});         // The code here will be triggered when the program exits abnormally, and exceptions can be handled here, such as process daemon/task restart
$runtime->finally(function(){});        // Whether the program exits normally or abnormally, the code here will be triggered.
$runtime->getProcessId();               // Get child process ID
$runtime->getPromise();                 // Get Promise object
```

---

### HttpServer Module

#### desc

> PRipple provides a simple HttpServer, which can be used to quickly build a simple Http server. The usage method is as
> follows

#### desc

> Among them, Request and Response inherit and implement the `RequestInterface` and `ResponseInterface` interface
> specifications of `Symfony`
> You can use them just like the HttpFoundation component in Symfony/Laravel

#### Example

```php
use P\IO;
use Psc\Store\Net\Http\Server\Request;
use Psc\Store\Net\Http\Server\Response;
use function P\await;
use function P\run;

$server = P\Net::Http()->server('http://127.0.0.1:8008');
$handler = function (Request $request, Response $response) {
    if ($request->getMethod() === 'POST') {
        $files = $request->files->get('file');
        $data  = [];
        foreach ($files as $file) {
            $data[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $file->getPathname(),
            ];
        }
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent(json_encode($data));
        $response->respond();
    }

    if ($request->getMethod() === 'GET') {
        if ($request->getPathInfo() === '/') {
            $response->setContent('Hello World!');
            $response->respond();
        }

        if ($request->getPathInfo() === '/download') {
            $response->setContent(
                IO::File()->open(__FILE__, 'r')
            );
            $response->respond();
        }

        if ($request->getPathInfo() === '/upload') {
            $template = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Upload</title></head><body><form action="/upload" method="post" enctype="multipart/form-data"><input type="file" name="file"><button type="submit">Upload</button></form></body>';
            $response->setContent($template);
            $response->respond();
        }

        if ($request->getPathInfo() === '/qq') {
            $qq = await(P\Net::Http()->Guzzle()->getAsync(
                'https://www.qq.com/'
            ));

            $response->setContent($qq->getBody()->getContents());
            $response->respond();
        }
    }
};

$server->onRequest = $handler;
$server->listen();

run();
```

#### Port reuse

> PRipple supports port multiplexing with `Parallel` Module

```php
# After creating the HttpServer as above, you can replace the listening method to implement port multiplexing.

$task = P\System::Process()->task( fn() => $server->listen() );

$task->run();   //runtime1
$task->run();   //runtime2
$task->run();   //runtime3
$task->run();   //runtime4
$task->run();   //runtime5

# Guardian mode startup example
$guardRun = function($task) use (&$guardRun){
    $task->run()->except(function() use ($task, &$guardRun){
        $guardRun($task);
    });
};
$guardRun($task);

P\run();
```

## Std API

- Stream ~
- SocketStream ~
- Promise ~
- ...

## Extends

- [Workerman](https://github.com/cloudtay/p-ripple-drive.git)
- [Webman](https://github.com/cloudtay/p-ripple-drive.git)
- [Laravel](https://github.com/cloudtay/p-ripple-drive.git)
- [ThinkPHP](https://github.com/cloudtay/p-ripple-drive.git)

## More

PRipple's asynchrony follows the Promise specification, and IO operations rely on `Psc\Core\Stream\Stream` and are
developed in accordance with `PSR-7`
Standard, for in-depth understanding, please refer to interface definitions such as `StreamInterface`
and `PromiseInterface`
Ensure the originality of the support library as much as possible without excessive encapsulation to facilitate
user-defined extensions

## Postscript

Developers are welcome to give it a try. I am soliciting more opinions and suggestions. You are welcome to submit a PR
or Issue and we will deal with it as soon as possible<br>
This project is in the alpha stage and may be unstable. Please be careful when using it in a production environment<br>

Contact information: jingnigg@gmail.com

### About

- RevoltPHP: [https://revolt.run/](https://revolt.run/)
- Workerman/Webman: [https://www.workerman.net/](https://www.workerman.net/)
- Laravel: [https://laravel.com/](https://laravel.com/)
- ThinkPHP: [https://www.thinkphp.cn/](https://www.thinkphp.cn/)
- Symfony: [https://symfony.com/](https://symfony.com/)
- PHP: [https://www.php.net/](https://www.php.net/)
- JavaScript: [https://www.javascript.com/](https://www.javascript.com/)

### Acknowledgments

- Jetbrains: [https://www.jetbrains.com/](https://www.jetbrains.com/)
- OpenAI: [https://www.openai.com/](https://www.openai.com/)
- W3C: [https://www.w3.org/](https://www.w3.org/)
