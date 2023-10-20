<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL\Server;

use GraphQL\Error\DebugFlag;
use GraphQL\Executor\ExecutionResult;
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
     * @param array<ValidationRule>|callable|null $validationRules
     * @phpstan-param ErrorFormatter|null $errorFormatter
     * @phpstan-param ErrorsHandler|null $errorsHandler
     * @phpstan-param ValidationRulesOption $validationRules
     * @phpstan-param PersistedQueryLoader|null $persistedQueryLoader
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly ?callable $errorFormatter = null,
        private readonly ?callable $errorsHandler = null,
        private readonly bool $queryBatching = false,
        private readonly ?callable $persistedQueryLoader = null,
        private readonly callable | array | null $validationRules = null,
        private readonly ?callable $fieldResolver = null,
        private readonly mixed $rootValue = null,
        private readonly int $debugFlag = DebugFlag::NONE,
    ) {
    }

    /** @phpstan-return mixed|RootValueResolver */
    public function getRootValue() : mixed
    {
        return $this->rootValue;
    }

    public function getSchema() : ?Schema
    {
        return $this->schema;
    }

    /** @phpstan-return ErrorFormatter|null */
    public function getErrorFormatter() : ?callable
    {
        return $this->errorFormatter;
    }

    /** @phpstan-return ErrorsHandler|null */
    public function getErrorsHandler() : ?callable
    {
        return $this->errorsHandler;
    }

    /**
     * @return array<ValidationRule>|callable|null
     * @phpstan-return ValidationRulesOption
     */
    public function getValidationRules() : array | callable | null
    {
        return $this->validationRules;
    }

    public function getFieldResolver() : ?callable
    {
        return $this->fieldResolver;
    }

    /** @phpstan-return PersistedQueryLoader|null */
    public function getPersistedQueryLoader() : ?callable
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
