<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared;

use Error;
use Exception;
use Generator;
use InvalidArgumentException;
use LogicException;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ConnectionReport;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\Credentials\NoCredentials;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplierFailed;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\Operation;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoiceMode;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Transport\AttemptFinished;
use OrganiseYou\LlmGateway\Transport\AttemptOutcome;
use OrganiseYou\LlmGateway\Transport\AttemptStarted;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\Transport;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;
use SensitiveParameter;
use Throwable;

/**
 * Everything that is the same for every format: the deadline, the retries, the count of attempts, the latency, the
 * trace id, the reports to the observer, the mapping of a failure to its reason, the refusal of what a model cannot
 * do, the sign-in headers, the check of a structured answer and the stream of one event for a format without
 * streaming.
 *
 * An adapter for one format extends this class and writes the abstract methods. The class is readonly, so an
 * adapter is readonly too: it keeps nothing between two calls. The constructor is final: a gateway is always built
 * as `new Adapter($preset, $config, $credentials, $transport)`. An adapter reads the data of its preset from
 * `$this->preset` and a setting of the customer with setting(); the clock, the observer and the retry policy are
 * the base's alone.
 *
 * A method of an adapter that takes a response or a request should mark that parameter `#[\SensitiveParameter]`
 * itself: PHP does not inherit the attribute, and an error thrown there would otherwise keep the body in its trace.
 *
 * @phpstan-type Fault array{reason: ErrorReason, status: ?int, code: ?string, wait: ?float, retryable: bool, detail: ?string, outcome: AttemptOutcome, failure: ?TransportFailure, broken: ?Error}
 */
