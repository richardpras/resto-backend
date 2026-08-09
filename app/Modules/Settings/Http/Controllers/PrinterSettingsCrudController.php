<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Print\Services\PrinterTestPrintService;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PrinterSettingsCrudController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
        private readonly PrinterTestPrintService $testPrintService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->domain->listPrinters()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'printerType' => ['required', 'string', 'in:kitchen,cashier,bar,dessert'],
            'connection' => ['required', 'string', 'in:bluetooth,lan,usb,shared'],
            'thermalPaperWidth' => ['nullable', 'string', 'in:58mm,80mm'],
            'autoCut' => ['sometimes', 'boolean'],
            'ip' => ['nullable', 'string', 'max:64'],
            'bluetoothDevice' => ['nullable', 'string', 'max:255'],
            'outletId' => ['required', 'integer', 'min:1', Rule::exists('outlets', 'id')],
            'assignedCategories' => ['nullable', 'array'],
            'assignedCategories.*' => ['string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Printer created successfully.',
            'data' => $this->domain->createPrinter($v),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $printerId): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'printerType' => ['required', 'string', 'in:kitchen,cashier,bar,dessert'],
            'connection' => ['required', 'string', 'in:bluetooth,lan,usb,shared'],
            'thermalPaperWidth' => ['nullable', 'string', 'in:58mm,80mm'],
            'autoCut' => ['sometimes', 'boolean'],
            'ip' => ['nullable', 'string', 'max:64'],
            'bluetoothDevice' => ['nullable', 'string', 'max:255'],
            'outletId' => ['required', 'integer', 'min:1', Rule::exists('outlets', 'id')],
            'assignedCategories' => ['nullable', 'array'],
            'assignedCategories.*' => ['string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Printer updated successfully.',
            'data' => $this->domain->updatePrinter($printerId, $v),
        ]);
    }

    public function destroy(string $printerId): JsonResponse
    {
        $this->domain->deletePrinter($printerId);

        return response()->json(['message' => 'Printer deleted successfully.']);
    }

    public function test(string $printerId): JsonResponse
    {
        try {
            $result = $this->testPrintService->dispatchForSettingPrinter($printerId);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Printer not found.'], Response::HTTP_NOT_FOUND);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Test print dispatched to hardware bridge.',
            'data' => $result,
        ]);
    }
}
