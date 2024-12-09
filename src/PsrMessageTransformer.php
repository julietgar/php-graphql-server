<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL\Server;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

use function gettype;
use function html_entity_decode;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_str;
use function sprintf;

use const JSON_OBJECT_AS_ARRAY;
use const JSON_THROW_ON_ERROR;

final class PsrMessageTransformer
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /** @return OperationParams|array<OperationParams> */
    public function fromServerRequest(
        ServerRequestInterface $request,
    ) : OperationParams|array {
        $httpMethod = $request->getMethod();

        switch ($httpMethod) {
            case 'POST':
                return $this->parseBodyParams($request);

            case 'GET':
                return $this->parseQueryParams($request);

            default:
                throw new RequestError(sprintf('HTTP Method "%s" is not supported', $httpMethod));
        }
    }

    /** @param ExecutionResult|array<ExecutionResult> $result */
    public function toResponse(
        ExecutionResult|array $result,
    ) : ResponseInterface {
        $body = $this->streamFactory->createStream();

        $body->write(json_encode($result, JSON_THROW_ON_ERROR));

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }

    /** @return OperationParams|array<OperationParams> */
    private function parseBodyParams(
        ServerRequestInterface $request,
    ) : OperationParams|array {
        $contentType = $request->getHeader('content-type');

        if (! isset($contentType[0])) {
            throw new RequestError('Missing "Content-Type" header');
        }

        switch ($contentType[0]) {
            case 'application/graphql':
                $bodyParams = [
                    'query' => (string) $request->getBody(),
                ];

                break;
            case 'application/json':
                try {
                    $bodyParams = json_decode(
                        json: (string) $request->getBody(),
                        flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR,
                    );
                } catch (Throwable $t) {
                    throw new RequestError(sprintf(
                        'Expected JSON object or array for "application/json" request, but failed to parse because: %s',
                        $t->getMessage(),
                    ));
                }

                if (is_array($bodyParams) === false) {
                    throw new RequestError(sprintf(
                        'Expected JSON object or array for "application/json" request, but received %s',
                        gettype($bodyParams),
                    ));
                }

                break;
            default:
                throw new RequestError(sprintf('Content-Type "%s" is not supported', $contentType[0]));
        }

        if (isset($bodyParams[0])) {
            $operations = [];
            foreach ($bodyParams as $entry) {
                $operations[] = OperationParams::create($entry);
            }

            return $operations;
        }

        return OperationParams::create($bodyParams);
    }

    private function parseQueryParams(
        ServerRequestInterface $request,
    ) : OperationParams {
        parse_str(html_entity_decode($request->getUri()->getQuery()), $queryParams);

        // @phpstan-ignore argument.type
        return OperationParams::create($queryParams, true);
    }
}
