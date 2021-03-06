<?php

/**
 * This file is part of `prooph/event-store-client`.
 * (c) 2018-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2018-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStoreClient\ClientOperations;

use Amp\Deferred;
use Prooph\EventStoreClient\EventAppearedOnSubscription;
use Prooph\EventStoreClient\EventId;
use Prooph\EventStoreClient\EventStoreSubscription;
use Prooph\EventStoreClient\Exception\AccessDeniedException;
use Prooph\EventStoreClient\Exception\InvalidArgumentException;
use Prooph\EventStoreClient\Exception\MaximumSubscribersReachedException;
use Prooph\EventStoreClient\Exception\PersistentSubscriptionDeletedException;
use Prooph\EventStoreClient\Internal\ConnectToPersistentSubscriptions;
use Prooph\EventStoreClient\Internal\EventMessageConverter;
use Prooph\EventStoreClient\Internal\PersistentEventStoreSubscription;
use Prooph\EventStoreClient\Messages\ClientMessages\ConnectToPersistentSubscription;
use Prooph\EventStoreClient\Messages\ClientMessages\PersistentSubscriptionAckEvents;
use Prooph\EventStoreClient\Messages\ClientMessages\PersistentSubscriptionConfirmation;
use Prooph\EventStoreClient\Messages\ClientMessages\PersistentSubscriptionNakEvents;
use Prooph\EventStoreClient\Messages\ClientMessages\PersistentSubscriptionStreamEventAppeared;
use Prooph\EventStoreClient\Messages\ClientMessages\SubscriptionDropped as SubscriptionDroppedMessage;
use Prooph\EventStoreClient\Messages\ClientMessages\SubscriptionDropped_SubscriptionDropReason as SubscriptionDropReasonMessage;
use Prooph\EventStoreClient\PersistentSubscriptionNakEventAction;
use Prooph\EventStoreClient\PersistentSubscriptionResolvedEvent;
use Prooph\EventStoreClient\SubscriptionDropped;
use Prooph\EventStoreClient\SubscriptionDropReason;
use Prooph\EventStoreClient\SystemData\InspectionDecision;
use Prooph\EventStoreClient\SystemData\InspectionResult;
use Prooph\EventStoreClient\SystemData\TcpCommand;
use Prooph\EventStoreClient\SystemData\TcpFlags;
use Prooph\EventStoreClient\SystemData\TcpPackage;
use Prooph\EventStoreClient\UserCredentials;
use Psr\Log\LoggerInterface as Logger;

/** @internal */
class ConnectToPersistentSubscriptionOperation extends AbstractSubscriptionOperation implements ConnectToPersistentSubscriptions
{
    /** @var string */
    private $groupName;
    /** @var int */
    private $bufferSize;
    /** @var string */
    private $subscriptionId;

    public function __construct(
        Logger $logger,
        Deferred $deferred,
        string $groupName,
        int $bufferSize,
        string $streamId,
        ?UserCredentials $userCredentials,
        EventAppearedOnSubscription $eventAppeared,
        ?SubscriptionDropped $subscriptionDropped,
        bool $verboseLogging,
        callable $getConnection
    ) {
        parent::__construct(
            $logger,
            $deferred,
            $streamId,
            false,
            $userCredentials,
            $eventAppeared,
            $subscriptionDropped,
            $verboseLogging,
            $getConnection
        );

        $this->groupName = $groupName;
        $this->bufferSize = $bufferSize;
    }

    protected function createSubscriptionPackage(): TcpPackage
    {
        $message = new ConnectToPersistentSubscription();
        $message->setEventStreamId($this->streamId);
        $message->setSubscriptionId($this->groupName);
        $message->setAllowedInFlightMessages($this->bufferSize);

        $login = null;
        $pass = null;

        if ($this->userCredentials) {
            $login = $this->userCredentials->username();
            $pass = $this->userCredentials->password();
        }

        return new TcpPackage(
            TcpCommand::connectToPersistentSubscription(),
            $this->userCredentials ? TcpFlags::authenticated() : TcpFlags::none(),
            $this->correlationId,
            $message->serializeToString(),
            $login,
            $pass
        );
    }

