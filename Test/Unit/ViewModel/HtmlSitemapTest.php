<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\ViewModel;

use Panth\HtmlSitemap\ViewModel\HtmlSitemap;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HtmlSitemapTest extends TestCase
{
    public function testProductPageUrls(): void
    {
        $viewModel = $this->getMockBuilder(HtmlSitemap::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPaginationBaseUrl'])
            ->getMock();
        $viewModel->method('getPaginationBaseUrl')->willReturn('https://example.test/sitemap');

        $this->assertSame('https://example.test/sitemap', $viewModel->getProductPageUrl(1));
        $this->assertSame('https://example.test/sitemap', $viewModel->getProductPageUrl(0));
        $this->assertSame('https://example.test/sitemap?p=3', $viewModel->getProductPageUrl(3));
    }

    public function testPaginationWindow(): void
    {
        $viewModel = $this->getMockBuilder(HtmlSitemap::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentPage', 'getTotalProductPages', 'getProductsPerPage', 'getTotalProductCount', 'getPaginationBaseUrl'])
            ->getMock();
        $viewModel->method('getCurrentPage')->willReturn(5);
        $viewModel->method('getTotalProductPages')->willReturn(9);
        $viewModel->method('getProductsPerPage')->willReturn(50);
        $viewModel->method('getTotalProductCount')->willReturn(420);
        $viewModel->method('getPaginationBaseUrl')->willReturn('https://example.test/sitemap');

        $pagination = $viewModel->getProductPagination();
        $this->assertSame([3, 4, 5, 6, 7], $pagination['window']);
        $this->assertSame(9, $pagination['total']);
    }
}
