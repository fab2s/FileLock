<?php

/*
 * This file is part of FileLock.
 *     (c) Fabrice de Stefanis / https://github.com/fab2s/FileLock
 * This source file is licensed under the MIT license which you will
 * find in the LICENSE file or at https://opensource.org/licenses/MIT
 */

namespace fab2s\FileLock;

/**
 * Class FileLock
 */
class FileLock
{
    /**
     * external lock type actually locks "inputFile.lock"
     */
    const LOCK_EXTERNAL = 'external';

    /**
     * lock the file itself
     */
    const LOCK_SELF = 'self';

    /**
     * @var string
     */
    protected $lockType = self::LOCK_EXTERNAL;

    /**
     * max number of lock attempt
     *
     * @var int
     */
    protected $lockTry = 3;

    /**
     * The number of seconds to wait between lock attempts
     *
     * @var float
     */
    protected $lockWait = 0.1;

    /**
     * @var string
     */
    protected $file;

    /**
     * @var resource
     */
    protected $handle;

    /**
     * @var string fopen mode
     */
    protected $mode;

    /**
     * @var bool
     */
    protected $lockAcquired = false;

    /**
     * FileLock constructor.
     *
     * @param string $file
     * @param string $lockMethod
     * @param string $mode
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $file, string $lockMethod, string $mode = 'wb')
    {
        $fileDir = dirname($file);
        if (!($fileDir = realpath($fileDir))) {
            throw new \InvalidArgumentException('File path not valid');
        }

        if ($lockMethod === self::LOCK_SELF) {
            $this->lockType = self::LOCK_SELF;
            $this->mode     = $mode;
            $this->file     = $fileDir . '/' . basename($file);

            return;
        }

        $fileDir    = is_writeable($fileDir) ? $fileDir . '/' : sys_get_temp_dir() . '/' . sha1($fileDir) . '_';
        $this->file = $fileDir . basename($file) . '.lock';
    }

    /**
     * since there is no more auto unlocking
     */
    public function __destruct()
    {
        $this->unLock();
    }

    /**
     * @param string         $file
     * @param string         $mode     fopen() mode
     * @param int|null       $maxTries 0|null for single non blocking attempt
     *                                 1 for a single blocking attempt
     *                                 1-N Number of non blocking attempts
     * @param float|int|null $lockWait Time to wait between attempts in second
     *
     * @return null|static
     */
    public static function open(string $file, string $mode, ?int $maxTries = null, $lockWait = null): ? self
    {
        $instance = new static($file, self::LOCK_SELF, $mode);
        $maxTries = max(0, (int) $maxTries);
        if ($maxTries > 1) {
            $instance->setLockTry($maxTries);
            $lockWait = max(0, (float) $lockWait);
            if ($lockWait > 0) {
                $instance->setLockWait($lockWait);
            }
            $instance->obtainLock();
        } else {
            $instance->doLock((bool) $maxTries);
        }

        if ($instance->isLocked()) {
            return $instance;
        }

        $instance->unLock();

        return null;
    }

    /**
     * @return resource
     */
    public function getHandle()
    {
        return $this->handle;
    }

    /**
     * @return string
     */
    public function getLockType(): string
    {
        return $this->lockType;
    }

    /**
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->lockAcquired;
    }

    /**
     * obtain a lock with retries
     *
     * @return bool
     */
    public function obtainLock(): bool
    {
        $tries       = 0;
        $waitClosure = $this->getWaitClosure();
        do {
            if ($this->doLock()) {
                return true;
            }

            ++$tries;
            $waitClosure();
        } while ($tries < $this->lockTry);

        return false;
    }

    /**
     * @param bool $blocking
     *
     * @return bool
     */
    public function doLock(bool $blocking = false): bool
    {
        if ($this->lockAcquired) {
            return true;
        }

        if ($this->obtainLockHandle()) {
            $this->lockAcquired = flock($this->handle, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB);
        }

        if (!$this->lockAcquired) {
            $this->unLock();
        }

        return $this->lockAcquired;
    }

    /**
     * release the lock
     *
     * @return static
     */
    public function unLock(): self
    {
        if (is_resource($this->handle)) {
            fflush($this->handle);
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->lockAcquired = false;
        $this->handle       = null;

        return $this;
    }

    /**
     * @param int $number
     *
     * @return static
     */
    public function setLockTry(int $number): self
    {
        $this->lockTry = max(1, (int) $number);

        return $this;
    }

    /**
     * @param float|int $seconds
     *
     * @return static
     */
    public function setLockWait($seconds)
    {
        $this->lockWait = max(0.0001, (float) $seconds);

        return $this;
    }

    /**
     * @return bool
     */
    protected function obtainLockHandle(): bool
    {
        $this->mode   = $this->mode ?: (is_file($this->file) ? 'rb' : 'wb');
        $this->handle = fopen($this->file, $this->mode) ?: null;
        if (!$this->handle) {
            return $this->obtainLockHandleFallBack();
        }

        return true;
    }

    /**
     * @return bool
     */
    protected function obtainLockHandleFallBack(): bool
    {
        if (
            $this->lockType === self::LOCK_EXTERNAL &&
            $this->mode === 'wb'
        ) {
            // if another process won the race at creating lock file
            $this->mode   = 'rb';
            $this->handle = fopen($this->file, $this->mode) ?: null;
        }

        return (bool) $this->handle;
    }

    /**
     * @return \Closure
     */
    protected function getWaitClosure(): \Closure
    {
        if ($this->lockWait > 300) {
            $wait = (int) $this->lockWait;

            return function () use ($wait) {
                sleep($wait);
            };
        }

        $wait = (int) ($this->lockWait * 1000000);

        return function () use ($wait) {
            usleep($wait);
        };
    }
}
