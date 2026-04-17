<?php

namespace SuperAICore\Models\Concerns;

use SuperAICore\Support\TablePrefix;

/**
 * Prepends the package table prefix to every model's resolved table name.
 *
 * Used by all 8 package models. Keeps their `$table` property as the short
 * semantic name (ai_providers), while the actual SQL identifier gets the
 * configured prefix prepended at resolve time.
 */
trait HasConfigurablePrefix
{
    public function getTable(): string
    {
        $base = $this->table ?? parent::getTable();
        $prefix = TablePrefix::value();
        if ($prefix !== '' && !str_starts_with($base, $prefix)) {
            return $prefix . $base;
        }
        return $base;
    }
}
