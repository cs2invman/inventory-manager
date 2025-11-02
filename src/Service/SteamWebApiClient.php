<?php

namespace App\Service;

use App\Exception\SteamWebApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for interacting with SteamWebAPI.com
 *
 * Handles HTTP requests to fetch CS2 item data from the SteamWebAPI service.
 * Implements retry logic with exponential backoff for reliability.
 */
class SteamWebApiClient
{
    private const MAX_RETRIES = 3;
    private const INITIAL_RETRY_DELAY = 2; // seconds
    private const REQUEST_TIMEOUT = 60; // seconds

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $apiKey
    ) {
    }

    /**
     * Fetch all CS2 items from the SteamWebAPI
     *
     * @return string Raw JSON response from the API
     * @throws SteamWebApiException If the request fails after all retries
     */
    public function fetchAllItems(): string
    {
        $url = $this->buildUrl('/items');

        $this->logger->info('Fetching CS2 items from SteamWebAPI', [
            'url' => $url,
        ]);

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;

            try {
                $this->logger->debug("Attempting to fetch items (attempt {$attempt}/" . self::MAX_RETRIES . ")");

                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => self::REQUEST_TIMEOUT,
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode === 401 || $statusCode === 403) {
                    throw SteamWebApiException::authenticationFailure();
                }

                if ($statusCode >= 400) {
                    $errorMessage = $this->extractErrorMessage($response);
                    throw SteamWebApiException::httpError($statusCode, $errorMessage);
                }

                $content = $response->getContent();

                // Validate that we received valid JSON
                $decoded = json_decode($content);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw SteamWebApiException::invalidResponse('Response is not valid JSON: ' . json_last_error_msg());
                }

                $this->logger->info('Successfully fetched CS2 items from SteamWebAPI', [
                    'attempt' => $attempt,
                    'content_length' => strlen($content),
                ]);

                return $content;

            } catch (TransportExceptionInterface $e) {
                $lastException = $e;
                $this->logger->warning("Network error on attempt {$attempt}", [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $delay = $this->calculateRetryDelay($attempt);
                    $this->logger->debug("Retrying in {$delay} seconds...");
                    sleep($delay);
                }
            } catch (SteamWebApiException $e) {
                // Don't retry authentication failures
                if (str_contains($e->getMessage(), 'authentication')) {
                    $this->logger->error('Authentication failure', [
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $lastException = $e;
                $this->logger->warning("API error on attempt {$attempt}", [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $delay = $this->calculateRetryDelay($attempt);
                    $this->logger->debug("Retrying in {$delay} seconds...");
                    sleep($delay);
                }
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->logger->error("Unexpected error on attempt {$attempt}", [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $delay = $this->calculateRetryDelay($attempt);
                    sleep($delay);
                }
            }
        }

        // All retries exhausted
        $this->logger->error('All retry attempts exhausted for SteamWebAPI request');

        if ($lastException instanceof SteamWebApiException) {
            throw $lastException;
        }

        throw SteamWebApiException::networkError(
            'Failed after ' . self::MAX_RETRIES . ' attempts',
            $lastException
        );
    }

    /**
     * Build the complete URL for an API endpoint
     */
    private function buildUrl(string $endpoint): string
    {
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query([
            'key' => $this->apiKey,
            'game' => 'cs2',
        ]);
    }

    /**
     * Calculate retry delay with exponential backoff
     */
    private function calculateRetryDelay(int $attempt): int
    {
        return self::INITIAL_RETRY_DELAY * (2 ** ($attempt - 1));
    }

    /**
     * Extract error message from response
     */
    private function extractErrorMessage($response): string
    {
        try {
            $content = $response->getContent(false);
            $decoded = json_decode($content, true);

            return $decoded['message'] ?? $decoded['error'] ?? 'Unknown error';
        } catch (\Throwable $e) {
            return 'Unable to parse error response';
        }
    }
}