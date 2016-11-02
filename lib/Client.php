<?php

namespace Amp\Socket;

use Amp as amp;

class Client {
    private $state;
    private static $succeeder;

    /**
     * @param resource $socket An open socket client resource
     */
    public function  __construct($socket) {
        \stream_set_blocking($socket, false);
        $this->state = $state = new \StdClass;
        $readWatcherId = amp\onReadable($socket, static function ($wid, $socket) use ($state) {
            $data = @\fread($socket, 8192);
            if ($data != "") {
                $state->bytesRead += \strlen($data);
                $state->buffer .= $data;
                Client::onRead($state);
            } else {
                Client::onEmptyRead($state);
            }
        }, $options = ["enable" => false]);
        $writeWatcherId = amp\onWritable($socket, static function ($wid, $socket) use ($state) {
            $op = \current($state->writeOperations);
            if ($bytes = @\fwrite($socket, $op->buffer)) {
                $state->bytesSent += $bytes;
                Client::onWrite($state, $op, $bytes);
            } else {
\Notion\Log\Log::info('EMPTY WRITE ON: ' . $op->buffer);
                Client::onEmptyWrite($state);
            }
        }, $options = ["enable" => false]);
        $state->readWatcherId = $readWatcherId;
        $state->writeWatcherId = $writeWatcherId;
        $state->socket = $socket;
        $state->isDead = false;
        $state->localName = \stream_socket_get_name($socket, $wantPeer = false);
        $state->remoteName = \stream_socket_get_name($socket, $wantPeer = true);
        $state->readOperations = [];
        $state->writeOperations = [];
        $state->bytesRead = 0;
        $state->bytesSent = 0;
        $state->buffer = "";

        // We avoid instantiating a closure every time a socket read/write completes
        // without exposing a method in the public API this way ... it may look hacky
        // but it's important for performance.
        if (empty(self::$succeeder)) {
            $callable = (new \ReflectionClass($this))->getMethod("succeed")->getClosure($this);
            self::$succeeder = $callable;
        }
    }

    /**
     * Retrieve the socket identifier
     *
     * @return int
     */
    public function id() {
        return (int) $this->state->socket;
    }

    /**
     * Retrive info about the connected socket
     *
     * @return array
     */
    public function info() {
        return [
            "alive" => empty($this->state->isDead),
            "local_name" => $this->state->localName,
            "remote_name" => $this->state->remoteName,
            "bytes_read" => $this->state->bytesRead,
            "bytes_sent" => $this->state->bytesSent,
        ];
    }

    /**
     * Is the socket connection still valid?
     *
     * @return bool
     */
    public function alive() {
        $state = $this->state;
        if ($state->isDead) {
            return false;
        } elseif (!is_resource($state->socket)) {
            $state->isDead = true;
            return false;
        } elseif (@feof($state->socket)) {
            $state->isDead = true;
            return false;
        }

        return true;
    }

    /**
     * Read data from the socket
     *
     * If the optional size parameter is undefined the returned promise will resolve
     * as soon as readable data is available on the socket. If a size is specified
     * the returned pormise will not resolve until the specified number of bytes is
     * read or the socket disconnects.
     *
     * @param int $size Optional size in bytes
     * @return \Amp\Promise
     */
    public function read($size = null) {
        if (isset($size) && (!\is_int($size) || $size < 1)) {
            return new amp\Failure(new \InvalidArgumentException(
                "Invalid size; integer > 0 or null required"
            ));
        }

        $state = $this->state;
        if (!$state->readOperations && isset($state->buffer[$size === null ? 0 : $size-1])) {
            $data = \substr($state->buffer, 0, $size);
            $state->buffer = \substr($state->buffer, $size);

            return new amp\Success($data);
        }
        if (!$this->alive()) {
            return new amp\Success(null);
        }
        if (empty($state->isReadEnabled)) {
            amp\enable($state->readWatcherId);
            $state->isReadEnabled = true;
        }
        $op = new \StdClass;
        $op->size = $size;
        $op->promisor = $promisor = new amp\Deferred;
        $op->eol = null;

        $state->readOperations[] = $op;

        return $promisor->promise();
    }

    /**
     * Read data from the socket until an end-of-line is encountered (or EOF)
     *
     * All data up to and including the end-of-line character(s) is used to resolve
     * the returned promise.
     *
     * If a disconnection occurs prior to reaching the end of a line the returned
     * promise will resolve with whatever buffered data was received. The optional
     * limit parameter is useful in server environments where protection against
     * malicious memory over-use by clients is needed.
     *
     * @param int $limit An option size limit in bytes
     * @return \Amp\Promise
     */
    public function readLine($limit = null) {
        if (isset($size) && (!\is_int($size) || $size < 1)) {
            return new amp\Failure(new \InvalidArgumentException(
                "Invalid limit; integer > 0 or null required"
            ));
        }
        $state = $this->state;
        if (!$state->readOperations && (false !== ($eolPos = \strpos($state->buffer, \PHP_EOL)) || ($limit >= 0 && isset($state->buffer[$limit-1])))) {
            $length = $eolPos !== false ? $eolPos + \strlen(PHP_EOL) : false;
            $length = $length === false || ($limit > 0 && $length > $limit) ? $limit : $length;

            $data = \substr($state->buffer, 0, $length);
            $state->buffer = substr($state->buffer, $length);

            return new amp\Success($data);
        }
        if (!$this->alive()) {
            return new amp\Success(null);
        }
        if (empty($state->isReadEnabled)) {
            amp\enable($this->state->readWatcherId);
            $state->isReadEnabled = true;
        }
        $op = new \StdClass;
        $op->size = ($limit > 0) ? $limit : null;
        $op->promisor = $promisor = new amp\Deferred;
        $op->eol = \PHP_EOL;

        $state->readOperations[] = $op;
        $promise = $promisor->promise();
        $promise->when(static function($wid) use ($state) {
            if (empty($state->isReadEnabled)) {
                amp\disable($state->readWatcherId);
            }
        });

        return $promise;
    }

