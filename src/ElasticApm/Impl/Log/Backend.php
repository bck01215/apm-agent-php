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

declare(strict_types=1);

namespace Elastic\Apm\Impl\Log;

use Elastic\Apm\Impl\Util\ClassNameUtil;
use Elastic\Apm\Impl\Util\ElasticApmExtensionUtil;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class Backend
{
    /** @var int */
    private $maxEnabledLevel;

    /** @var SinkInterface */
    private $logSink;

    public function __construct(int $maxEnabledLevel, ?SinkInterface $logSink)
    {
        $this->maxEnabledLevel = $maxEnabledLevel;
        $this->logSink = $logSink ??
                         (ElasticApmExtensionUtil::isLoaded()
                             ? new DefaultSink()
                             : NoopLogSink::singletonInstance());
    }

    public function maxEnabledLevel(): int
    {
        return $this->maxEnabledLevel;
    }

    /**
     * @param array<string, mixed> $statementCtx
     * @param LoggerData           $loggerData
     *
     * @return  array<array<string, mixed>>
     */
    private static function buildContextsStack(array $statementCtx, LoggerData $loggerData): array
    {
        $result = [];

        for (
            $currentLoggerData = $loggerData;
            $currentLoggerData !== null;
            $currentLoggerData = $currentLoggerData->inheritedData
        ) {
            $result[] = $currentLoggerData->context;
        }

        $result[] = [
            'namespace' => $loggerData->namespace,
            'class'     => ClassNameUtil::fqToShort($loggerData->fqClassName),
        ];

        $result[] = $statementCtx;

        return $result;
    }

    /**
     * @param int                  $statementLevel
     * @param string               $message
     * @param array<string, mixed> $statementCtx
     * @param int                  $srcCodeLine
     * @param string               $srcCodeFunc
     * @param LoggerData           $loggerData
     * @param bool|null            $includeStacktrace
     * @param int                  $numberOfStackFramesToSkip
     */
    public function log(
        int $statementLevel,
        string $message,
        array $statementCtx,
        int $srcCodeLine,
        string $srcCodeFunc,
        LoggerData $loggerData,
        ?bool $includeStacktrace,
        int $numberOfStackFramesToSkip
    ): void {
        $this->logSink->consume(
            $statementLevel,
            $message,
            self::buildContextsStack($statementCtx, $loggerData),
            $loggerData->category,
            $loggerData->srcCodeFile,
            $srcCodeLine,
            $srcCodeFunc,
            $includeStacktrace,
            $numberOfStackFramesToSkip + 1
        );
    }
}
