<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Readme;

use LogicException;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\ReadmeSamples;
use ReflectionMethod;

/**
 * The shared test set, run for real on the adapter, the preset, the fixtures and the test class that the README
 * shows under "Adding your own provider": the example a reader copies must pass it.
 *
 * The test class of the README is declared from the text of the README (see ReadmeSamples), so the test runner
 * does not find it in a file. This class stands in for it: every subject of the set is built by the subject()
 * method the README shows, on an object of the class the README declares. The fixtures it reads are the files
 * under tests/fixtures/talk, the folder the sample of the README names.
 */
final class ReadmeExampleContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        $class = ReadmeSamples::declared('ExampleAdapterContractTest');
        if (! is_subclass_of($class, ContractTestCase::class)) {
            throw new LogicException('The test class of the README must extend the shared test set.');
        }

        $subject = (new ReflectionMethod($class, 'subject'))->invoke(new $class('subject'), $retry);
        if (! $subject instanceof ContractSubject) {
            throw new LogicException('The subject() of the README must give a subject of the shared test set.');
        }

        return $subject;
    }
}
