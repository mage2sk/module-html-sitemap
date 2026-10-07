<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

class ConfigurationFilesTest extends TestCase
{
    private function load(string $relative): \SimpleXMLElement
    {
        $path = dirname(__DIR__, 3) . "/" . $relative;
        $this->assertTrue(is_file($path), $relative . " is missing");
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) file_get_contents($path));
        libxml_clear_errors();
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, $relative . " is not valid XML");
        return $xml;
    }

    public function testCategoryFormExposesTheExclusionToggle(): void
    {
        $xml = $this->load("view/adminhtml/ui_component/category_form.xml");
        $fields = $xml->xpath("//fieldset[@name=\"search_engine_optimization\"]/field[@name=\"exclude_from_html_sitemap\"]");

        $this->assertCount(1, $fields);
        $field = $fields[0];
        $this->assertSame("checkbox", (string) $field["formElement"]);
        $this->assertSame("boolean", (string) $field->settings->dataType);
        $this->assertSame("[STORE VIEW]", (string) $field->settings->scopeLabel);
        $this->assertSame("toggle", (string) $field->formElements->checkbox->settings->prefer);
        $map = [];
        foreach ($field->formElements->checkbox->settings->valueMap->map as $entry) {
            $map[(string) $entry["name"]] = (string) $entry;
        }
        $this->assertSame(["false" => "0", "true" => "1"], $map);
    }

    public function testProductsPerPageIsValidatedAgainstTheSupportedRange(): void
    {
        $xml = $this->load("etc/adminhtml/system.xml");
        $field = $xml->xpath("//section[@id=\"panth_html_sitemap\"]//field[@id=\"products_per_page\"]");

        $this->assertCount(1, $field);
        $rules = explode(" ", trim((string) $field[0]->validate));
        $this->assertContains("validate-digits", $rules);
        $this->assertContains("validate-digits-range", $rules);
        $this->assertContains("digits-range-50-2000", $rules);
    }

    public function testEveryGeneralFieldHasADefault(): void
    {
        $system = $this->load("etc/adminhtml/system.xml");
        $config = $this->load("etc/config.xml");
        $defaults = $config->default->panth_html_sitemap->general;

        foreach ($system->xpath("//section[@id=\"panth_html_sitemap\"]/group[@id=\"general\"]/field") as $field) {
            $id = (string) $field["id"];
            $this->assertTrue(isset($defaults->{$id}), "no default for " . $id);
        }
    }
}
