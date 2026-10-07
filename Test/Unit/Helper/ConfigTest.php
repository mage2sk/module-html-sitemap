<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\HtmlSitemap\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values = [], array $flags = []): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn($path) => $values[$path] ?? null);
        $scope->method('isSetFlag')->willReturnCallback(static fn($path) => (bool) ($flags[$path] ?? false));
        return new Config($scope);
    }

    public static function flagProvider(): array
    {
        return [
            ['isEnabled', Config::XML_ENABLED],
            ['isShowCategories', Config::XML_SHOW_CATEGORIES],
            ['isShowProducts', Config::XML_SHOW_PRODUCTS],
            ['isShowCmsPages', Config::XML_SHOW_CMS_PAGES],
            ['isShowStores', Config::XML_SHOW_STORES],
            ['isShowCustomLinks', Config::XML_SHOW_CUSTOM_LINKS],
            ['isShowSearchField', Config::XML_SHOW_SEARCH_FIELD],
            ['isShowTestimonials', Config::XML_SHOW_TESTIMONIALS],
            ['isShowFaqs', Config::XML_SHOW_FAQS],
            ['isShowDynamicForms', Config::XML_SHOW_DYNAMIC_FORMS],
        ];
    }

    #[DataProvider('flagProvider')]
    public function testFlagsReadTheirOwnPath(string $method, string $path): void
    {
        $this->assertTrue($this->config([], [$path => true])->{$method}());
        $this->assertFalse($this->config()->{$method}());
    }

    public function testFlagsAreReadAtStoreScopeForTheGivenStore(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects($this->once())->method('isSetFlag')
            ->with(Config::XML_ENABLED, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        $this->assertTrue((new Config($scope))->isEnabled(7));
    }

    public function testScalarValuesAreCast(): void
    {
        $config = $this->config([
            Config::XML_MAX_CATEGORY_DEPTH => '4',
            Config::XML_PRODUCT_SORT_ORDER => 'price',
            Config::XML_PRODUCT_URL_STRUCTURE => 'short',
            Config::XML_CUSTOM_LINKS => "a|b",
            Config::XML_META_TITLE => 'Map',
            Config::XML_META_DESCRIPTION => 'All pages',
        ]);

        $this->assertSame(4, $config->getMaxCategoryDepth());
        $this->assertSame('price', $config->getProductSortOrder());
        $this->assertSame('short', $config->getProductUrlStructure());
        $this->assertSame('a|b', $config->getCustomLinks());
        $this->assertSame('Map', $config->getMetaTitle());
        $this->assertSame('All pages', $config->getMetaDescription());
    }

    public function testUnsetValuesBecomeEmptyDefaults(): void
    {
        $config = $this->config();

        $this->assertSame(0, $config->getMaxCategoryDepth());
        $this->assertSame('', $config->getProductSortOrder());
        $this->assertSame('', $config->getMetaTitle());
        $this->assertSame([], $config->getExcludeCmsPages());
        $this->assertNull($config->getValue('any/path'));
    }

    public function testExcludeCmsPagesSplitsAndTrims(): void
    {
        $config = $this->config([Config::XML_EXCLUDE_CMS_PAGES => ' privacy , ,terms,, about-us ']);

        $this->assertSame(['privacy', 'terms', 'about-us'], $config->getExcludeCmsPages());
    }

    public static function perPageProvider(): array
    {
        return [
            'unset uses default' => [null, 500],
            'zero uses default'  => ['0', 500],
            'negative default'   => ['-10', 500],
            'below minimum'      => ['10', 50],
            'within range'       => ['700', 700],
            'above maximum'      => ['99999', 2000],
            'exact minimum'      => ['50', 50],
            'exact maximum'      => ['2000', 2000],
        ];
    }

    #[DataProvider('perPageProvider')]
    public function testProductsPerPageIsClamped(?string $raw, int $expected): void
    {
        $this->assertSame($expected, $this->config([Config::XML_PRODUCTS_PER_PAGE => $raw])->getProductsPerPage());
    }

    public function testGetValuePassesThroughStoreScope(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects($this->once())->method('getValue')
            ->with('web/default/cms_home_page', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('home');

        $this->assertSame('home', (new Config($scope))->getValue('web/default/cms_home_page', 3));
    }
}
