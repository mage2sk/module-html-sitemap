<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the `exclude_from_html_sitemap` boolean attribute to catalog categories.
 *
 * Checked when rendering the HTML sitemap — categories with this flag set
 * are filtered out of the tree. The attribute is placed in the
 * "Search Engine Optimization" attribute group when it exists; otherwise
 * it falls back to the default attribute group for the set.
 */
class AddExcludeFromHtmlSitemapAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Category::ENTITY, 'exclude_from_html_sitemap')) {
            $eavSetup->addAttribute(
                Category::ENTITY,
                'exclude_from_html_sitemap',
                [
                    'type'         => 'int',
                    'label'        => 'Exclude from HTML Sitemap',
                    'input'        => 'boolean',
                    'source'       => Boolean::class,
                    'default'      => '0',
                    'required'     => false,
                    'visible'      => true,
                    'global'       => ScopedAttributeInterface::SCOPE_STORE,
                    'group'        => 'Search Engine Optimization',
                    'sort_order'   => 210,
                    'user_defined' => false,
                ]
            );
        }

        $this->addAttributeToAllSets($eavSetup, 'exclude_from_html_sitemap');

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Assign the attribute to every existing category attribute set, preferring
     * the "Search Engine Optimization" group when present.
     */
    private function addAttributeToAllSets(EavSetup $eavSetup, string $attributeCode): void
    {
        $entityTypeId  = $eavSetup->getEntityTypeId(Category::ENTITY);
        $attributeSets = $eavSetup->getAllAttributeSetIds($entityTypeId);

        foreach ($attributeSets as $attributeSetId) {
            try {
                $groupId = $eavSetup->getAttributeGroupId(
                    $entityTypeId,
                    $attributeSetId,
                    'Search Engine Optimization'
                );
            } catch (\Exception $e) {
                $groupId = $eavSetup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
            }
            $eavSetup->addAttributeToSet($entityTypeId, $attributeSetId, $groupId, $attributeCode);
        }
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
