<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Controller\Index;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Panth\HtmlSitemap\Controller\Index\Index;
use Panth\HtmlSitemap\Helper\Config;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    private function config(bool $enabled, ?string $title = null, ?string $description = null): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn($enabled);
        $scope->method('getValue')->willReturnCallback(static fn($path) => [
            Config::XML_META_TITLE => $title,
            Config::XML_META_DESCRIPTION => $description,
        ][$path] ?? null);
        return new Config($scope);
    }

    public function testDisabledModuleForwardsToNoRoute(): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects($this->once())->method('setModule')->with('cms')->willReturnSelf();
        $forward->expects($this->once())->method('setController')->with('noroute')->willReturnSelf();
        $forward->expects($this->once())->method('forward')->with('index')->willReturnSelf();

        $factory = $this->createMock(ResultFactory::class);
        $factory->expects($this->once())->method('create')
            ->with(ResultFactory::TYPE_FORWARD)
            ->willReturn($forward);

        $this->assertSame($forward, (new Index($factory, $this->config(false)))->execute());
    }

    private function page(string $expectedTitle, ?string $expectedDescription): Page
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('set')->with($expectedTitle);

        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        if ($expectedDescription === null) {
            $pageConfig->expects($this->never())->method('setDescription');
        } else {
            $pageConfig->expects($this->once())->method('setDescription')->with($expectedDescription);
        }

        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        return $page;
    }

    public function testEnabledPageUsesConfiguredMeta(): void
    {
        $page = $this->page('Our Site Map', 'Every page we have');
        $factory = $this->createMock(ResultFactory::class);
        $factory->expects($this->once())->method('create')->with(ResultFactory::TYPE_PAGE)->willReturn($page);

        $result = (new Index($factory, $this->config(true, '  Our Site Map ', ' Every page we have ')))->execute();

        $this->assertSame($page, $result);
    }

    public function testBlankTitleFallsBackAndBlankDescriptionIsSkipped(): void
    {
        $page = $this->page('Site Map', null);
        $factory = $this->createStub(ResultFactory::class);
        $factory->method('create')->willReturn($page);

        $this->assertSame($page, (new Index($factory, $this->config(true, '   ', "\n")))->execute());
    }
}
