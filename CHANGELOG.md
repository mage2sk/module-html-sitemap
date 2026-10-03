# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.17] - 2026-10-03

### Fixed
- The product count used for pagination now applies the same rules as the product list (a store name and a URL rewrite in the current store are required), so pages are no longer short and the last pages are no longer empty.
- FAQ categories are filtered by store: only categories assigned to the current store or to All Store Views are listed.
