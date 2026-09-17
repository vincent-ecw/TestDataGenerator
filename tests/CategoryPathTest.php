<?php declare(strict_types=1);

namespace TestDataGenerator\Tests;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use TestDataGenerator\Service\DataImporter;

require_once __DIR__ . '/../src/Service/DataImporter.php';

final class CategoryPathTest extends TestCase
{
    public function testFullHierarchyIncludesInactiveParentsWithoutIndexedPaths(): void
    {
        $root = $this->category('1', 'Furniture', null);
        $office = $this->category('2', 'Office', $root->getId());
        $office->setActive(false);
        $cabinet = $this->category('3', 'Cabinets', $office->getId());
        $cabinet->setTranslated(['name' => 'Archive cabinets']);
        $other = $this->category('4', 'Chairs', $office->getId());

        $paths = $this->resolve([$root, $office, $cabinet, $other], [$cabinet->getId(), $other->getId()]);
        self::assertSame('Furniture > Office > Archive cabinets', $paths[$cabinet->getId()]);
        self::assertSame('Furniture > Office > Chairs', $paths[$other->getId()]);
    }

    public function testRootCategoryAndEmptySelection(): void
    {
        $root = $this->category('1', 'Furniture', null);
        self::assertSame([$root->getId() => 'Furniture'], $this->resolve([$root], [$root->getId()]));
        self::assertSame([], $this->resolve([], []));
    }

    public function testMissingParentIsReported(): void
    {
        $child = $this->category('2', 'Office', str_repeat('1', 32));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is missing');
        $this->resolve([$child], [$child->getId()]);
    }

    public function testCycleIsReported(): void
    {
        $root = $this->category('1', 'Furniture', str_repeat('2', 32));
        $child = $this->category('2', 'Office', $root->getId());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('circular');
        $this->resolve([$root, $child], [$child->getId()]);
    }

    private function category(string $digit, string $name, ?string $parent): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setId(str_repeat($digit, 32));
        $category->setName($name);
        $category->setParentId($parent);
        $category->setPath(null);
        return $category;
    }

    private function resolve(array $categories, array $ids): array
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(function (Criteria $criteria, Context $context) use ($categories): EntitySearchResult {
            self::assertSame([], $criteria->getFilters());
            $matches = array_values(array_filter($categories, fn (CategoryEntity $category) => in_array($category->getId(), $criteria->getIds(), true)));
            return new EntitySearchResult('category', count($matches), new CategoryCollection($matches), null, $criteria, $context);
        });
        $reflection = new \ReflectionClass(DataImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('categoryRepository')->setValue($importer, $repository);
        return $reflection->getMethod('resolveCategoryPaths')->invoke($importer, $ids, Context::createDefaultContext());
    }
}
