<?php

declare(strict_types=1);

namespace Kurzyx\AsyncMessengerBundle\Subscriber;

use Kurzyx\AsyncMessengerBundle\AsyncReceiverInterface;
use Kurzyx\AsyncMessengerBundle\TerminableAsyncReceiverInterface;
use React\EventLoop\LoopInterface as EventLoopInterface;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

/**
 * This event subscriber allows for envelopes to be received asynchronously (and immediately) when the worker is idle.
 *
 * Whenever the worker starts being idle, the event-loop is started until a receiver calls that there's at least one
 * envelope pending. The event-loop is then stopped (after one more tick), which in turn will resume the worker to
 * get all the pending envelopes from the receivers. Once all envelopes are handled, and no more pending envelopes
 * remain, the worker starts being idle again.
 *
 * Note that this only works if the worker is run using the {@see ConsumeMessagesCommand} command.
 *
 * There is one flaw in this design, which may make this a bit unreliable. If an envelope is acknowledges/rejected in an
 * async manner (if it depends on the event-loop), and the PHP process crashes. The acknowledgement/rejection is never
 * sent to the server. This isn't necessarily different to normal behaviour, however multiple envelopes may be handled
 * before the event-loop is resumed. So the chance of this happening is greater.
 * A solution to this would be to synchronously acknowledge/reject the envelope, however this depends on how the sender
 * implements this behaviour.
 */
final class HandleIdleMessageWorkerSubscriber implements EventSubscriberInterface, ResetInterface
{
    private EventLoopInterface $eventLoop;

    private ?Worker $currentWorker = null;
    private float $idleDuration = 0.0;

