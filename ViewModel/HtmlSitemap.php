<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\HtmlSitemap\Helper\Config;
use Psr\Log\LoggerInterface;

class HtmlSitemap implements ArgumentInterface
{
    private const ABSOLUTE_HARD_CAP = 2000;

    private ?int $totalProductCount = null;

    private ?array $categories = null;

    private ?array $products = null;

    private ?array $cmsPages = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    public function getIdentities(): array
    {
        $tags = [Category::CACHE_TAG, Product::CACHE_TAG, Page::CACHE_TAG];

        if ($this->isShowCategories()) {
            $this->collectCategoryTags($this->getCategories(), $tags);
        }
        if ($this->isShowProducts()) {
            foreach ($this->getProducts() as $product) {
                $tags[] = Product::CACHE_TAG . '_' . (int) $product['id'];
            }
        }
        if ($this->isShowCmsPages()) {
            foreach ($this->getCmsPages() as $page) {
                $tags[] = Page::CACHE_TAG . '_' . (int) $page['id'];
            }
        }

        return array_values(array_unique($tags));
    }

    private function collectCategoryTags(array $nodes, array &$tags): void
    {
        foreach ($nodes as $node) {
            $tags[] = Category::CACHE_TAG . '_' . (int) $node['id'];
            $tags[] = Product::CACHE_PRODUCT_CATEGORY_TAG . '_' . (int) $node['id'];
            if (!empty($node['children']) && is_array($node['children'])) {
                $this->collectCategoryTags($node['children'], $tags);
            }
        }
    }

    private function joinStoreValue(
        Select $select,
        string $alias,
        string $table,
        int $attributeId,
        int $storeId
    ): string {
        $select->joinLeft(
            [$alias . '_d' => $table],
            sprintf(
                '%1$s_d.entity_id = e.entity_id AND %1$s_d.attribute_id = %2$d AND %1$s_d.store_id = 0',
                $alias,
                $attributeId
            ),
            []
        );
        $select->joinLeft(
            [$alias . '_s' => $table],
            sprintf(
                '%1$s_s.entity_id = e.entity_id AND %1$s_s.attribute_id = %2$d AND %1$s_s.store_id = %3$d',
                $alias,
                $attributeId,
                $storeId
            ),
            []
        );

        return sprintf('COALESCE(%1$s_s.value, %1$s_d.value)', $alias);
    }

    public function getCurrentPage(): int
    {
        $raw = $this->request->getParam('p', 1);
        $page = is_numeric($raw) ? (int) $raw : 1;
        if ($page < 1) {
            $page = 1;
        }
        $total = $this->getTotalProductPages();
        if ($total > 0 && $page > $total) {
            $page = $total;
        }
        return $page;
    }

    public function getProductsPerPage(): int
    {
        return $this->config->getProductsPerPage();
    }

