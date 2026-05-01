<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Config reader for the HTML Sitemap module. All 14 admin toggles live
 * under `panth_html_sitemap/general/*`.
 */
class Config
{
    public const XML_ENABLED                  = 'panth_html_sitemap/general/enabled';
    public const XML_SHOW_CATEGORIES          = 'panth_html_sitemap/general/show_categories';
    public const XML_MAX_CATEGORY_DEPTH       = 'panth_html_sitemap/general/max_category_depth';
    public const XML_SHOW_PRODUCTS            = 'panth_html_sitemap/general/show_products';
    public const XML_PRODUCT_SORT_ORDER       = 'panth_html_sitemap/general/product_sort_order';
    public const XML_PRODUCT_URL_STRUCTURE    = 'panth_html_sitemap/general/product_url_structure';
    public const XML_SHOW_CMS_PAGES           = 'panth_html_sitemap/general/show_cms_pages';
    public const XML_SHOW_STORES              = 'panth_html_sitemap/general/show_stores';
    public const XML_SHOW_CUSTOM_LINKS        = 'panth_html_sitemap/general/show_custom_links';
    public const XML_CUSTOM_LINKS             = 'panth_html_sitemap/general/custom_links';
    public const XML_META_TITLE               = 'panth_html_sitemap/general/meta_title';
    public const XML_META_DESCRIPTION         = 'panth_html_sitemap/general/meta_description';
    public const XML_EXCLUDE_CMS_PAGES        = 'panth_html_sitemap/general/exclude_cms_pages';
    public const XML_SHOW_SEARCH_FIELD        = 'panth_html_sitemap/general/show_search_field';
    public const XML_PRODUCTS_PER_PAGE        = 'panth_html_sitemap/general/products_per_page';
    /** Optional integrations — toggles for source modules that may or may not be installed. */
    public const XML_SHOW_TESTIMONIALS        = 'panth_html_sitemap/general/show_testimonials';
    public const XML_SHOW_FAQS                = 'panth_html_sitemap/general/show_faqs';
    public const XML_SHOW_DYNAMIC_FORMS       = 'panth_html_sitemap/general/show_dynamic_forms';

    /** Hard floor/ceiling on products_per_page to keep the page responsive. */
    public const PER_PAGE_MIN = 50;
    public const PER_PAGE_MAX = 2000;
    public const PER_PAGE_DEFAULT = 500;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ENABLED, $storeId);
    }

    public function isShowCategories(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_CATEGORIES, $storeId);
    }

    public function getMaxCategoryDepth(?int $storeId = null): int
    {
        return (int) $this->value(self::XML_MAX_CATEGORY_DEPTH, $storeId);
    }

    public function isShowProducts(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_PRODUCTS, $storeId);
    }

    public function getProductSortOrder(?int $storeId = null): string
    {
        return (string) $this->value(self::XML_PRODUCT_SORT_ORDER, $storeId);
    }

    public function getProductUrlStructure(?int $storeId = null): string
    {
        return (string) $this->value(self::XML_PRODUCT_URL_STRUCTURE, $storeId);
    }

    public function isShowCmsPages(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_CMS_PAGES, $storeId);
    }

    public function isShowStores(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_STORES, $storeId);
    }

    public function isShowCustomLinks(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_CUSTOM_LINKS, $storeId);
    }

    public function getCustomLinks(?int $storeId = null): string
    {
        return (string) $this->value(self::XML_CUSTOM_LINKS, $storeId);
    }

    public function getMetaTitle(?int $storeId = null): string
    {
        return (string) $this->value(self::XML_META_TITLE, $storeId);
    }

    public function getMetaDescription(?int $storeId = null): string
    {
        return (string) $this->value(self::XML_META_DESCRIPTION, $storeId);
    }

    /**
     * @return array<int, string>
     */
    public function getExcludeCmsPages(?int $storeId = null): array
    {
        $raw = (string) $this->value(self::XML_EXCLUDE_CMS_PAGES, $storeId);
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== ''));
    }

    public function isShowSearchField(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_SEARCH_FIELD, $storeId);
    }

    public function isShowTestimonials(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_TESTIMONIALS, $storeId);
    }

    public function isShowFaqs(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_FAQS, $storeId);
    }

    public function isShowDynamicForms(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SHOW_DYNAMIC_FORMS, $storeId);
    }

    /**
     * Products per page in the HTML sitemap. Clamped to [PER_PAGE_MIN, PER_PAGE_MAX]
     * so an admin can't accidentally set a huge value that OOMs the render.
     */
    public function getProductsPerPage(?int $storeId = null): int
    {
        $raw = (int) $this->value(self::XML_PRODUCTS_PER_PAGE, $storeId);
        if ($raw <= 0) {
            $raw = self::PER_PAGE_DEFAULT;
        }
        return max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $raw));
    }

    /**
     * Passthrough for arbitrary config paths (used by ViewModel to resolve
     * `web/default/cms_home_page` when filtering the CMS page list).
     */
    public function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->value($path, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
