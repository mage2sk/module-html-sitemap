<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\HtmlSitemap\Setup\Patch\Data\AddExcludeFromHtmlSitemapAttribute;
use PHPUnit\Framework\TestCase;

class AddExcludeFromHtmlSitemapAttributeTest extends TestCase
{
    private function patch(
        EavSetup $eavSetup,
        ?ModuleDataSetupInterface $setup = null
    ): AddExcludeFromHtmlSitemapAttribute {
        $setup = $setup ?? $this->createStub(ModuleDataSetupInterface::class);
        $factory = $this->createStub(EavSetupFactory::class);
        $factory->method('create')->willReturn($eavSetup);
        return new AddExcludeFromHtmlSitemapAttribute($setup, $factory);
    }

    public function testCreatesAttributeAndAssignsItToEverySet(): void
    {
        $eav = $this->createMock(EavSetup::class);
        $eav->method('getAttributeId')->willReturn(false);
        $eav->method('getEntityTypeId')->willReturn(3);
        $eav->method('getAllAttributeSetIds')->willReturn([3, 9]);
        $eav->method('getAttributeGroupId')->willReturnCallback(
            static fn($type, $set) => $set === 3 ? 30 : 90
        );
        $eav->expects($this->once())->method('addAttribute')->with(
            Category::ENTITY,
            'exclude_from_html_sitemap',
            $this->callback(static fn(array $def) => $def['input'] === 'boolean'
                && $def['default'] === '0'
                && $def['global'] === ScopedAttributeInterface::SCOPE_STORE
                && $def['group'] === 'Search Engine Optimization'
                && $def['required'] === false)
        );
        $assigned = [];
        $eav->expects($this->exactly(2))->method('addAttributeToSet')->willReturnCallback(
            static function ($type, $set, $group, $code) use (&$assigned) {
                $assigned[] = [$type, $set, $group, $code];
            }
        );

        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->expects($this->once())->method('startSetup');
        $setup->expects($this->once())->method('endSetup');

        $patch = $this->patch($eav, $setup);
        $this->assertSame($patch, $patch->apply());
        $this->assertSame([
            [3, 3, 30, 'exclude_from_html_sitemap'],
            [3, 9, 90, 'exclude_from_html_sitemap'],
        ], $assigned);
    }

    public function testExistingAttributeIsOnlyReassigned(): void
    {
        $eav = $this->createMock(EavSetup::class);
        $eav->method('getAttributeId')->willReturn(155);
        $eav->method('getEntityTypeId')->willReturn(3);
        $eav->method('getAllAttributeSetIds')->willReturn([3]);
        $eav->method('getAttributeGroupId')->willReturn(30);
        $eav->expects($this->never())->method('addAttribute');
        $eav->expects($this->once())->method('addAttributeToSet')->with(3, 3, 30, 'exclude_from_html_sitemap');

        $this->patch($eav)->apply();
    }

    public function testMissingSeoGroupFallsBackToDefaultGroup(): void
    {
        $eav = $this->createMock(EavSetup::class);
        $eav->method('getAttributeId')->willReturn(155);
        $eav->method('getEntityTypeId')->willReturn(3);
        $eav->method('getAllAttributeSetIds')->willReturn([3]);
        $eav->method('getAttributeGroupId')->willThrowException(new LocalizedException(__('no group')));
        $eav->expects($this->once())->method('getDefaultAttributeGroupId')->with(3, 3)->willReturn(7);
        $eav->expects($this->once())->method('addAttributeToSet')->with(3, 3, 7, 'exclude_from_html_sitemap');

        $this->patch($eav)->apply();
    }

    public function testHasNoDependenciesOrAliases(): void
    {
        $this->assertSame([], AddExcludeFromHtmlSitemapAttribute::getDependencies());
        $this->assertSame([], $this->patch($this->createStub(EavSetup::class))->getAliases());
    }
}