    public function getTotalProductCount(): int
    {
        if ($this->totalProductCount !== null) {
            return $this->totalProductCount;
        }

        try {
            $store        = $this->storeManager->getStore();
            $storeId      = (int) $store->getId();
            $websiteId    = (int) $store->getWebsiteId();
            $conn         = $this->resource->getConnection();
            $prodEntity   = $this->resource->getTableName('catalog_product_entity');
            $prodInt      = $this->resource->getTableName('catalog_product_entity_int');
            $prodVarchar  = $this->resource->getTableName('catalog_product_entity_varchar');
            $prodWebsite  = $this->resource->getTableName('catalog_product_website');
            $eavAttr      = $this->resource->getTableName('eav_attribute');
            $urlTable     = $this->resource->getTableName('url_rewrite');

            $entityTypeId = $this->getProductEntityTypeId();
            $nameAttrId = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'name')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );
            $visAttrId = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'visibility')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );
            $statusAttrId = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'status')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );

            $select = $conn->select()
                ->from(['e' => $prodEntity], ['cnt' => new \Zend_Db_Expr('COUNT(DISTINCT e.entity_id)')])
                ->join(['pw' => $prodWebsite], 'pw.product_id = e.entity_id AND pw.website_id = ' . $websiteId, []);
            $nameExpr = $this->joinStoreValue($select, 'n', $prodVarchar, $nameAttrId, $storeId);
            $visExpr = $this->joinStoreValue($select, 'vis', $prodInt, $visAttrId, $storeId);
            $statusExpr = $this->joinStoreValue($select, 'st', $prodInt, $statusAttrId, $storeId);
            $select->where($visExpr . ' IN (?)', [2, 4])
                ->where($statusExpr . ' = ?', 1);
            $this->applyListableConstraints($select, $nameExpr, $urlTable, $storeId);

            return $this->totalProductCount = (int) $conn->fetchOne($select);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_HtmlSitemap] count failed: ' . $e->getMessage());
            return $this->totalProductCount = 0;
        }
    }

    private function applyListableConstraints(Select $select, string $nameExpr, string $urlTable, int $storeId): void
    {
        $select->where('TRIM(' . $nameExpr . ") <> ''")
            ->where(sprintf(
                "EXISTS (SELECT 1 FROM %s AS ur WHERE ur.entity_id = e.entity_id AND ur.entity_type = 'product'"
                . ' AND ur.store_id = %d AND ur.redirect_type = 0)',
                $urlTable,
                $storeId
            ));
    }

    public function getTotalProductPages(): int
    {
        $total = $this->getTotalProductCount();
        $per   = $this->getProductsPerPage();
        if ($total === 0 || $per === 0) {
            return 0;
        }
        $pages = (int) ceil($total / $per);
        return min($pages, self::ABSOLUTE_HARD_CAP);
    }

    public function getProductPagination(): array
    {
        $current = $this->getCurrentPage();
        $total   = $this->getTotalProductPages();
        $per     = $this->getProductsPerPage();
        $count   = $this->getTotalProductCount();

        $windowStart = max(1, $current - 2);
        $windowEnd   = min($total, $current + 2);
        $window = [];
        for ($i = $windowStart; $i <= $windowEnd; $i++) {
            $window[] = $i;
        }

        return [
            'current'     => $current,
            'total'       => $total,
            'per_page'    => $per,
            'total_items' => $count,
            'window'      => $window,
            'base_url'    => $this->getPaginationBaseUrl(),
        ];
    }

    public function getPaginationBaseUrl(): string
    {
        try {
            $store  = $this->storeManager->getStore();
            $base   = rtrim((string) $store->getBaseUrl(), '/');
            return $base . '/sitemap';
        } catch (\Throwable) {
            return '/sitemap';
        }
    }

    public function getProductPageUrl(int $page): string
    {
        $base = $this->getPaginationBaseUrl();
        if ($page <= 1) {
            return $base;
        }
        return $base . '?p=' . $page;
    }

    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMaxCategoryDepth(): int
    {
        return $this->config->getMaxCategoryDepth();
    }

    public function getProductSortOrder(): string
    {
        return $this->config->getProductSortOrder();
    }

    public function getProductUrlStructure(): string
    {
        return $this->config->getProductUrlStructure();
    }

    public function isShowStores(): bool
    {
        return $this->config->isShowStores();
    }

    public function isShowProducts(): bool
    {
        return $this->config->isShowProducts();
    }

    public function isShowCmsPages(): bool
    {
        return $this->config->isShowCmsPages();
    }

    public function isShowCategories(): bool
    {
        return $this->config->isShowCategories();
    }

    public function isShowCustomLinks(): bool
    {
        return $this->config->isShowCustomLinks();
    }

    public function isShowSearchField(): bool
    {
        return $this->config->isShowSearchField();
    }

    public function isShowTestimonials(): bool
    {
        return $this->config->isShowTestimonials();
    }

    public function isShowFaqs(): bool
    {
        return $this->config->isShowFaqs();
    }

    public function isShowDynamicForms(): bool
    {
        return $this->config->isShowDynamicForms();
    }

    public function getCustomLinks(): array
    {
        $raw = trim($this->config->getCustomLinks());
        if ($raw === '') {
            return [];
        }

        $links = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '|')) {
                [$url, $label] = array_map('trim', explode('|', $line, 2));
            } else {
                $url   = $line;
                $label = $line;
            }

            if ($url === '') {
                continue;
            }

            $links[] = [
                'url'   => $url,
                'label' => $label !== '' ? $label : $url,
            ];
        }

        return $links;
    }

    public function getCategories(): array
    {
        if ($this->categories === null) {
            $this->categories = $this->loadCategories();
        }
        return $this->categories;
    }

    private function loadCategories(): array
    {
        try {
            $store     = $this->storeManager->getStore();
            $storeId   = (int) $store->getId();
            $rootCatId = (int) $store->getRootCategoryId();
            $baseUrl   = rtrim((string) $store->getBaseUrl(), '/') . '/';

            $conn       = $this->resource->getConnection();
            $catEntity  = $this->resource->getTableName('catalog_category_entity');
            $catVarchar = $this->resource->getTableName('catalog_category_entity_varchar');
            $catInt     = $this->resource->getTableName('catalog_category_entity_int');
            $eavAttr    = $this->resource->getTableName('eav_attribute');
            $urlTable   = $this->resource->getTableName('url_rewrite');

            $entityTypeId = $this->getCategoryEntityTypeId();

            $nameAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'name')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            $isActiveAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'is_active')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            $excludeAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'exclude_from_html_sitemap')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            $select = $conn->select()
                ->from(['e' => $catEntity], ['entity_id', 'parent_id', 'level', 'path']);
            $nameExpr = $this->joinStoreValue($select, 'v', $catVarchar, $nameAttrId, $storeId);
            $select->columns(['name' => new \Zend_Db_Expr($nameExpr)])
                ->where('e.path LIKE ?', '1/' . $rootCatId . '/%')
                ->order('e.level ASC')
                ->order('e.position ASC');

            $maxDepth = $this->getMaxCategoryDepth();
            if ($maxDepth > 0) {
                $rootLevel  = (int) $conn->fetchOne(
                    $conn->select()->from($catEntity, 'level')->where('entity_id = ?', $rootCatId)
                );
                $maxLevel = $rootLevel + $maxDepth;
                $select->where('e.level <= ?', $maxLevel);
            }

            $rows = $conn->fetchAll($select);
            if (empty($rows)) {
                return [];
            }

            $ids = array_map(static fn($r) => (int) $r['entity_id'], $rows);

            $activeIds = array_flip(
                $this->fetchIntAttributeSet($conn, $catInt, $isActiveAttrId, $storeId, $ids, 1)
            );

            $excludedIds = [];
            if ($excludeAttrId > 0) {
                $excludedIds = array_flip(
                    $this->fetchIntAttributeSet($conn, $catInt, $excludeAttrId, $storeId, $ids, 1)
                );
            }

            $pathMap = [];
            $sel = $conn->select()
                ->from($urlTable, ['entity_id', 'request_path'])
                ->where('entity_type = ?', 'category')
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->where('entity_id IN (?)', $ids);
            foreach ($conn->fetchAll($sel) as $r) {
                $pathMap[(int) $r['entity_id']] = (string) $r['request_path'];
            }

            $nodes = [];
            foreach ($rows as $r) {
                $id   = (int) $r['entity_id'];
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (!isset($activeIds[$id])) {
                    continue;
                }
                if (isset($excludedIds[$id])) {
                    continue;
                }

                $nodes[$id] = [
                    'id'       => $id,
                    'name'     => $name,
                    'url'      => isset($pathMap[$id]) ? $baseUrl . ltrim($pathMap[$id], '/') : '#',
                    'level'    => (int) $r['level'],
                    'parent'   => (int) $r['parent_id'],
                    'children' => [],
                ];
            }

            $roots = [];
            foreach ($nodes as $id => &$node) {
                $pid = $node['parent'];
                if ($pid === $rootCatId || !isset($nodes[$pid])) {
                    $roots[] = &$node;
                } else {
                    $nodes[$pid]['children'][] = &$node;
                }
            }
            unset($node);

            return $roots;
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_HtmlSitemap] categories failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getProducts(): array
    {
        if ($this->products === null) {
            $this->products = $this->loadProducts();
        }
        return $this->products;
    }

    private function loadProducts(): array
    {
        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
            $mediaBaseUrl = $this->getProductMediaBaseUrl();

            $conn        = $this->resource->getConnection();
            $prodEntity  = $this->resource->getTableName('catalog_product_entity');
            $prodVarchar = $this->resource->getTableName('catalog_product_entity_varchar');
            $prodInt     = $this->resource->getTableName('catalog_product_entity_int');
            $prodDecimal = $this->resource->getTableName('catalog_product_entity_decimal');
            $eavAttr     = $this->resource->getTableName('eav_attribute');
            $urlTable    = $this->resource->getTableName('url_rewrite');
            $prodWebsite = $this->resource->getTableName('catalog_product_website');

            $entityTypeId = $this->getProductEntityTypeId();

            $nameAttrId = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'name')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );
            $visAttrId = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'visibility')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );
            $statusAttrId = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'status')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );

            $websiteId = (int) $store->getWebsiteId();

            $select = $conn->select()
                ->from(['e' => $prodEntity], ['entity_id'])
                ->join(['pw' => $prodWebsite], 'pw.product_id = e.entity_id AND pw.website_id = ' . $websiteId, []);
            $nameExpr = $this->joinStoreValue($select, 'n', $prodVarchar, $nameAttrId, $storeId);
            $visExpr = $this->joinStoreValue($select, 'vis', $prodInt, $visAttrId, $storeId);
            $statusExpr = $this->joinStoreValue($select, 'st', $prodInt, $statusAttrId, $storeId);
            $select->columns(['name' => new \Zend_Db_Expr($nameExpr)])
                ->where($visExpr . ' IN (?)', [2, 4])
                ->where($statusExpr . ' = ?', 1);
            $this->applyListableConstraints($select, $nameExpr, $urlTable, $storeId);
            $select->group('e.entity_id')
                ->limit($this->getProductsPerPage(), ($this->getCurrentPage() - 1) * $this->getProductsPerPage());

            $sortOrder = $this->getProductSortOrder();
            switch ($sortOrder) {
                case 'name_desc':
                    $select->order(new \Zend_Db_Expr($nameExpr . ' DESC'));
                    break;
                case 'newest':
                    $select->order('e.created_at DESC');
                    break;
                case 'oldest':
                    $select->order('e.created_at ASC');
                    break;
                case 'price':
                    $priceAttrId = (int) $conn->fetchOne(
                        $conn->select()->from($eavAttr, 'attribute_id')
                            ->where('attribute_code = ?', 'price')
                            ->where('entity_type_id = ?', $entityTypeId)->limit(1)
                    );
                    if ($priceAttrId > 0) {
                        $priceExpr = $this->joinStoreValue($select, 'pr', $prodDecimal, $priceAttrId, $storeId);
                        $select->order(new \Zend_Db_Expr($priceExpr . ' ASC'));
                    } else {
                        $select->order(new \Zend_Db_Expr($nameExpr . ' ASC'));
                    }
                    break;
                case 'position':
                    $select->order('e.entity_id ASC');
                    break;
                case 'name':
                default:
                    $select->order(new \Zend_Db_Expr($nameExpr . ' ASC'));
                    break;
            }

            $rows = $conn->fetchAll($select);
            if (empty($rows)) {
                return [];
            }

            $ids = array_map(static fn($r) => (int) $r['entity_id'], $rows);
            $nameMap = [];
            foreach ($rows as $r) {
                $nameMap[(int) $r['entity_id']] = trim((string) ($r['name'] ?? ''));
            }

            $imageMap = [];
            $smallImageAttrId = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'small_image')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );
            if ($smallImageAttrId > 0) {
                $imgSelect = $conn->select()
                    ->from($prodVarchar, ['entity_id', 'store_id', 'value'])
                    ->where('attribute_id = ?', $smallImageAttrId)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->where('entity_id IN (?)', $ids);
                foreach ($conn->fetchAll($imgSelect) as $r) {
                    $eid = (int) $r['entity_id'];
                    $val = trim((string) ($r['value'] ?? ''));
                    if ($val === '' || $val === 'no_selection') {
                        continue;
                    }
                    if (!isset($imageMap[$eid]) || (int) $r['store_id'] > 0) {
                        $imageMap[$eid] = str_starts_with($val, '/') ? $val : '/' . $val;
                    }
                }
            }

            $priceMap = [];
            $priceAttrIdForDisplay = (int) $conn->fetchOne(
                $conn->select()->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'price')
                    ->where('entity_type_id = ?', $entityTypeId)->limit(1)
            );
            if ($priceAttrIdForDisplay > 0) {
                $prSelect = $conn->select()
                    ->from($prodDecimal, ['entity_id', 'store_id', 'value'])
                    ->where('attribute_id = ?', $priceAttrIdForDisplay)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->where('entity_id IN (?)', $ids);
                foreach ($conn->fetchAll($prSelect) as $r) {
                    $eid = (int) $r['entity_id'];
                    $val = $r['value'];
                    if ($val === null || $val === '') {
                        continue;
                    }
                    if (!isset($priceMap[$eid]) || (int) $r['store_id'] > 0) {
                        $priceMap[$eid] = (float) $val;
                    }
                }
            }

            $urlStructure = $this->getProductUrlStructure();
            $pathMap = [];
            $urlSelect = $conn->select()
                ->from($urlTable, ['entity_id', 'request_path'])
                ->where('entity_type = ?', 'product')
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->where('entity_id IN (?)', $ids);

            if ($urlStructure === 'short') {
                $urlSelect->where('metadata IS NULL');
            } else {
                $urlSelect->order(new \Zend_Db_Expr('CASE WHEN metadata IS NOT NULL THEN 0 ELSE 1 END ASC'));
            }

            foreach ($conn->fetchAll($urlSelect) as $r) {
                $eid = (int) $r['entity_id'];
                if (!isset($pathMap[$eid])) {
                    $pathMap[$eid] = (string) $r['request_path'];
                }
            }

            if ($urlStructure === 'short') {
                $missingIds = array_diff($ids, array_keys($pathMap));
                if (!empty($missingIds)) {
                    $fallback = $conn->select()
                        ->from($urlTable, ['entity_id', 'request_path'])
                        ->where('entity_type = ?', 'product')
                        ->where('store_id = ?', $storeId)
                        ->where('redirect_type = ?', 0)
                        ->where('entity_id IN (?)', $missingIds);
                    foreach ($conn->fetchAll($fallback) as $r) {
                        $eid = (int) $r['entity_id'];
                        if (!isset($pathMap[$eid])) {
                            $pathMap[$eid] = (string) $r['request_path'];
                        }
                    }
                }
            }

            $out = [];
            foreach ($ids as $id) {
                $name = $nameMap[$id] ?? '';
                if ($name === '' || !isset($pathMap[$id])) {
                    continue;
                }

                $image = '';
                if (isset($imageMap[$id]) && $mediaBaseUrl !== '') {
                    $image = $mediaBaseUrl . $imageMap[$id];
                }

                $price = '';
                if (isset($priceMap[$id]) && $priceMap[$id] > 0) {
                    $price = (string) $this->priceCurrency->convertAndFormat(
                        $priceMap[$id],
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION,
                        $store
                    );
                }

                $out[] = [
                    'id'    => $id,
                    'name'  => $name,
                    'url'   => $baseUrl . ltrim($pathMap[$id], '/'),
                    'image' => $image,
                    'price' => $price,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_HtmlSitemap] products failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getCmsPages(): array
    {
        if ($this->cmsPages === null) {
            $this->cmsPages = $this->loadCmsPages();
        }
        return $this->cmsPages;
    }

    private function loadCmsPages(): array
    {
        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';

            $conn   = $this->resource->getConnection();
            $page   = $this->resource->getTableName('cms_page');
            $pstore = $this->resource->getTableName('cms_page_store');

            $homeIdentifier = (string) $this->config->getValue('web/default/cms_home_page');
            $excludedIdentifiers = $this->config->getExcludeCmsPages();

            $select = $conn->select()
                ->from(['p' => $page], ['page_id', 'identifier', 'title'])
                ->join(['ps' => $pstore], 'ps.page_id = p.page_id', [])
                ->where('p.is_active = ?', 1)
                ->where('ps.store_id IN (?)', [0, $storeId])
                ->group('p.page_id')
                ->order('p.title ASC');

            $out = [];
            foreach ($conn->fetchAll($select) as $r) {
                $ident = (string) $r['identifier'];
                if ($ident === '' || $ident === 'no-route' || $ident === $homeIdentifier) {
                    continue;
                }
                if ($excludedIdentifiers !== [] && in_array($ident, $excludedIdentifiers, true)) {
                    continue;
                }
                $out[] = [
                    'id'    => (int) $r['page_id'],
                    'title' => (string) $r['title'],
                    'url'   => $baseUrl . ltrim($ident, '/'),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_HtmlSitemap] cms failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getStores(): array
    {
        try {
            $stores = [];
            foreach ($this->storeManager->getStores() as $store) {
                if (!$store->isActive()) {
                    continue;
                }
                $stores[] = [
                    'name' => (string) $store->getName(),
                    'url'  => rtrim((string) $store->getBaseUrl(), '/') . '/',
                ];
            }

            return $stores;
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_HtmlSitemap] stores failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getTestimonials(): array
    {
        try {
            $store    = $this->storeManager->getStore();
            $storeId  = (int) $store->getId();
            $baseUrl  = rtrim((string) $store->getBaseUrl(), '/') . '/';
            $conn     = $this->resource->getConnection();
            $table    = $this->resource->getTableName('panth_testimonial');
            $catTable = $this->resource->getTableName('panth_testimonial_category');

            if (!$conn->isTableExists($table) && !$conn->isTableExists($catTable)) {
                return [];
            }

            $base = trim((string) ($this->config->getValue('panth_testimonials/general/route', $storeId)
                ?: 'testimonials'), '/') ?: 'testimonials';

            $out = [];

            if ($conn->isTableExists($catTable)) {
                $cols = $conn->describeTable($catTable);
                $columns = ['url_key', 'name'];
                $select = $conn->select()
                    ->from($catTable, $columns)
                    ->where('is_active = ?', 1)
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
                if (isset($cols['store_id'])) {
                    $select->where('store_id IN (?)', [0, $storeId]);
                }
                if (isset($cols['sort_order'])) {
                    $select->order('sort_order ASC');
                }
                $select->order('name ASC');
                foreach ($conn->fetchAll($select) as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    $key  = trim((string) ($row['url_key'] ?? ''));
                    if ($name === '' || $key === '') {
                        continue;
                    }
                    $out[] = [
                        'title' => $name,
                        'url'   => $baseUrl . $base . '/category/' . $key,
                    ];
                }
            }

            if ($conn->isTableExists($table)) {
                $cols = $conn->describeTable($table);
                $columns = ['url_key', 'title'];
                $select = $conn->select()
                    ->from($table, $columns)
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
                if (isset($cols['status'])) {
                    $select->where('status = ?', 1);
                }
                if (isset($cols['store_id'])) {
                    $select->where('store_id IN (?)', [0, $storeId]);
                }
                if (isset($cols['sort_order'])) {
                    $select->order('sort_order ASC');
                }
                $select->order('title ASC');
                foreach ($conn->fetchAll($select) as $row) {
                    $title = trim((string) ($row['title'] ?? ''));
                    $key   = trim((string) ($row['url_key'] ?? ''));
                    if ($title === '' || $key === '') {
                        continue;
                    }
                    $out[] = [
                        'title' => $title,
                        'url'   => $baseUrl . $base . '/' . $key,
                    ];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_HtmlSitemap] testimonials section failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getFaqs(): array
    {
        try {
            $store    = $this->storeManager->getStore();
            $storeId  = (int) $store->getId();
            $baseUrl  = rtrim((string) $store->getBaseUrl(), '/') . '/';
            $conn     = $this->resource->getConnection();
            $itemTable     = $this->resource->getTableName('panth_faq_item');
            $itemStore     = $this->resource->getTableName('panth_faq_item_store');
            $categoryTable = $this->resource->getTableName('panth_faq_category');
            $categoryStore = $this->resource->getTableName('panth_faq_category_store');

            $hasItems      = $conn->isTableExists($itemTable);
            $hasCategories = $conn->isTableExists($categoryTable);
            if (!$hasItems && !$hasCategories) {
                return [];
            }
            $hasItemStore = $hasItems && $conn->isTableExists($itemStore);

            $base = trim((string) ($this->config->getValue('panth_faq/general/faq_route', $storeId)
                ?: 'faq'), '/') ?: 'faq';

            $out = [];

            if ($hasCategories) {
                $cols = $conn->describeTable($categoryTable);
                $select = $conn->select()
                    ->from(['c' => $categoryTable], ['url_key', 'name'])
                    ->where('c.is_active = ?', 1)
                    ->where('c.url_key IS NOT NULL')
                    ->where('c.url_key != ?', '');
                if ($conn->isTableExists($categoryStore)) {
                    $select->join(
                        ['cs' => $categoryStore],
                        'cs.category_id = c.category_id AND cs.store_id IN (0, ' . (int) $storeId . ')',
                        []
                    )->group('c.category_id');
                }
                if (isset($cols['sort_order'])) {
                    $select->order('c.sort_order ASC');
                }
                $select->order('c.name ASC');
                foreach ($conn->fetchAll($select) as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    $key  = trim((string) ($row['url_key'] ?? ''));
                    if ($name === '' || $key === '') {
                        continue;
                    }
                    $out[] = [
                        'title' => $name,
                        'url'   => $baseUrl . $base . '/category/' . $key,
                    ];
                }
            }

            if ($hasItems) {
                $cols = $conn->describeTable($itemTable);
                $select = $conn->select()
                    ->from(['i' => $itemTable], ['url_key', 'question'])
                    ->where('i.url_key IS NOT NULL')
                    ->where('i.url_key != ?', '');
                if (isset($cols['is_active'])) {
                    $select->where('i.is_active = ?', 1);
                }
                if ($hasItemStore) {
                    $select->join(
                        ['s' => $itemStore],
                        's.item_id = i.item_id AND s.store_id IN (0, ' . (int) $storeId . ')',
                        []
                    )->group('i.item_id');
                }
                if (isset($cols['sort_order'])) {
                    $select->order('i.sort_order ASC');
                }
                $select->order('i.question ASC');
                foreach ($conn->fetchAll($select) as $row) {
                    $title = trim((string) ($row['question'] ?? ''));
                    $key   = trim((string) ($row['url_key'] ?? ''));
                    if ($title === '' || $key === '') {
                        continue;
                    }
                    $out[] = [
                        'title' => $title,
                        'url'   => $baseUrl . $base . '/item/' . $key,
                    ];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_HtmlSitemap] faqs section failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getDynamicForms(): array
    {
        try {
            $store    = $this->storeManager->getStore();
            $storeId  = (int) $store->getId();
            $baseUrl  = rtrim((string) $store->getBaseUrl(), '/') . '/';
            $conn     = $this->resource->getConnection();
            $table    = $this->resource->getTableName('panth_dynamic_form');

            if (!$conn->isTableExists($table)) {
                return [];
            }

            $cols = $conn->describeTable($table);
            $columns = ['url_key', 'title', 'name'];
            $select = $conn->select()
                ->from($table, array_intersect(['url_key', 'title', 'name'], array_keys($cols)))
                ->where('url_key IS NOT NULL')
                ->where('url_key != ?', '');
            if (isset($cols['is_active'])) {
                $select->where('is_active = ?', 1);
            }
            if (isset($cols['form_type'])) {
                $select->where('form_type IN (?)', ['page', 'both']);
            }
            if (isset($cols['store_id'])) {
                $select->where('store_id IN (?)', [0, $storeId]);
            }

            $out = [];
            foreach ($conn->fetchAll($select) as $row) {
                $key = trim((string) ($row['url_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    $title = trim((string) ($row['name'] ?? ''));
                }
                if ($title === '') {
                    $title = $key;
                }
                $out[] = [
                    'title' => $title,
                    'url'   => $baseUrl . 'pages/' . $key,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->info('[Panth_HtmlSitemap] dynamic-forms section failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getCategoryCount(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            $count++;
            if (!empty($node['children']) && is_array($node['children'])) {
                $count += $this->getCategoryCount($node['children']);
            }
        }
        return $count;
    }

    public function getLastUpdatedTimestamp(): string
    {
        return date('Y-m-d H:i');
    }

    public function getProductMediaBaseUrl(): string
    {
        try {
            $store = $this->storeManager->getStore();
            $base  = rtrim(
                (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            );
            return $base === '' ? '' : $base . '/catalog/product';
        } catch (\Throwable) {
            return '';
        }
    }

    public function getCurrencySymbol(): string
    {
        try {
            return (string) $this->priceCurrency->getCurrencySymbol($this->storeManager->getStore());
        } catch (\Throwable) {
            return '';
        }
    }

    public function getCategoryTree(): array
    {
        return $this->getCategories();
    }

    private function getCategoryEntityTypeId(): int
    {
        $conn = $this->resource->getConnection();
        $tbl  = $this->resource->getTableName('eav_entity_type');
        return (int) $conn->fetchOne(
            $conn->select()->from($tbl, 'entity_type_id')->where('entity_type_code = ?', 'catalog_category')
        );
    }

    private function getProductEntityTypeId(): int
    {
        $conn = $this->resource->getConnection();
        $tbl  = $this->resource->getTableName('eav_entity_type');
        return (int) $conn->fetchOne(
            $conn->select()->from($tbl, 'entity_type_id')->where('entity_type_code = ?', 'catalog_product')
        );
    }

    private function fetchIntAttributeSet(
        $conn,
        string $table,
        int $attrId,
        int $storeId,
        array $entityIds,
        int $value
    ): array {
        if ($attrId === 0 || empty($entityIds)) {
            return [];
        }

        $select = $conn->select()
            ->from($table, ['entity_id', 'store_id', 'value'])
            ->where('attribute_id = ?', $attrId)
            ->where('store_id IN (?)', [0, $storeId])
            ->where('entity_id IN (?)', $entityIds);

        $resolved = [];
        foreach ($conn->fetchAll($select) as $row) {
            $entityId = (int) $row['entity_id'];
            if (!isset($resolved[$entityId]) || (int) $row['store_id'] !== 0) {
                $resolved[$entityId] = (int) $row['value'];
            }
        }

        return array_keys(array_filter($resolved, static fn($v) => $v === $value));
    }
}
