<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Controller\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Panth\HtmlSitemap\Helper\Config;

/**
 * Custom router that maps the user-friendly "/sitemap" URL to the HTML
 * sitemap controller at htmlsitemap/index/index.
 *
 * Without this router the HTML sitemap is only accessible via the module
 * frontName (/htmlsitemap/), which is not intuitive for end users.
 */
class HtmlSitemapRouter implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $config
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $pathInfo = trim((string) $request->getPathInfo(), '/');

        if ($pathInfo !== 'sitemap') {
            return null;
        }

        if (!$this->config->isEnabled()) {
            return null;
        }

        $request->setModuleName('htmlsitemap');
        $request->setControllerName('index');
        $request->setActionName('index');
        $request->setAlias(
            \Magento\Framework\Url::REWRITE_REQUEST_PATH_ALIAS,
            'sitemap'
        );

        return $this->actionFactory->create(
            \Magento\Framework\App\Action\Forward::class
        );
    }
}
