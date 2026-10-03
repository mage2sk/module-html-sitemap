<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\HtmlSitemap\Helper\Config;
use Panth\HtmlSitemap\Test\Unit\Fixture\FakeSelect;
use Panth\HtmlSitemap\ViewModel\HtmlSitemap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HtmlSitemapDataTest extends TestCase
{
    private const ATTR = [
        'name' => 10,
        'is_active' => 11,
        'exclude_from_html_sitemap' => 12,
        'visibility' => 20,
        'status' => 21,
        'price' => 22,
        'small_image' => 23,
    ];

    /** @var FakeSelect[] */
    private array $selects = [];

    /** @var array */
    private array $configValues = [];

    /** @var array */
    private array $configFlags = [];

    /** @var int */
    private int $countCalls = 0;

    private function store(array $data = []): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($data['id'] ?? 1);
        $store->method('getWebsiteId')->willReturn($data['website'] ?? 1);
        $store->method('getRootCategoryId')->willReturn($data['root'] ?? 2);
        $store->method('getName')->willReturn($data['name'] ?? 'Default');
        $store->method('isActive')->willReturn($data['active'] ?? true);
        $store->method('getBaseUrl')->willReturnCallback(
            static fn($type = 'link') => $type === 'media'
                ? ($data['media'] ?? 'https://shop.test/media/')
                : ($data['base'] ?? 'https://shop.test/')
        );
        return $store;
    }

    private function connection(
        ?callable $fetchAll = null,
        array $tables = [],
        array $describe = [],
        int $count = 0
    ): AdapterInterface {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturnCallback(function () {
            $select = new FakeSelect();
            $this->selects[] = $select;
            return $select;
        });
        $conn->method('fetchOne')->willReturnCallback(function (FakeSelect $s) use ($count) {
            if ($s->table === 'eav_entity_type') {
                return $s->whereValue('entity_type_code') === 'catalog_category' ? 3 : 4;
            }
            if ($s->table === 'eav_attribute') {
                return self::ATTR[$s->whereValue('attribute_code')] ?? 0;
            }
            if ($s->table === 'catalog_category_entity') {
                return 1;
            }
            $this->countCalls++;
            return $count;
        });
        $conn->method('fetchAll')->willReturnCallback($fetchAll ?? static fn() => []);
        $conn->method('isTableExists')->willReturnCallback(static fn($t) => in_array($t, $tables, true));
        $conn->method('describeTable')->willReturnCallback(static fn($t) => $describe[$t] ?? []);
        return $conn;
    }

    private function viewModel(
        ?AdapterInterface $conn = null,
        ?Store $store = null,
        array $params = [],
        ?LoggerInterface $logger = null,
        ?PriceCurrencyInterface $price = null,
        ?StoreManagerInterface $storeManager = null
    ): HtmlSitemap {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn ?? $this->connection());
        $resource->method('getTableName')->willReturnArgument(0);

        if ($storeManager === null) {
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store ?? $this->store());
        }

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn($path) => $this->configValues[$path] ?? null);
        $scope->method('isSetFlag')->willReturnCallback(fn($path) => (bool) ($this->configFlags[$path] ?? false));

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => $params[$key] ?? $default
        );

        return new HtmlSitemap(
            $resource,
            $storeManager,
            new Config($scope),
            $logger ?? $this->createStub(LoggerInterface::class),
            $request,
            $price ?? $this->createStub(PriceCurrencyInterface::class)
        );
    }

    private function failingStoreManager(): StoreManagerInterface
    {
        $sm = $this->createStub(StoreManagerInterface::class);
        $sm->method('getStore')->willThrowException(new \RuntimeException('no store'));
        $sm->method('getStores')->willThrowException(new \RuntimeException('no stores'));
        return $sm;
    }

    private function selectFor(string $table): ?FakeSelect
    {
        foreach ($this->selects as $s) {
            if ($s->table === $table) {
                return $s;
            }
        }
        return null;
    }

    public function testTotalProductCountIsQueriedOnceAndCached(): void
    {
        $vm = $this->viewModel($this->connection(null, [], [], 1234));

        $this->assertSame(1234, $vm->getTotalProductCount());
        $this->assertSame(1234, $vm->getTotalProductCount());
        $this->assertSame(1, $this->countCalls);

        $select = $this->selectFor('catalog_product_entity');
        $this->assertSame([2, 4], $select->whereValue('COALESCE(vis_s.value, vis_d.value) IN'));
        $this->assertSame(1, $select->whereValue('COALESCE(st_s.value, st_d.value) ='));
        $this->assertStringContainsString('pw.website_id = 1', $select->joins[0][1]);
    }

    public function testCountAndListApplyTheSameNameAndUrlConstraints(): void
    {
        $vm = $this->viewModel($this->connection(null, [], [], 3));
        $vm->getTotalProductCount();
        $vm->getProducts();

        $selects = array_values(array_filter(
            $this->selects,
            static fn(FakeSelect $s) => $s->table === 'catalog_product_entity'
        ));
        $this->assertCount(2, $selects);
        foreach ($selects as $select) {
            $this->assertTrue($select->hasWhere("TRIM(COALESCE(n_s.value, n_d.value)) <> ''"));
            $this->assertTrue($select->hasWhere('EXISTS (SELECT 1 FROM url_rewrite AS ur'));
            $this->assertTrue($select->hasWhere('ur.store_id = 1 AND ur.redirect_type = 0'));
        }
    }

    public function testCountFailureIsLoggedAndReturnsZero(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('count failed: no store'));

        $vm = $this->viewModel(null, null, [], $logger, null, $this->failingStoreManager());

        $this->assertSame(0, $vm->getTotalProductCount());
        $this->assertSame(0, $vm->getTotalProductPages());
    }

    public function testTotalPagesRoundsUp(): void
    {
        $this->configValues[Config::XML_PRODUCTS_PER_PAGE] = 100;
        $vm = $this->viewModel($this->connection(null, [], [], 201));

        $this->assertSame(100, $vm->getProductsPerPage());
        $this->assertSame(3, $vm->getTotalProductPages());
    }

    public function testTotalPagesAreCappedAtHardLimit(): void
    {
        $this->configValues[Config::XML_PRODUCTS_PER_PAGE] = 50;
        $vm = $this->viewModel($this->connection(null, [], [], 10000000));

        $this->assertSame(2000, $vm->getTotalProductPages());
    }

    #[DataProvider('currentPageProvider')]
    public function testCurrentPageIsSanitisedAndClamped(?string $param, int $count, int $expected): void
    {
        $this->configValues[Config::XML_PRODUCTS_PER_PAGE] = 100;
        $params = $param === null ? [] : ['p' => $param];
        $vm = $this->viewModel($this->connection(null, [], [], $count), null, $params);

        $this->assertSame($expected, $vm->getCurrentPage());
    }

    public static function currentPageProvider(): array
    {
        return [
            'missing param'      => [null, 500, 1],
            'numeric string'     => ['3', 500, 3],
            'non numeric'        => ['abc', 500, 1],
            'negative'           => ['-4', 500, 1],
            'zero'               => ['0', 500, 1],
            'beyond last page'   => ['99', 500, 5],
            'no products at all' => ['7', 0, 7],
            'float string'       => ['2.9', 500, 2],
        ];
    }

    public function testPaginationOnFirstPage(): void
    {
        $this->configValues[Config::XML_PRODUCTS_PER_PAGE] = 100;
        $vm = $this->viewModel($this->connection(null, [], [], 250));

        $pagination = $vm->getProductPagination();

        $this->assertSame(1, $pagination['current']);
        $this->assertSame(3, $pagination['total']);
        $this->assertSame(100, $pagination['per_page']);
        $this->assertSame(250, $pagination['total_items']);
        $this->assertSame([1, 2, 3], $pagination['window']);
        $this->assertSame('https://shop.test/sitemap', $pagination['base_url']);
    }

    public function testPaginationOnLastPageAndEmptyCatalogue(): void
    {
        $this->configValues[Config::XML_PRODUCTS_PER_PAGE] = 50;
        $last = $this->viewModel($this->connection(null, [], [], 500), null, ['p' => '10'])
            ->getProductPagination();
        $this->assertSame([8, 9, 10], $last['window']);

        $empty = $this->viewModel($this->connection(null, [], [], 0))->getProductPagination();
        $this->assertSame(0, $empty['total']);
        $this->assertSame([], $empty['window']);
    }

    public function testPaginationBaseUrlTrimsSlashAndFallsBack(): void
    {
        $vm = $this->viewModel(null, $this->store(['base' => 'https://shop.test/de///']));
        $this->assertSame('https://shop.test/de/sitemap', $vm->getPaginationBaseUrl());
        $this->assertSame('https://shop.test/de/sitemap?p=2', $vm->getProductPageUrl(2));

        $broken = $this->viewModel(null, null, [], null, null, $this->failingStoreManager());
        $this->assertSame('/sitemap', $broken->getPaginationBaseUrl());
        $this->assertSame('/sitemap', $broken->getProductPageUrl(-1));
    }

    public function testConfigProxies(): void
    {
        $this->configFlags = [
            Config::XML_ENABLED => true,
            Config::XML_SHOW_STORES => true,
            Config::XML_SHOW_PRODUCTS => false,
            Config::XML_SHOW_CMS_PAGES => true,
            Config::XML_SHOW_CATEGORIES => true,
            Config::XML_SHOW_CUSTOM_LINKS => false,
            Config::XML_SHOW_SEARCH_FIELD => true,
            Config::XML_SHOW_TESTIMONIALS => true,
            Config::XML_SHOW_FAQS => false,
            Config::XML_SHOW_DYNAMIC_FORMS => true,
        ];
        $this->configValues = [
            Config::XML_MAX_CATEGORY_DEPTH => '3',
            Config::XML_PRODUCT_SORT_ORDER => 'newest',
            Config::XML_PRODUCT_URL_STRUCTURE => 'short',
        ];
        $vm = $this->viewModel();

        $this->assertTrue($vm->isEnabled());
        $this->assertTrue($vm->isShowStores());
        $this->assertFalse($vm->isShowProducts());
        $this->assertTrue($vm->isShowCmsPages());
        $this->assertTrue($vm->isShowCategories());
        $this->assertFalse($vm->isShowCustomLinks());
        $this->assertTrue($vm->isShowSearchField());
        $this->assertTrue($vm->isShowTestimonials());
        $this->assertFalse($vm->isShowFaqs());
        $this->assertTrue($vm->isShowDynamicForms());
        $this->assertSame(3, $vm->getMaxCategoryDepth());
        $this->assertSame('newest', $vm->getProductSortOrder());
        $this->assertSame('short', $vm->getProductUrlStructure());
    }

    public function testIsEnabledSwallowsConfigErrors(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willThrowException(new \RuntimeException('config down'));

        $vm = new HtmlSitemap(
            $this->createStub(ResourceConnection::class),
            $this->createStub(StoreManagerInterface::class),
            new Config($scope),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RequestInterface::class),
            $this->createStub(PriceCurrencyInterface::class)
        );

        $this->assertFalse($vm->isEnabled());
    }

    public function testCustomLinksParsing(): void
    {
        $this->configValues[Config::XML_CUSTOM_LINKS] = "  \n"
            . "https://a.test/about | About us\n"
            . "\n"
            . "https://b.test/plain\n"
            . "https://c.test/empty-label|   \n"
            . " | Orphan label\n"
            . "https://d.test/x|Label|with|pipes\r\n";

        $links = $this->viewModel()->getCustomLinks();

        $this->assertSame([
            ['url' => 'https://a.test/about', 'label' => 'About us'],
            ['url' => 'https://b.test/plain', 'label' => 'https://b.test/plain'],
            ['url' => 'https://c.test/empty-label', 'label' => 'https://c.test/empty-label'],
            ['url' => 'https://d.test/x', 'label' => 'Label|with|pipes'],
        ], $links);
    }

    public function testCustomLinksEmptyConfig(): void
    {
        $this->configValues[Config::XML_CUSTOM_LINKS] = "   \n  ";
        $this->assertSame([], $this->viewModel()->getCustomLinks());
    }

    private function categoryFetchAll(array $rows, array $active, array $excluded = [], array $urls = []): callable
    {
        return static function (FakeSelect $s) use ($rows, $active, $excluded, $urls) {
            switch ($s->table) {
                case 'catalog_category_entity':
                    return $rows;
                case 'catalog_category_entity_int':
                    return $s->whereValue('attribute_id') === self::ATTR['is_active'] ? $active : $excluded;
                case 'url_rewrite':
                    return $urls;
            }
            return [];
        };
    }

    private function catRow(int $id, int $parent, int $level, ?string $name): array
    {
        return ['entity_id' => $id, 'parent_id' => $parent, 'level' => $level, 'path' => '', 'name' => $name];
    }

    private function activeRows(array $ids): array
    {
        return array_map(static fn($id) => ['entity_id' => $id, 'store_id' => 0, 'value' => 1], $ids);
    }

    public function testCategoriesAreBuiltIntoATree(): void
    {
        $rows = [
            $this->catRow(3, 2, 2, 'Men'),
            $this->catRow(4, 2, 2, 'Women'),
            $this->catRow(5, 3, 3, 'Shirts'),
            $this->catRow(6, 5, 4, 'Polo'),
        ];
        $urls = [
            ['entity_id' => 3, 'request_path' => '/men.html'],
            ['entity_id' => 5, 'request_path' => 'men/shirts.html'],
        ];
        $vm = $this->viewModel(
            $this->connection($this->categoryFetchAll($rows, $this->activeRows([3, 4, 5, 6]), [], $urls))
        );

        $tree = $vm->getCategories();

        $this->assertCount(2, $tree);
        $this->assertSame('Men', $tree[0]['name']);
        $this->assertSame('https://shop.test/men.html', $tree[0]['url']);
        $this->assertSame('#', $tree[1]['url']);
        $this->assertSame('Shirts', $tree[0]['children'][0]['name']);
        $this->assertSame('https://shop.test/men/shirts.html', $tree[0]['children'][0]['url']);
        $this->assertSame('Polo', $tree[0]['children'][0]['children'][0]['name']);
        $this->assertSame(4, $vm->getCategoryCount($tree));
        $this->assertSame($tree, $vm->getCategoryTree());

        $main = $this->selectFor('catalog_category_entity');
        $this->assertSame('1/2/%', $main->whereValue('e.path LIKE'));
        $this->assertFalse($main->hasWhere('e.level <='));
    }

    public function testInactiveExcludedAndUnnamedCategoriesAreDropped(): void
    {
        $rows = [
            $this->catRow(3, 2, 2, 'Active'),
            $this->catRow(4, 2, 2, 'Store disabled'),
            $this->catRow(5, 2, 2, 'Excluded'),
            $this->catRow(6, 2, 2, '   '),
            $this->catRow(7, 4, 3, 'Child of disabled'),
            $this->catRow(8, 2, 2, 'No active row'),
        ];
        $active = $this->activeRows([3, 4, 5, 6, 7]);
        $active[] = ['entity_id' => 4, 'store_id' => 1, 'value' => 0];
        $excluded = [['entity_id' => 5, 'store_id' => 0, 'value' => 1]];

        $tree = $this->viewModel(
            $this->connection($this->categoryFetchAll($rows, $active, $excluded))
        )->getCategories();

        $this->assertSame(['Active', 'Child of disabled'], array_column($tree, 'name'));
    }

    public function testStoreLevelExclusionOverrideReincludesCategory(): void
    {
        $rows = [$this->catRow(3, 2, 2, 'Kept')];
        $excluded = [
            ['entity_id' => 3, 'store_id' => 0, 'value' => 1],
            ['entity_id' => 3, 'store_id' => 1, 'value' => 0],
        ];

        $tree = $this->viewModel(
            $this->connection($this->categoryFetchAll($rows, $this->activeRows([3]), $excluded))
        )->getCategories();

        $this->assertSame(['Kept'], array_column($tree, 'name'));
    }

    public function testMaxDepthLimitsTheCategoryLevel(): void
    {
        $this->configValues[Config::XML_MAX_CATEGORY_DEPTH] = 2;
        $this->viewModel($this->connection($this->categoryFetchAll([], [])))->getCategories();

        $main = null;
        foreach ($this->selects as $s) {
            if ($s->table === 'catalog_category_entity' && $s->hasWhere('e.path LIKE')) {
                $main = $s;
            }
        }
        $this->assertSame(3, $main->whereValue('e.level <='));
    }

    public function testCategoriesAreEmptyWhenNoRowsAndCached(): void
    {
        $calls = 0;
        $fetchAll = static function () use (&$calls) {
            $calls++;
            return [];
        };
        $vm = $this->viewModel($this->connection($fetchAll));

        $this->assertSame([], $vm->getCategories());
        $this->assertSame([], $vm->getCategories());
        $this->assertSame(1, $calls);
    }

    public function testCategoryFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('categories failed'));

        $vm = $this->viewModel(null, null, [], $logger, null, $this->failingStoreManager());
        $this->assertSame([], $vm->getCategories());
    }

    private function productFetchAll(
        array $rows,
        array $images = [],
        array $prices = [],
        array $urls = [],
        array $fallback = []
    ): callable {
        return static function (FakeSelect $s) use ($rows, $images, $prices, $urls, $fallback) {
            switch ($s->table) {
                case 'catalog_product_entity':
                    return $rows;
                case 'catalog_product_entity_varchar':
                    return $images;
                case 'catalog_product_entity_decimal':
                    return $prices;
                case 'url_rewrite':
                    if ($s->hasWhere('metadata IS NULL') || $fallback === []) {
                        return $urls;
                    }
                    return $fallback;
            }
            return [];
        };
    }

    public function testProductsAreAssembledWithImagesPricesAndUrls(): void
    {
        $this->configValues[Config::XML_PRODUCT_URL_STRUCTURE] = 'with_categories';
        $rows = [
            ['entity_id' => 1, 'name' => ' Shirt '],
            ['entity_id' => 2, 'name' => 'Free gift'],
            ['entity_id' => 3, 'name' => ''],
            ['entity_id' => 4, 'name' => 'No url'],
        ];
        $images = [
            ['entity_id' => 1, 'store_id' => 1, 'value' => 's/h/shirt-store.jpg'],
            ['entity_id' => 1, 'store_id' => 0, 'value' => '/s/h/shirt.jpg'],
            ['entity_id' => 2, 'store_id' => 0, 'value' => 'no_selection'],
        ];
        $prices = [
            ['entity_id' => 1, 'store_id' => 0, 'value' => '19.5'],
            ['entity_id' => 2, 'store_id' => 0, 'value' => '0'],
            ['entity_id' => 4, 'store_id' => 0, 'value' => null],
        ];
        $urls = [
            ['entity_id' => 1, 'request_path' => 'men/shirt.html'],
            ['entity_id' => 1, 'request_path' => 'shirt.html'],
            ['entity_id' => 2, 'request_path' => '/gift.html'],
            ['entity_id' => 3, 'request_path' => 'unnamed.html'],
        ];

        $price = $this->createMock(PriceCurrencyInterface::class);
        $price->expects($this->once())->method('convertAndFormat')
            ->with(19.5, false, PriceCurrencyInterface::DEFAULT_PRECISION)
            ->willReturn('$19.50');

        $vm = $this->viewModel(
            $this->connection($this->productFetchAll($rows, $images, $prices, $urls)),
            null,
            [],
            null,
            $price
        );

        $products = $vm->getProducts();

        $this->assertSame([
            [
                'id' => 1,
                'name' => 'Shirt',
                'url' => 'https://shop.test/men/shirt.html',
                'image' => 'https://shop.test/media/catalog/product/s/h/shirt-store.jpg',
                'price' => '$19.50',
            ],
            [
                'id' => 2,
                'name' => 'Free gift',
                'url' => 'https://shop.test/gift.html',
                'image' => '',
                'price' => '',
            ],
        ], $products);
        $this->assertSame($products, $vm->getProducts());

        $urlSelect = $this->selectFor('url_rewrite');
        $this->assertFalse($urlSelect->hasWhere('metadata IS NULL'));
        $this->assertStringContainsString('CASE WHEN metadata IS NOT NULL', $urlSelect->orders[0]);
    }

    public function testShortUrlStructureFallsBackForProductsWithoutShortRewrite(): void
    {
        $this->configValues[Config::XML_PRODUCT_URL_STRUCTURE] = 'short';
        $rows = [
            ['entity_id' => 1, 'name' => 'A'],
            ['entity_id' => 2, 'name' => 'B'],
        ];
        $urls = [['entity_id' => 1, 'request_path' => 'a.html']];
        $fallback = [['entity_id' => 2, 'request_path' => 'cat/b.html']];

        $products = $this->viewModel(
            $this->connection($this->productFetchAll($rows, [], [], $urls, $fallback))
        )->getProducts();

        $this->assertSame(
            ['https://shop.test/a.html', 'https://shop.test/cat/b.html'],
            array_column($products, 'url')
        );

        $rewriteSelects = array_values(array_filter($this->selects, static fn($s) => $s->table === 'url_rewrite'));
        $this->assertCount(2, $rewriteSelects);
        $this->assertTrue($rewriteSelects[0]->hasWhere('metadata IS NULL'));
        $this->assertSame([1 => 2], $rewriteSelects[1]->whereValue('entity_id IN'));
    }

    public function testShortUrlStructureSkipsFallbackWhenAllResolved(): void
    {
        $this->configValues[Config::XML_PRODUCT_URL_STRUCTURE] = 'short';
        $rows = [['entity_id' => 1, 'name' => 'A']];
        $urls = [['entity_id' => 1, 'request_path' => 'a.html']];

        $this->viewModel($this->connection($this->productFetchAll($rows, [], [], $urls)))->getProducts();

        $rewriteSelects = array_filter($this->selects, static fn($s) => $s->table === 'url_rewrite');
        $this->assertCount(1, $rewriteSelects);
    }

    public function testProductImagesNeedAMediaBaseUrl(): void
    {
        $rows = [['entity_id' => 1, 'name' => 'A']];
        $images = [['entity_id' => 1, 'store_id' => 0, 'value' => 'a.jpg']];
        $urls = [['entity_id' => 1, 'request_path' => 'a.html']];

        $products = $this->viewModel(
            $this->connection($this->productFetchAll($rows, $images, [], $urls)),
            $this->store(['media' => ''])
        )->getProducts();

        $this->assertSame('', $products[0]['image']);
    }

    #[DataProvider('sortOrderProvider')]
    public function testProductSortOrder(string $sort, string $expectedOrder): void
    {
        $this->configValues[Config::XML_PRODUCT_SORT_ORDER] = $sort;
        $this->viewModel($this->connection($this->productFetchAll([])))->getProducts();

        $main = $this->selectFor('catalog_product_entity');
        $this->assertSame([$expectedOrder], $main->orders);
        $this->assertSame(['e.entity_id'], $main->groups);
    }

    public static function sortOrderProvider(): array
    {
        return [
            'name asc'  => ['name', 'COALESCE(n_s.value, n_d.value) ASC'],
            'name desc' => ['name_desc', 'COALESCE(n_s.value, n_d.value) DESC'],
            'newest'    => ['newest', 'e.created_at DESC'],
            'oldest'    => ['oldest', 'e.created_at ASC'],
            'price'     => ['price', 'COALESCE(pr_s.value, pr_d.value) ASC'],
            'position'  => ['position', 'e.entity_id ASC'],
            'unknown'   => ['bogus', 'COALESCE(n_s.value, n_d.value) ASC'],
        ];
    }

    public function testProductQueryUsesPageOffset(): void
    {
        $this->configValues[Config::XML_PRODUCTS_PER_PAGE] = 100;
        $this->viewModel($this->connection($this->productFetchAll([]), [], [], 1000), null, ['p' => '3'])
            ->getProducts();

        $main = null;
        foreach ($this->selects as $s) {
            if ($s->table === 'catalog_product_entity' && $s->limitArgs !== null) {
                $main = $s;
            }
        }
        $this->assertSame([100, 200], $main->limitArgs);
    }

    public function testProductFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('products failed'));

        $vm = $this->viewModel(null, null, [], $logger, null, $this->failingStoreManager());
        $this->assertSame([], $vm->getProducts());
    }

    public function testCmsPagesSkipHomeNoRouteBlankAndExcluded(): void
    {
        $this->configValues['web/default/cms_home_page'] = 'home';
        $this->configValues[Config::XML_EXCLUDE_CMS_PAGES] = 'privacy, terms';
        $rows = [
            ['page_id' => 1, 'identifier' => 'home', 'title' => 'Home'],
            ['page_id' => 2, 'identifier' => 'no-route', 'title' => '404'],
            ['page_id' => 3, 'identifier' => '', 'title' => 'Blank'],
            ['page_id' => 4, 'identifier' => 'privacy', 'title' => 'Privacy'],
            ['page_id' => 5, 'identifier' => 'about-us', 'title' => 'About'],
            ['page_id' => 6, 'identifier' => '/contact', 'title' => 'Contact'],
        ];
        $vm = $this->viewModel($this->connection(static fn() => $rows));

        $this->assertSame([
            ['id' => 5, 'title' => 'About', 'url' => 'https://shop.test/about-us'],
            ['id' => 6, 'title' => 'Contact', 'url' => 'https://shop.test/contact'],
        ], $vm->getCmsPages());

        $select = $this->selectFor('cms_page');
        $this->assertSame([0, 1], $select->whereValue('ps.store_id IN'));
        $this->assertSame(1, $select->whereValue('p.is_active'));
    }

    public function testCmsFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('cms failed'));

        $vm = $this->viewModel(null, null, [], $logger, null, $this->failingStoreManager());
        $this->assertSame([], $vm->getCmsPages());
    }

    public function testStoresListsOnlyActiveStores(): void
    {
        $sm = $this->createStub(StoreManagerInterface::class);
        $sm->method('getStores')->willReturn([
            $this->store(['name' => 'English', 'base' => 'https://shop.test/en']),
            $this->store(['name' => 'Closed', 'active' => false]),
            $this->store(['name' => 'German', 'base' => 'https://shop.test/de/']),
        ]);

        $vm = $this->viewModel(null, null, [], null, null, $sm);

        $this->assertSame([
            ['name' => 'English', 'url' => 'https://shop.test/en/'],
            ['name' => 'German', 'url' => 'https://shop.test/de/'],
        ], $vm->getStores());
    }

    public function testStoresFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('stores failed'));

        $vm = $this->viewModel(null, null, [], $logger, null, $this->failingStoreManager());
        $this->assertSame([], $vm->getStores());
    }

    public function testTestimonialsEmptyWhenTablesMissing(): void
    {
        $this->assertSame([], $this->viewModel($this->connection())->getTestimonials());
    }

    public function testTestimonialsUseConfiguredRouteAndFilterRows(): void
    {
        $this->configValues['panth_testimonials/general/route'] = '/reviews/';
        $fetchAll = static fn(FakeSelect $s) => $s->table === 'panth_testimonial_category'
            ? [
                ['url_key' => 'stars', 'name' => 'Five stars'],
                ['url_key' => '', 'name' => 'No key'],
            ]
            : [
                ['url_key' => 'jane', 'title' => ' Jane '],
                ['url_key' => 'x', 'title' => ''],
            ];
        $conn = $this->connection(
            $fetchAll,
            ['panth_testimonial', 'panth_testimonial_category'],
            [
                'panth_testimonial' => ['status' => [], 'store_id' => [], 'sort_order' => []],
                'panth_testimonial_category' => [],
            ]
        );

        $this->assertSame([
            ['title' => 'Five stars', 'url' => 'https://shop.test/reviews/category/stars'],
            ['title' => 'Jane', 'url' => 'https://shop.test/reviews/jane'],
        ], $this->viewModel($conn)->getTestimonials());

        $item = $this->selectFor('panth_testimonial');
        $this->assertSame(1, $item->whereValue('status'));
        $this->assertSame([0, 1], $item->whereValue('store_id IN'));
        $this->assertSame(['sort_order ASC', 'title ASC'], $item->orders);
        $cat = $this->selectFor('panth_testimonial_category');
        $this->assertSame(['name ASC'], $cat->orders);
        $this->assertNull($cat->whereValue('store_id IN'));
    }

    public function testTestimonialsDefaultRoute(): void
    {
        $this->configValues['panth_testimonials/general/route'] = '///';
        $conn = $this->connection(
            static fn() => [['url_key' => 'k', 'title' => 'T']],
            ['panth_testimonial']
        );

        $this->assertSame(
            [['title' => 'T', 'url' => 'https://shop.test/testimonials/k']],
            $this->viewModel($conn)->getTestimonials()
        );
    }

    public function testFaqsBuildCategoryAndItemUrls(): void
    {
        $this->configValues['panth_faq/general/faq_route'] = 'help';
        $fetchAll = static fn(FakeSelect $s) => $s->table === 'panth_faq_category'
            ? [['url_key' => 'shipping', 'name' => 'Shipping'], ['url_key' => 'x', 'name' => '']]
            : [['url_key' => 'returns', 'question' => 'How to return?'], ['url_key' => '', 'question' => 'Q']];
        $conn = $this->connection(
            $fetchAll,
            ['panth_faq_item', 'panth_faq_item_store', 'panth_faq_category'],
            [
                'panth_faq_item' => ['is_active' => [], 'sort_order' => []],
                'panth_faq_category' => ['sort_order' => []],
            ]
        );

        $this->assertSame([
            ['title' => 'Shipping', 'url' => 'https://shop.test/help/category/shipping'],
            ['title' => 'How to return?', 'url' => 'https://shop.test/help/item/returns'],
        ], $this->viewModel($conn)->getFaqs());

        $item = $this->selectFor('panth_faq_item');
        $this->assertSame(1, $item->whereValue('i.is_active'));
        $this->assertStringContainsString('s.store_id IN (0, 1)', $item->joins[0][1]);
        $this->assertSame(['i.item_id'], $item->groups);
        $this->assertSame(['i.sort_order ASC', 'i.question ASC'], $item->orders);
    }

    public function testFaqCategoriesAreFilteredByStore(): void
    {
        $conn = $this->connection(
            static fn(FakeSelect $s) => $s->table === 'panth_faq_category'
                ? [['url_key' => 'shipping', 'name' => 'Shipping']]
                : [],
            ['panth_faq_category', 'panth_faq_category_store']
        );

        $this->assertSame(
            [['title' => 'Shipping', 'url' => 'https://shop.test/faq/category/shipping']],
            $this->viewModel($conn, $this->store(['id' => 3]))->getFaqs()
        );
        $category = $this->selectFor('panth_faq_category');
        $this->assertCount(1, $category->joins);
        $this->assertSame(['cs' => 'panth_faq_category_store'], $category->joins[0][0]);
        $this->assertStringContainsString('cs.store_id IN (0, 3)', $category->joins[0][1]);
        $this->assertSame(['c.category_id'], $category->groups);
    }

    public function testFaqsWithoutStoreTableOrCategories(): void
    {
        $conn = $this->connection(
            static fn() => [['url_key' => 'k', 'question' => 'Q']],
            ['panth_faq_item']
        );

        $this->assertSame(
            [['title' => 'Q', 'url' => 'https://shop.test/faq/item/k']],
            $this->viewModel($conn)->getFaqs()
        );
        $this->assertSame([], $this->selectFor('panth_faq_item')->joins);
        $this->assertSame([], $this->viewModel($this->connection())->getFaqs());
    }

    public function testDynamicFormsTitleFallbackChain(): void
    {
        $conn = $this->connection(
            static fn() => [
                ['url_key' => 'quote', 'title' => 'Get a quote', 'name' => 'n'],
                ['url_key' => 'survey', 'title' => '', 'name' => 'Survey'],
                ['url_key' => 'bare', 'title' => '', 'name' => ''],
                ['url_key' => ' ', 'title' => 'Skipped'],
            ],
            ['panth_dynamic_form'],
            [
                'panth_dynamic_form' => [
                    'url_key' => [], 'title' => [], 'name' => [],
                    'is_active' => [], 'form_type' => [], 'store_id' => [],
                ],
            ]
        );

        $this->assertSame([
            ['title' => 'Get a quote', 'url' => 'https://shop.test/pages/quote'],
            ['title' => 'Survey', 'url' => 'https://shop.test/pages/survey'],
            ['title' => 'bare', 'url' => 'https://shop.test/pages/bare'],
        ], $this->viewModel($conn)->getDynamicForms());

        $select = $this->selectFor('panth_dynamic_form');
        $this->assertSame(['page', 'both'], $select->whereValue('form_type IN'));
        $this->assertSame([0, 1], $select->whereValue('store_id IN'));
        $this->assertSame(1, $select->whereValue('is_active'));
    }

    public function testDynamicFormsSelectOnlyExistingColumns(): void
    {
        $conn = $this->connection(
            static fn() => [],
            ['panth_dynamic_form'],
            ['panth_dynamic_form' => ['url_key' => [], 'name' => []]]
        );

        $this->assertSame([], $this->viewModel($conn)->getDynamicForms());
        $select = $this->selectFor('panth_dynamic_form');
        $this->assertSame(['url_key', 'name'], array_values($select->columnsSpec));
        $this->assertNull($select->whereValue('is_active'));
        $this->assertSame([], $this->viewModel($this->connection())->getDynamicForms());
    }

    public function testOptionalSectionsLogAtInfoLevelOnFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('info');

        $vm = $this->viewModel(null, null, [], $logger, null, $this->failingStoreManager());

        $this->assertSame([], $vm->getTestimonials());
        $this->assertSame([], $vm->getFaqs());
        $this->assertSame([], $vm->getDynamicForms());
    }

    public function testIdentitiesIncludeEnabledSectionsOnly(): void
    {
        $this->configFlags = [
            Config::XML_SHOW_CATEGORIES => true,
            Config::XML_SHOW_PRODUCTS => false,
            Config::XML_SHOW_CMS_PAGES => true,
        ];
        $categoryRows = [$this->catRow(3, 2, 2, 'A'), $this->catRow(4, 3, 3, 'B')];
        $active = $this->activeRows([3, 4]);
        $fetchAll = static function (FakeSelect $s) use ($categoryRows, $active) {
            switch ($s->table) {
                case 'catalog_category_entity':
                    return $categoryRows;
                case 'catalog_category_entity_int':
                    return $s->whereValue('attribute_id') === self::ATTR['is_active'] ? $active : [];
                case 'cms_page':
                    return [
                        ['page_id' => 9, 'identifier' => 'a', 'title' => 'A'],
                        ['page_id' => 9, 'identifier' => 'b', 'title' => 'B'],
                    ];
            }
            return [];
        };

        $tags = $this->viewModel($this->connection($fetchAll))->getIdentities();

        $this->assertSame([
            Category::CACHE_TAG,
            Product::CACHE_TAG,
            Page::CACHE_TAG,
            Category::CACHE_TAG . '_3',
            Product::CACHE_PRODUCT_CATEGORY_TAG . '_3',
            Category::CACHE_TAG . '_4',
            Product::CACHE_PRODUCT_CATEGORY_TAG . '_4',
            Page::CACHE_TAG . '_9',
        ], $tags);
    }

    public function testIdentitiesWithProductsOnly(): void
    {
        $this->configFlags = [Config::XML_SHOW_PRODUCTS => true];
        $fetchAll = $this->productFetchAll(
            [['entity_id' => 7, 'name' => 'P']],
            [],
            [],
            [['entity_id' => 7, 'request_path' => 'p.html']]
        );

        $tags = $this->viewModel($this->connection($fetchAll))->getIdentities();

        $this->assertSame(
            [Category::CACHE_TAG, Product::CACHE_TAG, Page::CACHE_TAG, Product::CACHE_TAG . '_7'],
            $tags
        );
    }

    public function testCategoryCountHandlesFlatAndNestedInput(): void
    {
        $vm = $this->viewModel();

        $this->assertSame(0, $vm->getCategoryCount([]));
        $this->assertSame(2, $vm->getCategoryCount([['children' => 'bad'], ['children' => []]]));
        $this->assertSame(4, $vm->getCategoryCount([
            ['children' => [['children' => [['children' => []]]], ['id' => 1]]],
        ]));
    }

    public function testMediaBaseUrlAndCurrencySymbol(): void
    {
        $price = $this->createStub(PriceCurrencyInterface::class);
        $price->method('getCurrencySymbol')->willReturn('EUR');
        $vm = $this->viewModel(null, $this->store(['media' => 'https://cdn.test/media/']), [], null, $price);

        $this->assertSame('https://cdn.test/media/catalog/product', $vm->getProductMediaBaseUrl());
        $this->assertSame('EUR', $vm->getCurrencySymbol());

        $broken = $this->viewModel(null, null, [], null, null, $this->failingStoreManager());
        $this->assertSame('', $broken->getProductMediaBaseUrl());
        $this->assertSame('', $broken->getCurrencySymbol());
    }

    public function testLastUpdatedTimestampFormat(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',
            $this->viewModel()->getLastUpdatedTimestamp()
        );
    }
}
