<?php declare(strict_types=1);

namespace TestDataGenerator\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use TestDataGenerator\Service\GeminiClient;

require_once __DIR__ . '/../src/Service/GeminiClient.php';

final class GeminiClientLoggingTest extends TestCase
{
    private string $tempLogDir;

    protected function setUp(): void
    {
        $this->tempLogDir = sys_get_temp_dir() . '/tdg_test_' . uniqid();
        mkdir($this->tempLogDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $logFile = $this->tempLogDir . '/test_data_generator_api.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }
        if (is_dir($this->tempLogDir)) {
            rmdir($this->tempLogDir);
        }
    }

    public function testTextGenerationLogsRequestAndResponseToDedicatedLogFile(): void
    {
        $mockBody = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => '{"products":[{"name":"Test Product"}]}']
                        ]
                    ],
                    'finishReason' => 'STOP'
                ]
            ],
            'usageMetadata' => ['totalTokenCount' => 42]
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $mockBody)
        ]);
        $httpClient = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(function (string $key): ?string {
            if ($key === 'TestDataGenerator.config.apiKey') {
                return 'AIzaSySecretApiKey123456';
            }
            if ($key === 'TestDataGenerator.config.llmVersion') {
                return 'gemini-3.5-flash-lite';
            }
            return null;
        });

        $client = new GeminiClient($config, null, $this->tempLogDir, $httpClient);
        $result = $client->generateText('Generate 1 product', ['type' => 'OBJECT']);

        self::assertSame('{"products":[{"name":"Test Product"}]}', $result);

        $logPath = $this->tempLogDir . '/test_data_generator_api.log';
        self::assertFileExists($logPath);
        $logContent = file_get_contents($logPath);

        // Verify request was logged with masked key
        self::assertStringContainsString('[TEXT REQUEST]', $logContent);
        self::assertStringContainsString('AIzaSy...3456', $logContent);
        self::assertStringNotContainsString('AIzaSySecretApiKey123456', $logContent);
        self::assertStringContainsString('Generate 1 product', $logContent);

        // Verify response was logged
        self::assertStringContainsString('[TEXT SUCCESS]', $logContent);
        self::assertStringContainsString('FinishReason: STOP', $logContent);
        self::assertStringContainsString('Test Product', $logContent);
    }

    public function testHttpErrorIsLoggedAndThrowsDetailedException(): void
    {
        $errorBody = json_encode([
            'error' => [
                'code' => 400,
                'message' => 'API key not valid. Please pass a valid API key.',
                'status' => 'INVALID_ARGUMENT'
            ]
        ]);

        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], $errorBody)
        ]);
        $httpClient = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn('valid-looking-key-12345');

        $client = new GeminiClient($config, null, $this->tempLogDir, $httpClient);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HTTP 400');
        $this->expectExceptionMessage('API key not valid');

        try {
            $client->generateText('Test prompt', []);
        } finally {
            $logPath = $this->tempLogDir . '/test_data_generator_api.log';
            self::assertFileExists($logPath);
            $logContent = file_get_contents($logPath);
            self::assertStringContainsString('[TEXT ERROR]', $logContent);
            self::assertStringContainsString('API key not valid', $logContent);
        }
    }

    public function testSafetyBlockThrowsDetailedExceptionAndLogs(): void
    {
        $blockedBody = json_encode([
            'candidates' => [
                [
                    'finishReason' => 'SAFETY',
                    'safetyRatings' => [
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'probability' => 'HIGH']
                    ]
                ]
            ]
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $blockedBody)
        ]);
        $httpClient = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn('valid-key-12345');

        $client = new GeminiClient($config, null, $this->tempLogDir, $httpClient);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Finish reason: SAFETY');

        try {
            $client->generateText('Dangerous prompt', []);
        } finally {
            $logPath = $this->tempLogDir . '/test_data_generator_api.log';
            self::assertFileExists($logPath);
            $logContent = file_get_contents($logPath);
            self::assertStringContainsString('[TEXT ERROR]', $logContent);
            self::assertStringContainsString('FinishReason: SAFETY', $logContent);
        }
    }

    public function testImageGenerationSanitizesBase64InLogs(): void
    {
        $rawImageData = 'fake-binary-image-data-here';
        $base64Image = base64_encode(str_repeat('A', 500)); // 500+ chars base64

        $imageResponseBody = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => $base64Image
                                ]
                            ]
                        ]
                    ],
                    'finishReason' => 'STOP'
                ]
            ]
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $imageResponseBody)
        ]);
        $httpClient = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn('valid-key-12345');

        $client = new GeminiClient($config, null, $this->tempLogDir, $httpClient);
        $imageData = $client->generateImage('A professional photo of a red modern chair');

        self::assertSame(str_repeat('A', 500), $imageData);

        $logPath = $this->tempLogDir . '/test_data_generator_api.log';
        self::assertFileExists($logPath);
        $logContent = file_get_contents($logPath);

        self::assertStringContainsString('[IMAGE REQUEST]', $logContent);
        self::assertStringContainsString('[IMAGE SUCCESS]', $logContent);
        self::assertStringContainsString('inlineData', $logContent);
    }
}
