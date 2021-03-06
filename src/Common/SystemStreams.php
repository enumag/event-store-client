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

namespace Prooph\EventStoreClient\Common;

class SystemStreams
{
    public const PERSISTENT_SUBSCRIPTION_CONFIG = '$persistentSubscriptionConfig';
    public const ALL_STREAM = '$all';
    public const STREAMS_STREAM = '$streams';
    public const SETTINGS_STREAM = '$settings';
    public const STATS_STREAM_PREFIX = '$stats';
    public const SCAVANGE_STREAM = '$scavenges';

    public static function metastreamOf(string $streamId): string
    {
        return '$$' . $streamId;
    }

    public static function isMetastream(string $streamId): bool
    {
        return \strlen($streamId) > 1 && \substr($streamId, 0, 2) === '$$';
    }

    public static function originalStreamOf(string $metastreamId): string
    {
        return \substr($metastreamId, 2);
    }

    public static function isSystemStream(string $streamId): bool
    {
        return \strlen($streamId) !== 0 && $streamId[0] === '$';
    }
}
