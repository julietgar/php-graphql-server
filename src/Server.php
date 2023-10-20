<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Server
{
    public function __construct(
        private readonly PsrMessageTransformer $psr,
        private readonly Operator $operator,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
        mixed $context,
    ) : ResponseInterface {
        return $this->psr->toPsrResponse(
            $this->operator->execute(
                $context,
                $this->psr->fromPsrServerRequest($request),
            ),
        );
    }
}
