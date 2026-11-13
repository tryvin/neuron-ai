<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use NeuronAI\Exceptions\ContextWindowExceededException;
use Throwable;

use function array_merge;
use function ctype_digit;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function str_contains;
use function strtolower;
use function trim;

final class ProviderErrorClassifier
{
    /**
     * @var array<int, string>
     */
    private const NON_OVERFLOW_CODES = [
        'rate_limit',
        'rate-limit',
        'insufficient_quota',
        'quota_exceeded',
        'throttl',
        'too_many_requests',
        'resource_exhausted',
        'string_above_max_length',
        'slow_down',
        'overloaded',
        'service_unavailable',
    ];

    /**
     * @var array<int, string>
     */
    private const OVERFLOW_CODES = [
        'context_length_exceeded',
        'context_window_exceeded',
        'context_too_long',
        'input_too_long',
        'prompt_too_long',
        'maximum_context_length',
    ];

    private const OVERFLOW_PHRASES = '/(?:'
        . 'maximum context length is'
        . '|context (?:length|window) (?:has been )?(?:exceeded|too long)'
        . '|(?:prompt|input) (?:is )?too long'
        . '|input is too long for requested model'
        . '|too many input tokens'
        . '|input too large for model'
        . '|input token count\s*\(\d+\)\s*exceeds the maximum number of tokens allowed'
        . '|exceeds the maximum number of tokens allowed'
        . ')/i';

    private const NON_OVERFLOW_PHRASES = '/(?:'
        . 'rate[\s_-]?limit'
        . '|too many requests'
        . '|insufficient[\s_-]?quota'
        . '|quota[\s_-]?(?:exceeded|exhausted)'
        . '|throttl(?:ed|ing)?'
        . '|resource[\s_-]?exhausted'
        . '|tokens? per (?:minute|hour|day)'
        . '|requests? per (?:minute|second)'
        . '|\b(?:tpm|rpm)\b'
        . ')/i';

    public static function classify(
        ?HttpRequest $request,
        HttpResponse $response,
        ?Throwable $previous = null,
    ): ?ContextWindowExceededException {
        if (!in_array($response->statusCode, [400, 413], true)) {
            return null;
        }

        return self::build($request, $response, $previous);
    }

    public static function classifyStreamError(
        ?HttpRequest $request,
        string $body,
        ?Throwable $previous = null,
    ): ?ContextWindowExceededException {
        return self::build($request, new HttpResponse(200, $body), $previous);
    }

    private static function build(
        ?HttpRequest $request,
        HttpResponse $response,
        ?Throwable $previous,
    ): ?ContextWindowExceededException {
        $match = self::match($response->body);

        if ($match === null) {
            return null;
        }

        $message = $request !== null
            ? "HTTP {$response->statusCode} error during {$request->method->value} {$request->uri}: {$response->body}"
            : "HTTP {$response->statusCode} error: {$response->body}";

        if ($request !== null) {
            return ContextWindowExceededException::forResponse(
                $message,
                $request,
                $response,
                $match['promptTokens'],
                $match['maxContextTokens'],
                $match['providerCode'],
                $previous,
            );
        }

        return new ContextWindowExceededException(
            $message,
            null,
            $response,
            $previous,
            $match['promptTokens'],
            $match['maxContextTokens'],
            $match['providerCode'],
        );
    }

    /**
     * @return array{promptTokens: ?int, maxContextTokens: ?int, providerCode: ?string}|null
     */
    private static function match(string $body): ?array
    {
        $decoded = json_decode($body, true);
        $providerCode = self::providerCode($decoded);
        $lowerCode = $providerCode !== null ? strtolower($providerCode) : null;

        if ($lowerCode !== null) {
            foreach (self::NON_OVERFLOW_CODES as $code) {
                if (str_contains($lowerCode, $code)) {
                    return null;
                }
            }
        }

        $text = is_array($decoded)
            ? implode(' ', self::flattenStrings($decoded))
            : $body;

        if (preg_match(self::NON_OVERFLOW_PHRASES, $text) === 1) {
            return null;
        }

        $codeOverflow = false;
        if ($lowerCode !== null) {
            foreach (self::OVERFLOW_CODES as $code) {
                if (str_contains($lowerCode, $code)) {
                    $codeOverflow = true;
                    break;
                }
            }
        }

        if (!$codeOverflow && preg_match(self::OVERFLOW_PHRASES, $text) !== 1) {
            return null;
        }

        [$promptTokens, $maxContextTokens] = self::extractTokens($text);

        return [
            'promptTokens' => $promptTokens,
            'maxContextTokens' => $maxContextTokens,
            'providerCode' => $providerCode,
        ];
    }

    private static function providerCode(mixed $decoded): ?string
    {
        if (!is_array($decoded)) {
            return null;
        }

        $candidates = [];
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $candidates[] = $error['code'] ?? null;
            if (isset($error['error']) && is_array($error['error'])) {
                $candidates[] = $error['error']['code'] ?? null;
            }
            $candidates[] = $error['type'] ?? null;
            $candidates[] = $error['status'] ?? null;
        }

        $response = $decoded['response'] ?? null;
        $responseError = is_array($response) ? ($response['error'] ?? null) : null;
        if (is_array($responseError)) {
            $candidates[] = $responseError['code'] ?? null;
            $candidates[] = $responseError['type'] ?? null;
            $candidates[] = $responseError['status'] ?? null;
        }

        $candidates[] = $decoded['code'] ?? null;
        $candidates[] = $decoded['status'] ?? null;
        $candidates[] = $decoded['__type'] ?? null;
        $candidates[] = $decoded['type'] ?? null;

        foreach ($candidates as $candidate) {
            if (is_int($candidate) || !is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate === '' || strtolower($candidate) === 'error' || ctype_digit($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function flattenStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $strings = array_merge($strings, self::flattenStrings($item));
        }

        return $strings;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private static function extractTokens(string $text): array
    {
        $promptTokens = null;
        $maxContextTokens = null;

        if (preg_match('/prompt is too long:\s*(\d+)\s*tokens?\s*>\s*(\d+)\s*maximum/i', $text, $matches) === 1) {
            $promptTokens = (int) $matches[1];
            $maxContextTokens = (int) $matches[2];
        }

        if (preg_match(
            '/input token count\s*\((\d+)\)\s*exceeds the maximum number of tokens allowed\s*\((\d+)\)/i',
            $text,
            $matches
        ) === 1) {
            $promptTokens = (int) $matches[1];
            $maxContextTokens = (int) $matches[2];
        }

        if ($maxContextTokens === null &&
            preg_match('/maximum context length is\s*(\d+)\s*tokens?/i', $text, $matches) === 1
        ) {
            $maxContextTokens = (int) $matches[1];
        }

        if ($promptTokens === null &&
            preg_match('/(?:resulted in|request(?:ed| contains)?|used|using)\s*(\d+)\s*tokens?/i', $text, $matches) === 1
        ) {
            $promptTokens = (int) $matches[1];
        }

        if ($promptTokens === null &&
            $maxContextTokens === null &&
            preg_match('/(\d+)\s*tokens?\s*>\s*(\d+)/i', $text, $matches) === 1
        ) {
            $promptTokens = (int) $matches[1];
            $maxContextTokens = (int) $matches[2];
        }

        return [$promptTokens, $maxContextTokens];
    }
}
