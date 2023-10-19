<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL;

use Exception;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

use function is_array;

final class GraphQLServer
{
    public function __construct(
        private readonly ServerConfig $config,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
        mixed $context,
    ) : ResponseInterface | Promise {
        $parsedBody = $this->parsePsrRequest($request);

        return $this->toPsrResponse(
            $this->executeRequest($context, $parsedBody),
            $this->responseFactory->createResponse(),
            $this->streamFactory->createStream(),
        );
    }

    /**
     * Executes a GraphQL operation and returns an execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter).
     *
     * When $parsedBody is not set, it uses PHP globals to parse a request.
     * It is possible to implement request parsing elsewhere (e.g. using framework Request instance)
     * and then pass it to the server.
     *
     * PSR-7 compatible method executePsrRequest() does exactly this.
     *
     * @param OperationParams|array<OperationParams> $parsedBody
     *
     * @return ExecutionResult|array<int, ExecutionResult>|Promise
     *
     * @throws Exception
     * @throws InvariantViolation
     * @throws RequestError
     */
    private function executeRequest(
        mixed $context,
        OperationParams | array $parsedBody,
    ) : ExecutionResult | array | Promise {
        if (is_array($parsedBody)) {
            return $this->executeBatch($context, $parsedBody);
        }

        return $this->executeOperation($context, $parsedBody);
    }

    /**
     * Converts PSR-7 request to OperationParams or an array thereof.
     *
     * @throws RequestError
     *
     * @return OperationParams|array<OperationParams>
     *
     * @api
     */
    private function parsePsrRequest(
        ServerRequestInterface $request
    ) {
        if ($request->getMethod() === 'GET') {
            $bodyParams = [];
        } else {
            $contentType = $request->getHeader('content-type');

            if (! isset($contentType[0])) {
                throw new RequestError('Missing "Content-Type" header');
            }

            if (\stripos($contentType[0], 'application/graphql') !== false) {
                $bodyParams = ['query' => (string) $request->getBody()];
            } elseif (\stripos($contentType[0], 'application/json') !== false) {
                $bodyParams = $request->getParsedBody();

                $this->assertJsonObjectOrArray($bodyParams);
            } else {
                $bodyParams = $request->getParsedBody();

                $bodyParams ??= $this->decodeContent((string) $request->getBody());
            }
        }

        \parse_str(\html_entity_decode($request->getUri()->getQuery()), $queryParams);

        return $this->parseRequestParams(
            $request->getMethod(),
            $bodyParams,
            $queryParams
        );
    }

    /** @return array<mixed> */
    private function decodeContent(string $rawBody): array
    {
        \parse_str($rawBody, $bodyParams);

        return $bodyParams;
    }

    /**
     * @param mixed $bodyParams
     *
     * @throws RequestError
     */
    private function assertJsonObjectOrArray($bodyParams): void
    {
        if (! \is_array($bodyParams)) {
            $notArray = Utils::printSafeJson($bodyParams);
            throw new RequestError(
                sprintf(
                    'Expected JSON object or array for "application/json" request, got: %s',
                    $notArray
                )
            );
        }
    }

    /**
     * Parses normalized request params and returns instance of OperationParams
     * or array of OperationParams in case of batch operation.
     *
     * Returned value is a suitable input for `executeOperation` or `executeBatch` (if array)
     *
     * @param array<mixed> $bodyParams
     * @param array<mixed> $queryParams
     *
     * @throws RequestError
     *
     * @return OperationParams|array<int, OperationParams>
     */
    private function parseRequestParams(string $method, array $bodyParams, array $queryParams)
    {
        if ($method === 'GET') {
            return OperationParams::create($queryParams, true);
        }

        if ($method === 'POST') {
            if (isset($bodyParams[0])) {
                $operations = [];
                foreach ($bodyParams as $entry) {
                    $operations[] = OperationParams::create($entry);
                }

                return $operations;
            }

            return OperationParams::create($bodyParams);
        }

        throw new RequestError("HTTP Method \"{$method}\" is not supported");
    }

