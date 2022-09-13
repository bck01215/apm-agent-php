<?php

/*
 * Licensed to Elasticsearch B.V. under one or more contributor
 * license agreements. See the NOTICE file distributed with
 * this work for additional information regarding copyright
 * ownership. Elasticsearch B.V. licenses this file to you under
 * the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Elastic\Apm\Impl\AutoInstrument;

use Elastic\Apm\Impl\AutoInstrument\Util\AutoInstrumentationUtil;
use Elastic\Apm\Impl\AutoInstrument\Util\DbAutoInstrumentationUtil;
use Elastic\Apm\Impl\Constants;
use Elastic\Apm\Impl\Log\LogCategory;
use Elastic\Apm\Impl\Log\Logger;
use Elastic\Apm\Impl\Tracer;
use Elastic\Apm\Impl\Util\DbgUtil;
use Elastic\Apm\SpanInterface;
use mysqli;
use mysqli_stmt;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class MySQLiAutoInstrumentation extends AutoInstrumentationBase
{
    private const MYSQLI_CLASS_NAME = 'mysqli';
    private const MYSQLI_STMT_CLASS_NAME = 'mysqli_stmt';

    private const DYNAMICALLY_ATTACHED_PROPERTIES_TO_PROPAGATE = [
        DbAutoInstrumentationUtil::DYNAMICALLY_ATTACHED_PROPERTY_KEY_DB_NAME
    ];

    /** @var Logger */
    private $logger;

    /** @var AutoInstrumentationUtil */
    private $util;

    public function __construct(Tracer $tracer)
    {
        parent::__construct($tracer);

        $this->logger = $tracer->loggerFactory()->loggerForClass(
            LogCategory::AUTO_INSTRUMENTATION,
            __NAMESPACE__,
            __CLASS__,
            __FILE__
        )->addContext('this', $this);

        $this->util = new AutoInstrumentationUtil($tracer->loggerFactory());
    }

    /** @inheritDoc */
    public function name(): string
    {
        return InstrumentationNames::MYSQLI;
    }

    /** @inheritDoc */
    public function otherNames(): array
    {
        return [InstrumentationNames::DB];
    }

    /** @inheritDoc */
    public function register(RegistrationContextInterface $ctx): void
    {
        if (!extension_loaded('mysqli')) {
            return;
        }

        $this->interceptMySQLiConstructConnect($ctx);

        // $this->interceptCallsToMySQLiSelectDb($ctx);

        // $this->interceptCallsToQuery($ctx, 'query', 'mysqli_query');
        // $this->interceptCallsToQuery($ctx, 'multi_query', 'mysqli_multi_query');
        // $this->interceptCallsToQuery($ctx, 'real_query', 'mysqli_real_query');

        $this->interceptMySQLiPrepare($ctx);
        $this->interceptMySQLiStmtExecute($ctx);

        $this->interceptMySQLiToSpanAsFuncCall($ctx, 'ping');
        $this->interceptMySQLiToSpanAsFuncCall($ctx, 'begin_transaction');
        $this->interceptMySQLiToSpanAsFuncCall($ctx, 'commit');
        $this->interceptMySQLiToSpanAsFuncCall($ctx, 'rollback');
        $this->interceptMySQLiToSpanAsFuncCall($ctx, 'close');

        // Consider capturing the argument for
        // $this->interceptMySQLiToSpanAsFuncCall($ctx, 'autocommit');
        $this->interceptMySQLiToSpanAsFuncCall($ctx, 'kill');
    }

    private function interceptMySQLiConstructConnect(RegistrationContextInterface $ctx): void
    {
        /**
         * @param ?string $className
         * @param string  $funcName
         * @param ?object $interceptedCallThis
         * @param array   $interceptedCallArgs
         *
         * @return null|callable(int, bool, mixed): void
         */
        $preHook = function (
            ?string $className,
            string $funcName,
            ?object $interceptedCallThis,
            array $interceptedCallArgs
        ): ?callable {
            // function mysqli_connect(
            //      $host = null,         // <- $interceptedCallArgs[0]
            //      $username = null,     // <- $interceptedCallArgs[1]
            //      $password = null,     // <- $interceptedCallArgs[2]
            //      $database = null,     // <- $interceptedCallArgs[3]
            //      $port = null,         // <- $interceptedCallArgs[4]
            //      $socket = null        // <- $interceptedCallArgs[5]
            // );
            //
            // public function __construct (
            //      $host = null,         // <- $interceptedCallArgs[0]
            //      $username = null,     // <- $interceptedCallArgs[1]
            //      $passwd = null,       // <- $interceptedCallArgs[2]
            //      $database = null,     // <- $interceptedCallArgs[3]
            //      $port = null,         // <- $interceptedCallArgs[4]
            //      $socket = null        // <- $interceptedCallArgs[5]
            //  );

            /** @var ?string $dbName */
            $dbName = null;
            if (count($interceptedCallArgs) >= 4) {
                $fourthArg = $interceptedCallArgs[3];
                if ($fourthArg !== null) {
                    if (is_string($fourthArg)) {
                        $dbName = $fourthArg;
                    } else {
                        ($loggerProxy = $this->logger->ifErrorLevelEnabled(__LINE__, __FUNCTION__))
                        && $loggerProxy->log(
                            'Expected 4th argument to be database name but it is not a string.',
                            [
                                'className' => $className,
                                'funcName' => $funcName,
                                '4th argument type' => DbgUtil::getType($fourthArg),
                                '4th argument' => $this->logger->possiblySecuritySensitive($fourthArg),
                                'interceptedCallArgs' => $this->logger->possiblySecuritySensitive($interceptedCallArgs),
                            ]
                        );
                    }
                }
            }

            return AutoInstrumentationUtil::createPostHookFromEndSpan(
                self::beginSpan($className, $funcName, $dbName, /* statement: */ null),
                /**
                 * @param bool  $hasExitedByException
                 * @param mixed $returnValueOrThrown
                 */
                function (bool $hasExitedByException, $returnValueOrThrown) use ($dbName): void {
                    if ($hasExitedByException) {
                        return;
                    }
                    if (!$this->util->verifyInstanceOf(mysqli::class, $returnValueOrThrown)) {
                        return;
                    }
                    /** @var mysqli $returnValueOrThrown */
                    $this->util->setDynamicallyAttachedProperty(
                        $returnValueOrThrown,
                        DbAutoInstrumentationUtil::DYNAMICALLY_ATTACHED_PROPERTY_KEY_DB_NAME,
                        $dbName
                    );
                }
            );
        };

        $funcName = 'mysqli_connect';
        $ctx->interceptCallsToFunction(
            $funcName,
            /**
             * @param mixed[] $interceptedCallArgs
             *
             * @return null|callable(int, bool, mixed): mixed
             */
            function (array $interceptedCallArgs) use ($preHook, $funcName): ?callable {
                return $preHook(
                    null /* <- className */,
                    $funcName,
                    null /* <- interceptedCallThis */,
                    $interceptedCallArgs
                );
            }
        );

        $className = self::MYSQLI_CLASS_NAME;
        $methodName = '__construct';
        $ctx->interceptCallsToMethod(
            $className,
            $methodName,
            function (
                ?object $interceptedCallThis,
                array $interceptedCallArgs
            ) use (
                $preHook,
                $className,
                $methodName
            ): ?callable {
                return $preHook(
                    $className,
                    $methodName,
                    $interceptedCallThis,
                    $interceptedCallArgs
                );
            }
        );
    }


    // private function interceptCallsToMySQLiSelectDb(RegistrationContextInterface $ctx): void
    // {
    //     $preHook = function (
    //         ?object $interceptedCallThis,
    //         array $interceptedCallArgs,
    //         ?string $className,
    //         string $funcName
    //     ): ?callable {
    //         return $this->createSpanForCallIncludeFirstArgInName($interceptedCallArgs, $className, $funcName);
    //     };
    //
    //     $this->interceptCallsTo($ctx, self::MYSQLI_CLASS_NAME, 'select_db', 'mysqli_select_db', $preHook);
    // }

    private function interceptMySQLiToSpanAsFuncCall(
        RegistrationContextInterface $ctx,
        string $methodName
    ): void {
        $preHook = function (
            ?string $className,
            string $funcName,
            ?object $interceptedCallThis,
            /** @noinspection PhpUnusedParameterInspection */ array $interceptedCallArgs
        ): ?callable {
            /** @var ?string $dbName */
            $dbName = ($interceptedCallThis !== null)
                ? $this->util->getDynamicallyAttachedProperty(
                    $interceptedCallThis,
                    DbAutoInstrumentationUtil::DYNAMICALLY_ATTACHED_PROPERTY_KEY_DB_NAME,
                    null /* <- defaultValue */
                )
                : null;
            return AutoInstrumentationUtil::createPostHookFromEndSpan(
                self::beginSpan(
                    $className,
                    $funcName,
                    $dbName,
                    null /* <- statement */
                )
            );
        };

        $this->interceptCallsTo($ctx, self::MYSQLI_CLASS_NAME, $methodName, $preHook);
    }

    // private function interceptCallsToQuery(
    //     RegistrationContextInterface $ctx,
    //     string $methodName,
    //     string $funcName
    // ): void {
    //     $preHook = function (?object $interceptedCallThis, array $interceptedCallArgs): ?callable {
    //         if (!$this->verifyAtLeastOneArgument($interceptedCallArgs)) {
    //             return null;
    //         }
    //
    //         $statement = $interceptedCallArgs[0];
    //
    //         return AutoInstrumentationUtil::createPostHookFromEndSpan(
    //             self::beginSpan($statement, $statement)
    //         );
    //     };
    //
    //     $this->interceptCallsTo($ctx, self::MYSQLI_CLASS_NAME, $methodName, $funcName, $preHook);
    // }

    private function interceptMySQLiPrepare(RegistrationContextInterface $ctx): void
    {
        /**
         * @param ?string $className
         * @param string  $funcName
         * @param ?object $interceptedCallThis
         * @param array   $interceptedCallArgs
         *
         * @return null|callable(int, bool, mixed): void
         */
        $preHook = function (
            /** @noinspection PhpUnusedParameterInspection */
            ?string $className,
            /** @noinspection PhpUnusedParameterInspection */
            string $funcName,
            ?object $interceptedCallThis,
            array $interceptedCallArgs
        ): ?callable {
            if (!$this->util->verifyInstanceOf(mysqli::class, $interceptedCallThis)) {
                return null;
            }
            /** @var mysqli $interceptedCallThis */

            $dynPropsToPropagate = $this->util->getDynamicallyAttachedProperties(
                $interceptedCallThis,
                self::DYNAMICALLY_ATTACHED_PROPERTIES_TO_PROPAGATE
            );

            if (
                $this->util->verifyMinArgsCount(1, $interceptedCallArgs)
                && $this->util->verifyIsString($interceptedCallArgs[0])
            ) {
                $dynPropsToPropagate[DbAutoInstrumentationUtil::DYNAMICALLY_ATTACHED_PROPERTY_KEY_DB_QUERY]
                    = $interceptedCallArgs[0];
            }

            return function (
                int $numberOfStackFramesToSkip,
                bool $hasExitedByException,
                $returnValueOrThrown
            ) use (
                $dynPropsToPropagate
            ): void {
                // We use 'instanceof mysqli_stmt' instead of verifyInstanceOf on purpose
                // because mysqli_prepare return type is:
                //      mysqli_stmt|false A statement object or FALSE if an error occurred.
                if (!$hasExitedByException && $returnValueOrThrown instanceof mysqli_stmt) {
                    $this->util->setDynamicallyAttachedProperties($returnValueOrThrown, $dynPropsToPropagate);
                }
            };
        };

        $this->interceptCallsTo($ctx, self::MYSQLI_CLASS_NAME, 'prepare', $preHook);
    }

    private function interceptMySQLiStmtExecute(RegistrationContextInterface $ctx): void
    {
        $className = self::MYSQLI_STMT_CLASS_NAME;
        $methodName = 'execute';

        /**
         * @param ?string $className
         * @param string  $methodName
         * @param ?object $interceptedCallThis
         * @param array   $interceptedCallArgs
         *
         * @return null|callable(int, bool, mixed): void
         */
        $preHook = function (
            ?string $className,
            string $methodName,
            ?object $interceptedCallThis,
            /** @noinspection PhpUnusedParameterInspection */
            array $interceptedCallArgs
        ): ?callable {
            if (!$this->util->verifyInstanceOf(mysqli_stmt::class, $interceptedCallThis)) {
                return null;
            }
            /** @var mysqli_stmt $interceptedCallThis */

            /** @var ?string $dbName */
            $dbName = $this->util->getDynamicallyAttachedProperty(
                $interceptedCallThis,
                DbAutoInstrumentationUtil::DYNAMICALLY_ATTACHED_PROPERTY_KEY_DB_NAME,
                null /* <- defaultValue */
            );

            /** @var ?string $query */
            $query = $this->util->getDynamicallyAttachedProperty(
                $interceptedCallThis,
                DbAutoInstrumentationUtil::DYNAMICALLY_ATTACHED_PROPERTY_KEY_DB_QUERY,
                null /* <- defaultValue */
            );

            return AutoInstrumentationUtil::createPostHookFromEndSpan(
                self::beginSpan(
                    $className,
                    $methodName,
                    $dbName,
                    $query /* <- statement */
                )
            );
        };

        $this->interceptCallsTo($ctx, $className, $methodName, $preHook);
    }

    private static function buildFuncName(string $className, string $methodName): string
    {
        return $className . '_' . $methodName;
    }

    /**
     * @param RegistrationContextInterface                           $ctx
     * @param string                                                 $className
     * @param string                                                 $methodName
     * @param callable(?string, string, ?object, mixed[]): ?callable $preHook
     *
     * @return void
     */
    private function interceptCallsTo(
        RegistrationContextInterface $ctx,
        string $className,
        string $methodName,
        callable $preHook
    ): void {
        $funcName = self::buildFuncName($className, $methodName);
        $ctx->interceptCallsToFunction(
            $className . '_' . $methodName,
            /**
             * @param array $interceptedCallArgs
             *
             * @return null|callable(int, bool, mixed): void
             */
            function (array $interceptedCallArgs) use ($preHook, $funcName): ?callable {
                if (!$this->util->verifyMinArgsCount(1, $interceptedCallArgs)) {
                    return null;
                }
                $interceptedCallThis = $interceptedCallArgs[0];
                if (
                    $interceptedCallThis !== null
                    && !$this->util->verifyIsObject($interceptedCallThis)
                ) {
                    return null;
                }
                /** @var ?object $interceptedCallThis */

                return $preHook(
                    null /* <- className */,
                    $funcName /* <- funcName / methodName */,
                    $interceptedCallThis,
                    array_slice($interceptedCallArgs, 1) /* <- interceptedCallArgs */
                );
            }
        );

        $ctx->interceptCallsToMethod(
            $className,
            $methodName,
            /**
             * @param ?object $interceptedCallThis
             * @param array   $interceptedCallArgs
             *
             * @return null|callable(int, bool, mixed): void
             */
            function (
                ?object $interceptedCallThis,
                array $interceptedCallArgs
            ) use (
                $className,
                $methodName /* <- funcName / methodName */,
                $preHook
            ): ?callable {
                return $preHook($className, $methodName, $interceptedCallThis, $interceptedCallArgs);
            }
        );
    }

    private static function beginSpan(
        ?string $className,
        string $funcName,
        ?string $dbName,
        ?string $statement
    ): SpanInterface {
        return DbAutoInstrumentationUtil::beginDbSpan(
            $className,
            $funcName,
            Constants::SPAN_SUBTYPE_MYSQL,
            $dbName,
            $statement
        );
    }
}
