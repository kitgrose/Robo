<?php
namespace Robo\Task\Base;

use Robo\Contract\CommandInterface;
use Robo\Contract\PrintedInterface;
use Robo\Result;
use Robo\Task\BaseTask;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Class ParallelExecTask
 *
 * ``` php
 * <?php
 * $this->taskParallelExec()
 *   ->process('php ~/demos/script.php hey')
 *   ->process('php ~/demos/script.php hoy')
 *   ->process('php ~/demos/script.php gou')
 *   ->run();
 * ?>
 * ```
 */
class ParallelExec extends BaseTask implements CommandInterface, PrintedInterface
{
    use \Robo\Common\CommandReceiver;

    /**
     * @var Process[]
     */
    protected $processes = [];

    /**
     * @var null|int
     */
    protected $timeout = null;

    /**
     * @var null|int
     */
    protected $idleTimeout = null;

    /**
     * @var bool
     */
    protected $isPrinted = false;

    /**
     * {@inheritdoc}
     */
    public function getPrinted()
    {
        return $this->isPrinted;
    }

    /**
     * @param bool $isPrinted
     *
     * @return $this
     */
    public function printed($isPrinted = true)
    {
        $this->isPrinted = $isPrinted;
        return $this;
    }

    /**
     * @param string|\Robo\Contract\CommandInterface $command
     *
     * @return $this
     */
    public function process($command)
    {
        $this->processes[] = new Process($this->receiveCommand($command));
        return $this;
    }

    /**
     * Stops process if it runs longer then `$timeout` (seconds).
     *
     * @param int $timeout
     *
     * @return $this
     */
    public function timeout($timeout)
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Stops process if it does not output for time longer then `$timeout` (seconds).
     *
     * @param int $idleTimeout
     *
     * @return $this
     */
    public function idleTimeout($idleTimeout)
    {
        $this->idleTimeout = $idleTimeout;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getCommand()
    {
        return implode(' && ', $this->processes);
    }

    /**
     * @return int
     */
    public function progressIndicatorSteps()
    {
        return count($this->processes);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        foreach ($this->processes as $process) {
            $process->setIdleTimeout($this->idleTimeout);
            $process->setTimeout($this->timeout);
            $process->start();
            $this->printTaskInfo($process->getCommandLine());
        }

        $this->startProgressIndicator();
        $running = $this->processes;
        while (true) {
            foreach ($running as $k => $process) {
                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException $e) {
                    $this->printTaskWarning("Process timed out for {command}", ['command' => $process->getCommandLine(), '_style' => ['command' => 'fg=white;bg=magenta']]);
                }
                if (!$process->isRunning()) {
                    $this->advanceProgressIndicator();
                    if ($this->isPrinted) {
                        $this->printTaskInfo("Output for {command}:\n\n{output}", ['command' => $process->getCommandLine(), 'output' => $process->getOutput(), '_style' => ['command' => 'fg=white;bg=magenta']]);
                        $errorOutput = $process->getErrorOutput();
                        if ($errorOutput) {
                            $this->printTaskError(rtrim($errorOutput));
                        }
                    }
                    unset($running[$k]);
                }
            }
            if (empty($running)) {
                break;
            }
            usleep(1000);
        }
        $this->stopProgressIndicator();

        $errorMessage = '';
        $exitCode = 0;
        foreach ($this->processes as $p) {
            if ($p->getExitCode() === 0) {
                continue;
            }
            $errorMessage .= "'" . $p->getCommandLine() . "' exited with code ". $p->getExitCode()." \n";
            $exitCode = max($exitCode, $p->getExitCode());
        }
        if (!$errorMessage) {
            $this->printTaskSuccess('{process-count} processes finished running', ['process-count' => count($this->processes)]);
        }

        return new Result($this, $exitCode, $errorMessage, ['time' => $this->getExecutionTime()]);
    }
}
