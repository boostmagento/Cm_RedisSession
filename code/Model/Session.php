<?php
/*
==New BSD License==

Copyright (c) 2013, Colin Mollenhour
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * The name of Colin Mollenhour may not be used to endorse or promote products
      derived from this software without specific prior written permission.
    * The module name must remain Cm_RedisSession.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
/**
 * Redis session handler with optimistic locking.
 *
 * Features:
 *  - Falls back to mysql handler if it can't connect to redis. Mysql handler falls back to file handler.
 *  - When a session's data exceeds the compression threshold the session data will be compressed.
 *  - Compression libraries supported are 'gzip', 'lzf' and 'snappy'. Lzf and Snappy are much faster than gzip.
 *  - Compression can be enabled, disabled, or reconfigured on the fly with no loss of session data.
 *  - Expiration is handled by Redis. No garbage collection needed.
 *  - Logs when sessions are not written due to not having or losing their lock.
 *  - Limits the number of concurrent lock requests before a 503 error is returned.
 *
 * Locking Algorithm Properties:
 *  - Only one process may get a write lock on a session.
 *  - A process may lose it's lock if another process breaks it, in which case the session will not be written.
 *  - The lock may be broken after BREAK_AFTER seconds and the process that gets the lock is indeterminate.
 *  - Only MAX_CONCURRENCY processes may be waiting for a lock for the same session or else a 503 error is returned.
 *  - Detects crashed processes to prevent session deadlocks (Linux only).
 *  - Detects inactive waiting processes to prevent false-positives in concurrency throttling.
 *
 */
class Cm_RedisSession_Model_Session extends Mage_Core_Model_Mysql4_Session
{
    const SLEEP_TIME         = 500000;   /* Sleep 0.5 seconds between lock attempts (1,000,000 == 1 second) */
    const FAIL_AFTER         = 15;       /* Try to break lock for at most this many seconds */
    const DETECT_ZOMBIES     = 5;        /* Try to detect zombies every this many seconds */
    const MAX_LIFETIME       = 2592000;  /* Redis backend limit */
    const SESSION_PREFIX     = 'sess_';
    const LOG_FILE           = 'redis_session.log';

    /* Bots get shorter session lifetimes */
    const BOT_REGEX          = '/^alexa|^blitz\.io|bot|^browsermob|crawl|^curl|^facebookexternalhit|feed|google web preview|^ia_archiver|^java|jakarta|^load impact|^magespeedtest|monitor|nagios|^pinterest|postrank|slurp|spider|uptime|yandex/i';

    const XML_PATH_HOST            = 'global/redis_session/host';
    const XML_PATH_PORT            = 'global/redis_session/port';
    const XML_PATH_PASS            = 'global/redis_session/password';
    const XML_PATH_TIMEOUT         = 'global/redis_session/timeout';
    const XML_PATH_PERSISTENT      = 'global/redis_session/persistent';
    const XML_PATH_DB              = 'global/redis_session/db';
    const XML_PATH_COMPRESSION_THRESHOLD = 'global/redis_session/compression_threshold';
    const XML_PATH_COMPRESSION_LIB = 'global/redis_session/compression_lib';
    const XML_PATH_LOG_LEVEL       = 'global/redis_session/log_level';
    const XML_PATH_MAX_CONCURRENCY = 'global/redis_session/max_concurrency';
    const XML_PATH_BREAK_AFTER     = 'global/redis_session/break_after_%s';
    const XML_PATH_BOT_LIFETIME    = 'global/redis_session/bot_lifetime';

    const DEFAULT_TIMEOUT               = 2.5;
    const DEFAULT_COMPRESSION_THRESHOLD = 2048;
    const DEFAULT_COMPRESSION_LIB       = 'gzip';
    const DEFAULT_LOG_LEVEL             = 1;
    const DEFAULT_MAX_CONCURRENCY       = 6;        /* The maximum number of concurrent lock waiters per session */
    const DEFAULT_BREAK_AFTER           = 30;       /* Try to break the lock after this many seconds */
    const DEFAULT_BOT_LIFETIME          = 7200;     /* The session lifetime for bots - shorter to prevent bots from wasting backend storage */

    /** @var bool */
    protected $_useRedis;

    /** @var Credis_Client */
    protected $_redis;

    /** @var int */
    protected $_dbNum;

