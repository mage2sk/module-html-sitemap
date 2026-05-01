<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\HtmlSitemap\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Theme-agnostic HTML sitemap data source.
 *
 * Returns lightweight arrays for template rendering — no block-specific
 * logic, no JavaScript. All section visibility and sort options are driven
 * by admin configuration under panth_html_sitemap/general/*.
 */
class HtmlSitemap implements ArgumentInterface
{
    /** @var int Absolute hard cap across ALL pages combined. At 500 products/page × 2000 pages = 1,000,000 products. Beyond that, an XML sitemap is the right tool. */
    private const ABSOLUTE_HARD_CAP = 2000;

    /** @var int|null Memoised total product count for this request. */
    private ?int $totalProductCount = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Read the current page from the `p` query param and clamp to a
     * non-negative int. Malicious or malformed values fall back to page 1.
     */
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

    /**
     * Return a single COUNT(*) over the visible-product select — cheap even
     * at 100k+ products because the underlying indexes cover every filter.
     */
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
            $prodWebsite  = $this->resource->getTableName('catalog_product_website');
            $eavAttr      = $this->resource->getTableName('eav_attribute');

            $entityTypeId = $this->getProductEntityTypeId();
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
                ->join(['pw' => $prodWebsite], 'pw.product_id = e.entity_id AND pw.website_id = ' . $websiteId, [])
                ->join(
                    ['vis' => $prodInt],
                    'vis.entity_id = e.entity_id AND vis.attribute_id = ' . $visAttrId
                    . ' AND vis.store_id IN (0, ' . $storeId . ')',
                    []
                )
                ->join(
                    ['st' => $prodInt],
                    'st.entity_id = e.entity_id AND st.attribute_id = ' . $statusAttrId
                    . ' AND st.store_id IN (0, ' . $storeId . ')',
                    []
                )
                ->where('vis.value IN (?)', [2, 4])
                ->where('st.value = ?', 1);

