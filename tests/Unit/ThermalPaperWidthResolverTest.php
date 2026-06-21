<?php

namespace Tests\Unit;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Modules\Print\Services\ThermalPaperWidthResolver;
use Tests\TestCase;

class ThermalPaperWidthResolverTest extends TestCase
{
    private ThermalPaperWidthResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(ThermalPaperWidthResolver::class);
    }

    public function test_resolves_58mm_from_profile_meta(): void
    {
        $profile = new PrinterProfile([
            'meta' => [
                'print' => [
                    'thermalPaperWidth' => '58mm',
                    'thermalWidthChars' => 32,
                ],
            ],
        ]);

        $this->assertSame(32, $this->resolver->resolveWidthChars($profile));
        $this->assertSame('58mm', $this->resolver->resolvePaperWidth($profile));
    }

    public function test_resolves_80mm_from_profile_meta(): void
    {
        $profile = new PrinterProfile([
            'meta' => [
                'print' => [
                    'thermalPaperWidth' => '80mm',
                    'thermalWidthChars' => 42,
                ],
            ],
        ]);

        $this->assertSame(42, $this->resolver->resolveWidthChars($profile));
        $this->assertSame('80mm', $this->resolver->resolvePaperWidth($profile));
    }

    public function test_missing_meta_defaults_to_58mm(): void
    {
        $this->assertSame(32, $this->resolver->resolveWidthChars(null));
        $this->assertSame('58mm', $this->resolver->resolvePaperWidth(null));
        $this->assertSame(32, $this->resolver->resolveWidthChars(new PrinterProfile(['meta' => []])));
    }

    public function test_meta_for_paper_width_maps_correctly(): void
    {
        $this->assertSame(
            ['thermalPaperWidth' => '58mm', 'thermalWidthChars' => 32],
            $this->resolver->metaForPaperWidth('58mm'),
        );
        $this->assertSame(
            ['thermalPaperWidth' => '80mm', 'thermalWidthChars' => 42],
            $this->resolver->metaForPaperWidth('80mm'),
        );
    }

    public function test_resolve_from_setting_printer(): void
    {
        $setting = new SettingPrinter(['thermal_paper_width' => '80mm']);
        $this->assertSame(42, $this->resolver->resolveFromSettingPrinter($setting));
    }
}
