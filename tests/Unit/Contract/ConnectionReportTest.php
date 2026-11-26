<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\CheckResult;
use OrganiseYou\LlmGateway\Contract\CheckStatus;
use OrganiseYou\LlmGateway\Contract\ConnectionReport;
use OrganiseYou\LlmGateway\Contract\ErrorReason;

function reportOf(CheckStatus ...$statuses): ConnectionReport
{
    $results = array_map(fn(CheckStatus $status): CheckResult => new CheckResult($status), array_values($statuses));

    return new ConnectionReport($results[0], $results[1], $results[2], $results[3], $results[4], 'trace-1');
}

describe('CheckStatus', function (): void {
    it('has the four answers of the design', function (): void {
        expect(array_map(fn(CheckStatus $status): string => $status->value, CheckStatus::cases()))
            ->toBe(['yes', 'no', 'not_supported', 'not_run']);
    });
});

describe('CheckResult', function (): void {
    it('holds a status alone', function (): void {
        $result = new CheckResult(CheckStatus::Yes);

        expect($result->status)->toBe(CheckStatus::Yes)
            ->and($result->reason)->toBeNull()
            ->and($result->providerCode)->toBeNull();
    });

    it('holds a reason and a lower-cased provider code with the status no', function (): void {
        $result = new CheckResult(CheckStatus::No, ErrorReason::CredentialsRejected, 'Invalid_Key');

        expect($result->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and($result->providerCode)->toBe('invalid_key');
    });

    it('drops a provider code that is not a plain word, without an exception', function (string $code): void {
        expect((new CheckResult(CheckStatus::No, ErrorReason::Rejected, $code))->providerCode)->toBeNull();
    })->with([
        'a sentence' => ['the key is wrong'],
        'empty' => [''],
        'longer than 64' => [str_repeat('a', 65)],
        'a line break at the end' => ["code\n"],
    ]);

    it('refuses a reason with a status other than no', function (CheckStatus $status): void {
        expect(fn() => new CheckResult($status, ErrorReason::Rejected))
            ->toThrow(InvalidArgumentException::class, 'A reason goes only with the status no.');
    })->with([[CheckStatus::Yes], [CheckStatus::NotSupported], [CheckStatus::NotRun]]);

    it('refuses a provider code with a status other than no', function (CheckStatus $status): void {
        expect(fn() => new CheckResult($status, null, 'some_code'))
            ->toThrow(InvalidArgumentException::class, 'A provider code goes only with the status no.');
    })->with([[CheckStatus::Yes], [CheckStatus::NotSupported], [CheckStatus::NotRun]]);

    it('keeps a refused provider code out of the refusal', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            try {
                new CheckResult(CheckStatus::Yes, null, LEAK_MARKER);
                $refusal = null;
            } catch (InvalidArgumentException $thrown) {
                $refusal = $thrown;
            }

            expect($refusal)->not->toBeNull();
            expectNoMarker(exceptionForms($refusal ?? new LogicException('not refused')));
        });
    });
});

describe('ConnectionReport', function (): void {
    it('holds the five answers and the trace id', function (): void {
        $report = reportOf(CheckStatus::Yes, CheckStatus::Yes, CheckStatus::No, CheckStatus::NotSupported, CheckStatus::NotRun);

        expect($report->signIn->status)->toBe(CheckStatus::Yes)
            ->and($report->modelExists->status)->toBe(CheckStatus::Yes)
            ->and($report->toolCall->status)->toBe(CheckStatus::No)
            ->and($report->structuredAnswer->status)->toBe(CheckStatus::NotSupported)
            ->and($report->stream->status)->toBe(CheckStatus::NotRun)
            ->and($report->traceId)->toBe('trace-1');
    });

    it('has passed when every answer is yes or not supported', function (): void {
        expect(reportOf(CheckStatus::Yes, CheckStatus::Yes, CheckStatus::NotSupported, CheckStatus::Yes, CheckStatus::NotSupported)->allPassed())->toBeTrue();
    });

    it('has not passed when an answer is no or not run, whichever of the five it is', function (int $position, CheckStatus $status): void {
        $statuses = array_fill(0, 5, CheckStatus::Yes);
        $statuses[$position] = $status;

        expect(reportOf(...$statuses)->allPassed())->toBeFalse();
    })->with([0, 1, 2, 3, 4])->with([[CheckStatus::No], [CheckStatus::NotRun]]);

    it('refuses a trace id that breaks the pattern', function (string $traceId): void {
        $yes = new CheckResult(CheckStatus::Yes);

        expect(fn() => new ConnectionReport($yes, $yes, $yes, $yes, $yes, $traceId))
            ->toThrow(InvalidArgumentException::class, 'The trace id must match');
    })->with([
        'empty' => [''],
        'a space' => ['trace 1'],
        'longer than 128' => [str_repeat('a', 129)],
        'a line break at the end' => ["trace-1\n"],
    ]);
});