    /**
     * Executes batched GraphQL operations with shared promise queue
     * (thus, effectively batching deferreds|promises of all queries at once).
     *
     * @param array<OperationParams> $operations
     *
     * @throws \Exception
     * @throws InvariantViolation
     *
     * @return array<int, ExecutionResult>|Promise
     */
    private function executeBatch(mixed $context, array $operations)
    {
        $promiseAdapter = $this->config->getPromiseAdapter() ?? Executor::getPromiseAdapter();

        $result = [];
        foreach ($operations as $operation) {
            $result[] = $this->promiseToExecuteOperation($promiseAdapter, $context, $operation, true);
        }

        $result = $promiseAdapter->all($result);

        // Wait for promised results when using sync promises
        if ($promiseAdapter instanceof SyncPromiseAdapter) {
            $result = $promiseAdapter->wait($result);
        }

        return $result;
    }

    /**
     * Converts query execution result to PSR-7 response.
     *
     * @param Promise|ExecutionResult|array<ExecutionResult> $result
     *
     * @throws \InvalidArgumentException
     * @throws \JsonException
     * @throws \RuntimeException
     *
     * @return Promise|ResponseInterface
     */
    private function toPsrResponse($result, ResponseInterface $response, StreamInterface $writableBodyStream)
    {
        if ($result instanceof Promise) {
            return $result->then(
                fn ($actualResult): ResponseInterface => $this->doConvertToPsrResponse($actualResult, $response, $writableBodyStream)
            );
        }

        return $this->doConvertToPsrResponse($result, $response, $writableBodyStream);
    }