    protected $_compressionThreshold;
    protected $_compressionLib;
    protected $_logLevel;
    protected $_maxConcurrency;
    protected $_breakAfter;
    protected $_botLifetime;
    protected $_isBot = FALSE;
    protected $_hasLock;
    protected $_sessionWritten; // avoid infinite loops
    protected $_timeStart; // re-usable for timing instrumentation

    static public $failedLockAttempts = 0; // for debug or informational purposes

    public function __construct()
    {
        $this->_timeStart = microtime(true);

        // Database config
        $host = (string)   (Mage::getConfig()->getNode(self::XML_PATH_HOST) ?: '127.0.0.1');
        $port = (int)      (Mage::getConfig()->getNode(self::XML_PATH_PORT) ?: '6379');
        $pass = (string)   (Mage::getConfig()->getNode(self::XML_PATH_PASS) ?: '');
        $timeout = (float) (Mage::getConfig()->getNode(self::XML_PATH_TIMEOUT) ?: self::DEFAULT_TIMEOUT);
        $persistent = (string) (Mage::getConfig()->getNode(self::XML_PATH_PERSISTENT) ?: '');
        $this->_dbNum = (int) (Mage::getConfig()->getNode(self::XML_PATH_DB) ?: 0);

        // General config
        $this->_compressionThreshold = (int) (Mage::getConfig()->getNode(self::XML_PATH_COMPRESSION_THRESHOLD) ?: self::DEFAULT_COMPRESSION_THRESHOLD);
        $this->_compressionLib = (string) (Mage::getConfig()->getNode(self::XML_PATH_COMPRESSION_LIB) ?: self::DEFAULT_COMPRESSION_LIB);
        $this->_logLevel = (int) (Mage::getConfig()->getNode(self::XML_PATH_LOG_LEVEL) ?: self::DEFAULT_LOG_LEVEL);
        $this->_maxConcurrency = (int) (Mage::getConfig()->getNode(self::XML_PATH_MAX_CONCURRENCY) ?: self::DEFAULT_MAX_CONCURRENCY);
        $this->_breakAfter = (float) (Mage::getConfig()->getNode(sprintf(self::XML_PATH_BREAK_AFTER, session_name())) ?: self::DEFAULT_BREAK_AFTER);
        $this->_botLifetime = (int) (Mage::getConfig()->getNode(self::XML_PATH_BOT_LIFETIME) ?: self::DEFAULT_BOT_LIFETIME);

        // Use sleep time multiplier so break time is in seconds
        $this->_breakAfter = (int) round((1000000 / self::SLEEP_TIME) * $this->_breakAfter);
        $this->_failAfter = (int) round((1000000 / self::SLEEP_TIME) * self::FAIL_AFTER);

        // Detect bots by user agent
        $userAgent = empty($_SERVER['HTTP_USER_AGENT']) ? FALSE : $_SERVER['HTTP_USER_AGENT'];
        $this->_isBot = ! $userAgent || preg_match(self::BOT_REGEX, $userAgent);

        // Connect and authenticate
        $this->_redis = new Credis_Client($host, $port, $timeout, $persistent);
        if (!empty($pass)) {
            $this->_redis->auth($pass) or Zend_Cache::throwException('Unable to authenticate with the redis server.');
        }
        $this->_redis->setCloseOnDestruct(FALSE);  // Destructor order cannot be predicted
        $this->_useRedis = TRUE;
        if ($this->_logLevel >= Zend_Log::DEBUG) {
            $this->_log(sprintf("%s initialized for connection to %s:%s after %.5f seconds",
                get_class($this), $host, $port, (microtime(true) - $this->_timeStart)
            ));
            if ($this->_isBot) {
                $this->_log(sprintf("Bot detected for user agent: %s", $userAgent));
            }
        }
    }

    /**
     * @param $msg
     * @param $level
     */
    protected function _log($msg, $level = Zend_Log::DEBUG)
    {
        Mage::log("{$this->_getPid()}: $msg", $level, self::LOG_FILE);
    }

    /**
     * Check DB connection
     *
     * @return bool
     */
    public function hasConnection()
    {
        if( ! $this->_useRedis) return parent::hasConnection();

        try {
            $this->_redis->connect();
            if ($this->_logLevel >= Zend_Log::DEBUG) {
                $this->_log("Connected to Redis");
                $this->_timeStart = microtime(true);
            }
            return TRUE;
        }
        catch (Exception $e) {
            Mage::logException($e);
            $this->_redis = NULL;
            $this->_log("Unable to connect to Redis; falling back to MySQL handler", Zend_Log::EMERG);

            // Fall-back to MySQL handler. If this fails, the file handler will be used.
            $this->_useRedis = FALSE;
            parent::__construct();
            return parent::hasConnection();
        }
    }

