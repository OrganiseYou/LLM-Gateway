<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Tests\Support\BrokenContractCase;
use OrganiseYou\LlmGateway\Tests\Support\BrokenGateway;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;

// The shared test set must fail a gateway that breaks the contract. Each row names one fault and the test of the
// set that must catch it. The test is first run on the sound gateway, where it passes, so the failure that follows
// comes from the fault and from nothing else.

it('fails a gateway that', function (string $fault, string $test, string $failsWith = AssertionFailedError::class, bool $onTheAdapter = false): void {
    $sound = new BrokenContractCase('teeth');
    $sound->onTheAdapter = $onTheAdapter || in_array($fault, BrokenContractCase::ON_THE_ADAPTER, true);
    $sound->{$test}();

    $broken = new BrokenContractCase('teeth');
    $broken->onTheAdapter = $onTheAdapter;
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
    // The opaque part: each assertion of the set on it, with the gateway that breaks it.
    BrokenGateway::DROPS_OPAQUE => [BrokenGateway::DROPS_OPAQUE, 'testAToolCallWithOpaqueData'],
    BrokenGateway::DROPS_OPAQUE . ' (in a stream)' => [BrokenGateway::DROPS_OPAQUE, 'testAStreamOfAToolCallWithOpaqueData'],
    BrokenGateway::DROPS_OPAQUE . ' (on the adapter)' => [BrokenGateway::DROPS_OPAQUE, 'testAToolCallWithOpaqueData', AssertionFailedError::class, true],
    BrokenGateway::DROPS_OPAQUE . ' (on the adapter, in a stream)' => [BrokenGateway::DROPS_OPAQUE, 'testAStreamOfAToolCallWithOpaqueData', AssertionFailedError::class, true],
    BrokenContractCase::OPAQUE_FOR_THE_STREAM_ONLY => [BrokenContractCase::OPAQUE_FOR_THE_STREAM_ONLY, 'testOpaqueDataGoesBackWithTheMessageItCameWith', LogicException::class],
    BrokenContractCase::OPAQUE_FOR_THE_STREAM_ONLY . ' (asked for the stream)' => [BrokenContractCase::OPAQUE_FOR_THE_STREAM_ONLY, 'testAStreamOfAToolCallWithOpaqueData', LogicException::class],
    BrokenGateway::KEY_IN_OPAQUE => [BrokenGateway::KEY_IN_OPAQUE, 'testAToolCallWithOpaqueData'],
    BrokenGateway::KEY_IN_OPAQUE . ' (in a stream)' => [BrokenGateway::KEY_IN_OPAQUE, 'testAStreamOfAToolCallWithOpaqueData'],
    BrokenGateway::KEY_IN_OPAQUE . ' (on the adapter)' => [BrokenGateway::KEY_IN_OPAQUE, 'testAToolCallWithOpaqueData', AssertionFailedError::class, true],
    BrokenGateway::OPAQUE_IN_TEXT => [BrokenGateway::OPAQUE_IN_TEXT, 'testAToolCallWithOpaqueData'],
    BrokenGateway::OPAQUE_IN_TEXT . ' (in a stream)' => [BrokenGateway::OPAQUE_IN_TEXT, 'testAStreamOfAToolCallWithOpaqueData'],
    BrokenGateway::CHANGES_OPAQUE_BYTES . ' (the fake: the part that arrives is not the scripted one)' => [BrokenGateway::CHANGES_OPAQUE_BYTES, 'testOpaqueDataGoesBackWithTheMessageItCameWith'],
    BrokenGateway::CHANGES_OPAQUE_BYTES . ' (on the adapter: a changed part is not sent back)' => [BrokenGateway::CHANGES_OPAQUE_BYTES, 'testOpaqueDataGoesBackWithTheMessageItCameWith', AssertionFailedError::class, true],
    BrokenGateway::DROPS_OPAQUE . ' (seen in the next round too)' => [BrokenGateway::DROPS_OPAQUE, 'testOpaqueDataGoesBackWithTheMessageItCameWith'],
    BrokenContractCase::SENDS_OPAQUE_OF_A_CHANGED_MESSAGE => [BrokenContractCase::SENDS_OPAQUE_OF_A_CHANGED_MESSAGE, 'testOpaqueDataIsNotSentWithAMessageThatWasChanged'],
    BrokenContractCase::SENDS_FOREIGN_OPAQUE => [BrokenContractCase::SENDS_FOREIGN_OPAQUE, 'testOpaqueDataThatIsNotTheGatewaysOwnIsNotSent'],
    BrokenContractCase::SENDS_FOREIGN_OPAQUE . ' (a changed message too)' => [BrokenContractCase::SENDS_FOREIGN_OPAQUE, 'testOpaqueDataIsNotSentWithAMessageThatWasChanged'],
    BrokenGateway::OPAQUE_IN_DETAIL => [BrokenGateway::OPAQUE_IN_DETAIL, 'testOpaqueDataIsInNoErrorAndInNoEventOfTheNextRound'],
    BrokenGateway::OPAQUE_IN_DETAIL . ' (on the adapter)' => [BrokenGateway::OPAQUE_IN_DETAIL, 'testOpaqueDataIsInNoErrorAndInNoEventOfTheNextRound', AssertionFailedError::class, true],
    // The finish reasons: a refusal and a withheld answer are answers, each with its own finish reason.
    BrokenGateway::REFUSAL_AS_STOP => [BrokenGateway::REFUSAL_AS_STOP, 'testAnAnswerTheModelDeclinedToGiveIsAnAnswerWithItsOwnFinishReason'],
    BrokenGateway::REFUSAL_AS_STOP . ' (in a stream)' => [BrokenGateway::REFUSAL_AS_STOP, 'testAStreamOfAnAnswerTheModelDeclinedToGive'],
    BrokenGateway::REFUSAL_AS_STOP . ' (on the adapter)' => [BrokenGateway::REFUSAL_AS_STOP, 'testAnAnswerTheModelDeclinedToGiveIsAnAnswerWithItsOwnFinishReason', AssertionFailedError::class, true],
    BrokenGateway::THROWS_WITHHELD => [BrokenGateway::THROWS_WITHHELD, 'testAnAnswerTheProviderWithheldIsAnAnswerWithoutTextNotAnError'],
    BrokenGateway::THROWS_WITHHELD . ' (in a stream)' => [BrokenGateway::THROWS_WITHHELD, 'testAStreamOfAnAnswerTheProviderWithheldEndsWithAnAnswer'],
    BrokenGateway::THROWS_WITHHELD . ' (on the adapter)' => [BrokenGateway::THROWS_WITHHELD, 'testAnAnswerTheProviderWithheldIsAnAnswerWithoutTextNotAnError', AssertionFailedError::class, true],
    BrokenGateway::THROWS_WITHHELD . ' (on the adapter, in a stream)' => [BrokenGateway::THROWS_WITHHELD, 'testAStreamOfAnAnswerTheProviderWithheldEndsWithAnAnswer', AssertionFailedError::class, true],
    BrokenGateway::WITHHELD_AS_STOP => [BrokenGateway::WITHHELD_AS_STOP, 'testAnAnswerTheProviderWithheldIsAnAnswerWithoutTextNotAnError'],
    BrokenGateway::WITHHELD_AS_STOP . ' (in a stream)' => [BrokenGateway::WITHHELD_AS_STOP, 'testAStreamOfAnAnswerTheProviderWithheldEndsWithAnAnswer'],
    BrokenContractCase::REFUSAL_FOR_THE_STREAM_ONLY => [BrokenContractCase::REFUSAL_FOR_THE_STREAM_ONLY, 'testAnAnswerTheModelDeclinedToGiveIsAnAnswerWithItsOwnFinishReason', LogicException::class],
    BrokenContractCase::PLAIN_ANSWER_DOES_NOT_APPLY => [BrokenContractCase::PLAIN_ANSWER_DOES_NOT_APPLY, 'testAPlainAnswer', LogicException::class],
    // The retry hook: a response that forbids a retry ends the call, with the reason of its status and no wait.
    BrokenContractCase::RETRIES_WHAT_FORBIDS_IT => [BrokenContractCase::RETRIES_WHAT_FORBIDS_IT, 'testAResponseThatForbidsARetryIsNotTriedAgain'],
    BrokenContractCase::RETRIES_WHAT_FORBIDS_IT . ' (seen by the retry rule too)' => [BrokenContractCase::RETRIES_WHAT_FORBIDS_IT, 'testOnlyTheFailuresThatMayBeRetriedAreTriedAgain'],
    BrokenGateway::WAIT_AFTER_NO_RETRY => [BrokenGateway::WAIT_AFTER_NO_RETRY, 'testAResponseThatForbidsARetryIsNotTriedAgain', AssertionFailedError::class, true],
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
    'testATemperatureIsRefusedWhenTheModelTakesNone',
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

// A host project runs the set under its own php.ini, which may be the one for production: no arguments in traces
// and no bytes of a string argument in their string form. The verdict on a key in an error must be the same there.

dataset('the settings of PHP for traces', [
    'PHP without a php.ini, and php.ini-development' => [false, 15],
    'arguments kept, no bytes of a string argument shown' => [false, 0],
    'php.ini-production' => [true, 0],
    'arguments left out, 15 bytes of a string argument' => [true, 15],
]);

it('gives the same verdict on a key in an error under', function (bool $ignoreArgs, int $stringLength, string $fault): void {
    withExceptionIgnoreArgs($ignoreArgs, function () use ($fault): void {
        (new BrokenContractCase('teeth'))->testNoKeyInAnyErrorOrItsTrace();

        $broken = new BrokenContractCase('teeth');
        $broken->fault = $fault;

        expect(fn() => $broken->testNoKeyInAnyErrorOrItsTrace())->toThrow(AssertionFailedError::class, 'shows the key the gateway signed in with');
    }, $stringLength);
})->with('the settings of PHP for traces')->with([BrokenGateway::KEY_IN_DETAIL, BrokenGateway::KEY_AS_CODE]);

it('says so with the setting to change, and fails nothing, when its search of the traces saw no arguments under', function (bool $ignoreArgs, int $stringLength): void {
    withExceptionIgnoreArgs($ignoreArgs, function () use ($ignoreArgs): void {
        $case = new BrokenContractCase('teeth');

        try {
            $case->testTheTracesAreSearchedWithTheArgumentsOfEveryCall();
            $skipped = null;
        } catch (SkippedTest $skip) {
            $skipped = $skip->getMessage();
        }

        if (! $ignoreArgs) {
            expect($skipped)->toBeNull();

            return;
        }

        expect($skipped)->toContain('zend.exception_ignore_args')
            ->toContain('<ini name="zend.exception_ignore_args" value="0"/>');
    }, $stringLength);
})->with('the settings of PHP for traces');
