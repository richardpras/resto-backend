<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OutletSettingsCrudController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->domain->listOutlets()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'manager' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'invoicePrefix' => ['nullable', 'string', 'max:64'],
            'orderPrefix' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json([
            'message' => 'Outlet created successfully.',
            'data' => $this->domain->createOutlet($v),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $outletId): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'manager' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'invoicePrefix' => ['nullable', 'string', 'max:64'],
            'orderPrefix' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json([
            'message' => 'Outlet updated successfully.',
            'data' => $this->domain->updateOutlet($outletId, $v),
        ]);
    }

    public function destroy(string $outletId): JsonResponse
    {
        $this->domain->deleteOutlet($outletId);

        return response()->json([
            'message' => 'Outlet deleted successfully.',
        ]);
    }
}