    /**
     * @param ExecutionResult|array<ExecutionResult> $result
     *
     * @throws \InvalidArgumentException
     * @throws \JsonException
     * @throws \RuntimeException
     */
    private function doConvertToPsrResponse($result, ResponseInterface $response, StreamInterface $writableBodyStream): ResponseInterface
    {
        $writableBodyStream->write(\json_encode($result, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($writableBodyStream);
    }

    /**
     * Executes GraphQL operation with given server configuration and returns execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter).
     *
     * @throws \Exception
     * @throws InvariantViolation
     *
     * @return ExecutionResult|Promise
     */
    private function executeOperation(mixed $context, OperationParams $op)
    {
        $promiseAdapter = $this->config->getPromiseAdapter() ?? Executor::getPromiseAdapter();
        $result = $this->promiseToExecuteOperation($promiseAdapter, $context, $op);

        if ($promiseAdapter instanceof SyncPromiseAdapter) {
            $result = $promiseAdapter->wait($result);
        }

        return $result;
    }

    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    private function promiseToExecuteOperation(
        PromiseAdapter $promiseAdapter,
        mixed $context,
        OperationParams $op,
        bool $isBatch = false
    ): Promise {
        try {
            if ($this->config->getSchema() === null) {
                throw new InvariantViolation('Schema is required for the server');
            }

            if ($isBatch && ! $this->config->getQueryBatching()) {
                throw new RequestError('Batched queries are not supported by this server');
            }

            $errors = $this->validateOperationParams($op);

            if ($errors !== []) {
                $locatedErrors = \array_map(
                    [Error::class, 'createLocatedError'],
                    $errors
                );

                return $promiseAdapter->createFulfilled(
                    new ExecutionResult(null, $locatedErrors)
                );
            }

            $doc = $op->queryId !== null
                ? $this->loadPersistedQuery($op)
                : $op->query;

            if (! $doc instanceof DocumentNode) {
                $doc = Parser::parse($doc);
            }

            $operationAST = AST::getOperationAST($doc, $op->operation);

            if ($operationAST === null) {
                throw new RequestError('Failed to determine operation type');
            }

            $operationType = $operationAST->operation;

            if ($operationType !== 'query' && $op->readOnly) {
                throw new RequestError('GET supports only query operation');
            }

            $result = GraphQL::promiseToExecute(
                $promiseAdapter,
                $this->config->getSchema(),
                $doc,
                $this->resolveRootValue($this->config, $op, $doc, $operationType),
                $this->resolveContextValue($context, $op, $doc, $operationType),
                $op->variables,
                $op->operation,
                $this->config->getFieldResolver(),
                $this->resolveValidationRules($this->config, $op, $doc, $operationType)
            );
        } catch (RequestError $e) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [Error::createLocatedError($e)])
            );
        } catch (Error $e) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [$e])
            );
        }

        $applyErrorHandling = function (ExecutionResult $result): ExecutionResult {
            $result->setErrorsHandler($this->config->getErrorsHandler());

            $result->setErrorFormatter(
                FormattedError::prepareFormatter(
                    $this->config->getErrorFormatter(),
                    $this->config->getDebugFlag()
                )
            );

            return $result;
        };

        return $result->then($applyErrorHandling);
    }

    /**
     * Checks validity of OperationParams extracted from HTTP request and returns an array of errors
     * if params are invalid (or empty array when params are valid).
     *
     * @return array<int, RequestError>
     *
     * @api
     */
    private function validateOperationParams(OperationParams $params): array
    {
        $errors = [];
        $query = $params->query ?? '';
        $queryId = $params->queryId ?? '';
        if ($query === '' && $queryId === '') {
            $errors[] = new RequestError('GraphQL Request must include at least one of those two parameters: "query" or "queryId"');
        }

        if (! \is_string($query)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "query" must be string, but got '
                . Utils::printSafeJson($params->query)
            );
        }

        if (! \is_string($queryId)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "queryId" must be string, but got '
                . Utils::printSafeJson($params->queryId)
            );
        }

        if ($params->operation !== null && ! \is_string($params->operation)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "operation" must be string, but got '
                . Utils::printSafeJson($params->operation)
            );
        }

        if ($params->variables !== null && (! \is_array($params->variables) || isset($params->variables[0]))) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "variables" must be object or JSON string parsed to object, but got '
                . Utils::printSafeJson($params->originalInput['variables'])
            );
        }

        return $errors;
    }

    /**
     * @throws RequestError
     *
     * @return mixed
     */
    private function loadPersistedQuery(OperationParams $operationParams)
    {
        $loader = $this->config->getPersistedQueryLoader();

        if ($loader === null) {
            throw new RequestError('Persisted queries are not supported by this server');
        }

        $source = $loader($operationParams->queryId, $operationParams);

        // @phpstan-ignore-next-line Necessary until PHP gains function types
        if (! \is_string($source) && ! $source instanceof DocumentNode) {
            $documentNode = DocumentNode::class;
            $safeSource = Utils::printSafe($source);
            throw new InvariantViolation("Persisted query loader must return query string or instance of {$documentNode} but got: {$safeSource}");
        }

        return $source;
    }

    /** @return mixed */
    private function resolveRootValue(
        ServerConfig $config,
        OperationParams $params,
        DocumentNode $doc,
        string $operationType
    ) {
        $rootValue = $config->getRootValue();

        if (\is_callable($rootValue)) {
            $rootValue = $rootValue($params, $doc, $operationType);
        }

        return $rootValue;
    }

    /** @return mixed user defined */
    private function resolveContextValue(
        mixed $context,
        OperationParams $params,
        DocumentNode $doc,
        string $operationType
    ) {
        if (\is_callable($context)) {
            $context = $context($params, $doc, $operationType);
        }

        return $context;
    }

    /** @return array<mixed>|null */
    private function resolveValidationRules(
        ServerConfig $config,
        OperationParams $params,
        DocumentNode $doc,
        string $operationType
    ): ?array {
        $validationRules = $config->getValidationRules();

        if (\is_callable($validationRules)) {
            $validationRules = $validationRules($params, $doc, $operationType);
        }

        // @phpstan-ignore-next-line unless PHP gains function types, we have to check this at runtime
        if ($validationRules !== null && ! \is_array($validationRules)) {
            $safeValidationRules = Utils::printSafe($validationRules);
            throw new InvariantViolation("Expecting validation rules to be array or callable returning array, but got: {$safeValidationRules}");
        }

        return $validationRules;
    }
}
