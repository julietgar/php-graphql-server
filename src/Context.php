<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL\Server;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

use function array_key_exists;
use function count;

final class Context implements IteratorAggregate, Countable
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        ServerRequestInterface $request,
        private array $parameters = [],
    ) {
        $this->parameters['request'] = $request;
    }

    public function get(
        string $key,
        mixed $default = null,
    ) : mixed {
        return array_key_exists($key, $this->parameters)
            ? $this->parameters[$key]
            : $default;
    }

    public function has(
        string $key,
    ) : bool {
        return array_key_exists($key, $this->parameters);
    }

    public function set(
        string $key,
        mixed $value,
    ) : void {
        if ($key === 'request') {
            throw new RuntimeException('The \'request\' parameter is read-only and can not be modified');
        }

        $this->parameters[$key] = $value;
    }

    /** @return ArrayIterator<string, mixed> */
    public function getIterator() : ArrayIterator
    {
        return new ArrayIterator($this->parameters);
    }

    public function count() : int
    {
        return count($this->parameters);
    }
}
