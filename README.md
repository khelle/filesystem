Filesystem
==========

Evented filesystem access utilizing [EIO](http://php.net/eio).

[![Build Status](https://secure.travis-ci.org/reactphp/filesystem.png?branch=master)](http://travis-ci.org/reactphp/filesystem) [![Code Climate](https://codeclimate.com/github/reactphp/filesystem/badges/gpa.svg)](https://codeclimate.com/github/reactphp/filesystem)

Table of Contents
-----------------

1. [Introduction](#introduction)
2. [Adapters](#adapters)
3. [Examples](#examples)
   * [Creating filesystem object](#creating-filesystem-object)
   * [File object](#file-object)
     * [Reading files](#reading-files)
     * [Writing files](#writing-files)
   * [Directory object](#directory-object)
     * [List contents](#list-contents)
4. [License](#license)

Introduction
------------

Filesystem WIP for [EIO](http://php.net/eio), keep in mind that this can be very unstable at times and is not stable by a long shot!

Adapters
------------

* ChildProcessAdapter - Adapter using child processes to perform IO actions (default adapter if no extensions are installed)
* EioAdapter - Adapter using `ext-eio`
* PthreadsAdapter - Adapter using `ext-pthreads`

Examples
--------

`Adding examples here over time.`

Creating filesystem object
--------------------------

```php
<?php

$loop = \React\EventLoop\Factory::create();
$filesystem = \React\Filesystem\Filesystem::create($loop);
```

File object
--------------------------

```php
<?php

$loop = \React\EventLoop\Factory::create();
$filesystem = \React\Filesystem\Filesystem::create($loop);

$file = $filesystem->file(__FILE__); // Returns a \React\Filesystem\Node\FileInterface compatible object
```

Reading files
-------------

```php
$filesystem->getContents('test.txt')->then(function($contents) {
});
```

Which is a convenience method for:

```php
$filesystem->open('test.txt')->then(function($stream) {
    return React\Stream\BufferedSink::createPromise($stream);
})->then(function($contents) {
});
```

Which in it's turn is a convenience method for:

```php
$filesystem->file('test.txt')->open('r')->then(function ($stream) use ($node) {
    $buffer = '';
    $deferred = new \React\Promise\Deferred();
    $stream->on('data', function ($data) use (&$buffer) {
        $buffer += $data;
    });
    $stream->on('end', function ($data) use ($stream, $deferred, &$buffer) {
        $stream->close();
        $deferred->resolve(&$buffer);
    });
    return $deferred->promise();
});
```

Writing files
-------------

Open a file for writing (`w` flag) and write `abcde` to `test.txt` and close it. Create it (`c` flag) when it doesn't exists and truncate it (`t` flag) when it does.

```php
$filesystem->file('test.txt')->open('cwt')->then(function ($stream) {
    $stream->write('a');
    $stream->write('b');
    $stream->write('c');
    $stream->write('d');
    $stream->end('e');
});
```

Directory object
--------------------------

```php
<?php

$loop = \React\EventLoop\Factory::create();
$filesystem = \React\Filesystem\Filesystem::create($loop);

$dir = $filesystem->dir(__DIR__); // Returns a \React\Filesystem\Node\DirectoryInterface compatible object
```

List contents
-------------

```php
$filesystem->dir(__DIR__)->ls()->then(function (\SplObjectStorage $list) {
   foreach ($list as $node) {
       echo $node->getPath(), PHP_EOL;
   }
});
```

License
-------

React/Filesystem is released under the [MIT](https://github.com/reactphp/filesystem/blob/master/LICENSE) license.
