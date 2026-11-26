<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Tests\Support\BrokenContractCase;
use OrganiseYou\LlmGateway\Tests\Support\BrokenGateway;
use PHPUnit\Framework\AssertionFailedError;

// The shared test set must fail a gateway that breaks the contract. Each row names one fault and the test of the
// set that must catch it. The test is first run on the sound gateway, where it passes, so the failure that follows
// comes from the fault and from nothing else.

it('fails a gateway that', function (string $fault, string $test, string $failsWith = AssertionFailedError::class): void {
    $sound = new BrokenContractCase('teeth');
    $sound->onTheAdapter = in_array($fault, BrokenContractCase::ON_THE_ADAPTER, true);
    $sound->{$test}();

    $broken = new BrokenContractCase('teeth');
    $broken->fault = $fault;

    expect(fn() => $broken->{$test}())->toThrow($failsWith);
})->with([
    BrokenGateway::USAGE_TWICE => [BrokenGateway::USAGE_TWICE, 'testAStreamOfAPlainAnswer'],
    BrokenGateway::OTHER_TEXT => [BrokenGateway::OTHER_TEXT, 'testAStreamOfAPlainAnswer'],
    BrokenGateway::EVENT_AFTER_END => [BrokenGateway::EVENT_AFTER_END, 'testAStreamOfAPlainAnswer'],
    BrokenGateway::CALL_NEVER_COMPLETES => [BrokenGateway::CALL_NEVER_COMPLETES, 'testAStreamOfAToolCall'],
    BrokenGateway::THROWS_FROM_STREAM => [BrokenGateway::THROWS_FROM_STREAM, 'testEveryFailureOfAStreamIsItsOneAndLastEvent'],
    BrokenGateway::EAGER_STREAM => [BrokenGateway::EAGER_STREAM, 'testNothingIsSentBeforeAStreamIsRead'],
    BrokenGateway::STRUCTURED_FOR_TOOL_CALL => [BrokenGateway::STRUCTURED_FOR_TOOL_CALL, 'testAToolCallBesideAResponseFormatHasNoStructuredResult'],
    BrokenGateway::THROWS_UNUSABLE => [BrokenGateway::THROWS_UNUSABLE, 'testAStructuredAnswerThatDoesNotMatchIsReturnedWithItsReasons'],
    BrokenGateway::OWN_TRACE_ID => [BrokenGateway::OWN_TRACE_ID, 'testAPlainAnswer'],
    BrokenGateway::KEY_IN_DETAIL => [BrokenGateway::KEY_IN_DETAIL, 'testNoKeyInAnyErrorOrItsTrace'],
    BrokenGateway::KEY_AS_CODE => [BrokenGateway::KEY_AS_CODE, 'testNoKeyInAnyErrorOrItsTrace'],
    BrokenGateway::INVENTED_CODE => [BrokenGateway::INVENTED_CODE, 'testEveryFailureHasItsReason'],
    BrokenGateway::DROPS_USAGE => [BrokenGateway::DROPS_USAGE, 'testAPlainAnswer'],
    BrokenGateway::DROPS_USAGE . ' (in a stream)' => [BrokenGateway::DROPS_USAGE, 'testAStreamOfAPlainAnswer'],
    BrokenGateway::WRONG_ATTEMPTS => [BrokenGateway::WRONG_ATTEMPTS, 'testEveryFailureOfAStreamIsItsOneAndLastEvent'],
    BrokenGateway::NO_STATUS_IN_STREAM => [BrokenGateway::NO_STATUS_IN_STREAM, 'testEveryFailureOfAStreamIsItsOneAndLastEvent'],
    BrokenGateway::OTHER_STARTED_NAME => [BrokenGateway::OTHER_STARTED_NAME, 'testAStreamOfAToolCall'],
    BrokenGateway::SECOND_REQUEST_AFTER_REDIRECT => [BrokenGateway::SECOND_REQUEST_AFTER_REDIRECT, 'testOnlyTheFailuresThatMayBeRetriedAreTriedAgain'],
    BrokenContractCase::SENDS_TRACE_ID => [BrokenContractCase::SENDS_TRACE_ID, 'testAPlainAnswer'],
    BrokenContractCase::SENDS_TRACE_ID . ' (with a failure)' => [BrokenContractCase::SENDS_TRACE_ID, 'testEveryFailureHasItsReason'],
    BrokenContractCase::PROVIDER_WORDS_IN_DETAIL => [BrokenContractCase::PROVIDER_WORDS_IN_DETAIL, 'testAnErrorInsideASuccessfulResponseIsAProviderErrorWithoutTheWordsOfTheProvider'],
    BrokenContractCase::SENDS_WHAT_IT_CANNOT_CARRY => [BrokenContractCase::SENDS_WHAT_IT_CANNOT_CARRY, 'testARequestTheFormatCannotCarryIsRejectedBeforeAnythingIsSent'],
    BrokenContractCase::WRONG_REASON => [BrokenContractCase::WRONG_REASON, 'testEveryFailureHasItsReason'],
    BrokenContractCase::WRONG_REASON . ' (seen by the retry rule too)' => [BrokenContractCase::WRONG_REASON, 'testOnlyTheFailuresThatMayBeRetriedAreTriedAgain'],
    BrokenContractCase::ANOTHER_KEY => [BrokenContractCase::ANOTHER_KEY, 'testNoKeyInAnAnswerOrInTheEventsOfAStream'],
    BrokenContractCase::MISSING_FIXTURE => [BrokenContractCase::MISSING_FIXTURE, 'testAToolCall', LogicException::class],
]);

it('fails a gateway that answers a request its capabilities say it cannot do', function (string $test): void {
    $broken = new BrokenContractCase('teeth');
    $broken->fault = BrokenGateway::ANSWERS_WHAT_IT_CANNOT;

    expect(fn() => $broken->{$test}())->toThrow(AssertionFailedError::class);
})->with([
    'testToolsAreRefusedWhenTheModelCannotCallTools',
    'testAForcedToolCallIsRefusedWhenTheModelCannotBeForced',
    'testAResponseFormatIsRefusedWhenTheModelCannotGiveAStructuredAnswer',
    'testToolsWithAResponseFormatAreRefusedWhenTheModelCannotTakeBoth',
]);

it('names the failure without repeating the key', function (): void {
    $broken = new BrokenContractCase('testNoKeyInAnyErrorOrItsTrace');
    $broken->fault = BrokenGateway::KEY_IN_DETAIL;

    try {
        $broken->testNoKeyInAnyErrorOrItsTrace();
        $message = 'the test passed';
    } catch (AssertionFailedError $failure) {
        $message = $failure->getMessage() . $failure->getTraceAsString();
    }

    expect($message)->toContain('shows the key the gateway signed in with')
        ->and(stripos($message, 'MARKER'))->toBeFalse();
});
