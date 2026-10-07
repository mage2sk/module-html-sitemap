<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Model\Config\Source;

use Panth\HtmlSitemap\Model\Config\Source\ProductSortOrder;
use Panth\HtmlSitemap\Model\Config\Source\ProductUrlStructure;
use PHPUnit\Framework\TestCase;

class SourceModelsTest extends TestCase
{
    public function testProductSortOrderOptionsMatchConstants(): void
    {
        $options = (new ProductSortOrder())->toOptionArray();

        $this->assertSame(
            [
                ProductSortOrder::NAME_ASC,
                ProductSortOrder::NAME_DESC,
                ProductSortOrder::NEWEST,
                ProductSortOrder::OLDEST,
                ProductSortOrder::PRICE,
                ProductSortOrder::POSITION,
            ],
            array_column($options, 'value')
        );
        $this->assertSame('Name (A-Z)', (string) $options[0]['label']);
        $this->assertSame('Price (low to high)', (string) $options[4]['label']);
    }

    public function testProductSortOrderValuesAreUnderstoodByTheViewModel(): void
    {
        $this->assertSame(
            ['name', 'name_desc', 'newest', 'oldest', 'price', 'position'],
            array_column((new ProductSortOrder())->toOptionArray(), 'value')
        );
    }

    public function testProductUrlStructureOptions(): void
    {
        $options = (new ProductUrlStructure())->toOptionArray();

        $this->assertSame(['short', 'with_categories'], array_column($options, 'value'));
        $this->assertStringContainsString('Short', (string) $options[0]['label']);
    }
}
