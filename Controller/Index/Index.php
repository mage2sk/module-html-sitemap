<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Panth\HtmlSitemap\Helper\Config;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly ResultFactory $resultFactory,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);
            $forward->setModule('cms');
            $forward->setController('noroute');
            $forward->forward('index');
            return $forward;
        }

        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);

        $metaTitle = trim($this->config->getMetaTitle());
        if ($metaTitle === '') {
            $metaTitle = (string) __('Site Map');
        }
        $result->getConfig()->getTitle()->set($metaTitle);

        $metaDescription = trim($this->config->getMetaDescription());
        if ($metaDescription !== '') {
            $result->getConfig()->setDescription($metaDescription);
        }

        return $result;
    }
}
