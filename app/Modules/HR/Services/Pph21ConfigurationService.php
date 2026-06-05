<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Pph21Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Pph21ConfigurationService
{
    /**
     * @return Collection<int, Pph21Config>
     */
    public function list(): Collection
    {
        return Pph21Config::query()
            ->with('brackets')
            ->orderByDesc('effective_date')
            ->get();
    }

    public function find(int $configId): Pph21Config
    {
        $config = Pph21Config::query()->with('brackets')->find($configId);
        abort_if($config === null, 404, 'PPh21 configuration not found.');

        return $config;
    }

    public function create(array $payload): Pph21Config
    {
        $this->assertValidPtkpStatus($payload);
        $brackets = $payload['brackets'] ?? $this->defaultBrackets();

        return DB::transaction(function () use ($payload, $brackets) {
            $config = Pph21Config::query()->create([
                'effective_date' => $payload['effectiveDate'],
                'ptkp_tk0' => (float) ($payload['ptkpTk0'] ?? 54000000),
                'ptkp_tk1' => (float) ($payload['ptkpTk1'] ?? 58500000),
                'ptkp_tk2' => (float) ($payload['ptkpTk2'] ?? 63000000),
                'ptkp_tk3' => (float) ($payload['ptkpTk3'] ?? 67500000),
                'ptkp_k0' => (float) ($payload['ptkpK0'] ?? 58500000),
                'ptkp_k1' => (float) ($payload['ptkpK1'] ?? 63000000),
                'ptkp_k2' => (float) ($payload['ptkpK2'] ?? 67500000),
                'ptkp_k3' => (float) ($payload['ptkpK3'] ?? 72000000),
                'is_active' => (bool) ($payload['isActive'] ?? true),
            ]);

            $this->syncBrackets($config, $brackets);

            return $config->refresh()->load('brackets');
        });
    }

    public function update(int $configId, array $payload): Pph21Config
    {
        $config = $this->find($configId);

        return DB::transaction(function () use ($config, $payload) {
            $data = [];
            $map = [
                'effectiveDate' => 'effective_date',
                'ptkpTk0' => 'ptkp_tk0',
                'ptkpTk1' => 'ptkp_tk1',
                'ptkpTk2' => 'ptkp_tk2',
                'ptkpTk3' => 'ptkp_tk3',
                'ptkpK0' => 'ptkp_k0',
                'ptkpK1' => 'ptkp_k1',
                'ptkpK2' => 'ptkp_k2',
                'ptkpK3' => 'ptkp_k3',
                'isActive' => 'is_active',
            ];

            foreach ($map as $key => $column) {
                if (array_key_exists($key, $payload)) {
                    $data[$column] = $payload[$key];
                }
            }

            if ($data !== []) {
                $config->update($data);
            }

            if (array_key_exists('brackets', $payload)) {
                $this->syncBrackets($config, $payload['brackets']);
            }

            return $config->refresh()->load('brackets');
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultBrackets(): array
    {
        return [
            ['incomeFrom' => 0, 'incomeTo' => 60000000, 'taxRate' => 5],
            ['incomeFrom' => 60000000, 'incomeTo' => 250000000, 'taxRate' => 15],
            ['incomeFrom' => 250000000, 'incomeTo' => 500000000, 'taxRate' => 25],
            ['incomeFrom' => 500000000, 'incomeTo' => 5000000000, 'taxRate' => 30],
            ['incomeFrom' => 5000000000, 'incomeTo' => null, 'taxRate' => 35],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $brackets
     */
    private function syncBrackets(Pph21Config $config, array $brackets): void
    {
        if ($brackets === []) {
            throw ValidationException::withMessages([
                'brackets' => ['At least one tax bracket is required.'],
            ]);
        }

        $config->brackets()->delete();

        foreach ($brackets as $bracket) {
            $config->brackets()->create([
                'income_from' => (float) ($bracket['incomeFrom'] ?? 0),
                'income_to' => array_key_exists('incomeTo', $bracket) && $bracket['incomeTo'] !== null
                    ? (float) $bracket['incomeTo']
                    : null,
                'tax_rate' => (float) ($bracket['taxRate'] ?? 0),
            ]);
        }
    }

    private function assertValidPtkpStatus(array $payload): void
    {
        unset($payload);
    }
}
