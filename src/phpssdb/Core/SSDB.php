<?php
/**
 *
 * This file is part of Phpfastcache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt and LICENCE files.
 *
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 * @author Contributors  https://github.com/PHPSocialNetwork/phpfastcache/graphs/contributors
 */

namespace phpssdb\Core;

use Exception;


/**
 * Class SSDB
 * @package phpssdb\Core
 */
class SSDB
{
    public const STEP_SIZE = 0;
    public const STEP_DATA = 1;

    public $sock = null;
    public $last_resp = null;
    public array $resp = [];
    public int $step;
    public int $block_size;
    private bool $debug = false;
    private bool $_closed = false;
    private string $recv_buf = '';
    private bool $_easy = false;
    private bool $batch_mode = false;
    private array $batch_cmds = [];
    private ?string $async_auth_password = null;

    public function __construct(string $host, int $port, int $timeout_ms = 2000)
    {
        $timeout_f = (float)$timeout_ms / 1000;
        $this->sock = @stream_socket_client("[$host]:$port", $errno, $errstr, $timeout_f);
        if (!$this->sock) {
            throw new SSDBException("$errno: $errstr");
        }
        $timeout_sec = intval($timeout_ms / 1000);
        $timeout_usec = ($timeout_ms - $timeout_sec * 1000) * 1000;
        @stream_set_timeout($this->sock, $timeout_sec, $timeout_usec);
        if (function_exists('stream_set_chunk_size')) {
            @stream_set_chunk_size($this->sock, 1024 * 1024);
        }
    }

    public function set_timeout(int $timeout_ms)
    {
        $timeout_sec = intval($timeout_ms / 1000);
        $timeout_usec = ($timeout_ms - $timeout_sec * 1000) * 1000;
        @stream_set_timeout($this->sock, $timeout_sec, $timeout_usec);
    }

    /**
     * After this method invoked with yesno=true, all requesting methods
     * will not return a SSDB_Response object.
     * And some certain methods like get/zget will return false
     * when response is not ok(not_found, etc)
     */
    public function easy(): void
    {
        $this->_easy = true;
    }

    public function closed(): bool
    {
        return $this->_closed;
    }

    public function multi(): self
    {
        return $this->batch();
    }

    public function batch(): self
    {
        $this->batch_mode = true;
        $this->batch_cmds = [];
        return $this;
    }

    public function exec(): array
    {
        $ret = [];
        foreach ($this->batch_cmds as $op) {
            [$cmd, $params] = $op;
            $this->send_req($cmd, $params);
        }
        foreach ($this->batch_cmds as $op) {
            [$cmd, $params] = $op;
            $resp = $this->recv_resp($cmd, $params);
            $resp = $this->check_easy_resp($cmd, $resp);
            $ret[] = $resp;
        }
        $this->batch_mode = false;
        $this->batch_cmds = [];
        return $ret;
    }

    private function send_req(string $cmd, array $params)
    {
        $req = [$cmd];
        foreach ($params as $p) {
            if (is_array($p)) {
                $req = array_merge($req, $p);
            } else {
                $req[] = $p;
            }
        }
        return $this->send($req);
    }

    public function send(array $data)
    {
        $ps = [];
        foreach ($data as $p) {
            $ps[] = strlen($p);
            $ps[] = $p;
        }
        $s = join("\n", $ps) . "\n\n";
        if ($this->debug) {
            echo '> ' . str_replace(["\r", "\n"], ['\r', '\n'], $s) . "\n";
        }
        try {
            while (true) {
                $ret = @fwrite($this->sock, $s);
                if ($ret === false || $ret === 0) {
                    $this->close();
                    throw new SSDBException('Connection lost');
                }
                $s = substr($s, $ret);
                if (strlen($s) == 0) {
                    break;
                }
                @fflush($this->sock);
            }
        } catch (Exception $e) {
            $this->close();
            throw new SSDBException($e->getMessage());
        }
        return $ret;
    }

    public function close(): void
    {
        if (!$this->_closed) {
            @fclose($this->sock);
            $this->_closed = true;
            $this->sock = null;
        }
    }

