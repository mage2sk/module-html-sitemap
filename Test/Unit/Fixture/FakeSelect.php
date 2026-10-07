<?php
declare(strict_types=1);

namespace Panth\HtmlSitemap\Test\Unit\Fixture;

use Magento\Framework\DB\Select;

/**
 * Select double that records the query parts instead of rendering SQL.
 */
class FakeSelect extends Select
{
    /** @var string */
    public string $table = '';

    /** @var array */
    public array $columnsSpec = [];

    /** @var array */
    public array $wheres = [];

    /** @var array */
    public array $joins = [];

    /** @var array */
    public array $orders = [];

    /** @var array */
    public array $groups = [];

    /** @var array|null */
    public ?array $limitArgs = null;

    /**
     * Intentionally does not call the parent constructor: no adapter is needed.
     */
    public function __construct()
    {
    }

    /**
     * @inheritdoc
     */
    public function from($name, $cols = '*', $schema = null)
    {
        $this->table = is_array($name) ? (string) reset($name) : (string) $name;
        $this->columnsSpec = is_array($cols) ? $cols : [$cols];
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function where($cond, $value = null, $type = null)
    {
        $this->wheres[] = [$cond, $value];
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function join($name, $cond, $cols = self::SQL_WILDCARD, $schema = null)
    {
        $this->joins[] = [$name, $cond];
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function joinLeft($name, $cond, $cols = self::SQL_WILDCARD, $schema = null)
    {
        $this->joins[] = [$name, $cond];
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function columns($cols = '*', $correlationName = null)
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function order($spec)
    {
        $this->orders[] = (string) $spec;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function group($spec)
    {
        $this->groups[] = $spec;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function limit($count = null, $offset = null)
    {
        $this->limitArgs = [$count, $offset];
        return $this;
    }

    /**
     * Value bound to the first where() whose condition starts with the prefix.
     *
     * @param string $prefix
     * @return mixed
     */
    public function whereValue(string $prefix)
    {
        foreach ($this->wheres as [$cond, $value]) {
            if (str_starts_with((string) $cond, $prefix)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Whether any where() condition contains the fragment.
     *
     * @param string $fragment
     * @return bool
     */
    public function hasWhere(string $fragment): bool
    {
        foreach ($this->wheres as [$cond]) {
            if (str_contains((string) $cond, $fragment)) {
                return true;
            }
        }
        return false;
    }
}
