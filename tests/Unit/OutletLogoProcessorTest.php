<?php

namespace Tests\Unit;

use App\Modules\Settings\Services\OutletLogoProcessor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OutletLogoProcessorTest extends TestCase
{
    public function test_processes_display_and_thermal_bundle(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for outlet logo processing.');
        }

        $processor = app(OutletLogoProcessor::class);
        $file = UploadedFile::fake()->image('logo.png', 240, 240);

        $encoded = $processor->process($file);

        $this->assertLessThanOrEqual(OutletLogoProcessor::TARGET_BYTES, $encoded['display']['bytes']);
        $this->assertSame('webp', $encoded['display']['extension']);
        $this->assertArrayHasKey('58', $encoded['thermal']);
        $this->assertArrayHasKey('80', $encoded['thermal']);
        $this->assertNotEmpty($encoded['thermal']['58']['rasterBase64']);
        $this->assertNotEmpty($encoded['thermal']['80']['rasterBase64']);
        $this->assertLessThanOrEqual(OutletLogoProcessor::THERMAL_WIDTH_58, $encoded['thermal']['58']['width']);
        $this->assertLessThanOrEqual(OutletLogoProcessor::THERMAL_WIDTH_80, $encoded['thermal']['80']['width']);
        $this->assertGreaterThan(0, $encoded['thermal']['58']['width']);
        $this->assertGreaterThan(0, $encoded['thermal']['80']['width']);
    }
}
