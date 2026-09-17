<?php declare(strict_types=1);

namespace TestDataGenerator\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class GeminiClient
{
    private SystemConfigService $systemConfig;
    private GuzzleClient $httpClient;
    private ?LoggerInterface $systemLogger;
    private ?LoggerInterface $apiLogger = null;
    private ?string $logsDir;

    public function __construct(
        SystemConfigService $systemConfig,
        ?LoggerInterface $logger = null,
        ?string $logsDir = null,
        ?GuzzleClient $httpClient = null
    ) {
        $this->systemConfig = $systemConfig;
        $this->systemLogger = $logger;
        $this->logsDir = $logsDir;
        $this->httpClient = $httpClient ?? new GuzzleClient();

        $this->initApiLogger();
    }

    public function generateText(string $prompt, array $schema): string
    {
        $apiKey = (string) $this->systemConfig->get('TestDataGenerator.config.apiKey');
        $model = (string) $this->systemConfig->get('TestDataGenerator.config.llmVersion');
        if (empty($model)) {
            $model = 'gemini-3.5-flash-lite';
        }

        if (empty($apiKey)) {
            $errorMsg = 'Gemini API Key is not configured. Please configure it in plugin settings.';
            $this->logError($errorMsg);
            throw new \Exception($errorMsg);
        }

        $callId = substr(bin2hex(random_bytes(4)), 0, 8);
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema
            ]
        ];

        $this->logInfo(sprintf('[#%s] [TEXT REQUEST] Model: %s | URL: %s', $callId, $model, $url), [
            'call_id' => $callId,
            'type' => 'text',
            'model' => $model,
            'url' => $url,
            'prompt_length' => strlen($prompt),
            'prompt' => $prompt,
            'schema' => $schema,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->maskApiKey($apiKey),
            ],
        ]);

        $startTime = microtime(true);

        try {
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ],
                'timeout' => 90.0,
                'force_ip_resolve' => 'v4',
            ]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 3);
            $response = $e instanceof RequestException ? $e->getResponse() : null;
            $statusCode = $response ? $response->getStatusCode() : null;
            $responseBody = $response ? (string) $response->getBody() : '';

            $this->logError(sprintf('[#%s] [TEXT ERROR] Request failed after %.3fs: %s', $callId, $duration, $e->getMessage()), [
                'call_id' => $callId,
                'type' => 'text',
                'model' => $model,
                'url' => $url,
                'duration_sec' => $duration,
                'status_code' => $statusCode,
                'error_message' => $e->getMessage(),
                'response_body' => $responseBody !== '' ? $responseBody : null,
                'prompt' => $prompt,
            ]);

            throw new \Exception(sprintf(
                'Gemini API text generation request failed (HTTP %s): %s. Response: %s',
                $statusCode !== null ? (string) $statusCode : 'N/A',
                $e->getMessage(),
                $responseBody !== '' ? $responseBody : '(empty response)'
            ), 0, $e);
        }

        $duration = round(microtime(true) - $startTime, 3);
        $rawBody = (string) $response->getBody();
        $body = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = sprintf('Failed to decode Gemini JSON response: %s', json_last_error_msg());
            $this->logError(sprintf('[#%s] [TEXT ERROR] %s', $callId, $errorMsg), [
                'call_id' => $callId,
                'type' => 'text',
                'duration_sec' => $duration,
                'raw_response' => substr($rawBody, 0, 1000),
            ]);
            throw new \Exception(sprintf('%s. Raw response: %s', $errorMsg, substr($rawBody, 0, 500)));
        }

        // Check for top-level API errors
        if (isset($body['error'])) {
            $errorDetail = is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'])) : (string) $body['error'];
            $this->logError(sprintf('[#%s] [TEXT ERROR] Gemini API returned error: %s', $callId, $errorDetail), [
                'call_id' => $callId,
                'type' => 'text',
                'duration_sec' => $duration,
                'error' => $body['error'],
            ]);
            throw new \Exception('Gemini API returned error: ' . $errorDetail);
        }

        // Check for blocked prompt
        if (isset($body['promptFeedback']['blockReason'])) {
            $blockReason = $body['promptFeedback']['blockReason'];
            $this->logError(sprintf('[#%s] [TEXT ERROR] Gemini prompt blocked: %s', $callId, $blockReason), [
                'call_id' => $callId,
                'type' => 'text',
                'duration_sec' => $duration,
                'prompt_feedback' => $body['promptFeedback'],
            ]);
            throw new \Exception(sprintf('Gemini blocked prompt with reason "%s". Feedback: %s', $blockReason, json_encode($body['promptFeedback'])));
        }

        if (empty($body['candidates'])) {
            $this->logError(sprintf('[#%s] [TEXT ERROR] Gemini returned no candidates', $callId), [
                'call_id' => $callId,
                'type' => 'text',
                'duration_sec' => $duration,
                'body' => $body,
            ]);
            throw new \Exception(sprintf('Gemini returned no candidates in response: %s', $rawBody));
        }

        $candidate = $body['candidates'][0];
        $finishReason = $candidate['finishReason'] ?? 'UNKNOWN';

        if (isset($candidate['content']['parts'][0]['text'])) {
            $text = $candidate['content']['parts'][0]['text'];

            $this->logInfo(sprintf('[#%s] [TEXT SUCCESS] Duration: %.3fs | FinishReason: %s | Text length: %d chars', $callId, $duration, $finishReason, strlen($text)), [
                'call_id' => $callId,
                'type' => 'text',
                'model' => $model,
                'duration_sec' => $duration,
                'finish_reason' => $finishReason,
                'usage' => $body['usageMetadata'] ?? [],
                'response_text' => $text,
            ]);

            return $text;
        }

        // Content was missing or blocked
        $safetyRatings = $candidate['safetyRatings'] ?? [];
        $this->logError(sprintf('[#%s] [TEXT ERROR] Gemini candidate returned no text. FinishReason: %s', $callId, $finishReason), [
            'call_id' => $callId,
            'type' => 'text',
            'duration_sec' => $duration,
            'finish_reason' => $finishReason,
            'candidate' => $candidate,
            'body' => $body,
        ]);

        throw new \Exception(sprintf(
            'Invalid response from Gemini API: no text part found. Finish reason: %s. Safety ratings: %s',
            $finishReason,
            json_encode($safetyRatings)
        ));
    }

    public function generateImage(string $prompt): string
    {
        $apiKey = (string) $this->systemConfig->get('TestDataGenerator.config.apiKey');
        if (empty($apiKey)) {
            $errorMsg = 'Gemini API Key is not configured. Please configure it in plugin settings.';
            $this->logError($errorMsg);
            throw new \Exception($errorMsg);
        }

        $callId = substr(bin2hex(random_bytes(4)), 0, 8);
        $model = 'gemini-3.1-flash-lite-image';
        $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $model);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => '1:1',
                ]
            ]
        ];

        $this->logInfo(sprintf('[#%s] [IMAGE REQUEST] Model: %s | URL: %s', $callId, $model, $url), [
            'call_id' => $callId,
            'type' => 'image',
            'model' => $model,
            'url' => $url,
            'prompt' => $prompt,
            'generationConfig' => $payload['generationConfig'],
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->maskApiKey($apiKey),
            ],
        ]);

        $startTime = microtime(true);

        try {
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ],
                'timeout' => 60.0,
                'force_ip_resolve' => 'v4',
            ]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 3);
            $response = $e instanceof RequestException ? $e->getResponse() : null;
            $statusCode = $response ? $response->getStatusCode() : null;
            $responseBody = $response ? (string) $response->getBody() : '';

            $this->logError(sprintf('[#%s] [IMAGE ERROR] Request failed after %.3fs: %s', $callId, $duration, $e->getMessage()), [
                'call_id' => $callId,
                'type' => 'image',
                'model' => $model,
                'url' => $url,
                'duration_sec' => $duration,
                'status_code' => $statusCode,
                'error_message' => $e->getMessage(),
                'response_body' => $responseBody !== '' ? $responseBody : null,
                'prompt' => $prompt,
            ]);

            throw new \Exception(sprintf(
                'Gemini API image generation request failed (HTTP %s): %s. Response: %s',
                $statusCode !== null ? (string) $statusCode : 'N/A',
                $e->getMessage(),
                $responseBody !== '' ? $responseBody : '(empty response)'
            ), 0, $e);
        }

        $duration = round(microtime(true) - $startTime, 3);
        $rawBody = (string) $response->getBody();
        $body = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = sprintf('Failed to decode Gemini image JSON response: %s', json_last_error_msg());
            $this->logError(sprintf('[#%s] [IMAGE ERROR] %s', $callId, $errorMsg), [
                'call_id' => $callId,
                'type' => 'image',
                'duration_sec' => $duration,
                'raw_response' => substr($rawBody, 0, 1000),
            ]);
            throw new \Exception(sprintf('%s. Raw response: %s', $errorMsg, substr($rawBody, 0, 500)));
        }

        // Check for top-level API errors
        if (isset($body['error'])) {
            $errorDetail = is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'])) : (string) $body['error'];
            $this->logError(sprintf('[#%s] [IMAGE ERROR] Gemini API returned error: %s', $callId, $errorDetail), [
                'call_id' => $callId,
                'type' => 'image',
                'duration_sec' => $duration,
                'error' => $body['error'],
            ]);
            throw new \Exception('Gemini API returned error for image generation: ' . $errorDetail);
        }

        // Check for blocked prompt
        if (isset($body['promptFeedback']['blockReason'])) {
            $blockReason = $body['promptFeedback']['blockReason'];
            $this->logError(sprintf('[#%s] [IMAGE ERROR] Gemini prompt blocked: %s', $callId, $blockReason), [
                'call_id' => $callId,
                'type' => 'image',
                'duration_sec' => $duration,
                'prompt_feedback' => $body['promptFeedback'],
            ]);
            throw new \Exception(sprintf('Gemini blocked image prompt with reason "%s". Details: %s', $blockReason, json_encode($body['promptFeedback'])));
        }

        $imageBytes = null;
        $formatFound = null;

        if (isset($body['candidates'][0]['content']['parts'])) {
            foreach ($body['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inlineData']['data'])) {
                    $imageBytes = base64_decode($part['inlineData']['data']);
                    $formatFound = 'inlineData';
                    break;
                }
            }
        }

        if ($imageBytes === null && isset($body['predictions'][0]['bytesBase64Encoded'])) {
            $imageBytes = base64_decode($body['predictions'][0]['bytesBase64Encoded']);
            $formatFound = 'predictions.bytesBase64Encoded';
        }

        if ($imageBytes === null && isset($body['predictions'][0]['image']['imageBytes'])) {
            $imageBytes = base64_decode($body['predictions'][0]['image']['imageBytes']);
            $formatFound = 'predictions.image.imageBytes';
        }

        if ($imageBytes !== null && strlen($imageBytes) > 0) {
            $candidate = $body['candidates'][0] ?? [];
            $finishReason = $candidate['finishReason'] ?? 'STOP';

            $this->logInfo(sprintf('[#%s] [IMAGE SUCCESS] Duration: %.3fs | Format: %s | Image size: %d bytes', $callId, $duration, $formatFound, strlen($imageBytes)), [
                'call_id' => $callId,
                'type' => 'image',
                'model' => $model,
                'duration_sec' => $duration,
                'format' => $formatFound,
                'image_size_bytes' => strlen($imageBytes),
                'finish_reason' => $finishReason,
            ]);

            return $imageBytes;
        }

        $sanitizedBody = $this->sanitizeResponseBodyForLogging($body ?? []);
        $finishReason = $body['candidates'][0]['finishReason'] ?? 'UNKNOWN';

        $this->logError(sprintf('[#%s] [IMAGE ERROR] Invalid response structure from Gemini Imagen API. FinishReason: %s', $callId, $finishReason), [
            'call_id' => $callId,
            'type' => 'image',
            'duration_sec' => $duration,
            'finish_reason' => $finishReason,
            'body' => $sanitizedBody,
        ]);

        throw new \Exception(sprintf(
            'Invalid response structure from Gemini Imagen API. Finish reason: %s. Response: %s',
            $finishReason,
            json_encode($sanitizedBody)
        ));
    }

    public function getApiLogPath(): ?string
    {
        if ($this->logsDir !== null) {
            return rtrim($this->logsDir, '/') . '/test_data_generator_api.log';
        }
        return null;
    }

    private function initApiLogger(): void
    {
        if ($this->logsDir === null || !is_dir($this->logsDir) || !is_writable($this->logsDir)) {
            return;
        }

        try {
            $logPath = rtrim($this->logsDir, '/') . '/test_data_generator_api.log';
            $handler = new StreamHandler($logPath, Level::Debug);
            $formatter = new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context%\n",
                'Y-m-d H:i:s',
                true,
                true
            );
            $handler->setFormatter($formatter);
            $this->apiLogger = new Logger('gemini_api', [$handler]);
        } catch (\Throwable $e) {
            if ($this->systemLogger !== null) {
                $this->systemLogger->warning('Could not initialize dedicated Gemini API logger: ' . $e->getMessage());
            }
        }
    }

    private function maskApiKey(string $apiKey): string
    {
        $len = strlen($apiKey);
        if ($len <= 8) {
            return '***';
        }
        return substr($apiKey, 0, 6) . '...' . substr($apiKey, -4);
    }

    private function sanitizeResponseBodyForLogging(array $body): array
    {
        array_walk_recursive($body, function (&$value, $key): void {
            if (is_string($value) && strlen($value) > 200 && in_array($key, ['data', 'bytesBase64Encoded', 'imageBytes'], true)) {
                $value = sprintf('[base64 image data: %d chars]', strlen($value));
            }
        });
        return $body;
    }

    private function logInfo(string $message, array $context = []): void
    {
        if ($this->apiLogger !== null) {
            $this->apiLogger->info($message, $context);
        } elseif ($this->systemLogger !== null) {
            $this->systemLogger->info('[GeminiClient] ' . $message, $context);
        }
    }

    private function logError(string $message, array $context = []): void
    {
        if ($this->apiLogger !== null) {
            $this->apiLogger->error($message, $context);
        }
        if ($this->systemLogger !== null) {
            $this->systemLogger->error('[GeminiClient] ' . $message, $context);
        }
    }
}
