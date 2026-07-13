<?php

namespace App\Modules\Imports\Support;

final class ImportColumnSpec
{
    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOL = 'bool';

    public const TYPE_ENUM = 'enum';

    public const TYPE_DATE = 'date';

    public const TYPE_RELATION = 'relation';

    /**
     * @param  list<string>  $enumValues
     */
    public function __construct(
        public readonly string $field,
        public readonly string $labelId,
        public readonly string $labelEn,
        public readonly bool $required = false,
        public readonly string $type = self::TYPE_TEXT,
        public readonly string $noteId = '',
        public readonly string $noteEn = '',
        public readonly array $enumValues = [],
        public readonly ?string $relation = null,
    ) {}

    public function bilingualHeader(): string
    {
        return "{$this->labelId} / {$this->labelEn} ({$this->field})";
    }

    public function bilingualNote(): string
    {
        if ($this->noteId === '' && $this->noteEn === '') {
            return $this->required ? 'Wajib / Required' : '';
        }

        return trim("{$this->noteId} / {$this->noteEn}");
    }

    /**
     * @return list<string>
     */
    public function aliasKeys(): array
    {
        $keys = [
            $this->field,
            strtolower($this->field),
            $this->normalizeAliasKey($this->labelId),
            $this->normalizeAliasKey($this->labelEn),
            $this->normalizeAliasKey($this->bilingualHeader()),
        ];

        return array_values(array_unique(array_filter($keys)));
    }

    private function normalizeAliasKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace([' ', '-'], '_', $value);

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }
}