    /**
     * Fetch session data
     *
     * @param string $sessionId
     * @return string
     */
    public function read($sessionId)
    {
        if ( ! $this->_useRedis) return parent::read($sessionId);
        Varien_Profiler::start(__METHOD__);

        // Get lock on session. Increment the "lock" field and if the new value is 1, we have the lock.
        $sessionId = self::SESSION_PREFIX.$sessionId;
        $tries = $waiting = $lock = 0;
        $lockPid = $oldLockPid = NULL; // Restart waiting for lock when current lock holder changes
        $detectZombies = FALSE;
        if ($this->_logLevel >= Zend_Log::DEBUG) {
            $this->_log(sprintf("Attempting read lock on ID %s", $sessionId));
            $this->_timeStart = microtime(true);
        }
        if ($this->_dbNum) {
            $this->_redis->select($this->_dbNum);
        }
        while(1)
        {
            // Increment lock value for this session and retrieve the new value
            $oldLock = $lock;
            $lock = $this->_redis->hIncrBy($sessionId, 'lock', 1);

            // Get the pid of the process that has the lock
            if ($lock != 1 && $tries + 1 >= $this->_breakAfter) {
                $lockPid = $this->_redis->hGet($sessionId, 'pid');
            }

            // If we got the lock, update with our pid and reset lock and expiration
            if (   $lock == 1                          // We actually do have the lock
                || (
                        $tries >= $this->_breakAfter   // We are done waiting and want to start trying to break it
                     && $oldLockPid == $lockPid        // Nobody else got the lock while we were waiting
                   )
            ) {
                $setData = array(
                    'pid' => $this->_getPid(),
                    'lock' => 1,
                );

                // Save request data in session so if a lock is broken we can know which page it was for debugging
                if ($this->_logLevel >= Zend_Log::INFO) {
                    $additionalDetails = sprintf("(%s attempts)", $tries);
                    if ($this->_logLevel >= Zend_Log::DEBUG) {
                        $additionalDetails = sprintf("after %.5f seconds ", (microtime(true) - $this->_timeStart)) . $additionalDetails;
                    }
                    if (empty($_SERVER['REQUEST_METHOD'])) {
                        $setData['req'] = $_SERVER['SCRIPT_NAME'];
                    } else {
                        $setData['req'] = "{$_SERVER['REQUEST_METHOD']} {$_SERVER['SERVER_NAME']}{$_SERVER['REQUEST_URI']}";
                    }
                    if ($lock != 1) {
                        $this->_log(sprintf(
                            "Successfully broke lock for ID %s %s. Lock: %s\nLast request of broken lock: %s",
                            $sessionId, $additionalDetails, $lock, $this->_redis->hGet($sessionId, 'req')
                        ), Zend_Log::INFO);
                    }
                }
                $this->_redis->pipeline()
                    ->hMSet($sessionId, $setData)
                    ->expire($sessionId, min($this->getLifeTime(), self::MAX_LIFETIME))
                    ->exec();
                $this->_hasLock = TRUE;
                break;
            }

            // Otherwise, add to "wait" counter and continue
            else if ( ! $waiting) {
                $i = 0;
                do {
                    $waiting = $this->_redis->hIncrBy($sessionId, 'wait', 1);
                } while (++$i < $this->_maxConcurrency && $waiting < 1);
                if ($this->_logLevel >= Zend_Log::DEBUG) {
                    $this->_log(sprintf(
                        "Waiting for lock on ID %s (%s tries, %s waiting, %.5f seconds elapsed)",
                        $sessionId, $tries, $waiting, (microtime(true) - $this->_timeStart)
                    ));
                }
            }

            // Handle overloaded sessions
            else {
                // Detect broken sessions (e.g. caused by fatal errors)
                if ($detectZombies) {
                    $detectZombies = FALSE;
                    if ( $lock > $oldLock                 // lock shouldn't be less than old lock (another process broke the lock)
                      && $lock + 1 < $oldLock + $waiting // lock should be old+waiting, otherwise there must be a dead process
                    ) {
                        // Reset session to fresh state
                        if ($this->_logLevel >= Zend_Log::INFO) {
                            $this->_log(sprintf(
                                "Detected zombie waiter after %.5f seconds for ID %s (%s waiting)\n  %s (%s - %s)",
                                (microtime(true) - $this->_timeStart),
                                $sessionId, $waiting,
                                Mage::app()->getRequest()->getRequestUri(), Mage::app()->getRequest()->getClientIp(), Mage::app()->getRequest()->getHeader('User-Agent')
                            ), Zend_Log::INFO);
                        }
                        $waiting = $this->_redis->hIncrBy($sessionId, 'wait', -1);
                        continue;
                    }
                }

                // Limit concurrent lock waiters to prevent server resource hogging
                if ($waiting >= $this->_maxConcurrency) {
                    // Overloaded sessions get 503 errors
                    $this->_redis->hIncrBy($sessionId, 'wait', -1);
                    $this->_sessionWritten = TRUE; // Prevent session from getting written
                    $writes = $this->_redis->hGet($sessionId, 'writes');
                    if ($this->_logLevel >= Zend_Log::WARN) {
                        $this->_log(sprintf(
                            "Session concurrency exceeded for ID %s; displaying HTTP 503 (%s waiting, %s total requests)\n  %s (%s - %s)",
                            $sessionId, $waiting, $writes,
                            Mage::app()->getRequest()->getRequestUri(), Mage::app()->getRequest()->getClientIp(), Mage::app()->getRequest()->getHeader('User-Agent')
                        ), Zend_Log::WARN);
                    }
                    require_once(Mage::getBaseDir() . DS . 'errors' . DS . '503.php');
                    exit;
                }
            }

            $tries++;
            $oldLockPid = $lockPid;

            // Detect dead waiters
            if ($tries == 1 /* TODO - $tries % 10 == 0 ? */) {
                $detectZombies = TRUE;
                usleep(self::SLEEP_TIME + 10000); // sleep + 0.01 seconds
            }
            // Detect dead processes every 10 seconds
            if ($tries % self::DETECT_ZOMBIES == 0) {
                Varien_Profiler::start(__METHOD__.'-detect-zombies');
                if ($this->_logLevel >= Zend_Log::DEBUG) {
                    $this->_log(sprintf(
                        "Checking for zombies after %.5f seconds of waiting...", (microtime(true) - $this->_timeStart)
                    ));
                }
                $pid = $this->_redis->hGet($sessionId, 'pid');
                if ($pid && ! $this->_pidExists($pid)) {
                    // Allow a live process to get the lock
                    $this->_redis->hSet($sessionId, 'lock', 0);
                    if ($this->_logLevel >= Zend_Log::INFO) {
                        $this->_log(sprintf(
                            "Detected zombie process (%s) for %s (%s waiting)\n  %s (%s - %s)",
                            $pid, $sessionId, $waiting,
                            Mage::app()->getRequest()->getRequestUri(), Mage::app()->getRequest()->getClientIp(), Mage::app()->getRequest()->getHeader('User-Agent')
                        ), Zend_Log::INFO);
                    }
                    Varien_Profiler::stop(__METHOD__.'-detect-zombies');
                    continue;
                }
                Varien_Profiler::stop(__METHOD__.'-detect-zombies');
            }
            // Timeout
            if ($tries >= $this->_breakAfter + $this->_failAfter) {
                $this->_hasLock = FALSE;
                if ($this->_logLevel >= Zend_Log::NOTICE) {
                    $additionalDetails = sprintf("(%s attempts)", $tries);
                    if ($this->_logLevel >= Zend_Log::DEBUG) {
                        $additionalDetails = sprintf("after %.5f seconds ", (microtime(true) - $this->_timeStart)) . $additionalDetails;
                    }
                    $this->_log(sprintf("Giving up on read lock for ID %s %s", $sessionId, $additionalDetails), Zend_Log::NOTICE);
                }
                break;
            }
            else {
                if ($this->_logLevel >= Zend_Log::DEBUG) {
                    $this->_log(sprintf(
                        "Waiting for lock on ID %s (%d tries, lock pid is %s, %.5f seconds elapsed)",
                        $sessionId, $tries, $lockPid, (microtime(true) - $this->_timeStart)
                    ));
                }
                Varien_Profiler::start(__METHOD__.'-wait');
                usleep(self::SLEEP_TIME);
                Varien_Profiler::stop(__METHOD__.'-wait');
            }
        }
        self::$failedLockAttempts = $tries;

        // This process is no longer waiting for a lock
        if ($tries > 0) {
            $this->_redis->hIncrBy($sessionId, 'wait', -1);
        }

        // Session can be read even if it was not locked by this pid!
        $sessionData = $this->_redis->hGet($sessionId, 'data');
        if ($this->_logLevel >= Zend_Log::DEBUG) {
            $this->_log(sprintf("Data read for ID %s after %.5f seconds", $sessionId, (microtime(true) - $this->_timeStart)));
        }
        Varien_Profiler::stop(__METHOD__);
        return $sessionData ? $this->_decodeData($sessionData) : '';
    }

