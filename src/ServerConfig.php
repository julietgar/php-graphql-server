<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL\Server;

use GraphQL\Error\DebugFlag;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use GraphQL\Type\Schema;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * @phpstan-type PersistedQueryLoader callable(string $queryId, OperationParams $operation) : (string | DocumentNode)
 * @phpstan-type RootValueResolver callable(OperationParams $operation, DocumentNode $doc, string $operationType) : mixed
 * @phpstan-type ValidationRulesOption array<ValidationRule> | callable(OperationParams $operation, DocumentNode $doc, string $operationType) : array<ValidationRule> | null
 * @phpstan-import-type ErrorsHandler from ExecutionResult
 * @phpstan-import-type ErrorFormatter from ExecutionResult
 */
final class ServerConfig
{
    /**
     * @var callable|null
     * @phpstan-var ErrorFormatter|null $errorFormatter
     */
    private readonly mixed $errorFormatter;

    /**
     * @var callable|null
     * @phpstan-var ErrorsHandler|null $errorsHandler
     */
    private readonly mixed $errorsHandler;

    /**
     * @var callable|null
     * @phpstan-var PersistedQueryLoader|null $persistedQueryLoader
     */
    private readonly mixed $persistedQueryLoader;

    /** @var callable|null */
    private readonly mixed $fieldResolver;

    /**
     * @var array<ValidationRule>|callable|null
     * @phpstan-var ValidationRulesOption $validationRules
     */
    private readonly mixed $validationRules;

    /**
     * @param array<ValidationRule>|callable|null $validationRules
     * @phpstan-param ErrorFormatter|null $errorFormatter
     * @phpstan-param ErrorsHandler|null $errorsHandler
     * @phpstan-param ValidationRulesOption $validationRules
     * @phpstan-param PersistedQueryLoader|null $persistedQueryLoader
     */
    public function __construct(
        private readonly Schema $schema,
        callable|null $errorFormatter = null,
        callable|null $errorsHandler = null,
        private readonly bool $queryBatching = false,
        callable|null $persistedQueryLoader = null,
        array|callable|null $validationRules = null,
        callable|null $fieldResolver = null,
        private readonly mixed $rootValue = null,
        private readonly int $debugFlag = DebugFlag::NONE,
        private readonly PromiseAdapter|null $promiseAdapter = null,
    ) {
        $this->errorFormatter = $errorFormatter;
        $this->errorsHandler = $errorsHandler;
        $this->fieldResolver = $fieldResolver;
        $this->persistedQueryLoader = $persistedQueryLoader;
        $this->validationRules = $validationRules;
    }

    /** @phpstan-return mixed|RootValueResolver */
    public function getRootValue() : mixed
    {
        return $this->rootValue;
    }

    public function getSchema() : Schema|null
    {
        return $this->schema;
    }

    /** @phpstan-return ErrorFormatter|null */
    public function getErrorFormatter() : callable|null
    {
        return $this->errorFormatter;
    }

    /** @phpstan-return ErrorsHandler|null */
    public function getErrorsHandler() : callable|null
    {
        return $this->errorsHandler;
    }

    public function getPromiseAdapter() : PromiseAdapter
    {
        return $this->promiseAdapter ?? Executor::getDefaultPromiseAdapter();
    }

    /**
     * @return array<ValidationRule>|callable|null
     * @phpstan-return ValidationRulesOption
     */
    public function getValidationRules() : array|callable|null
    {
        return $this->validationRules;
    }

    public function getFieldResolver() : callable|null
    {
        return $this->fieldResolver;
    }

    /** @phpstan-return PersistedQueryLoader|null */
    public function getPersistedQueryLoader() : callable|null
    {
        return $this->persistedQueryLoader;
    }

    public function getDebugFlag() : int
    {
        return $this->debugFlag;
    }

    public function getQueryBatching() : bool
    {
        return $this->queryBatching;
    }
}
