<?php declare(strict_types=1);

namespace TestDataGenerator\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class GenerateTestDataMessage implements AsyncMessageInterface
{
    private int $categoriesCount;
    private int $productsCount;
    private bool $generateImages;
    private bool $useExistingCategories;
    private bool $createTranslationsOnly;
    private ?string $selectedCategoryId;
    private bool $deleteTestDataBeforeGeneration;
    private bool $generateReviews;
    private bool $generateManufacturers;
    private int $manufacturersCount;
    private ?string $manufacturersBranch;

    public function __construct(
        int $categoriesCount,
        int $productsCount,
        bool $generateImages,
        bool $useExistingCategories,
        bool $createTranslationsOnly = false,
        ?string $selectedCategoryId = null,
        bool $deleteTestDataBeforeGeneration = false,
        bool $generateReviews = false,
        bool $generateManufacturers = false,
        int $manufacturersCount = 5,
        ?string $manufacturersBranch = null
    ) {
        $this->categoriesCount = $categoriesCount;
        $this->productsCount = $productsCount;
        $this->generateImages = $generateImages;
        $this->useExistingCategories = $useExistingCategories;
        $this->createTranslationsOnly = $createTranslationsOnly;
        $this->selectedCategoryId = $selectedCategoryId;
        $this->deleteTestDataBeforeGeneration = $deleteTestDataBeforeGeneration;
        $this->generateReviews = $generateReviews;
        $this->generateManufacturers = $generateManufacturers;
        $this->manufacturersCount = $manufacturersCount;
        $this->manufacturersBranch = $manufacturersBranch;
    }

    public function getCategoriesCount(): int
    {
        return $this->categoriesCount;
    }

    public function getProductsCount(): int
    {
        return $this->productsCount;
    }

    public function isGenerateImages(): bool
    {
        return $this->generateImages;
    }

    public function isUseExistingCategories(): bool
    {
        return $this->useExistingCategories;
    }

    public function isCreateTranslationsOnly(): bool
    {
        return $this->createTranslationsOnly;
    }

    public function getSelectedCategoryId(): ?string
    {
        return $this->selectedCategoryId;
    }

    public function isDeleteTestDataBeforeGeneration(): bool
    {
        return $this->deleteTestDataBeforeGeneration;
    }

    public function isGenerateReviews(): bool
    {
        return $this->generateReviews;
    }

    public function isGenerateManufacturers(): bool
    {
        return $this->generateManufacturers;
    }

    public function getManufacturersCount(): int
    {
        return $this->manufacturersCount;
    }

    public function getManufacturersBranch(): ?string
    {
        return $this->manufacturersBranch;
    }
}
