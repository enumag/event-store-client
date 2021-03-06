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

namespace Prooph\EventStoreClient\Internal;

use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use Amp\Uri\InvalidUriException;
use Amp\Uri\Uri;
use Prooph\EventStoreClient\EndPoint;

/** @internal */
final class SingleEndpointDiscoverer implements EndPointDiscoverer
{
    /** @var string */
    private $connectionString;
    /** @var bool */
    private $useSslConnection;

    public function __construct(string $connectionString, bool $useSslConnection)
    {
        $this->connectionString = $connectionString;
        $this->useSslConnection = $useSslConnection;
    }

    public function discoverAsync(?EndPoint $failedTcpEndPoint): Promise
    {
        try {
            $uri = new Uri($this->connectionString);
        } catch (InvalidUriException $e) {
            return new Failure($e);
        }

        $endPoint = new EndPoint($uri->getHost(), $uri->getPort());

        return new Success(
            new NodeEndPoints(
                $this->useSslConnection ? null : $endPoint,
                $this->useSslConnection ? $endPoint : null
            )
        );
    }
}
