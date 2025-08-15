<?php

namespace mindseekermedia\craftrelatedelements\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enableNestedElements = true;
    public int $initialLimit = 10;
    public bool $showElementTypeLabel = true;

    public function rules(): array
    {
        return [
            [['enableNestedElements', 'showElementTypeLabel'], 'boolean'],
            [['initialLimit'], 'integer', 'min' => 1, 'max' => 100],
            [['initialLimit'], 'default', 'value' => 10],
        ];
    }
}