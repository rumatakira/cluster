<?php

/** @noinspection PhpUndefinedClassInspection CallableMaker */

namespace Amp\Cluster;

use Amp\CallableMaker;
use Amp\Deferred;
use Amp\MultiReasonException;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\Process;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\asyncCall;
use function Amp\call;

final class Watcher
{
    use CallableMaker;

    const WORKER_TIMEOUT = 5000;

    /** @var resource[] */
    private $sockets = [];

    /** @var bool */
    private $running = false;

    /** @var string[] */
    private $script;

    /** @var PsrLogger */
    private $logger;

    /** @var string Socket server URI */
    private $uri;

    /** @var Server */
    private $server;

    /** @var callable */
    private $bind;

    /** @var \SplObjectStorage */
    private $workers;

    /** @var callable[][] */
    private $onMessage = [];

    /** @var Deferred|null */
    private $deferred;

    /** @var Promise|null */
    private $startPromise;

    /**
     * @param string|string[]  $script Script path and optional arguments.
     * @param PsrLogger $logger
     */
    public function __construct($script, PsrLogger $logger)
    {
        if (Cluster::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        if (!canReusePort() && !\extension_loaded("sockets")) {
            throw new \Error("The sockets extension is required to create clusters on this system");
        }

        $this->logger = $logger;
        $this->uri = "unix://" . \tempnam(\sys_get_temp_dir(), "amp-cluster-ipc-") . ".sock";

        $this->script = \array_merge(
            [__DIR__ . '/Internal/cluster-runner.php', $this->uri],
            \is_array($script) ? \array_values(\array_map("\\strval", $script)) : [(string) $script]
        );

        $this->workers = new \SplObjectStorage;

        /** @noinspection PhpDeprecationInspection */
        $this->bind = $this->callableFromInstanceMethod("bindSocket");
    }

    public function __destruct()
    {
        if ($this->running) {
            $this->stop();
        }
    }

    /**
     * Attaches a callback to be invoked when a message is received from a worker process.
     *
     * @param string   $event
     * @param callable $callback
     */
    public function onMessage(string $event, callable $callback)
    {
        $this->onMessage[$event][] = $callback;
    }

    /**
     * @param int $count Number of cluster workers to spawn.
     *
     * @return Promise Resolved when the cluster has stopped.
     */
    public function run(int $count): Promise
    {
        if ($this->running) {
            throw new \Error("The cluster is already running");
        }

        $this->server = Socket\listen($this->uri);

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->deferred = new Deferred;
        $this->running = true;

        return call(function () use ($count) {
            try {
                $promises = [];
                for ($i = 0; $i < $count; ++$i) {
                    $promises[] = $this->startWorker();
                }
                yield Promise\all($promises);
            } catch (\Throwable $exception) {
                $this->stop();
            }

            return $this->deferred->promise();
        });
    }

    private function startWorker(): Promise
    {
        return $this->startPromise = call(function () {
            if ($this->startPromise) {
                yield $this->startPromise; // Wait for previous worker to start, required for IPC socket identification.
            }

            $process = new Process($this->script);
            yield $process->start();

            try {
                $socket = yield Promise\timeout($this->server->accept(), self::WORKER_TIMEOUT);
            } catch (\Throwable $exception) {
                if ($process->isRunning()) {
                    $process->kill();
                }

                throw new ClusterException("Starting the cluster worker failed", 0, $exception);
            }

            \assert($socket instanceof Socket\ServerSocket);

            $worker = new Internal\IpcParent($process, $socket, $this->bind, function (string $event, $data) {
                foreach ($this->onMessage[$event] ?? [] as $callback) {
                    asyncCall($callback, $data);
                }
            });

            $stdout = call(function () use ($process) {
                $stream = $process->getStdout();
                $stream->unreference();
                while (null !== $chunk = yield $stream->read()) {
                    $this->logger->info($chunk);
                }
            });

            $stderr = call(function () use ($process) {
                $stream = $process->getStderr();
                $stream->unreference();
                while (null !== $chunk = yield $stream->read()) {
                    $this->logger->error($chunk);
                }
            });

            $this->workers->attach($worker, [$process, $runner = $worker->run()]);

            $promises = [$runner, $stdout, $stderr];

            asyncCall(function () use ($worker, $promises) {
                try {
                    yield Promise\all($promises); // Wait for worker to exit.
                    $this->logger->info("Worker terminated cleanly" . ($this->running ? ", restarting..." : "."));
                } catch (ContextException $exception) {
                    $this->logger->error("Worker died unexpectedly" . ($this->running ? ", restarting..." : "."));
                } catch (\Throwable $exception) {
                    $this->logger->error((string) $exception);
                } finally {
                    $this->workers->detach($worker);
                }

                if ($this->running) {
                    try {
                        yield $this->startWorker();
                    } catch (\Throwable $exception) {
                        $deferred = $this->deferred;
                        $this->deferred = null;
                        $deferred->fail(new ClusterException("Restarting a worker failed", 0, $exception));
                        $this->stop();
                    }
                }
            });
        });
    }

    /**
     * Stops the cluster.
     */
    public function stop()
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        $promise = call(function () {
            $promises = [];

            /** @var Internal\IpcParent $worker */
            foreach ($this->workers as $worker) {
                $promises[] = call(function () use ($worker) {
                    /** @var Process $process */
                    list($process, $promise) = $this->workers[$worker];

                    try {
                        yield $process->send(null);
                        yield Promise\timeout($promise, self::WORKER_TIMEOUT);
                    } catch (\Throwable $exception) {
                        if ($process->isRunning()) {
                            $process->kill();
                        }

                        if (!$exception instanceof ContextException) {
                            throw $exception;
                        }
                    }
                });
            }

            list($exceptions) = yield Promise\any($promises);

            $this->server->close();

            $this->workers = new \SplObjectStorage;

            if (!empty($exceptions)) {
                $exception = new MultiReasonException($exceptions);
                throw new ClusterException("Stopping the cluster failed", 0, $exception);
            }
        });

        if ($this->deferred) {
            $deferred = $this->deferred;
            $this->deferred = null;
            $deferred->resolve($promise);
        }
    }

    /**
     * Broadcast data to all workers, triggering any callbacks registered with Cluster::onMessage().
     *
     * @param mixed $data
     *
     * @return Promise Resolved once data has been sent to all workers.
     */
    public function broadcast($data): Promise
    {
        $promises = [];
        /** @var Internal\IpcParent $worker */
        foreach ($this->workers as $worker) {
            $promises[] = $worker->send($data);
        }
        return Promise\all($promises);
    }

    /* @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param string $uri
     *
     * @return resource Stream socket server resource.
     */
    private function bindSocket(string $uri)
    {
        if (isset($this->sockets[$uri])) {
            return $this->sockets[$uri];
        }

        if (!\strncmp($uri, "unix://", 7)) {
            @\unlink(\substr($uri, 7));
        }

        $context = \stream_context_create([
            "socket" => [
                "so_reuseaddr" => \stripos(PHP_OS, "WIN") === 0, // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                "so_reuseport" => canReusePort(),
                "ipv6_v6only" => true,
            ],
        ]);

        // Do NOT use STREAM_SERVER_LISTEN here - we explicitly invoke \socket_listen() in our worker processes
        if (!$socket = \stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context)) {
            throw new \RuntimeException(\sprintf("Failed binding socket on %s: [Err# %s] %s", $uri, $errno, $errstr));
        }

        return $this->sockets[$uri] = $socket;
    }
}
