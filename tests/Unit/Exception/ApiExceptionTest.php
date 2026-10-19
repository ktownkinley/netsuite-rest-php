<?php

namespace NetSuiteRest\Tests\Unit\Exception;

use GuzzleHttp\Psr7\Response;
use NetSuiteRest\Exception\ApiException;
use NetSuiteRest\Http\NetSuiteResponse;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase
{
    public function testParsesTheNetSuiteErrorEnvelope(): void
    {
        $response = new NetSuiteResponse(new Response(400, [], json_encode([
            'type' => 'https://example.test/errors/invalid-id',
            'title' => 'Bad Request',
            'status' => 400,
            'o:errorDetails' => [
                ['detail' => 'Invalid id.', 'o:errorCode' => 'SSS_INVALID_ID', 'o:errorPath' => 'id'],
            ],
        ])));

        $exception = new ApiException($response);

        self::assertSame($response, $exception->getResponse());
        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('https://example.test/errors/invalid-id', $exception->getType());
        self::assertSame('Bad Request', $exception->getTitle());
        self::assertSame(
            [['detail' => 'Invalid id.', 'o:errorCode' => 'SSS_INVALID_ID', 'o:errorPath' => 'id']],
            $exception->getErrorDetails(),
        );
    }

    /**
     * The message prioritizes the first error detail's specific diagnostic over the generic
     * `title` ("Bad Request" says nothing a 400 status code didn't already say) — this is the
     * exact real-world case that motivated the change: a normal application logging/displaying
     * just the exception message should see the actionable NetSuite explanation, not boilerplate.
     */
    public function testMessageUsesTheFirstErrorDetailWhenOneIsPresent(): void
    {
        $response = new NetSuiteResponse(new Response(400, [], json_encode([
            'title' => 'Bad Request',
            'o:errorDetails' => [
                ['detail' => "Unknown identifier 'quantityavailable'", 'o:errorCode' => 'USER_ERROR'],
            ],
        ])));

        $exception = new ApiException($response);

        self::assertSame(
            "NetSuite API request failed with status 400: Unknown identifier 'quantityavailable'.",
            $exception->getMessage(),
        );
    }

    /**
     * Only the first detail is ever used in the message, even when NetSuite reports several —
     * getErrorDetails() is the complete-access path for the rest, not the message.
     */
    public function testMessageUsesOnlyTheFirstErrorDetailWhenThereAreMultiple(): void
    {
        $response = new NetSuiteResponse(new Response(400, [], json_encode([
            'title' => 'Bad Request',
            'o:errorDetails' => [
                ['detail' => 'First problem.', 'o:errorCode' => 'A'],
                ['detail' => 'Second problem.', 'o:errorCode' => 'B'],
            ],
        ])));

        $exception = new ApiException($response);

        self::assertStringContainsString('First problem.', $exception->getMessage());
        self::assertStringNotContainsString('Second problem.', $exception->getMessage());
        self::assertCount(2, $exception->getErrorDetails());
    }

    public function testMessageFallsBackToTitleWhenNoErrorDetailsArePresent(): void
    {
        $response = new NetSuiteResponse(new Response(404, [], json_encode([
            'title' => 'Not Found',
        ])));

        $exception = new ApiException($response);

        self::assertSame('NetSuite API request failed with status 404: Not Found.', $exception->getMessage());
    }

    /**
     * A malformed/empty `detail` on the first error entry degrades to the title, the same as
     * having no o:errorDetails at all — a missing diagnostic shouldn't surface as an empty or
     * malformed-looking message fragment.
     */
    public function testMessageFallsBackToTitleWhenTheFirstErrorDetailHasNoUsableText(): void
    {
        $response = new NetSuiteResponse(new Response(400, [], json_encode([
            'title' => 'Bad Request',
            'o:errorDetails' => [
                ['o:errorCode' => 'USER_ERROR'],
            ],
        ])));

        $exception = new ApiException($response);

        self::assertSame('NetSuite API request failed with status 400: Bad Request.', $exception->getMessage());
    }

    /**
     * Reproduces the real sandbox response that motivated this: NetSuite's own detail text
     * already ended in a period, and unconditionally appending another produced a visible
     * ".." in the message.
     */
    public function testMessageDoesNotDoublePunctuateWhenTheDetailAlreadyEndsInAPeriod(): void
    {
        $response = new NetSuiteResponse(new Response(400, [], json_encode([
            'title' => 'Bad Request',
            'o:errorDetails' => [
                ['detail' => 'Available identifiers are: {customer=customer}.', 'o:errorCode' => 'INVALID_PARAMETER'],
            ],
        ])));

        $exception = new ApiException($response);

        self::assertSame(
            'NetSuite API request failed with status 400: Available identifiers are: {customer=customer}.',
            $exception->getMessage(),
        );
    }

    /**
     * Proves the fix checks for terminal punctuation generally, not specifically for a period —
     * a detail ending in "?" (or "!") must not get a trailing "." appended either.
     */
    public function testMessageDoesNotDoublePunctuateForOtherTerminalPunctuation(): void
    {
        $response = new NetSuiteResponse(new Response(400, [], json_encode([
            'title' => 'Bad Request',
            'o:errorDetails' => [
                ['detail' => 'Is this value really correct?', 'o:errorCode' => 'X'],
            ],
        ])));

        $exception = new ApiException($response);

        self::assertSame(
            'NetSuite API request failed with status 400: Is this value really correct?',
            $exception->getMessage(),
        );
    }

    /**
     * The punctuation check applies uniformly wherever the message text came from — not just
     * the error-detail path — since both flow through the same message-building logic.
     */
    public function testMessageDoesNotDoublePunctuateWhenTheFallbackTitleAlreadyEndsInPunctuation(): void
    {
        $response = new NetSuiteResponse(new Response(400, [], json_encode([
            'title' => 'Request rejected!',
        ])));

        $exception = new ApiException($response);

        self::assertSame('NetSuite API request failed with status 400: Request rejected!', $exception->getMessage());
    }

    public function testDegradesGracefullyWhenTheBodyIsNotTheExpectedErrorShape(): void
    {
        $response = new NetSuiteResponse(new Response(500, [], 'Internal Server Error'));

        $exception = new ApiException($response);

        self::assertSame(500, $exception->getStatusCode());
        self::assertNull($exception->getType());
        self::assertNull($exception->getTitle());
        self::assertSame([], $exception->getErrorDetails());
        self::assertStringContainsString('500', $exception->getMessage());
    }

    public function testDegradesGracefullyWhenTheBodyIsEmpty(): void
    {
        $response = new NetSuiteResponse(new Response(503, [], ''));

        $exception = new ApiException($response);

        self::assertSame(503, $exception->getStatusCode());
        self::assertNull($exception->getType());
        self::assertNull($exception->getTitle());
        self::assertSame([], $exception->getErrorDetails());
    }
}
