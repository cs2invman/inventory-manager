<?php

namespace App\Exception;

/**
 * Exception thrown when SteamWebAPI operations fail.
 *
 * This exception is used for:
 * - Network failures when contacting the API
 * - Invalid API responses (malformed JSON, unexpected structure)
 * - HTTP error codes from the API
 * - Timeout errors
 * - Authentication failures
 */
class SteamWebApiException extends \RuntimeException
{
    public static function networkError(string $message, ?\Throwable $previous = null): self
    {
        return new self("Network error while contacting SteamWebAPI: {$message}", 0, $previous);
    }

    public static function invalidResponse(string $message, ?\Throwable $previous = null): self
    {
        return new self("Invalid API response: {$message}", 0, $previous);
    }

    public static function httpError(int $statusCode, string $message): self
    {
        return new self("HTTP {$statusCode} error from SteamWebAPI: {$message}");
    }

    public static function timeout(int $seconds): self
    {
        return new self("SteamWebAPI request timed out after {$seconds} seconds");
    }

    public static function authenticationFailure(): self
    {
        return new self("SteamWebAPI authentication failed. Please check your API key.");
    }
}