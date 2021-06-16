# FileLock

[![Build Status](https://travis-ci.com/fab2s/FileLock.svg?branch=master)](https://travis-ci.com/fab2s/FileLock) [![Total Downloads](https://poser.pugx.org/fab2s/filelock/downloads)](//packagist.org/packages/fab2s/filelock) [![Monthly Downloads](https://poser.pugx.org/fab2s/filelock/d/monthly)](//packagist.org/packages/fab2s/filelock) [![Latest Stable Version](https://poser.pugx.org/fab2s/filelock/v/stable)](https://packagist.org/packages/fab2s/filelock) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/fab2s/FileLock/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/fab2s/FileLock/?branch=master) [![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat)](http://makeapullrequest.com) [![License](https://poser.pugx.org/fab2s/filelock/license)](https://packagist.org/packages/fab2s/filelock)

A fluent _Helper_ to properly handle file locking based on [flock()](https://php.net/flock).

FileLock offers two locking strategies and several options.
Just like `flock()`, FileLock can either wait until an exclusive lock is acquired (Blocking), or fail immediately (Non Blocking), but it can also try a configurable amount of time to acquire a Non Blocking exclusive lock, and wait a configurable amount of time between each attempts. FileLock can either lock a file (Self Locking) or create a file.lock and lock it instead (External Locking)

## Installation

Math can be installed using composer :

```
composer require "fab2s/filelock"
```

`FileLock` is also included in [OpinHelper](https://github.com/fab2s/OpinHelpers) which packages several bellow "Swiss Army Knife" level Helpers covering some of the most annoying aspects of php programing, such as UTF8 string manipulation, high precision Mathematics or properly locking a file

Should you need to work with php bellow 7.1, you can still use [OpinHelper](https://github.com/fab2s/OpinHelpers) `0.x`

## Prerequisites

`FileLock` has no specific dependencies 

## External Locking

This locking strategy does not lock the input filePath itself but rather creates a new file `$lockFilePath = "$inputFilePath.lock";` and attempts to [flock()](https://php.net/flock) it instead
This method is preferred in highly concurrent usages, where many processes will try to write the same file at once, such as file caching. Because this allows us to first try to open the `.lock` file in _write_ mode and fail back to _read_ mode before a `flock()` is eventually attempted

By using a separate file for locking, we make sure that every write waiting for the lock (this should be done in Blocking mode) does not hold any handle on the cache file itself while it is most likely already being intensively read

By failing back to _read_ mode when _write_ failed, we again lower the _write_ handles to a single one, only when the external `.lock` file needs to be created. 
Altogether, this means that after warm up, each process waiting to write on the same file will be holding a _read_ handle on the `.lock` file, while a single _write_ one is at most used to actually write the cache file. As _write_ handles are costly to open, approximately ten times slower than _read_ handles, doing this can end up making some difference

External locking can also be useful when you do not want to actually `flock()` a file (could be already locked or used by some other program/process), or because you just need exclusivity for something as the lock file is then created for you and every PHP process will be able to check its existence

> Please note that the external and empty `.lock` file is never deleted by FileLock and that its presence does not necessarily means that the lock is active

If the `$lockFilePath = "$inputFilePath.lock";`  version of the `.lock` file (that is where the $inputFilePath directory) is not writable, it is created in `sys_get_temp_dir()` instead, with a hashed `basedir($inputFilePath)` prefix in filename

```php
$filePath = "/some/dir/some.file.ext";
$lock = new FileLock($filePath, Filelock::LOCK_EXTERNAL); // will create /some/dir/some/file.ext.lock or /tmp/sha1(/some/dir/some)_file.ext.lock
```

## Self Locking

This locking strategy does acquire a lock on the input filePath itself. It provide with more guarantees than the External Locking strategy as the file will be locked for any process, not just the ones checking the lock through FileLock and it is preferred when write sessions are not _instant_

```php
$filePath = "/some/dir/some.file.ext";
$lock = new FileLock($filePath, Filelock::LOCK_SELF); // will directtly flock() /some/dir/some/file.ext
```

It _could_ make sense under specific circumstances to use a double lock, both External and Self, using two FileLock instances

## In practice

In both External and Self locking, once you have an instance you can:

- Acquire a Blocking lock:
    
    ```php
    $lock->doLock(true);
    // we either own the lock or php timed out
    ```

- Attempt to acquire a Non Blocking lock:
    
    ```php
    if ($lock->doLock()) {
        // we got the lock
    }
    ```

- Attempt to acquire a Non Blocking lock several time before failing:
    
    ```php
    $isLocked = $lock->setLockTry(5) // default is 3
        ->setLockWait(0.01) // default is 0.1 second
        ->obtainLock(); // will try 5 times and wait 0.01 second in between
    if ($isLocked) { // could call $lock->isLocked()
        // we got the lock
    }
    ```

From there, you can get the underlying handle:

```php
$lockHandle = $lock->getHandle();
```

This is mostly useful when Self locking as you probably need the handle to actually write something.

### Release lock
    
In all cases, locks are either released upon instance destruction or manually:

```php
$lock->unLock(); // doing so also fclose() underlying handle
```

> It is **IMPORTANT** to notice that when you acquire an Self lock, you need to keep the $lock instance alive until you are done with manipulating the file. Because FileLock is set to release its locks and handles when destroyed. This could happen if you where to acquire a lock in some function without storing the resulting instance outside of its scope.

## Open Factory

FileLock comes with an handy factory to ease exclusively and self locked file opening:

```php
    /**
     * @param string     $file
     * @param string     $mode fopen() mode
     * @param int|null   $maxTries 0|null for single non blocking attempt
     *                             1 for a single blocking attempt
     *                             1-N Number of non blocking attempts
     * @param float|null $lockWait Time to wait between attempts in second
     *
     * @return null|static
     */
    public static function open($file, $mode, $maxTries = null, $lockWait = null)
```

Usage is pretty similar to [fopen()](https://php.net/fopen) except it returns a FileLock instance upon success (open + lock) instead of a resource and null when it failed.

```php
$filePath = "/some/dir/some.file.ext";
$mode = 'wb'; // any fopen() mode
$fileLock = Filelock::open($filePath, $mode); // returns null or FileLock instance

if ($fileLock) {
	// we got it opened and locked
	$handle = $fileLock->getHandle();
}
```

## Requirements

`FileLock` is tested against php 7.1, 7.2, 7.3, 7.4 and 8.0

## Contributing

Contributions are welcome, do not hesitate to open issues and submit pull requests.

## License

`FileLock` is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).