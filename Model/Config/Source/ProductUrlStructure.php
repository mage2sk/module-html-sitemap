<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProductUrlStructure implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'short',           'label' => __('Short (product URL key only)')],
            ['value' => 'with_categories', 'label' => __('With Categories (category/product)')],
        ];
    }
}
