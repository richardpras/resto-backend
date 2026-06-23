<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentMethodSettingsCrudController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->domain->listPaymentMethods()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:cash,digital'],
            'integration' => ['nullable', 'string', 'max:255'],
            'fee' => ['nullable', 'numeric'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'chartAccountId' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        return response()->json([
            'message' => 'Payment method created successfully.',
            'data' => $this->domain->createPaymentMethod($v),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $paymentMethodId): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:cash,digital'],
            'integration' => ['nullable', 'string', 'max:255'],
            'fee' => ['nullable', 'numeric'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'chartAccountId' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        return response()->json([
            'message' => 'Payment method updated successfully.',
            'data' => $this->domain->updatePaymentMethod($paymentMethodId, $v),
        ]);
    }

    public function destroy(string $paymentMethodId): JsonResponse
    {
        $this->domain->deletePaymentMethod($paymentMethodId);

        return response()->json(['message' => 'Payment method deleted successfully.']);
    }
}