    protected function preInspectPackage(TcpPackage $package): ?InspectionResult
    {
        if ($package->command()->equals(TcpCommand::persistentSubscriptionConfirmation())) {
            $message = new PersistentSubscriptionConfirmation();
            $message->parseFromString($package->data());

            $this->confirmSubscription($message->getLastCommitPosition(), $message->getLastEventNumber());
            $this->subscriptionId = $message->getSubscriptionId();

            return new InspectionResult(InspectionDecision::subscribed(), 'SubscriptionConfirmation');
        }

        if ($package->command()->equals(TcpCommand::persistentSubscriptionStreamEventAppeared())) {
            $message = new PersistentSubscriptionStreamEventAppeared();
            $message->parseFromString($package->data());

            $event = EventMessageConverter::convertResolvedIndexedEventMessageToResolvedEvent($message->getEvent());
            $this->eventAppeared(new PersistentSubscriptionResolvedEvent($event, $message->getRetryCount()));

            return new InspectionResult(InspectionDecision::doNothing(), 'StreamEventAppeared');
        }

        if ($package->command()->equals(TcpCommand::subscriptionDropped())) {
            $message = new SubscriptionDroppedMessage();
            $message->parseFromString($package->data());

            if ($message->getReason() === SubscriptionDropReasonMessage::AccessDenied) {
                $this->dropSubscription(SubscriptionDropReason::accessDenied(), new AccessDeniedException('You do not have access to the stream'));

                return new InspectionResult(InspectionDecision::endOperation(), 'SubscriptionDropped');
            }

            if ($message->getReason() === SubscriptionDropReasonMessage::NotFound) {
                $this->dropSubscription(SubscriptionDropReason::notFound(), new InvalidArgumentException('Subscription not found'));

                return new InspectionResult(InspectionDecision::endOperation(), 'SubscriptionDropped');
            }

            if ($message->getReason() === SubscriptionDropReasonMessage::PersistentSubscriptionDeleted) {
                $this->dropSubscription(SubscriptionDropReason::persistentSubscriptionDeleted(), new PersistentSubscriptionDeletedException());

                return new InspectionResult(InspectionDecision::endOperation(), 'SubscriptionDropped');
            }

            if ($message->getReason() === SubscriptionDropReasonMessage::SubscriberMaxCountReached) {
                $this->dropSubscription(SubscriptionDropReason::maxSubscribersReached(), new MaximumSubscribersReachedException());

                return new InspectionResult(InspectionDecision::endOperation(), 'SubscriptionDropped');
            }

            $this->dropSubscription(SubscriptionDropReason::byValue($message->getReason()), null, ($this->getConnection)());

            return new InspectionResult(InspectionDecision::endOperation(), 'SubscriptionDropped');
        }

        return null;
    }

    protected function createSubscriptionObject(int $lastCommitPosition, ?int $lastEventNumber): EventStoreSubscription
    {
        return new PersistentEventStoreSubscription(
            $this,
            $this->streamId,
            $lastCommitPosition,
            $lastEventNumber
        );
    }

    /** @param EventId[] $eventIds */
    public function notifyEventsProcessed(array $eventIds): void
    {
        if (empty($eventIds)) {
            throw new InvalidArgumentException('EventIds cannot be empty');
        }

        $message = new PersistentSubscriptionAckEvents();
        $message->setSubscriptionId($this->subscriptionId);

        foreach ($eventIds as $eventId) {
            $message->appendProcessedEventIds($eventId->toBinary());
        }

        $login = null;
        $pass = null;

        if ($this->userCredentials) {
            $login = $this->userCredentials->username();
            $pass = $this->userCredentials->password();
        }

        $package = new TcpPackage(
            TcpCommand::persistentSubscriptionAckEvents(),
            $this->userCredentials ? TcpFlags::authenticated() : TcpFlags::none(),
            $this->correlationId,
            $message->serializeToString(),
            $login,
            $pass
        );

        $this->enqueueSend($package);
    }

    /**
     * @param EventId[] $eventIds
     * @param PersistentSubscriptionNakEventAction $action
     * @param string $reason
     */
    public function notifyEventsFailed(
        array $eventIds,
        PersistentSubscriptionNakEventAction $action,
        string $reason
    ): void {
        if (empty($eventIds)) {
            throw new InvalidArgumentException('EventIds cannot be empty');
        }

        $message = new PersistentSubscriptionNakEvents();
        $message->setSubscriptionId($this->subscriptionId);
        $message->setMessage($reason);
        $message->setAction($action->value());

        foreach ($eventIds as $eventId) {
            $message->appendProcessedEventIds($eventId->toBinary());
        }

        $login = null;
        $pass = null;

        if ($this->userCredentials) {
            $login = $this->userCredentials->username();
            $pass = $this->userCredentials->password();
        }

        $package = new TcpPackage(
            TcpCommand::persistentSubscriptionNakEvents(),
            $this->userCredentials ? TcpFlags::authenticated() : TcpFlags::none(),
            $this->correlationId,
            $message->serializeToString(),
            $login,
            $pass
        );

        $this->enqueueSend($package);
    }

    public function name(): string
    {
        return 'ConnectToPersistentSubscription';
    }

    public function __toString(): string
    {
        return \sprintf(
            'StreamId: %s, ResolveLinkTos: %s, GroupName: %s, BufferSize: %d, SubscriptionId: %s',
            $this->streamId,
            $this->resolveLinkTos ? 'yes' : 'no',
            $this->groupName,
            $this->bufferSize,
            $this->subscriptionId
        );
    }
}
