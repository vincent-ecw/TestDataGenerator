<?php declare(strict_types=1);

namespace TestDataGenerator\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use TestDataGenerator\Message\GenerateTestDataMessage;

#[Route(defaults: ['_routeScope' => ['api']])]
class GeneratorController extends AbstractController
{
    private MessageBusInterface $messageBus;
    private string $environment;
    private string $logsDir;

    public function __construct(MessageBusInterface $messageBus, string $environment, string $logsDir = '')
    {
        $this->messageBus = $messageBus;
        $this->environment = $environment;
        $this->logsDir = $logsDir;
    }

    #[Route(path: '/api/test-data-generator/env', name: 'api.test_data_generator.env', methods: ['GET'])]
    public function getEnv(): JsonResponse
    {
        return new JsonResponse([
            'environment' => $this->environment,
            'isDev' => $this->environment === 'dev',
        ]);
    }

    #[Route(path: '/api/test-data-generator/api-log', name: 'api.test_data_generator.api_log', methods: ['GET'], defaults: ['_acl' => ['system.plugin_maintenance']])]
    public function getApiLog(Request $request): JsonResponse
    {
        $logPath = rtrim($this->logsDir, '/') . '/test_data_generator_api.log';
        if (!file_exists($logPath)) {
            return new JsonResponse([
                'exists' => false,
                'path' => $logPath,
                'lines' => [],
            ]);
        }

        $linesCount = (int) ($request->query->get('lines', 100));
        $linesCount = max(1, min($linesCount, 1000));

        $file = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $slice = $file ? array_slice($file, -$linesCount) : [];

        return new JsonResponse([
            'exists' => true,
            'path' => $logPath,
            'size' => filesize($logPath),
            'lines' => $slice,
        ]);
    }

    #[Route(path: '/api/test-data-generator/generate', name: 'api.test_data_generator.generate', methods: ['POST'], defaults: ['_acl' => ['system.plugin_maintenance']])]
    public function generate(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $data = [];
        if (!empty($content)) {
            $data = json_decode($content, true) ?? [];
        }

        if (empty($data)) {
            $data = $request->request->all();
        }

        $categoriesCount = isset($data['categoriesCount']) ? (int) $data['categoriesCount'] : 5;
        $productsCount = isset($data['productsCount']) ? (int) $data['productsCount'] : 20;
        $generateImages = isset($data['generateImages']) ? (bool) $data['generateImages'] : false;
        $generateReviews = isset($data['generateReviews']) ? (bool) $data['generateReviews'] : false;
        $generateManufacturers = isset($data['generateManufacturers']) ? (bool) $data['generateManufacturers'] : false;
        $manufacturersCount = isset($data['manufacturersCount']) ? (int) $data['manufacturersCount'] : 5;
        $manufacturersBranch = isset($data['manufacturersBranch']) && !empty($data['manufacturersBranch']) ? (string) $data['manufacturersBranch'] : null;
        $useExistingCategories = isset($data['useExistingCategories']) ? (bool) $data['useExistingCategories'] : false;
        $createTranslationsOnly = isset($data['createTranslationsOnly']) ? (bool) $data['createTranslationsOnly'] : false;
        $selectedCategoryId = isset($data['selectedCategoryId']) && !empty($data['selectedCategoryId']) ? (string) $data['selectedCategoryId'] : null;
        
        $deleteTestDataBeforeGeneration = false;
        if ($this->environment === 'dev') {
            $deleteTestDataBeforeGeneration = isset($data['deleteTestDataBeforeGeneration']) ? (bool) $data['deleteTestDataBeforeGeneration'] : false;
        }

        if (!$createTranslationsOnly && !$generateManufacturers && ((!$useExistingCategories && $categoriesCount <= 0) || $productsCount <= 0)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid count parameters.'], 400);
        }

        $this->messageBus->dispatch(new GenerateTestDataMessage(
            $categoriesCount,
            $productsCount,
            $generateImages,
            $useExistingCategories,
            $createTranslationsOnly,
            $selectedCategoryId,
            $deleteTestDataBeforeGeneration,
            $generateReviews,
            $generateManufacturers,
            $manufacturersCount,
            $manufacturersBranch
        ));

        return new JsonResponse([
            'success' => true,
            'message' => 'Generation started in the background.'
        ]);
    }
}
