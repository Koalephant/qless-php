<?php

namespace Qless\Tests\Support;

/**
 * Qless\Tests\Support\BackgroundProcessTrait
 *
 * @package Qless\Tests
 */
trait BackgroundProcessTrait
{

    /**
     * @var false|resource
     */
    protected $process;

    /**
     * @var array
     */
    protected $pipes;

    protected function stopBackgroundTask(): void
    {
        foreach ($this->pipes as $pipe) {
            if (! is_resource($pipe)) {
                continue;
            }
            fclose($pipe);
        }

        proc_terminate($this->process);
        $this->process = null;
        $this->pipes = null;
    }

    protected function getBackgroundStdOut(): string
    {
        if (is_resource($this->pipes[1]) && get_resource_type($this->pipes[1]) !== 'Unknown') {
            stream_set_blocking($this->pipes[1], true);
        }

        return stream_get_contents($this->pipes[1]);
    }

    protected function runBackgroundScript(string $file, array $arguments = [], ?string $errorLog = null): void
    {

        $command = 'php';
        \array_unshift($arguments, $file);

        $this->process = proc_open(
            escapeshellcmd($command) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)),
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                $errorLog ? ['file', $errorLog, 'a'] : ['pipe', 'w']
            ],
            $this->pipes,
            getcwd()
        );
        stream_set_blocking($this->pipes[1], false);
        if (! $errorLog) {
            stream_set_blocking($this->pipes[2], false);
        }
    }
}
