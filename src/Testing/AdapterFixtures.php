<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

/**
 * The responses of one format, for the shared test set. Written by hand from the documentation of the format, with
 * made-up ids and text: never captured from a real account.
 *
 * Write each method as a `match` over the cases without a default: a case that is added later then fails the test
 * instead of being left out. Give null only for what the format cannot answer at all. The set asks for a fixture
 * only when the capabilities of the gateway say it can do what the fixture shows, and then a null fails the test.
 */
interface AdapterFixtures
{
    /**
     * The whole successful response (status 200 to 299) that holds what the case says. NotApplicable, with the
     * reason, only for the three cases a format may lack: ContractFixture::ToolCallWithOpaqueData when the format
     * has no data that must go back unchanged, ContractFixture::Refused when it cannot tell a refusal from an
     * answer that ended by itself, and ContractFixture::Withheld when it never says that an output was withheld.
     * For every other case it fails the test.
     */
    public function answer(ContractFixture $fixture): HttpResponse|NotApplicable|null;

    /**
     * The same answer as the chunks of a stream, cut where a server would cut them. Asked only when the gateway
     * says it streams natively. NotApplicable exactly when answer() gives it for the case: the two must agree.
     *
     * @return list<string>|NotApplicable|null
     */
    public function stream(ContractFixture $fixture): array|NotApplicable|null;

    /**
     * The response of the format for a failed request with this status, in the shape of its error body.
     *
     * @param string $echo a text to repeat in the body wherever the format has room for words of the provider: its
     *                     message and its own code. The set gives the key it signed in with, because a provider
     *                     can repeat a key; nothing of it may reach an output of the gateway.
     */
    public function failed(int $status, string $echo): HttpResponse;

    /**
     * The failed response, changed so that it says itself that the request must not be tried again, the way the
     * format documents it: a header or a field of the error body. The status stays, and so does the text the body
     * repeats. The gateway must then fail at once, with the reason of the status. NotApplicable, with the reason,
     * for a format that has no such sign.
     *
     * @param HttpResponse $failed what failed() gave for the status 503
     */
    public function retryForbidden(HttpResponse $failed): HttpResponse|NotApplicable;

    /**
     * A response with the status 200 whose body is an error in the shape of the format, with the text
     * repeated as in failed(). NotApplicable, with the reason, for a format that never puts an error in a
     * successful response.
     */
    public function errorInBody(string $echo): HttpResponse|NotApplicable;

    /**
     * The chunks of a stream with a status of 200 whose first event is an error in the shape of the format, with
     * the text repeated as in failed(). Asked only when the gateway says it streams natively. NotApplicable, with
     * the reason, for a format that never puts an error in a stream.
     *
     * @return list<string>|NotApplicable
     */
    public function errorInStream(string $echo): array|NotApplicable;

    /**
     * The request, changed in one place so that this format cannot carry it: a tool name longer than the format
     * allows, a value outside what it takes. The gateway must refuse it as rejected before anything is sent.
     * NotApplicable, with the reason, for a format that carries every request the contract allows.
     *
     * @param Request $request a request this format can carry; it gives the tool "lookup" when the gateway says its
     *                         model can call tools
     */
    public function requestTheFormatCannotCarry(Request $request): Request|NotApplicable;
}
