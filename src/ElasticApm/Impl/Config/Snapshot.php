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

namespace Elastic\Apm\Impl\Config;

use Elastic\Apm\Impl\Log\Level as LogLevel;
use Elastic\Apm\Impl\Log\LoggableInterface;
use Elastic\Apm\Impl\Log\LoggableTrait;
use Elastic\Apm\Impl\Log\LoggerFactory;
use Elastic\Apm\Impl\Util\WildcardListMatcher;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class Snapshot implements LoggableInterface
{
    //
    // Steps to add new configuration option (let's assume new option name is `my_new_option'):
    //
    //      1) Follow the steps in <repo root>/src/ext/ConfigManager.h to add the new option for C part of the agent.
    //         NOTE: Build C part of the agent after making the changes above and before proceeding to the steps below.
    //
    //
    //      2) Add
    //
    //              public const MY_NEW_OPTION = 'my_new_option';
    //
    //         to class \Elastic\Apm\Impl\Config\OptionNames
    //
    //
    //      3) Add
    //
    //              OptionNames::MY_NEW_OPTION => new <my_new_option type>OptionMetadata(),
    //
    //         to class \Elastic\Apm\Impl\Config\AllOptionsMetadata
    //
    //
    //      4) Add
    //
    //              /** @var <my_new_option type> */
    //              private $myNewOption;
    //
    //         to \Elastic\Apm\Impl\Config\Snapshot class
    //
    //
    //      5) Add
    //
    //             public function myNewOption(): <my_new_option type>
    //             {
    //                 return $this->myNewOption;
    //             }
    //
    //         to \Elastic\Apm\Impl\Config\Snapshot class
    //
    //
    //      6) Add
    //
    //             OptionNames::MY_NEW_OPTION => <my_new_option type>RawToParsedValues,
    //
    //         to return value of buildOptionNameToRawToValue()
    //             in \ElasticApmTests\ComponentTests\ConfigSettingTest class
    //
    //
    //      7) Optionally add option specific test such as \ElasticApmTests\ComponentTests\ApiKeyTest
    //
    //
    use SnapshotTrait;
    use LoggableTrait;

    public const LOG_LEVEL_STDERR_DEFAULT = LogLevel::CRITICAL;
    public const LOG_LEVEL_SYSLOG_DEFAULT = LogLevel::INFO;

    /** @var array<string, mixed> */
    private $optNameToParsedValue;

    /** @var string */
    private $apiKey;

    /** @var bool */
    private $asyncBackendComm;

    /** @var bool */
    private $breakdownMetrics;

    /** @var ?WildcardListMatcher */
    private $devInternal;

    /** @var SnapshotDevInternal */
    private $devInternalParsed;

    /** @var ?WildcardListMatcher */
    private $disableInstrumentations;

    /** @var bool */
    private $disableSend;

    /** @var bool */
    private $enabled;

    /** @var ?string */
    private $environment;

    /** @var ?string */
    private $hostname;

    /** @var ?int */
    private $logLevel;

    /** @var ?int */
    private $logLevelStderr;

    /** @var ?int */
    private $logLevelSyslog;

    /** @var string */
    private $secretToken;

    /** @var float - In milliseconds */
    private $serverTimeout;

    /** @var ?string */
    private $serviceName;

    /** @var ?string */
    private $serviceNodeName;

    /** @var ?string */
    private $serviceVersion;

    /** @var ?WildcardListMatcher */
    private $transactionIgnoreUrls;

    /** @var int */
    private $transactionMaxSpans;

    /** @var float */
    private $transactionSampleRate;

    /** @var ?WildcardListMatcher */
    private $urlGroups = null;

    /** @var bool */
    private $verifyServerCert;

    /** @var int */
    private $effectiveLogLevel;

    /** @var float */
    private $effectiveTransactionSampleRate;

    /** @var string */
    private $effectiveTransactionSampleRateAsString;

    /**
     * Snapshot constructor.
     *
     * @param array<string, mixed> $optNameToParsedValue
     */
    public function __construct(array $optNameToParsedValue, LoggerFactory $loggerFactory)
    {
        $this->setPropertiesToValuesFrom($optNameToParsedValue);

        $this->setEffectiveLogLevel();
        $this->setEffectiveTransactionSampleRate();

        $this->devInternalParsed = new SnapshotDevInternal($this->devInternal, $loggerFactory);
    }

    private function setEffectiveLogLevel(): void
    {
        $this->effectiveLogLevel = max(
            ($this->logLevelStderr ?? $this->logLevel) ?? self::LOG_LEVEL_STDERR_DEFAULT,
            ($this->logLevelSyslog ?? $this->logLevel) ?? self::LOG_LEVEL_SYSLOG_DEFAULT
        );
    }

    private function setEffectiveTransactionSampleRate(): void
    {
        if ($this->transactionSampleRate === 0.0) {
            $this->effectiveTransactionSampleRate = 0.0;
            $this->effectiveTransactionSampleRateAsString = '0';
            return;
        }

        if ($this->transactionSampleRate === 1.0) {
            $this->effectiveTransactionSampleRate = 1.0;
            $this->effectiveTransactionSampleRateAsString = '1';
            return;
        }

        if ($this->transactionSampleRate < 0.0001) {
            $this->effectiveTransactionSampleRate = 0.0001;
            $this->effectiveTransactionSampleRateAsString = '0.0001';
            return;
        }

        $this->effectiveTransactionSampleRate = round(
            $this->transactionSampleRate,
            4 /* <- precision - number of decimal digits */
        );
        $asString = number_format(
            $this->effectiveTransactionSampleRate,
            /* number of decimal digits: */ 4,
            /* decimal_separator: */ '.',
            /* thousands_separator - without thousands separator: */ ''
        );
        // Remove trailing zeros if there any
        $asString = preg_replace('/\.?0+$/', '', $asString) ?? $asString;
        $this->effectiveTransactionSampleRateAsString = $asString;
    }

    /**
     * @param string $optName
     *
     * @return mixed
     */
    public function parsedValueFor(string $optName)
    {
        return $this->optNameToParsedValue[$optName];
    }

    public function breakdownMetrics(): bool
    {
        return $this->breakdownMetrics;
    }

    public function devInternal(): SnapshotDevInternal
    {
        return $this->devInternalParsed;
    }

    public function disableInstrumentations(): ?WildcardListMatcher
    {
        return $this->disableInstrumentations;
    }

    public function disableSend(): bool
    {
        return $this->disableSend;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function environment(): ?string
    {
        return $this->environment;
    }

    public function hostname(): ?string
    {
        return $this->hostname;
    }

    public function effectiveLogLevel(): int
    {
        return $this->effectiveLogLevel;
    }

    public function serverTimeout(): float
    {
        return $this->serverTimeout;
    }

    public function serviceName(): ?string
    {
        return $this->serviceName;
    }

    public function serviceNodeName(): ?string
    {
        return $this->serviceNodeName;
    }

    public function serviceVersion(): ?string
    {
        return $this->serviceVersion;
    }

    public function transactionIgnoreUrls(): ?WildcardListMatcher
    {
        return $this->transactionIgnoreUrls;
    }

    public function transactionMaxSpans(): int
    {
        return $this->transactionMaxSpans;
    }

    public function effectiveTransactionSampleRate(): float
    {
        return $this->effectiveTransactionSampleRate;
    }

    public function effectiveTransactionSampleRateAsString(): string
    {
        return $this->effectiveTransactionSampleRateAsString;
    }

    public function urlGroups(): ?WildcardListMatcher
    {
        return $this->urlGroups;
    }
}
