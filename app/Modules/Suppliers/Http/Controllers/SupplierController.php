<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Modules\Suppliers\Http\Requests\StoreSupplierRequest;
use App\Modules\Suppliers\Http\Requests\UpdateSupplierRequest;
use App\Modules\Suppliers\Http\Resources\SupplierResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        $suppliers = Supplier::query()->orderBy('name')->get();

        return response()->json([
            'data' => SupplierResource::collection($suppliers),
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::query()->create($request->validated());

        return response()->json([
            'message' => 'Supplier created successfully.',
            'data' => new SupplierResource($supplier),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->fill($request->validated());
        $supplier->save();

        return response()->json([
            'message' => 'Supplier updated successfully.',
            'data' => new SupplierResource($supplier->fresh()),
        ]);
    }

    public function updateStatus(Supplier $supplier): JsonResponse
    {
        $supplier->status = $supplier->status === 'active' ? 'inactive' : 'active';
        $supplier->save();

        return response()->json([
            'message' => 'Status updated.',
            'data' => new SupplierResource($supplier->fresh()),
        ]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully.',
        ]);
    }
}