    private function recv_resp($cmd, $params): SSDB_Response
    {
        $resp = $this->recv();
        if ($resp === false) {
            return new SSDB_Response('error', 'Unknown error');
        } else if (!$resp) {
            return new SSDB_Response('disconnected', 'Connection closed');
        }
        if ($resp[0] == 'noauth') {
            $errmsg = isset($resp[1]) ? $resp[1] : '';
            return new SSDB_Response($resp[0], $errmsg);
        }
        switch ($cmd) {
            case 'dbsize':
            case 'ping':
            case 'qset':
            case 'getbit':
            case 'setbit':
            case 'countbit':
            case 'strlen':
            case 'set':
            case 'setx':
            case 'setnx':
            case 'zset':
            case 'hset':
            case 'qpush':
            case 'qpush_front':
            case 'qpush_back':
            case 'qtrim_front':
            case 'qtrim_back':
            case 'del':
            case 'zdel':
            case 'hdel':
            case 'hsize':
            case 'zsize':
            case 'qsize':
            case 'hclear':
            case 'zclear':
            case 'qclear':
            case 'multi_set':
            case 'multi_del':
            case 'multi_hset':
            case 'multi_hdel':
            case 'multi_zset':
            case 'multi_zdel':
            case 'incr':
            case 'decr':
            case 'zincr':
            case 'zdecr':
            case 'hincr':
            case 'hdecr':
            case 'zget':
            case 'zrank':
            case 'zrrank':
            case 'zcount':
            case 'zsum':
            case 'zremrangebyrank':
            case 'zremrangebyscore':
            case 'ttl':
            case 'expire':
                if ($resp[0] == 'ok') {
                    $val = isset($resp[1]) ? intval($resp[1]) : 0;
                    return new SSDB_Response($resp[0], $val);
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
            case 'zavg':
                if ($resp[0] == 'ok') {
                    $val = isset($resp[1]) ? floatval($resp[1]) : (float)0;
                    return new SSDB_Response($resp[0], $val);
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
            case 'get':
            case 'substr':
            case 'getset':
            case 'hget':
            case 'qget':
            case 'qfront':
            case 'qback':
                if ($resp[0] == 'ok') {
                    if (count($resp) == 2) {
                        return new SSDB_Response('ok', $resp[1]);
                    } else {
                        return new SSDB_Response('server_error', 'Invalid response');
                    }
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
                break;
            case 'qpop':
            case 'qpop_front':
            case 'qpop_back':
                if ($resp[0] == 'ok') {
                    $size = 1;
                    if (isset($params[1])) {
                        $size = intval($params[1]);
                    }
                    if ($size <= 1) {
                        if (count($resp) == 2) {
                            return new SSDB_Response('ok', $resp[1]);
                        } else {
                            return new SSDB_Response('server_error', 'Invalid response');
                        }
                    } else {
                        $data = array_slice($resp, 1);
                        return new SSDB_Response('ok', $data);
                    }
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
                break;
            case 'keys':
            case 'zkeys':
            case 'hkeys':
            case 'hlist':
            case 'zlist':
            case 'qslice':
                if ($resp[0] == 'ok') {
                    $data = [];
                    if ($resp[0] == 'ok') {
                        $data = array_slice($resp, 1);
                    }
                    return new SSDB_Response($resp[0], $data);
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
            case 'auth':
            case 'exists':
            case 'hexists':
            case 'zexists':
                if ($resp[0] == 'ok') {
                    if (count($resp) == 2) {
                        return new SSDB_Response('ok', (bool)$resp[1]);
                    } else {
                        return new SSDB_Response('server_error', 'Invalid response');
                    }
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
                break;
            case 'multi_exists':
            case 'multi_hexists':
            case 'multi_zexists':
                if ($resp[0] == 'ok') {
                    if (count($resp) % 2 == 1) {
                        $data = [];
                        for ($i = 1; $i < count($resp); $i += 2) {
                            $data[$resp[$i]] = (bool)$resp[$i + 1];
                        }
                        return new SSDB_Response('ok', $data);
                    } else {
                        return new SSDB_Response('server_error', 'Invalid response');
                    }
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
                break;
            case 'scan':
            case 'rscan':
            case 'zscan':
            case 'zrscan':
            case 'zrange':
            case 'zrrange':
            case 'hscan':
            case 'hrscan':
            case 'hgetall':
            case 'multi_hsize':
            case 'multi_zsize':
            case 'multi_get':
            case 'multi_hget':
            case 'multi_zget':
            case 'zpop_front':
            case 'zpop_back':
                if ($resp[0] == 'ok') {
                    if (count($resp) % 2 == 1) {
                        $data = [];
                        for ($i = 1; $i < count($resp); $i += 2) {
                            if ($cmd[0] == 'z') {
                                $data[$resp[$i]] = intval($resp[$i + 1]);
                            } else {
                                $data[$resp[$i]] = $resp[$i + 1];
                            }
                        }
                        return new SSDB_Response('ok', $data);
                    } else {
                        return new SSDB_Response('server_error', 'Invalid response');
                    }
                } else {
                    $errmsg = isset($resp[1]) ? $resp[1] : '';
                    return new SSDB_Response($resp[0], $errmsg);
                }
                break;
            default:
                return new SSDB_Response($resp[0], array_slice($resp, 1));
        }
        return new SSDB_Response('error', 'Unknown command: $cmd');
    }

    public function recv(): ?array
    {
        $this->step = self::STEP_SIZE;
        while (true) {
            $ret = $this->parse();
            if ($ret === null) {
                try {
                    $data = @fread($this->sock, 1024 * 1024);
                    if ($this->debug) {
                        echo '< ' . str_replace(["\r", "\n"], ['\r', '\n'], $data) . "\n";
                    }
                } catch (Exception $e) {
                    $data = '';
                }
                if ($data === false || $data === '') {
                    if (feof($this->sock)) {
                        $this->close();
                        throw new SSDBException('Connection lost');
                    } else {
                        throw new SSDBTimeoutException('Connection timeout');
                    }
                }
                $this->recv_buf .= $data;
            } else {
                return $ret;
            }
        }
    }

    private function parse(): ?array
    {
        $spos = 0;
        $epos = 0;
        $buf_size = strlen($this->recv_buf);
        // performance issue for large reponse
        //$this->recv_buf = ltrim($this->recv_buf);
        while (true) {
            $spos = $epos;
            if ($this->step === self::STEP_SIZE) {
                $epos = strpos($this->recv_buf, "\n", $spos);
                if ($epos === false) {
                    break;
                }
                $epos += 1;
                $line = substr($this->recv_buf, $spos, $epos - $spos);
                $spos = $epos;

                $line = trim($line);
                if (strlen($line) == 0) { // head end
                    $this->recv_buf = substr($this->recv_buf, $spos);
                    $ret = $this->resp;
                    $this->resp = [];
                    return $ret;
                }
                $this->block_size = intval($line);
                $this->step = self::STEP_DATA;
            }
            if ($this->step === self::STEP_DATA) {
                $epos = $spos + $this->block_size;
                if ($epos <= $buf_size) {
                    $n = strpos($this->recv_buf, "\n", $epos);
                    if ($n !== false) {
                        $data = substr($this->recv_buf, $spos, $epos - $spos);
                        $this->resp[] = $data;
                        $epos = $n + 1;
                        $this->step = self::STEP_SIZE;
                        continue;
                    }
                }
                break;
            }
        }

        // packet not ready
        if ($spos > 0) {
            $this->recv_buf = substr($this->recv_buf, $spos);
        }
        return null;
    }

    private function check_easy_resp(string $cmd, $resp)
    {
        $this->last_resp = $resp;
        if ($this->_easy) {
            if ($resp->not_found()) {
                return NULL;
            } else if (!$resp->ok() && !is_array($resp->data)) {
                return false;
            } else {
                return $resp->data;
            }
        } else {
            $resp->cmd = $cmd;
            return $resp;
        }
    }

    public function request()
    {
        $args = func_get_args();
        $cmd = array_shift($args);
        return $this->__call($cmd, $args);
    }

    /**
     * @param string $cmd
     * @param array $params
     * @return mixed
     * @throws SSDBException
     */
    public function __call(string $cmd, array $params = [])
    {
        $cmd = strtolower($cmd);
        if ($this->async_auth_password !== null) {
            $pass = $this->async_auth_password;
            $this->async_auth_password = null;
            $auth = $this->__call('auth', [$pass]);
            if ($auth !== true) {
                throw new Exception("Authentication failed");
            }
        }

        if ($this->batch_mode) {
            $this->batch_cmds[] = [$cmd, $params];
            return $this;
        }

        try {
            if ($this->send_req($cmd, $params) === false) {
                $resp = new SSDB_Response('error', 'send error');
            } else {
                $resp = $this->recv_resp($cmd, $params);
            }
        } catch (SSDBException $e) {
            if ($this->_easy) {
                throw $e;
            } else {
                $resp = new SSDB_Response('error', $e->getMessage());
            }
        }

        if ($resp->code == 'noauth') {
            $msg = $resp->message;
            throw new Exception($msg);
        }

        $resp = $this->check_easy_resp($cmd, $resp);
        return $resp;
    }

    public function auth(?string $password): void
    {
        $this->async_auth_password = $password;
    }

    /**
     * @param array $kvs
     * @return mixed
     * @throws SSDBException
     */
    public function multi_set(array $kvs = [])
    {
        $args = [];
        foreach ($kvs as $k => $v) {
            $args[] = $k;
            $args[] = $v;
        }
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $name
     * @param array $kvs
     * @return mixed
     * @throws SSDBException
     */
    public function multi_hset(string $name, array $kvs = [])
    {
        $args = [$name];
        foreach ($kvs as $k => $v) {
            $args[] = $k;
            $args[] = $v;
        }
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $name
     * @param array $kvs
     * @return mixed
     * @throws SSDBException
     */
    public function multi_zset(string $name, array $kvs = [])
    {
        $args = [$name];
        foreach ($kvs as $k => $v) {
            $args[] = $k;
            $args[] = $v;
        }
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $key
     * @param int $val
     * @return mixed
     * @throws SSDBException
     */
    public function incr(string $key, int $val = 1)
    {
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $key
     * @param int $val
     * @return mixed
     * @throws SSDBException
     */
    public function decr(string $key, int $val = 1)
    {
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $name
     * @param string $key
     * @param int $score
     * @return mixed
     * @throws SSDBException
     */
    public function zincr(string $name, string $key, int $score = 1)
    {
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $name
     * @param string $key
     * @param int $score
     * @return mixed
     * @throws SSDBException
     */
    public function zdecr(string $name, string $key, int $score = 1)
    {
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $key
     * @param $score
     * @param $value
     * @return mixed
     * @throws SSDBException
     */
    public function zadd(string $key, $score, $value)
    {
        $args = [$key, $value, $score];
        return $this->__call('zset', $args);
    }

    /**
     * @param string $name
     * @param string $key
     * @return mixed
     * @throws SSDBException
     */
    public function zRevRank(string $name, string $key)
    {
        $args = func_get_args();
        return $this->__call("zrrank", $args);
    }

    /**
     * @param string $name
     * @param int $offset
     * @param int $limit
     * @return mixed
     * @throws SSDBException
     */
    public function zRevRange(string $name, int $offset, int $limit)
    {
        $args = func_get_args();
        return $this->__call("zrrange", $args);
    }

    /**
     * @param string $name
     * @param string $key
     * @param int $val
     * @return mixed
     * @throws SSDBException
     */
    public function hincr(string $name, string $key, int $val = 1)
    {
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    /**
     * @param string $name
     * @param string $key
     * @param int $val
     * @return mixed
     * @throws SSDBException
     */
    public function hdecr(string $name, string $key, int $val = 1)
    {
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }
}
