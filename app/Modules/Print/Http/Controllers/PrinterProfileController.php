<?php

namespace App\Modules\Print\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Print\Http\Requests\StorePrinterProfileRequest;
use App\Modules\Print\Http\Requests\UpdatePrinterProfileRequest;
use App\Modules\Print\Http\Resources\PrinterProfileResource;
use App\Modules\Print\Services\PrinterManagementService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PrinterProfileController extends Controller
{
    public function __construct(
        private readonly PrinterManagementService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PrinterProfileResource::collection($this->service->listProfiles()),
        ]);
    }

    public function store(StorePrinterProfileRequest $request): JsonResponse
    {
        $profile = $this->service->createProfile($request->validated());

        return response()->json([
            'message' => 'Printer profile created successfully.',
            'data' => new PrinterProfileResource($profile),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePrinterProfileRequest $request, int $profile): JsonResponse
    {
        $updated = $this->service->updateProfile($profile, $request->validated());

        return response()->json([
            'message' => 'Printer profile updated successfully.',
            'data' => new PrinterProfileResource($updated),
        ]);
    }

    public function destroy(int $profile): JsonResponse
    {
        $this->service->deleteProfile($profile);

        return response()->json(['message' => 'Printer profile deleted successfully.']);
    }
}
