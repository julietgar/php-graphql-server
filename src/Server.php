<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Server
{
    public function __construct(
        private readonly PsrMessageTransformer $psrTransformer,
        private readonly Operator $operator,
        private readonly ServerConfig $config,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
        Context $context,
    ) : ResponseInterface {
        return $this->psrTransformer->toResponse(
            $this->operator->execute(
                $context,
                $this->config,
                $this->psrTransformer->fromServerRequest($request),
            ),
        );
    }
}
