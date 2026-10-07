<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Block\Html;

use Magento\Framework\View\Element\Template\Context;
use Panth\HtmlSitemap\Block\Html\Sitemap;
use Panth\HtmlSitemap\ViewModel\HtmlSitemap;
use PHPUnit\Framework\TestCase;

class SitemapTest extends TestCase
{
    public function testExposesViewModelAndDelegatesIdentities(): void
    {
        $viewModel = $this->createMock(HtmlSitemap::class);
        $viewModel->expects($this->once())->method('getIdentities')->willReturn(['cat_c', 'cat_c_3']);

        $block = new Sitemap($this->createStub(Context::class), $viewModel);

        $this->assertSame($viewModel, $block->getHtmlSitemapViewModel());
        $this->assertSame(['cat_c', 'cat_c_3'], $block->getIdentities());
    }
}
