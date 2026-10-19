<?php

namespace NetSuiteRest\Exception;

use NetSuiteRest\Http\NetSuiteResponse;

/**
 * Thrown when NetSuite received a request and responded with an HTTP error status
 * (400/401/403/404/422/429/5xx/etc). Parses NetSuite's RFC7807-style error body when
 * present:
 *
 *     {
 *         "type": "...",
 *         "title": "Bad Request",
 *         "status": 400,
 *         "o:errorDetails": [
 *             {"detail": "...", "o:errorCode": "SSS_INVALID_ID", "o:errorPath": "..."}
 *         ]
 *     }
 *
 * If the error body is missing or doesn't match that shape, type/title/errorDetails
 * degrade to null/empty rather than throwing — the caller already has a real HTTP error
 * to react to, and a malformed error body shouldn't mask it with a different exception.
 */
final class ApiException extends NetSuiteException
{
    private readonly ?string $type;

    private readonly ?string $title;

    /** @var array<int, array<string, mixed>> */
    private readonly array $errorDetails;

    public function __construct(private readonly NetSuiteResponse $response)
    {
        $body = $this->tryDecodeBody();

        $this->type = is_string($body['type'] ?? null) ? $body['type'] : null;
        $this->title = is_string($body['title'] ?? null) ? $body['title'] : null;
        $this->errorDetails = is_array($body['o:errorDetails'] ?? null) ? $body['o:errorDetails'] : [];

        // `title` is generic ("Bad Request" for every 400, regardless of cause) — the first
        // error detail's `detail` text is NetSuite's actual, specific diagnostic (e.g. "Unknown
        // identifier 'quantityavailable'"), so it takes priority in the message a caller is
        // most likely to log or display. getTitle()/getErrorDetails() remain unchanged and give
        // full structured access regardless of what the message picked.
        $messageDetail = $this->firstErrorDetail() ?? $this->title;

        parent::__construct(self::buildMessage($response->getStatusCode(), $messageDetail));
    }

    private function firstErrorDetail(): ?string
    {
        $detail = $this->errorDetails[0]['detail'] ?? null;

        return is_string($detail) && $detail !== '' ? $detail : null;
    }

    /**
     * NetSuite's own detail/title text sometimes already ends in terminal punctuation — a real
     * sandbox 400 ended in "...Available identifiers are: {customer=customer}.", and
     * unconditionally appending our own "." produced a visible ".." there. The trailing period
     * is only added when the selected text doesn't already end in one; this checks for terminal
     * punctuation generally (./!/?), not specifically for a period.
     */
    private static function buildMessage(int $statusCode, ?string $detail): string
    {
        $prefix = "NetSuite API request failed with status {$statusCode}";

        if ($detail === null) {
            return "{$prefix}.";
        }

        return self::endsWithTerminalPunctuation($detail)
            ? "{$prefix}: {$detail}"
            : "{$prefix}: {$detail}.";
    }

    private static function endsWithTerminalPunctuation(string $text): bool
    {
        return in_array(substr(rtrim($text), -1), ['.', '!', '?'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryDecodeBody(): ?array
    {
        try {
            return $this->response->json();
        } catch (UnexpectedResponseException) {
            return null;
        }
    }

    public function getResponse(): NetSuiteResponse
    {
        return $this->response;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getErrorDetails(): array
    {
        return $this->errorDetails;
    }
}
