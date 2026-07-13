<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Support\ImportRelationListProvider;
use App\Modules\Imports\Support\ImportTemplateSchema;
use App\Modules\Imports\Support\MasterImportBundleBuilder;
use App\Modules\Imports\Support\XlsxTemplateBuilder;

final class MasterImportTemplateSupport
{
    public function __construct(
        private readonly MasterImportBundleBuilder $bundleBuilder,
        private readonly XlsxTemplateBuilder $xlsxBuilder,
        private readonly ImportRelationListProvider $relationListProvider,
    ) {}

    /**
     * @return array<string, array{headers: list<string>, examples: list<array<string, string>>}>
     */
    public function legacySheetDefinitions(string $phase): array
    {
        $sheets = $this->sheetsForPhase($phase);
        $map = ImportTemplateSchema::toLegacySheetMap($sheets);
        $legacy = [];
        foreach ($map as $filename => $definition) {
            $legacy[$filename] = [
                'headers' => $definition['headers'],
                'examples' => $definition['examples'],
            ];
        }

        return $legacy;
    }

    /**
     * @return list<\App\Modules\Imports\Support\ImportSheetDefinition>
     */
    public function sheetsForPhase(string $phase): array
    {
        return match ($phase) {
            'phase1' => ImportTemplateSchema::phase1(),
            'phase2' => ImportTemplateSchema::phase2(),
            'phase3' => ImportTemplateSchema::phase3(),
            'phase4' => ImportTemplateSchema::phase4(),
            default => [],
        };
    }

    public function buildZip(string $readme, string $phase, string $prefix): string
    {
        return $this->bundleBuilder->buildZip($readme, $this->sheetsForPhase($phase), $prefix);
    }

    public function buildXlsx(
        string $phaseTitle,
        string $instructionsId,
        string $instructionsEn,
        string $phase,
        int $outletId,
    ): string {
        $sheets = $this->sheetsForPhase($phase);
        $relationLists = $this->relationListProvider->collectRelationLists($outletId, $sheets);

        return $this->xlsxBuilder->build(
            $phaseTitle,
            $instructionsId,
            $instructionsEn,
            $sheets,
            $relationLists,
        );
    }
}
