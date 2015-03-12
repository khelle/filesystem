<?php

namespace React\Filesystem;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Filesystem\Eio;

class EioAdapter implements AdapterInterface
{

    const CREATION_MODE = 'rw-rw-rw-';

    protected $typeClassMapping = [
        EIO_DT_DIR => '\React\Filesystem\Node\Directory',
        EIO_DT_REG => '\React\Filesystem\Node\File',
    ];

    protected $active = false;
    protected $loop;
    protected $openFlagResolver;
    protected $permissionFlagResolver;
    protected $queuedInvoker;

    public function __construct(LoopInterface $loop)
    {
        eio_init();
        $this->loop = $loop;
        $this->fd = eio_get_event_stream();
        $this->openFlagResolver = new Eio\OpenFlagResolver();
        $this->permissionFlagResolver = new Eio\PermissionFlagResolver();
        $this->queuedInvoker = new QueuedInvoker($this);
    }

    /**
     * @return LoopInterface
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * {@inheritDoc}
     */
    public function stat($filename)
    {
        return $this->callFilesystem('eio_stat', [$filename]);
    }

    /**
     * {@inheritDoc}
     */
    public function unlink($filename)
    {
        return $this->callFilesystem('eio_unlink', [$filename]);
    }

    /**
     * {@inheritDoc}
     */
    public function rename($fromFilename, $toFilename)
    {
        return $this->callFilesystem('eio_rename', [$fromFilename, $toFilename]);
    }

    /**
     * {@inheritDoc}
     */
    public function chmod($path, $mode)
    {
        return $this->callFilesystem('eio_chmod', [$path, $mode]);
    }

    /**
     * {@inheritDoc}
     */
    public function chown($path, $uid, $gid)
    {
        return $this->callFilesystem('eio_chown', [$path, $uid, $gid]);
    }

    /**
     * {@inheritDoc}
     */
    public function ls($path, $flags = EIO_READDIR_STAT_ORDER)
    {
        return $this->queuedInvoker->invokeCall('eio_readdir', [$path, $flags], false)->then(function ($result) use ($path) {
            return $this->processLsContents($path, $result);
        });
    }

    /**
     * @param $result
     * @return array
     */
    protected function processLsContents($basePath, $result)
    {
        $list = [];
        if (isset($result['dents'])) {
            foreach ($result['dents'] as $entry) {
                $path = $basePath . DIRECTORY_SEPARATOR . $entry['name'];
                if (isset($this->typeClassMapping[$entry['type']])) {
                    $list[$entry['name']] = \React\Promise\resolve(new $this->typeClassMapping[$entry['type']]($path, $this));
                    continue;
                }

                if ($entry['type'] === EIO_DT_UNKNOWN) {
                    $list[$entry['name']] = $this->stat($path)->then(function ($stat) use ($path) {
                        switch (true) {
                            case ($stat['mode'] & 0x4000) == 0x4000:
                                return \React\Promise\resolve(new Directory($path, $this));
                                break;
                            case ($stat['mode'] & 0x8000) == 0x8000:
                                return \React\Promise\resolve(new File($path, $this));
                                break;
                        }
                    });
                }
            }
        }

        return \React\Promise\all($list);
    }


    /**
     * {@inheritDoc}
     */
    public function mkdir($path, $mode = self::CREATION_MODE)
    {
        return $this->callFilesystem('eio_mkdir', [
            $path,
            $this->permissionFlagResolver->resolve($mode),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function rmdir($path)
    {
        return $this->callFilesystem('eio_rmdir', [$path]);
    }

    /**
     * {@inheritDoc}
     */
    public function open($path, $flags, $mode = self::CREATION_MODE)
    {
        $flags = $this->openFlagResolver->resolve($flags);
        return $this->callFilesystem('eio_open', [
            $path,
            $flags,
            $this->permissionFlagResolver->resolve($mode),
        ])->then(function ($fileDescriptor) use ($path, $flags) {
            return Eio\StreamFactory::create($path, $fileDescriptor, $flags, $this);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function close($fd)
    {
        return $this->callFilesystem('eio_close', [$fd]);
    }

    /**
     * {@inheritDoc}
     */
    public function touch($path, $mode = self::CREATION_MODE, $time = null)
    {
        return $this->stat($path)->then(function () use ($path, $time) {
            if ($time === null) {
                $time = microtime(true);
            }
            return $this->callFilesystem('eio_utime', [
                $path,
                $time,
                $time,
            ]);
        }, function () use ($path, $mode) {
            return $this->callFilesystem('eio_open', [
                $path,
                EIO_O_CREAT,
                $this->permissionFlagResolver->resolve($mode),
            ])->then(function ($fd) use ($path) {
                return $this->close($fd);
            });
        });
    }

    /**
     * {@inheritDoc}
     */
    public function read($fileDescriptor, $length, $offset)
    {
        return $this->callFilesystem('eio_read', [
            $fileDescriptor,
            $length,
            $offset,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function write($fileDescriptor, $data, $length, $offset)
    {
        return $this->callFilesystem('eio_write', [
            $fileDescriptor,
            $data,
            $length,
            $offset,
        ]);
    }

    /**
     * @param string $function
     * @param array $args
     * @param int $errorResultCode
     * @return \React\Promise\Promise
     */
    public function callFilesystem($function, $args, $errorResultCode = -1)
    {
        $deferred = new Deferred();

        // Run this in a future tick to make sure all EIO calls are run within the loop
        $this->loop->futureTick(function () use ($function, $args, $errorResultCode, $deferred) {
            $this->executeDelayedCall($function, $args, $errorResultCode, $deferred);
        });

        return $deferred->promise();
    }

    protected function executeDelayedCall($function, $args, $errorResultCode, $deferred)
    {
        $this->register();
        $args[] = EIO_PRI_DEFAULT;
        $args[] = function ($data, $result, $req) use ($deferred, $errorResultCode, $function, $args) {
            if ($result == $errorResultCode) {
                $exception = new Eio\UnexpectedValueException(@eio_get_last_error($req));
                $exception->setArgs($args);
                $deferred->reject($exception);
                return;
            }

            $deferred->resolve($result);
        };

        if (!call_user_func_array($function, $args)) {
            $name = $function;
            if (!is_string($function)) {
                $name = get_class($function);
            }
            $exception = new Eio\RuntimeException('Unknown error calling "' . $name . '"');
            $exception->setArgs($args);
            $deferred->reject($exception);
        };
    }

    protected function register()
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        $this->loop->addReadStream($this->fd, [$this, 'handleEvent']);
    }

    protected function unregister()
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->loop->removeReadStream($this->fd, [$this, 'handleEvent']);
    }

    public function handleEvent()
    {
        if ($this->workPendingCount() == 0) {
            return;
        }

        while (eio_npending()) {
            eio_poll();
        }

        if ($this->workPendingCount() == 0) {
            $this->unregister();
        }
    }

    public function workPendingCount()
    {
        return eio_nreqs() + eio_npending() + eio_nready();
    }
}