    /**
     * Update session
     *
     * @param string $sessionId
     * @param string $sessionData
     * @return boolean
     */
    public function write($sessionId, $sessionData)
    {
        Varien_Profiler::start(__METHOD__);
        if ( ! $this->_useRedis) return parent::write($sessionId, $sessionData);
        if ($this->_sessionWritten) {
            if ($this->_logLevel >= Zend_Log::DEBUG) {
                $this->_log(sprintf("Repeated session write detected; skipping for ID %s", $sessionId));
            }
            Varien_Profiler::stop(__METHOD__);
            return TRUE;
        }
        $this->_sessionWritten = TRUE;
        if ($this->_logLevel >= Zend_Log::DEBUG) {
            $this->_log(sprintf("Attempting write to ID %s", $sessionId));
            $this->_timeStart = microtime(true);
        }

        // Do not overwrite the session if it is locked by another pid
        try {
            if($this->_dbNum) $this->_redis->select($this->_dbNum);  // Prevent conflicts with other connections?
            $pid = $this->_redis->hGet('sess_'.$sessionId, 'pid'); // PHP Fatal errors cause self::SESSION_PREFIX to not work..
            if ( ! $pid || $pid == $this->_getPid()) {
                if ($this->_logLevel >= Zend_Log::DEBUG) {
                    $this->_log(sprintf("Write lock obtained on ID %s", $sessionId));
                }
                $this->_writeRawSession($sessionId, $sessionData, $this->getLifeTime());
                if ($this->_logLevel >= Zend_Log::DEBUG) {
                    $this->_log(sprintf("Data written to ID %s after %.5f seconds", $sessionId, (microtime(true) - $this->_timeStart)));
                }
            }
            else {
                if ($this->_logLevel >= Zend_Log::WARN) {
                    if ($this->_hasLock) {
                        $this->_log(sprintf("Unable to write session after %.5f seconds, another process took the lock for ID %s",
                            (microtime(true) - $this->_timeStart), $sessionId
                        ), Zend_Log::WARN);
                    } else {
                        $this->_log(sprintf("Unable to write session after %.5f seconds, unable to acquire lock on ID %s",
                            (microtime(true) - $this->_timeStart), $sessionId
                        ), Zend_Log::WARN);
                    }
                }
            }
        }
        catch(Exception $e) {
            if (class_exists('Mage', false)) {
                Mage::logException($e);
            } else {
                error_log("$e");
            }
            Varien_Profiler::stop(__METHOD__);
            return FALSE;
        }
        Varien_Profiler::stop(__METHOD__);
        return TRUE;
    }

