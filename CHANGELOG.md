# Changelog

All notable changes to this extension are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.3]

### Added
- **Full Preview gallery** in README — 7 screenshots organised into three
  sections: Admin Configuration, Frontend — Hyvä theme, Frontend — Luma
  theme. Each theme section is a 3-column table showing the hero + tree,
  product grid, and CMS-pages block side by side, demonstrating that the
  theme-agnostic template produces identical output on both stores.
- All screenshots resized to 1400px max width (via `sips`) and polished
  with a 1px `#e5e7eb` border (via ImageMagick) so they don't blend into
  the white GitHub page background. Metadata stripped for privacy.

## [1.0.2]

### Added
- `docs/admin-configuration.png` screenshot + Preview section in README
  showing all 15 admin toggles at *Stores → Configuration → Panth
  Infotech → HTML Sitemap*.

## [1.0.1]

### Fixed
- ACL XML duplicate resource-id error that prevented the admin config
  page from loading (`Panth_X::config` was declared under both
  `Panth_Core::panth_extensions` and `Magento_Config::config`). The
  redundant declaration under `Panth_Core::panth_extensions` has been
  removed; the menu link continues to gate on the real system-config
  resource.

## [1.0.0] — Initial release

### Added

- **Customer-facing HTML sitemap** at `/sitemap` (via a custom router)
  rendering categories as a nested tree, products in a grid, CMS pages,
  optional store switcher, and optional admin-configured custom links.
- **Pagination for large catalogs.** The product list is paginated via
  `?p=N` — default 500 products per page, admin-configurable between 50
  and 2,000. Stores with 100k+ products render instantly because the
  underlying SQL uses `LIMIT/OFFSET` on indexed EAV joins and the total
  count is a single `COUNT(DISTINCT)` query (memoised per request). An
  absolute hard cap of 2,000 pages prevents runaway URLs.
- **Admin config** under *Panth Infotech → HTML Sitemap* with 15 toggles:
  master enabled, show categories / max depth, show products / sort order
  / URL structure (short vs with-categories) / per-page, show CMS pages
  / exclude list, show stores, show custom links / link list, meta
  title / description, show client-side search field.
- **`exclude_from_html_sitemap` category attribute** created via data
  patch so store admins can hide individual categories from the tree
  without touching configuration.
- **Direct admin menu link** under *Panth Infotech* that jumps straight
  to the module configuration section (matches the menu pattern used by
  every other Panth module).

### Notes

- Extracted from `Panth_AdvancedSEO` 1.0.x. All files under the new
  `Panth\HtmlSitemap` namespace. Config paths moved from
  `panth_seo/html_sitemap/*` to `panth_html_sitemap/general/*`.
- Theme-agnostic: the rendering template is plain PHP + vanilla JS +
  scoped CSS, identical output on Hyva and Luma.