    public function __construct(EventLoopInterface $eventLoop)
    {
        $this->eventLoop = $eventLoop;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerStoppedEvent::class => 'onWorkerStopped',
            ConsoleEvents::COMMAND    => 'onConsoleCommand',
            ConsoleEvents::TERMINATE  => 'onConsoleTerminate',
            KernelEvents::TERMINATE   => 'onKernelTerminate',
        ];
    }

    public function reset()
    {
        $this->currentWorker = null;
        $this->idleDuration = 0;
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if ($this->currentWorker !== null) {
            throw new RuntimeException('Unable to start a worker within a worker.');
        }

        $this->currentWorker = $worker = $event->getWorker();

        foreach ($this->getReceivers($worker) as $receiver) {
            if ($receiver instanceof AsyncReceiverInterface) {
                $receiver->setOnEnvelopePendingCallback(fn() => $this->onEnvelopeReady());
            }
        }
    }

    private function onEnvelopeReady(): void
    {
        // Stop the event loop so that the message worker can handle the envelope(s).
        // This does not interrupt the current event-loop tick, so it is possible that there's multiple envelope's
        // ready if they were all received within a single tick.
        $this->eventLoop->stop();
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (! $event->isWorkerIdle()) {
            return;
        }

        if ($this->currentWorker !== $event->getWorker()) {
            throw new RuntimeException('A worker is running that is not the current worker.');
        }

        $interruptTimer = null;

        // Stop the async consumers after the configured idle duration. We do this so that the non-async receivers can
        // run at the specified interval.
        // If there's no idle duration at all, then we can run the event-loop until an envelope is ready.
        if ($this->idleDuration > 0.0) {
            $interruptTimer = $this->eventLoop->addTimer(
                $this->idleDuration,
                fn() => $this->eventLoop->stop()
            );
        }

        try {
            $this->eventLoop->run();
        } finally {
            if ($interruptTimer !== null) {
                // Always cancel the timer after the loop has stopped. We do this to prevent duplicate duration-timers
                // when resuming the event-loop. This could otherwise happen if the event-loop is for some reason
                // stopped before the duration-timer is triggered.
                $this->eventLoop->cancelTimer($interruptTimer);
            }
        }
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if ($this->currentWorker !== $event->getWorker()) {
            throw new RuntimeException('A worker has stopped that is not the current worker.');
        }

        $this->terminateCurrentWorker();
    }

    public function onConsoleTerminate(): void
    {
        // It may be that the current worker was not stopped normally, e.g. due to an exception. If so, we can still
        // terminate the current worker from here.
        if ($this->currentWorker !== null) {
            $this->terminateCurrentWorker();
        }
    }

    public function onKernelTerminate(): void
    {
        // It may be that the current worker was not stopped normally, e.g. due to an exception. If so, we can still
        // terminate the current worker from here.
        if ($this->currentWorker !== null) {
            $this->terminateCurrentWorker();
        }
    }

    private function terminateCurrentWorker(): void
    {
        $worker = $this->currentWorker;
        $this->currentWorker = null;

        foreach ($this->getReceivers($worker) as $receiver) {
            if ($receiver instanceof AsyncReceiverInterface) {
                $receiver->setOnEnvelopePendingCallback(null);
            }

            // Prevent the receiver from receiving any more envelopes. Do this before running the event-loop again.
            if ($receiver instanceof TerminableAsyncReceiverInterface) {
                $receiver->terminateAsync();
            }
        }

        // Once the worker has stopped, it may be that an acknowledgment or rejection was not sent to the server yet.
        // This could happen if the implementation for ack/rejection depends on an event-loop tick in order to be sent.
        // Additionally, the consumers that are terminated may need to tell the server that this has happened.
        // To solve this problem, run the event-loop one more tick to send the remaining packages to the server.
        $this->eventLoop->futureTick(fn() => $this->eventLoop->stop());
        $this->eventLoop->run();
    }

    /**
     * @throws ReflectionException
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if (! $event->getCommand() instanceof ConsumeMessagesCommand) {
            return;
        }

        $input = $event->getInput();

        $sleepDuration = (float) $input->getOption('sleep');
        if ($sleepDuration === 0.0) {
            // TODO: Only warn if there's receivers that are not async...

            (new SymfonyStyle($event->getInput(), $event->getOutput()))
                ->warning('Running this command with no sleep duration will block your CPU. Don\'t do this!');
        }

        // We're going to sleep using Amp, so tell the original worker to not sleep at all.
        // Symfony apparently doesn't allow us to override the input arguments easily, so we have to use reflection.
        if ($input instanceof ArgvInput) {
            $this->replaceOrAppendArgument($input, 'tokens', '--sleep', '0');
        } else if ($input instanceof ArrayInput) {
            $this->replaceOrAppendArgument($input, 'parameters', '--sleep', '0');
        }

        $this->idleDuration = $sleepDuration;
    }

    /**
     * @throws ReflectionException
     */
    private function replaceOrAppendArgument(InputInterface $input, string $propertyName, string $argumentName, string $replacementValue): void
    {
        $property = new ReflectionProperty(get_class($input), $propertyName);
        $property->setAccessible(true);

        $arguments = $property->getValue($input);

        if ($input->hasParameterOption($argumentName)) {
            $argumentIndex = array_search($argumentName, $arguments, true);
            if ($argumentIndex !== false) {
                array_splice($arguments, $argumentIndex, 2);
            }

            $argumentIndex = array_search("$argumentName={$input->getParameterOption($argumentName)}", $arguments, true);
            if ($argumentIndex !== false) {
                array_splice($arguments, $argumentIndex, 1);
            }
        }

        $arguments[] = $argumentName;
        $arguments[] = $replacementValue;

        $property->setValue($input, $arguments);
    }

    /**
     * @return ReceiverInterface[]
     */
    private function getReceivers(Worker $worker): array
    {
        // Unfortunately the worker does not expose the receivers, so we have to use reflection to access these.
        $receiversProperty = new ReflectionProperty(Worker::class, 'receivers');
        $receiversProperty->setAccessible(true);

        return $receiversProperty->getValue($worker);
    }
}