    /**
     * Destroy session
     *
     * @param string $sessionId
     * @return boolean
     */
    public function destroy($sessionId)
    {
        if ( ! $this->_useRedis) return parent::destroy($sessionId);
        Varien_Profiler::start(__METHOD__);

        if ($this->_logLevel >= Zend_Log::DEBUG) {
            $this->_log(sprintf("Destroying ID %s", $sessionId));
        }
        $this->_redis->pipeline();
        if($this->_dbNum) $this->_redis->select($this->_dbNum);
        $this->_redis->del(self::SESSION_PREFIX.$sessionId);
        $this->_redis->exec();
        Varien_Profiler::stop(__METHOD__);
        return TRUE;
    }

    /**
     * Overridden to prevent calling getLifeTime at shutdown
     *
     * @return bool
     */
    public function close()
    {
        if ( ! $this->_useRedis) return parent::close();
        if ($this->_logLevel >= Zend_Log::DEBUG) {
            $this->_log("Closing connection");
        }
        if ($this->_redis) $this->_redis->close();
        return TRUE;
    }

    /**
     * Garbage collection
     *
     * @param int $maxLifeTime ignored
     * @return boolean
     */
    public function gc($maxLifeTime)
    {
        if ( ! $this->_useRedis) return parent::gc($maxLifeTime);
        return TRUE;
    }