    /**
     * Write data to the socket
     *
     * Upon write completion the returned promise will resolve to an integer indicating
     * the number of bytes written. If no bytes were written prior to disconnection the
     * returned promise resolves to NULL.
     *
     * @param string $data
     * @return \Amp\Promise<int|null>
     */
    public function write($data) {
        $len = \strlen($data);
        if (!($len && \is_string($data))) {
            return new amp\Failure(new \LogicException(
                "String of minimum length 1 required"
            ));
        }
        $state = $this->state;
        if (!$this->alive()) {
            return new amp\Success(null);
        }
        if (empty($state->isWriteEnabled)) {
            amp\enable($this->state->writeWatcherId);
            $state->isWriteEnabled = true;
        }
        $op = new \StdClass;
        $op->buffer = $data;
        $op->size = $len;
        $op->bytesWritten = null;
        $op->promisor = $promisor = new amp\Deferred;

        $state->writeOperations[] = $op;

        return $promisor->promise();
    }

    /**
     * Manually close the client connection
     *
     * This method is not required as simply allowing the client object to be
     * garbage collected is sufficient to unload its associated resources.
     *
     * @return void
     */
    public function close() {
        $state = $this->state;
        if (\is_resource($state->socket)) {
            @\fclose($state->socket);
        }
        if ($state->isDead) {
            return;
        }

        amp\cancel($state->readWatcherId);
        amp\cancel($state->writeWatcherId);
        foreach ($state->readOperations as $op) {
            $op->promisor->succeed(null);
        }
        foreach ($state->writeOperations as $op) {
            $op->promisor->succeed($op->bytesWritten);
        }
    }

    private static function onRead($state) {
        $resolved = 0;
        foreach ($state->readOperations as $op) {
            if (isset($op->eol)) {
                $eolPos = \strpos($state->buffer, $op->eol);
                if ($eolPos !== false || ($op->size && \strlen($state->buffer) >= $op->size)) {
                    $length = $eolPos + \strlen($op->eol);
                    if ($op->size && $length > $op->size) {
                        $length = $op->size;
                    }

                    $chunk = \substr($state->buffer, 0, $length);
                    $state->buffer = \substr($state->buffer, $length);
                    $options = ["cb_data" => [$state, $op, $chunk]];
                    amp\immediately(self::$succeeder, $options);
                } else {
                    break;
                }
            } elseif ($op->size) {
                if (isset($state->buffer[$op->size-1])) {
                    $chunk = \substr($state->buffer, 0, $op->size);
                    $state->buffer = \substr($state->buffer, $op->size);
                    $options = ["cb_data" => [$state, $op, $chunk]];
                    amp\immediately(self::$succeeder, $options);
                } else {
                    break;
                }
            } else {
                if (isset($state->buffer[0])) {
                    $buffer = $state->buffer;
                    $state->buffer = "";
                    $options = ["cb_data" => [$state, $op, $buffer]];
                    amp\immediately(self::$succeeder, $options);
                } else {
                    break;
                }
            }

            ++$resolved;
        }

        $state->readOperations = array_slice($state->readOperations, $resolved);

        if (empty($state->readOperations)) {
            $state->isReadEnabled = false;
            amp\disable($state->readWatcherId);
        }
    }

    private static function succeed($wid, array $struct) {
        list($state, $op, $result) = $struct;
        $op->promisor->succeed($result);
    }

    private static function onEmptyRead($state) {

    if (\is_resource($state->socket) || !@\feof($state->socket))
    {
        \Notion\Log\Log::info('GOT EMPTY READ');
        \Notion\Log\Log::info('IS RESOURCE? ' . var_export(is_resource($state->socket), true));
        \Notion\Log\Log::info('IS EOF? ' . var_export(feof($state->socket), true));
    }

        if (!\is_resource($state->socket) || @\feof($state->socket)) {
            $state->isDead = true;
            amp\cancel($state->readWatcherId);

            // sink the buffer
            Client::onRead($state);
            // discard all readOperations that were left after buffer is empty
            foreach ($state->readOperations as $op) {
                $op->promisor->succeed();
            }
            $state->readOperations = [];
        }
    }

    private static function onWrite($state, $op, $bytes) {
        $op->bytesWritten += $bytes;
        if ($op->bytesWritten < $op->size) {
            $op->buffer = \substr($op->buffer, $bytes);
            return;
        }
        \array_shift($state->writeOperations);
        $options = ["cb_data" => [$state, $op, $op->bytesWritten]];
        amp\immediately(self::$succeeder, $options);
        if (empty($state->writeOperations)) {
            $state->isWriteEnabled = false;
            amp\disable($state->writeWatcherId);
        }
    }

    private static function onEmptyWrite($state) {

	if (\is_resource($state->socket) && !@\feof($state->socket))
	{
        \Notion\Log\Log::info('GOT EMPTY WRITE');
		\Notion\Log\Log::info('REMAINING SOCKET DATA: ' . @fread($state->socket, 1024));
	}

        if (!\is_resource($state->socket) || @\feof($state->socket)) {
            $state->isDead = true;
            amp\cancel($state->writeWatcherId);
            foreach ($state->writeOperations as $op) {
                $op->promisor->succeed($op->bytesWritten);
            }
            $state->writeOperations = [];
        }
    }

    /**
     * Automatically unload associated resources/watchers when garbage collected
     */
    public function __destruct() {
        $this->close();
    }
}