abstract readonly class AbstractAdapter implements Gateway
{
    /** The trace id of an answer or an error an adapter builds; the base replaces it with the one of the call. */
    private const string NO_TRACE_ID = 'not-set';

    private const string REDIRECTED = 'the server answered with a redirect, and a redirect is never followed';

    /** Every address starts with this: the resolved base URL without a slash at its end. */
    private string $baseUrl;

    /**
     * @throws GatewayException with the reason not_configured, when the credentials or the configuration do not
     *                          fit the preset; nothing is sent
     */
    final public function __construct(
        protected Preset $preset,
        private GatewayConfig $config,
        #[SensitiveParameter]
        private Credentials $credentials,
        private Transport $transport,
    ) {
        $known = $credentials instanceof NoCredentials
            || $credentials instanceof BearerKey
            || $credentials instanceof HeaderKey
            || $credentials instanceof BearerToken;

        if (! $known || ! in_array($credentials->kind(), $preset->credentialKinds, true)) {
            throw new GatewayException(
                ErrorReason::NotConfigured,
                self::newTraceId(),
                null,
                null,
                null,
                0,
                'the kind of the credentials is not one this preset accepts',
            );
        }

        $this->baseUrl = rtrim(GatewayFactory::resolveBaseUrl($preset, $config), '/');
    }

    // ---------------------------------------------------------------------------------------------------------
    // The five operations.
    // ---------------------------------------------------------------------------------------------------------

    final public function complete(Request $request): Answer
    {
        $started = $this->config->clock->monotonic();
        $traceId = $request->traceId ?? self::newTraceId();

        return $this->completion($request, Operation::Complete, $traceId, $started);
    }

    final public function stream(Request $request): iterable
    {
        return $this->events($request);
    }

    final public function models(float $timeoutSeconds, ?string $traceId = null): array
    {
        self::assertTimeout($timeoutSeconds);
        self::assertTraceId($traceId);

        $started = $this->config->clock->monotonic();
        $traceId ??= self::newTraceId();

        try {
            $template = $this->modelsRequest($timeoutSeconds);
        } catch (GatewayException $refusal) {
            throw $this->error($refusal->reason, $traceId, null, $refusal->providerCode, null, 0, $refusal->detail);
        }
        if ($template === null) {
            throw $this->error(ErrorReason::NotSupported, $traceId, null, null, null, 0, 'the models cannot be listed here');
        }

        $models = $this->exchange(Operation::Models, $this->owned($template), null, $traceId, $started, $started + $timeoutSeconds);
        if ($models instanceof Answer) {
            throw new LogicException('The model list was read as an answer.');
        }

        return $models;
    }

    final public function capabilities(string $model): Capabilities
    {
        self::assertModel($model);

        return $this->capabilitiesFor($model, null);
    }

    /**
     * Not built yet: until the connection check exists every call is refused as not_supported, and nothing is sent.
     */
    final public function check(string $model, float $timeoutSeconds, ?string $traceId = null): ConnectionReport
    {
        self::assertModel($model);
        self::assertTimeout($timeoutSeconds);
        self::assertTraceId($traceId);

        throw $this->error(ErrorReason::NotSupported, $traceId ?? self::newTraceId(), null, null, null, 0, 'the connection check is not available yet');
    }

    // ---------------------------------------------------------------------------------------------------------
    // What an adapter writes.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * What this model can do, from the data of the preset and what the adapter knows of its format. Must send
     * nothing. A model the adapter does not know gets the defaults of the format. The model name is checked before
     * this is called.
     */
    abstract protected function modelCapabilities(string $model): Capabilities;

    /**
     * The request of one completion in the format of the provider: method, address, headers and body. The address
     * comes from url(). The sign-in header and the time left are added by the base for every attempt; whatever
     * timeout is given here is replaced.
     *
     * A value the format cannot carry is refused by throwing failure(ErrorReason::Rejected, …).
     *
     * @param bool $stream true when the answer is asked as a stream; only when the capabilities say the model
     *                     streams natively
     */
    abstract protected function completionRequest(Request $request, bool $stream): HttpRequest;

    /**
     * The answer in a response with a status of 200 to 299, built with answer(). An error inside the body is
     * thrown as failure(ErrorReason::ProviderError, …).
     */
    abstract protected function readCompletion(HttpResponse $response, Request $request): Answer;

    /**
     * The neutral events of a stream with a status of 200 to 299. The last event is a StreamFinished whose answer
     * is built with answer(); the base fills in the latency, the attempts, the trace id and the structured result.
     * An error inside the stream is thrown as failure(…).
     *
     * @param iterable<int, SseEvent> $events
     * @return iterable<StreamEvent>
     */
    abstract protected function readStream(iterable $events, Request $request): iterable;

    /**
     * The request for the model list, or null when the format or this server has none.
     */
    abstract protected function modelsRequest(float $timeoutSeconds): ?HttpRequest;

    /**
     * The models in a response with a status of 200 to 299.
     *
     * @return list<ModelInfo>
     */
    abstract protected function readModels(HttpResponse $response): array;

    /**
     * The provider's own code in a failed response, or null. The base keeps it only when it is a plain word and is
     * not a credential.
     */
    protected function providerCode(#[SensitiveParameter] HttpResponse $response): ?string
    {
        return null;
    }

    /**
     * Lets a format change the reason the status gave, for a code its documentation names: an unknown model, for
     * example. Not asked for a redirect. A reason of rate_limited or unavailable is retried, every other is not.
     */
    protected function refineReason(#[SensitiveParameter] HttpResponse $response, ErrorReason $reason): ErrorReason
    {
        return $reason;
    }

    /**
     * The wait a failed response asks for, in seconds, or null when it tells none. The base reads the Retry-After
     * header, as seconds or as a date; a format that documents another field adds it here.
     */
    protected function retryAfterSeconds(#[SensitiveParameter] HttpResponse $response): ?float
    {
        $value = trim((string) $response->header('retry-after'));

        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) === 1) {
            return (float) $value;
        }

        $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
        $date = '/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), ([0-9]{2}) (' . implode('|', array_keys($months)) . ') ([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) GMT$/D';
        if (preg_match($date, $value, $part) === 1 && checkdate($months[$part[2]], (int) $part[1], (int) $part[3])
            && (int) $part[4] < 24 && (int) $part[5] < 60 && (int) $part[6] < 61) {
            $at = gmmktime((int) $part[4], (int) $part[5], (int) $part[6], $months[$part[2]], (int) $part[1], (int) $part[3]);

            return $at === false ? null : (float) max(0, $at - $this->config->clock->unixTime());
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------------------
    // What an adapter uses.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The one way to an address: the base URL of the gateway, a path and a query. A request whose address was not
     * built here is not sent, so the host of a request never changes.
     *
     * @param string $path starts with a slash; a part that comes from a request (a model name) is encoded by the
     *                     adapter with rawurlencode() first
     * @param array<string, int|string> $query
     */
    final protected function url(string $path, array $query = []): string
    {
        if (preg_match('/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/D', $path) !== 1) {
            throw new InvalidArgumentException('A path must start with a slash and hold only characters of a URL path.');
        }
        if (preg_match('/(?:^|\/)(?:\.|%2e){1,2}(?:\/|$)/Di', $path) === 1) {
            throw new InvalidArgumentException('A path must not hold a dot segment.');
        }

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * The value the customer gave for a field of the preset, or null when it gave none.
     */
    final protected function setting(string $key): ?string
    {
        return $this->config->settings[$key] ?? null;
    }

    /**
     * An answer as an adapter knows it: without the latency, the attempts and the trace id, which the base fills in.
     *
     * @param list<ToolCall> $toolCalls
     * @param string|null $model the model that answered; null or empty when the provider does not say
     * @param StructuredResult|null $structured only when the adapter got the structured answer its own way
     */
    final protected function answer(
        Request $request,
        #[SensitiveParameter]
        string $text,
        #[SensitiveParameter]
        array $toolCalls,
        FinishReason $finishReason,
        Usage $usage,
        #[SensitiveParameter]
        ?string $model = null,
        #[SensitiveParameter]
        ?StructuredResult $structured = null,
    ): Answer {
        return new Answer(
            $text,
            $structured,
            $toolCalls,
            $finishReason,
            $model === null || $model === '' ? $request->model : $model,
            $usage,
            0,
            1,
            self::NO_TRACE_ID,
        );
    }

    /**
     * The error an adapter throws: a value its format cannot carry (rejected, before a request is sent) or an error
     * inside a response that looked successful (provider_error). The base fills in the trace id, the attempts and
     * the HTTP status, and never retries it.
     *
     * @param string|null $detail a sentence written by the adapter: never text of a response
     */
    final protected function failure(
        ErrorReason $reason,
        #[SensitiveParameter]
        ?string $detail = null,
        #[SensitiveParameter]
        ?string $providerCode = null,
    ): GatewayException {
        return new GatewayException($reason, self::NO_TRACE_ID, null, $providerCode, null, 0, $detail);
    }

    // ---------------------------------------------------------------------------------------------------------
    // One completion, and one exchange with its retries.
    // ---------------------------------------------------------------------------------------------------------

    private function completion(Request $request, Operation $operation, string $traceId, float $started): Answer
    {
        $this->refuseWhatTheModelCannotDo($request, $traceId);

        $answer = $this->exchange(
            $operation,
            $this->template($request, false, $traceId),
            $request,
            $traceId,
            $started,
            $started + $request->timeoutSeconds,
        );
        if (! $answer instanceof Answer) {
            throw new LogicException('The answer was read as a model list.');
        }

        return $answer;
    }

    /**
     * Sends the request until it succeeds, cannot be retried, has no retry left or runs out of time.
     *
     * @param Request|null $request the completion to read from the response; null: the model list
     * @return Answer|list<ModelInfo>
     */
    private function exchange(
        Operation $operation,
        #[SensitiveParameter]
        HttpRequest $template,
        ?Request $request,
        string $traceId,
        float $started,
        float $deadline,
    ): Answer|array {
        $clock = $this->config->clock;

        for ($attempt = 1; ; $attempt++) {
            [$httpRequest, $inUse] = $this->signed($template, $deadline, $traceId, $attempt - 1);

            $this->reportStarted($operation, $traceId, $attempt, $httpRequest);
            $attemptStarted = $clock->monotonic();

            $response = null;
            $fault = null;
            $result = null;
            $foreign = null;

            try {
                $response = $this->transport->send($httpRequest);
            } catch (TransportException $thrown) {
                $fault = self::faultOfTransport($thrown->failure, null);
                unset($thrown);
            } catch (Throwable $thrown) {
                // Not a failure a transport may throw: it passes as it is, after the report of the attempt.
                $foreign = $thrown;
                unset($thrown);
            }
            if ($foreign !== null) {
                $this->reportFinished($operation, $traceId, $attempt, $httpRequest, null, self::faultOfTransport(TransportFailure::ConnectionFailed, null), false, $attemptStarted);

                throw $foreign;
            }

            if ($response !== null && ($response->status < 200 || $response->status > 299)) {
                $fault = $this->faultOfResponse($response);
            } elseif ($response !== null) {
                try {
                    $result = $this->read($response, $request, $attempt, $traceId, $started);
                } catch (GatewayException $thrown) {
                    $fault = self::faultOfAdapter($thrown, $response->status);
                } catch (Exception) {
                    $fault = self::unreadable($response->status, null);
                } catch (Error $thrown) {
                    $fault = self::unreadable($response->status, self::adapterError($thrown));
                    unset($thrown);
                }
            }

            $wait = $fault === null ? null : $this->waitBeforeRetry($fault, $attempt, $deadline);

            $this->reportFinished($operation, $traceId, $attempt, $httpRequest, $response, $fault, $wait !== null, $attemptStarted);

            if ($fault === null && $result !== null) {
                return $result;
            }
            if ($fault === null) {
                throw new LogicException('An attempt ended without a result and without a failure.');
            }
            if ($fault['broken'] !== null) {
                throw $fault['broken'];
            }
            if ($wait === null) {
                throw $this->errorOfFault($fault, $traceId, $attempt, $inUse);
            }

            $clock->sleep($wait);
        }
    }

    /**
     * @return Answer|list<ModelInfo>
     */
    private function read(
        #[SensitiveParameter]
        HttpResponse $response,
        ?Request $request,
        int $attempt,
        string $traceId,
        float $started,
    ): Answer|array {
        if ($request === null) {
            return $this->readModels($response);
        }

        return $this->finished($this->readCompletion($response, $request), $request, $attempt, $traceId, $started);
    }

    /**
     * The answer of the adapter with what only the base knows.
     *
     * @throws GatewayException provider_error, when the answer has no text and no tool call and was not cut off at
     *                          the token limit; rejected, when the schema of the response format cannot be used
     */
    private function finished(
        #[SensitiveParameter]
        Answer $answer,
        Request $request,
        int $attempts,
        string $traceId,
        float $started,
    ): Answer {
        // A model that spent every token before it gave any output is cut off, not broken: that answer is returned.
        if ($answer->text === '' && $answer->toolCalls === [] && $answer->finishReason !== FinishReason::Length) {
            throw $this->failure(ErrorReason::ProviderError, 'the answer has no text and no tool call');
        }

        // The place of the structured check. An answer has a structured result when its request has a response
        // format and the answer asks for no tool call: an answer that asks for tool calls is not the final answer,
        // and its text is not checked. A result the adapter got its own way (a forced tool call) is kept as it is
        // and not checked again; otherwise the text of the answer is checked here. A schema the validator cannot
        // use is thrown as rejected and takes the way of every error an adapter raises while it reads a response.
        $structured = null;
        if ($request->responseFormat !== null && $answer->toolCalls === []) {
            $structured = $answer->structured
                ?? (new StructuredAnswerChecker())->check($answer->text, $request->responseFormat, $answer->finishReason);
        }

        return new Answer(
            $answer->text,
            $structured,
            $answer->toolCalls,
            $answer->finishReason,
            $answer->model,
            $answer->usage,
            $this->millisecondsSince($started),
            $attempts,
            $traceId,
        );
    }

    // ---------------------------------------------------------------------------------------------------------
    // The stream.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Every event is given with a plain yield from this one generator, so the keys count up from 0.
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    private function events(Request $request): Generator
    {
        $clock = $this->config->clock;
        $started = $clock->monotonic();
        $deadline = $started + $request->timeoutSeconds;
        $traceId = $request->traceId ?? self::newTraceId();

        $answer = null;
        $template = null;
        try {
            if ($this->refuseWhatTheModelCannotDo($request, $traceId)->streaming === Support::Native) {
                $template = $this->template($request, true, $traceId);
            } else {
                // No streaming in this format: the whole answer, as the events a stream would have given.
                $answer = $this->completion($request, Operation::Stream, $traceId, $started);
            }
        } catch (GatewayException $refusal) {
            yield new StreamFailed($refusal, '', []);

            return;
        }

        if ($answer !== null) {
            foreach (self::answerAsEvents($answer) as $event) {
                yield $event;
            }

            return;
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                [$httpRequest, $inUse] = $this->signed($template, $deadline, $traceId, $attempt - 1);
            } catch (GatewayException $refusal) {
                yield new StreamFailed($refusal, '', []);

                return;
            }

            $this->reportStarted(Operation::Stream, $traceId, $attempt, $httpRequest);
            $attemptStarted = $clock->monotonic();

            $status = null;
            $headers = [];
            $read = '';
            $text = '';
            $toolCalls = [];
            $yielded = false;
            $fault = null;
            $open = false;
            $streamed = null;
            $foreign = null;

            try {
                $streamed = $this->transport->stream($httpRequest);
            } catch (TransportException $thrown) {
                $fault = self::faultOfTransport($thrown->failure, null);
                unset($thrown);
            } catch (Throwable $thrown) {
                // Not a failure a transport may throw: it passes as it is, after the report of the attempt.
                $foreign = $thrown;
                unset($thrown);
            }
            if ($foreign !== null) {
                $this->reportFinished(Operation::Stream, $traceId, $attempt, $httpRequest, null, self::faultOfTransport(TransportFailure::ConnectionFailed, null), false, $attemptStarted);

                throw $foreign;
            }

            try {
                if ($streamed !== null) {
                    $status = $streamed->status;
                    $headers = $streamed->headers;
                    $open = true;
                    $chunks = $this->guarded($streamed->chunks, $deadline, $read);
                    unset($streamed);

                    try {
                        if ($status < 200 || $status > 299) {
                            // The chunks are the error body. One that breaks half-way still tells its status.
                            try {
                                foreach ($chunks as $chunk) {
                                    unset($chunk);
                                }
                            } catch (TransportException $thrown) {
                                if ($thrown->failure === TransportFailure::TimedOut) {
                                    $fault = self::faultOfTransport(TransportFailure::TimedOut, $status);
                                }
                                unset($thrown);
                            }
                            $fault ??= $this->faultOfResponse(new HttpResponse($status, $headers, $read));
                        } else {
                            foreach ($this->whatTheAdapterReads($chunks, $request) as $event) {
                                if (! $event instanceof StreamEvent) {
                                    // A fault in the code of the adapter: nothing of it is handed to the caller.
                                    $fault = self::unreadable($status, new Error('The adapter gave a value that is not a stream event.'));

                                    break;
                                }
                                if ($event instanceof StreamFailed) {
                                    $fault = self::faultOfAdapter($event->error, $status);

                                    break;
                                }
                                if ($event instanceof StreamFinished) {
                                    $answer = $this->finished($event->answer, $request, $attempt, $traceId, $started);
                                    $open = false;
                                    $this->reportFinished(Operation::Stream, $traceId, $attempt, $httpRequest, new HttpResponse($status, $headers, $read), null, false, $attemptStarted);

                                    yield new StreamFinished($answer);

                                    return;
                                }
                                if ($event instanceof TextDelta) {
                                    $text .= $event->text;
                                } elseif ($event instanceof ToolCallCompleted) {
                                    $toolCalls[] = $event->toolCall;
                                }

                                $yielded = true;
                                yield $event;
                            }

                            $fault ??= self::faultOfAdapter($this->failure(ErrorReason::ProviderError, 'the stream ended without its last event'), $status);
                        }
                    } catch (TransportException $thrown) {
                        $fault = self::faultOfTransport($thrown->failure, $status);
                        unset($thrown);
                    } catch (GatewayException $thrown) {
                        $fault = self::faultOfAdapter($thrown, $status);
                    } catch (Exception) {
                        $fault = self::unreadable($status, null);
                    } catch (Error $thrown) {
                        $fault = self::unreadable($status, self::adapterError($thrown));
                        unset($thrown);
                    }
                }
                if ($fault === null) {
                    throw new LogicException('An attempt ended without a result and without a failure.');
                }

                // Only a stream that gave nothing yet may be tried again.
                $wait = $yielded ? null : $this->waitBeforeRetry($fault, $attempt, $deadline);

                $open = false;
                $this->reportFinished(
                    Operation::Stream,
                    $traceId,
                    $attempt,
                    $httpRequest,
                    $status === null ? null : new HttpResponse($status, $headers, $read),
                    $fault,
                    $wait !== null,
                    $attemptStarted,
                );

                if ($fault['broken'] !== null) {
                    throw $fault['broken'];
                }
                if ($wait === null) {
                    yield new StreamFailed($this->errorOfFault($fault, $traceId, $attempt, $inUse), $text, $toolCalls);

                    return;
                }
            } finally {
                // The caller stopped reading: the attempt is still reported, with what was read until then.
                if ($open && $status !== null) {
                    $this->reportFinished(Operation::Stream, $traceId, $attempt, $httpRequest, new HttpResponse($status, $headers, $read), null, false, $attemptStarted);
                }
            }

            $clock->sleep($wait);
        }
    }

    /**
     * What the adapter makes of a stream. Its promise is a list of stream events; what it really gives is checked.
     *
     * @param iterable<string> $chunks
     * @return iterable<mixed>
     */
    private function whatTheAdapterReads(#[SensitiveParameter] iterable $chunks, Request $request): iterable
    {
        return $this->readStream((new SseReader())->read($chunks), $request);
    }

    /**
     * The chunks of the transport, with the deadline of the call checked for every chunk and every byte kept for
     * the report of the attempt.
     *
     * @param iterable<string> $chunks
     * @return Generator<int, string, mixed, void>
     * @throws TransportException timed_out, when a chunk arrives after the deadline
     */
    private function guarded(
        #[SensitiveParameter]
        iterable $chunks,
        float $deadline,
        #[SensitiveParameter]
        string &$read,
    ): Generator {
        $clock = $this->config->clock;

        foreach ($chunks as $chunk) {
            $read .= $chunk;
            if ($clock->monotonic() >= $deadline) {
                throw new TransportException(TransportFailure::TimedOut);
            }

            yield $chunk;
        }
    }

    /**
     * The events of a stream for an answer that arrived whole.
     *
     * @return list<StreamEvent>
     */
    private static function answerAsEvents(#[SensitiveParameter] Answer $answer): array
    {
        $events = [];
        if ($answer->text !== '') {
            $events[] = new TextDelta($answer->text);
        }
        foreach ($answer->toolCalls as $index => $toolCall) {
            $events[] = new ToolCallStarted($index, $toolCall->id, $toolCall->name);
            $events[] = new ToolCallCompleted($index, $toolCall);
        }
        if ($answer->usage->inputTokens !== null || $answer->usage->outputTokens !== null) {
            $events[] = new UsageReported($answer->usage);
        }
        $events[] = new StreamFinished($answer);

        return $events;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Before an attempt: what the model can do, the request of the adapter, the sign-in.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @throws GatewayException not_supported, before any request
     */
    private function refuseWhatTheModelCannotDo(Request $request, string $traceId): Capabilities
    {
        $can = $this->capabilitiesFor($request->model, $traceId);
        $hasTools = $request->tools !== [];
        $hasFormat = $request->responseFormat !== null;

        $detail = match (true) {
            $hasTools && $can->toolCalling === Support::None => 'this model cannot call tools',
            $request->toolChoice->mode === ToolChoiceMode::Tool && $can->forcedToolCall === Support::None => 'this model cannot be made to call one named tool',
            $hasFormat && $can->structuredOutput === Support::None => 'this model cannot give a structured answer',
            $hasTools && $hasFormat && $can->toolsWithStructuredOutput === Support::None => 'this model cannot take tools and a response format in one request',
            default => null,
        };
        if ($detail !== null) {
            throw $this->error(ErrorReason::NotSupported, $traceId, null, null, null, 0, $detail);
        }

        return $can;
    }

    /**
     * The capabilities the adapter states. An error the adapter raises there gets the trace id of the call.
     *
     * @param string|null $traceId null: outside a call, a new one is made
     */
    private function capabilitiesFor(string $model, ?string $traceId): Capabilities
    {
        try {
            return $this->modelCapabilities($model);
        } catch (GatewayException $refusal) {
            throw $this->error($refusal->reason, $traceId ?? self::newTraceId(), null, $refusal->providerCode, null, 0, $refusal->detail);
        }
    }

    /**
     * The request of the adapter, before the sign-in and the time left are added.
     *
     * @throws GatewayException what the adapter refused, with the trace id of the call
     */
    private function template(Request $request, bool $stream, string $traceId): HttpRequest
    {
        try {
            $template = $this->completionRequest($request, $stream);
        } catch (GatewayException $refusal) {
            throw $this->error($refusal->reason, $traceId, null, $refusal->providerCode, null, 0, $refusal->detail);
        }

        return $this->owned($template);
    }

    /**
     * Refuses a request whose address does not start with the base URL of the gateway.
     */
    private function owned(#[SensitiveParameter] HttpRequest $template): HttpRequest
    {
        $rest = str_starts_with($template->url, $this->baseUrl) ? substr($template->url, strlen($this->baseUrl)) : null;

        if ($rest === null
            || ($rest !== '' && $rest[0] !== '/' && $rest[0] !== '?')
            || preg_match('/\/(?:\.|%2e){1,2}(?:[\/?]|$)/Di', $rest) === 1) {
            throw new LogicException(
                'An adapter must build every address with url(): this request does not stay under the base URL of the gateway.',
            );
        }

        return $template;
    }

    /**
     * The request of one attempt: the template with the sign-in header and the time that is left.
     *
     * @param int $attemptsMade the attempts before this one
     * @return array{HttpRequest, list<Secret>} the request, and the secrets that were asked for this attempt alone
     * @throws GatewayException timeout, when the time of the call is used up; credentials_rejected, when the token
     *                          supplier gave no usable token
     */
    private function signed(
        #[SensitiveParameter]
        HttpRequest $template,
        float $deadline,
        string $traceId,
        int $attemptsMade,
    ): array {
        $clock = $this->config->clock;
        $outOfTime = 'the time of the call was used up before an attempt';

        if ($this->secondsUntil($deadline) <= 0.0) {
            throw $this->error(ErrorReason::Timeout, $traceId, null, null, null, $attemptsMade, $outOfTime);
        }

        $signIn = [];
        $inUse = [];
        $supplierFailed = false;
        $credentials = $this->credentials;

        if ($credentials instanceof BearerKey) {
            $signIn['Authorization'] = self::bearer($credentials->key);
        } elseif ($credentials instanceof HeaderKey) {
            $signIn[(string) $this->preset->keyHeader] = $credentials->key;
        } elseif ($credentials instanceof BearerToken) {
            try {
                $token = $credentials->token();
                $inUse[] = $token;
                $signIn['Authorization'] = self::bearer($token);
            } catch (TokenSupplierFailed) {
                // Its text names a class of the host: nothing of it is copied.
                $supplierFailed = true;
            }
        }
        if ($supplierFailed) {
            throw $this->error(ErrorReason::CredentialsRejected, $traceId, null, null, null, $attemptsMade, 'the token supplier gave no usable token');
        }

        // The supplier's time counts against the call.
        $remaining = $this->secondsUntil($deadline);
        if ($remaining <= 0.0) {
            throw $this->error(ErrorReason::Timeout, $traceId, null, null, null, $attemptsMade, $outOfTime);
        }

        return [
            new HttpRequest(
                $template->method,
                $template->url,
                $template->headers,
                [...$template->secretHeaders, ...$signIn],
                $remaining,
                $template->body,
            ),
            $inUse,
        ];
    }

    private static function bearer(Secret $secret): Secret
    {
        return new Secret('Bearer ' . $secret->reveal());
    }

    // ---------------------------------------------------------------------------------------------------------
    // After an attempt: what failed, whether to try again, the error.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @return Fault
     */
    private static function faultOfTransport(TransportFailure $failure, ?int $status): array
    {
        [$reason, $retryable, $detail] = match ($failure) {
            TransportFailure::ConnectionFailed => [ErrorReason::Unavailable, true, 'the connection failed or broke'],
            TransportFailure::TimedOut => [ErrorReason::Timeout, false, 'the time of the call ran out while waiting for the response'],
            TransportFailure::Redirected => [ErrorReason::Unavailable, false, self::REDIRECTED],
            TransportFailure::Blocked => [ErrorReason::NotConfigured, false, 'the transport of the host refused the request'],
        };

        return [
            'reason' => $reason,
            'status' => $status,
            'code' => null,
            'wait' => null,
            'retryable' => $retryable,
            'detail' => $detail,
            'outcome' => AttemptOutcome::TransportError,
            'failure' => $failure,
            'broken' => null,
        ];
    }

    /**
     * The reason of a response with a status outside 200 to 299.
     *
     * @return Fault
     */
    private function faultOfResponse(#[SensitiveParameter] HttpResponse $response): array
    {
        $status = $response->status;
        $redirect = $status >= 300 && $status <= 399;

        $reason = match (true) {
            $status === 401, $status === 403 => ErrorReason::CredentialsRejected,
            $status === 429 => ErrorReason::RateLimited,
            $status === 408, $status >= 500 => ErrorReason::Unavailable,
            $redirect => ErrorReason::Unavailable,
            $status >= 400 => ErrorReason::Rejected,
            default => ErrorReason::ProviderError,
        };

        // Each question to the adapter on its own: one it cannot answer (a body that is no JSON) does not take
        // the others with it. An exception leaves what the status and the headers say; an error is a fault.
        $code = null;
        $wait = null;
        $broken = null;
        try {
            if (! $redirect) {
                $reason = $this->refineReason($response, $reason);
            }
        } catch (Exception) {
            // The status alone decides.
        } catch (Error $thrown) {
            $broken = self::adapterError($thrown);
            unset($thrown);
        }
        $retryable = ! $redirect && ($reason === ErrorReason::RateLimited || $reason === ErrorReason::Unavailable);
        try {
            $code = $this->providerCode($response);
        } catch (Exception) {
            // No code.
        } catch (Error $thrown) {
            $broken ??= self::adapterError($thrown);
            unset($thrown);
        }
        try {
            $wait = $retryable ? $this->retryAfterSeconds($response) : null;
        } catch (Exception) {
            // No wait told.
        } catch (Error $thrown) {
            $broken ??= self::adapterError($thrown);
            unset($thrown);
        }
        return [
            'reason' => $reason,
            'status' => $status,
            'code' => $code,
            'wait' => $wait === null || is_nan($wait) ? null : max(0.0, $wait),
            'retryable' => $retryable && $broken === null,
            'detail' => $redirect ? self::REDIRECTED : null,
            'outcome' => AttemptOutcome::HttpError,
            'failure' => null,
            'broken' => $broken,
        ];
    }

    /**
     * An error the adapter threw while it read a response. Never retried.
     *
     * @return Fault
     */
    private static function faultOfAdapter(GatewayException $thrown, ?int $status): array
    {
        return [
            'reason' => $thrown->reason,
            'status' => $status,
            'code' => $thrown->providerCode,
            'wait' => null,
            'retryable' => false,
            'detail' => $thrown->detail,
            'outcome' => AttemptOutcome::HttpError,
            'failure' => null,
            'broken' => null,
        ];
    }

    /**
     * @param Error|null $broken the error to throw in place of a fault in the code of the adapter, after the report
     * @return Fault
     */
    private static function unreadable(?int $status, #[SensitiveParameter] ?Error $broken): array
    {
        return [
            'reason' => ErrorReason::ProviderError,
            'status' => $status,
            'code' => null,
            'wait' => null,
            'retryable' => false,
            'detail' => 'the response could not be read',
            'outcome' => AttemptOutcome::HttpError,
            'failure' => null,
            'broken' => $broken,
        ];
    }

    /**
     * An error in the code of an adapter is a fault, not a failure of the provider: it is not mapped to a reason
     * and not retried. A new error with a fixed text is thrown in its place, not chained, so that no frame of the
     * adapter with a response in it survives.
     */
    private static function adapterError(#[SensitiveParameter] Error $thrown): Error
    {
        // An anonymous class carries the path of its file after a null byte.
        $class = strstr($thrown::class, "\0", true);

        return new Error(sprintf(
            'The adapter failed with an error of class %s, thrown in %s on line %d.',
            $class === false ? $thrown::class : $class,
            basename($thrown->getFile()),
            $thrown->getLine(),
        ));
    }

    /**
     * The wait before the next attempt, or null when the failure of this attempt is the result of the call.
     *
     * @param Fault $fault
     */
    private function waitBeforeRetry(#[SensitiveParameter] array $fault, int $attempt, float $deadline): ?float
    {
        $policy = $this->config->retry;

        if (! $fault['retryable']) {
            return null;
        }
        if ($fault['wait'] !== null && $fault['wait'] > $policy->maxProviderWaitSeconds) {
            return null;
        }
        if (! isset($policy->waitsSeconds[$attempt - 1])) {
            return null;
        }

        $wait = $fault['wait'] ?? $policy->waitsSeconds[$attempt - 1];
        if ($this->config->clock->monotonic() + $wait >= $deadline) {
            return null;
        }

        return $wait;
    }

    /**
     * @param Fault $fault
     * @param list<Secret> $inUse
     */
    private function errorOfFault(
        #[SensitiveParameter]
        array $fault,
        string $traceId,
        int $attempts,
        #[SensitiveParameter]
        array $inUse,
    ): GatewayException {
        return $this->error($fault['reason'], $traceId, $fault['status'], $fault['code'], $fault['wait'], $attempts, $fault['detail'], $inUse);
    }

    /**
     * The one place a gateway exception is built after the constructor: the status and the wait are brought into
     * their ranges, and a provider code or a detail that holds a credential is dropped.
     *
     * @param list<Secret> $inUse the secrets asked for this attempt alone: a token of the supplier
     */
    private function error(
        ErrorReason $reason,
        string $traceId,
        ?int $status,
        #[SensitiveParameter]
        ?string $code,
        ?float $wait,
        int $attempts,
        #[SensitiveParameter]
        ?string $detail,
        #[SensitiveParameter]
        array $inUse = [],
    ): GatewayException {
        if ($status !== null) {
            $status = max(100, min(599, $status));
        }

        $seconds = null;
        if ($wait !== null && ! is_nan($wait) && ($reason === ErrorReason::RateLimited || $reason === ErrorReason::Unavailable)) {
            $seconds = (int) ceil(max(0.0, min($wait, 2_147_483_647.0)));
        }

        // A code or a detail that holds a credential anywhere in it, in any case, is dropped whole.
        foreach ([...$this->credentials->secrets(), ...$inUse] as $secret) {
            if ($code !== null && stripos($code, $secret->reveal()) !== false) {
                $code = null;
            }
            if ($detail !== null && stripos($detail, $secret->reveal()) !== false) {
                $detail = null;
            }
        }

        return new GatewayException($reason, $traceId, $status, $code, $seconds, max(0, $attempts), $detail);
    }

    // ---------------------------------------------------------------------------------------------------------
    // The reports to the observer. Whatever the observer throws is dropped: a report never changes a call.
    // ---------------------------------------------------------------------------------------------------------

    private function reportStarted(
        Operation $operation,
        string $traceId,
        int $attempt,
        #[SensitiveParameter]
        HttpRequest $request,
    ): void {
        try {
            $this->config->observer->attemptStarted(
                new AttemptStarted($this->preset->id, $operation, $traceId, $attempt, $request),
            );
        } catch (Throwable) {
            // Dropped.
        }
    }

    /**
     * @param Fault|null $fault null: the attempt succeeded
     */
    private function reportFinished(
        Operation $operation,
        string $traceId,
        int $attempt,
        #[SensitiveParameter]
        HttpRequest $request,
        #[SensitiveParameter]
        ?HttpResponse $response,
        #[SensitiveParameter]
        ?array $fault,
        bool $willRetry,
        float $attemptStarted,
    ): void {
        try {
            $this->config->observer->attemptFinished(new AttemptFinished(
                $this->preset->id,
                $operation,
                $traceId,
                $attempt,
                $request,
                $response,
                $fault === null ? AttemptOutcome::Succeeded : $fault['outcome'],
                $fault === null ? null : $fault['failure'],
                $willRetry,
                $this->millisecondsSince($attemptStarted),
            ));
        } catch (Throwable) {
            // Dropped.
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Small rules.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @phpstan-impure the clock moves between two calls
     */
    private function secondsUntil(float $deadline): float
    {
        return $deadline - $this->config->clock->monotonic();
    }

    private function millisecondsSince(float $moment): int
    {
        return max(0, (int) round(($this->config->clock->monotonic() - $moment) * 1000));
    }

    private static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function assertTimeout(float $timeoutSeconds): void
    {
        if (! Rules::isTimeout($timeoutSeconds)) {
            throw new InvalidArgumentException('The timeout must be a finite number of seconds above 0.');
        }
    }

    private static function assertTraceId(?string $traceId): void
    {
        if ($traceId !== null && ! Rules::isTraceId($traceId)) {
            throw new InvalidArgumentException('The trace id must match ^[A-Za-z0-9_.:-]{1,128}$.');
        }
    }

    private static function assertModel(string $model): void
    {
        if (! Rules::isModelName($model)) {
            throw new InvalidArgumentException('The model must not be empty and must not hold a control character.');
        }
    }
}