            return $this->totalProductCount = (int) $conn->fetchOne($select);
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth_HtmlSitemap] count failed: ' . $e->getMessage());
            return $this->totalProductCount = 0;
        }
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

    /**
     * Return pagination metadata suitable for a prev/next + numbered page UI.
     *
     * @return array{current: int, total: int, per_page: int, total_items: int, window: int[], base_url: string}
     */
    public function getProductPagination(): array
    {
        $current = $this->getCurrentPage();
        $total   = $this->getTotalProductPages();
        $per     = $this->getProductsPerPage();
        $count   = $this->getTotalProductCount();

        // A compact sliding window: up to 5 page numbers centered on current.
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

    /**
     * Build the canonical pagination base URL (e.g. "https://store.example/sitemap").
     * Pagination links append `?p=N` to this.
     */
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

    // ------------------------------------------------------------------
    //  Config-driven accessors
    // ------------------------------------------------------------------

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

    /**
     * Parse the custom_links textarea config.
     *
     * Each line follows the format:  URL | Label
     * If no pipe is present the URL is used as the label.
     *
     * @return array<int, array{url: string, label: string}>
     */
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

    // ------------------------------------------------------------------
    //  Data loaders
    // ------------------------------------------------------------------

    /**
     * Build a nested category tree, optionally limited by depth and
     * respecting the `exclude_from_html_sitemap` attribute when present.
     *
     * @return array<int, array{id: int, name: string, url: string, level: int, children: array}>
     */
    public function getCategories(): array
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
                ->from(['e' => $catEntity], ['entity_id', 'parent_id', 'level', 'path'])
                ->joinLeft(
                    ['v' => $catVarchar],
                    'v.entity_id = e.entity_id AND v.attribute_id = ' . $nameAttrId
                    . ' AND v.store_id IN (0, ' . $storeId . ')',
                    ['name' => 'v.value']
                )
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

            $activeIds = $this->fetchIntAttributeSet($conn, $catInt, $isActiveAttrId, $storeId, $ids, 1);

            $excludedIds = [];
            if ($excludeAttrId > 0) {
                $excludedIds = $this->fetchIntAttributeSet($conn, $catInt, $excludeAttrId, $storeId, $ids, 1);
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
                if (!in_array($id, $activeIds, true)) {
                    continue;
                }
                if (in_array($id, $excludedIds, true)) {
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

    /**
     * Load visible products for the current store, sorted by config,
     * limited to {@see PRODUCT_LIMIT}.
     *
     * @return array<int, array{name: string, url: string, image: string, price: string}>
     */
    public function getProducts(): array
    {
        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
            $mediaBaseUrl = $this->getProductMediaBaseUrl();
            $currencySymbol = $this->getCurrencySymbol();

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
                ->join(['pw' => $prodWebsite], 'pw.product_id = e.entity_id AND pw.website_id = ' . $websiteId, [])
                ->joinLeft(
                    ['n' => $prodVarchar],
                    'n.entity_id = e.entity_id AND n.attribute_id = ' . $nameAttrId
                    . ' AND n.store_id IN (0, ' . $storeId . ')',
                    ['name' => 'n.value']
                )
                ->join(
                    ['vis' => $prodInt],
                    'vis.entity_id = e.entity_id AND vis.attribute_id = ' . $visAttrId
                    . ' AND vis.store_id IN (0, ' . $storeId . ')',
                    []
                )
                ->join(
                    ['st' => $prodInt],
                    'st.entity_id = e.entity_id AND st.attribute_id = ' . $statusAttrId
                    . ' AND st.store_id IN (0, ' . $storeId . ')',
                    []
                )
                ->where('vis.value IN (?)', [2, 4])
                ->where('st.value = ?', 1)
                ->group('e.entity_id')
                ->limit($this->getProductsPerPage(), ($this->getCurrentPage() - 1) * $this->getProductsPerPage());

            $sortOrder = $this->getProductSortOrder();
            switch ($sortOrder) {
                case 'name_desc':
                    $select->order('n.value DESC');
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
                        $select->joinLeft(
                            ['pr' => $prodDecimal],
                            'pr.entity_id = e.entity_id AND pr.attribute_id = ' . $priceAttrId
                            . ' AND pr.store_id IN (0, ' . $storeId . ')',
                            []
                        )->order('pr.value ASC');
                    } else {
                        $select->order('n.value ASC');
                    }
                    break;
                case 'position':
                    $select->order('e.entity_id ASC');
                    break;
                case 'name':
                default:
                    $select->order('n.value ASC');
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
                    $price = $currencySymbol . number_format($priceMap[$id], 2);
                }

                $out[] = [
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

    /**
     * Load active CMS pages for the current store, excluding the homepage
     * identifier and no-route page.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function getCmsPages(): array
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
                ->from(['p' => $page], ['identifier', 'title'])
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

    /**
     * Return all active stores. Useful for a store-switcher section.
     *
     * @return array<int, array{name: string, url: string}>
     */
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

    // ------------------------------------------------------------------
    //  Optional integrations — render only when source module is installed
    // ------------------------------------------------------------------

    /**
     * Testimonials section.
     *
     * **Conditional**: returns [] when the `panth_testimonial` table
     * isn't present (the source module isn't installed). Never
     * references a `Panth_Testimonials` class — pure raw SQL through
     * the resource connection.
     *
     * @return array<int, array{title: string, url: string}>
     */
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

            // Categories first (broader landing pages).
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

            // Individual approved testimonials.
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

    /**
     * FAQ section.
     *
     * **Conditional**: returns [] when neither `panth_faq_item` nor
     * `panth_faq_category` exists.
     *
     * @return array<int, array{title: string, url: string}>
     */
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

            $hasItems      = $conn->isTableExists($itemTable);
            $hasCategories = $conn->isTableExists($categoryTable);
            if (!$hasItems && !$hasCategories) {
                return [];
            }
            $hasItemStore = $hasItems && $conn->isTableExists($itemStore);

            // Source module stores this under `panth_faq/general/faq_route`
            // (Helper\Data::XML_PATH_FAQ_ROUTE in module-faq).
            $base = trim((string) ($this->config->getValue('panth_faq/general/faq_route', $storeId)
                ?: 'faq'), '/') ?: 'faq';

            $out = [];

            if ($hasCategories) {
                $cols = $conn->describeTable($categoryTable);
                $select = $conn->select()
                    ->from($categoryTable, ['url_key', 'name'])
                    ->where('is_active = ?', 1)
                    ->where('url_key IS NOT NULL')
                    ->where('url_key != ?', '');
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

    /**
     * Dynamic Forms section.
     *
     * **Conditional**: returns [] when the `panth_dynamic_form` table
     * is missing. Skips widget-only forms (form_type = 'widget') —
     * they don't have a standalone URL.
     *
     * @return array<int, array{title: string, url: string}>
     */
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
                    $title = trim((string) ($row['name'] ?? '')); // admin name fallback
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

    // ------------------------------------------------------------------
    //  Counts + meta helpers for header/summary UI
    // ------------------------------------------------------------------

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
            $store = $this->storeManager->getStore();
            $code  = (string) $store->getCurrentCurrencyCode();
            return match ($code) {
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'INR' => '₹',
                'JPY' => '¥',
                default => $code !== '' ? $code . ' ' : '',
            };
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @deprecated Use getCategories() instead.
     * @return array<int, array{id: int, name: string, url: string, level: int, children: array}>
     */
    public function getCategoryTree(): array
    {
        return $this->getCategories();
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

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

    /**
     * Return entity IDs that have a specific integer attribute value.
     *
     * @param  \Magento\Framework\DB\Adapter\AdapterInterface $conn
     * @param  string $table  The EAV int table name
     * @param  int    $attrId
     * @param  int    $storeId
     * @param  int[]  $entityIds
     * @param  int    $value
     * @return int[]
     */
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
            ->from($table, ['entity_id'])
            ->where('attribute_id = ?', $attrId)
            ->where('store_id IN (?)', [0, $storeId])
            ->where('entity_id IN (?)', $entityIds)
            ->where('value = ?', $value);

        return array_map('intval', $conn->fetchCol($select));
    }
}