    /**
     * @return int|mixed
     */
    public function getLifeTime()
    {
        if ($this->_isBot) {
            return min(parent::getLifeTime(), $this->_botLifetime);
        }
        return parent::getLifeTime();
    }

    /**
     * Public for testing purposes only.
     *
     * @param string $data
     * @return string
     */
    public function _encodeData($data)
    {
        Varien_Profiler::start(__METHOD__);
        $originalDataSize = strlen($data);
        if ($this->_compressionThreshold > 0 && $this->_compressionLib != 'none' && $originalDataSize >= $this->_compressionThreshold) {
            if ($this->_logLevel >= Zend_Log::DEBUG) {
                $this->_log(sprintf("Compressing %s bytes with %s", $originalDataSize,$this->_compressionLib));
                $this->_timeStart = microtime(true);
            }
            switch($this->_compressionLib) {
                case 'snappy': $data = snappy_compress($data); break;
                case 'lzf':    $data = lzf_compress($data); break;
                case 'gzip':   $data = gzcompress($data, 1); break;
            }
            if($data) {
                $data = ':'.substr($this->_compressionLib,0,2).':'.$data;
                if ($this->_logLevel >= Zend_Log::DEBUG) {
                    $this->_log(sprintf("Data compressed by %.1f percent in %.5f seconds",
                        ($originalDataSize == 0 ? 0 : (100 - (strlen($data) / $originalDataSize * 100))), (microtime(true) - $this->_timeStart)
                    ));
                }
            } else if ($this->_logLevel >= Zend_Log::WARN) {
                $this->_log(sprintf("Could not compress session data using %s", $this->_compressionLib), Zend_Log::WARN);
            }
        }
        Varien_Profiler::stop(__METHOD__);
        return $data;
    }

    /**
     * Public for testing purposes only.
     *
     * @param string $data
     * @return string
     */
    public function _decodeData($data)
    {
        Varien_Profiler::start(__METHOD__);
        switch (substr($data,0,4)) {
            // asking the data which library it uses allows for transparent changes of libraries
            case ':sn:': $data = snappy_uncompress(substr($data,4)); break;
            case ':lz:': $data = lzf_decompress(substr($data,4)); break;
            case ':gz:': $data = gzuncompress(substr($data,4)); break;
        }
        Varien_Profiler::stop(__METHOD__);
        return $data;
    }

    /**
     * Public for testing/import purposes only.
     *
     * @param $id
     * @param $data
     * @param $lifetime
     * @throws Exception
     */
    public function _writeRawSession($id, $data, $lifetime)
    {
        if ( ! $this->_useRedis) {
            throw new Exception('Not connected to redis!');
        }

        $sessionId = 'sess_' . $id;
        $this->_redis->pipeline()
            ->select($this->_dbNum)
            ->hMSet($sessionId, array(
                'data' => $this->_encodeData($data),
                'lock' => 0, // 0 so that next lock attempt will get 1
            ))
            ->hIncrBy($sessionId, 'writes', 1) // For informational purposes only
            ->expire($sessionId, min($lifetime, 2592000))
            ->exec();
    }

    /**
     * @param string $id
     * @return array
     * @throws Exception
     */
    public function _inspectSession($id)
    {
        if ( ! $this->_useRedis) {
            throw new Exception('Not connected to redis!');
        }

        $sessionId = 'sess_' . $id;
        $this->_redis->select($this->_dbNum);
        $data = $this->_redis->hGetAll($sessionId);
        if ($data && isset($data['data'])) {
            $data['data'] = $this->_decodeData($data['data']);
        }
        return $data;
    }

    /**
     * @return string
     */
    public function _getPid()
    {
        return gethostname().'|'.getmypid();
    }

    /**
     * @param $pid
     * @return bool
     */
    public function _pidExists($pid)
    {
        list($host,$pid) = explode('|', $pid);
        if (PHP_OS != 'Linux' || $host != gethostname()) {
            return TRUE;
        }
        return @file_exists('/proc/'.$pid);
    }

}
