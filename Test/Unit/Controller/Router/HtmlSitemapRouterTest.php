<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Controller\Router;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Url;
use Panth\HtmlSitemap\Controller\Router\HtmlSitemapRouter;
use Panth\HtmlSitemap\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSitemapRouterTest extends TestCase
{
    private function config(bool $enabled): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn($enabled);
        return new Config($scope);
    }

    public static function otherPathProvider(): array
    {
        return [
            'home'          => [''],
            'xml sitemap'   => ['/sitemap.xml'],
            'nested'        => ['/sitemap/page'],
            'prefixed'      => ['/en/sitemap'],
            'case differs'  => ['/Sitemap'],
        ];
    }

    #[DataProvider('otherPathProvider')]
    public function testOtherPathsAreIgnored(string $path): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->expects($this->never())->method('setModuleName');

        $factory = $this->createMock(ActionFactory::class);
        $factory->expects($this->never())->method('create');

        $this->assertNull((new HtmlSitemapRouter($factory, $this->config(true)))->match($request));
    }

    public function testDisabledModuleDoesNotMatch(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn('/sitemap');
        $request->expects($this->never())->method('setPathInfo');

        $factory = $this->createMock(ActionFactory::class);
        $factory->expects($this->never())->method('create');

        $this->assertNull((new HtmlSitemapRouter($factory, $this->config(false)))->match($request));
    }

    #[DataProvider('sitemapPathProvider')]
    public function testSitemapPathIsRewrittenToTheController(string $path): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->expects($this->once())->method('setModuleName')->with('htmlsitemap')->willReturnSelf();
        $request->expects($this->once())->method('setControllerName')->with('index')->willReturnSelf();
        $request->expects($this->once())->method('setActionName')->with('index')->willReturnSelf();
        $request->expects($this->once())->method('setAlias')
            ->with(Url::REWRITE_REQUEST_PATH_ALIAS, 'sitemap')->willReturnSelf();
        $request->expects($this->once())->method('setPathInfo')->with('/htmlsitemap/index/index')->willReturnSelf();

        $action = $this->createStub(Forward::class);
        $factory = $this->createMock(ActionFactory::class);
        $factory->expects($this->once())->method('create')->with(Forward::class)->willReturn($action);

        $this->assertSame($action, (new HtmlSitemapRouter($factory, $this->config(true)))->match($request));
    }

    public static function sitemapPathProvider(): array
    {
        return [['/sitemap'], ['/sitemap/'], ['sitemap']];
    }
}
