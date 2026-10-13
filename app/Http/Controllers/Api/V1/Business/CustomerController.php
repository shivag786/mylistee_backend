<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\V1\Business\Concerns\ResolvesBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Business\SaveShopCustomerRequest;
use App\Http\Resources\ShopCustomerResource;
use App\Models\ShopCustomer;
use App\Services\ShopCustomerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's customer book: walk-in customers kept by mobile number, with
 * optional name, email and city. Each shop sees and edits only its own rows.
 */
class CustomerController extends Controller
{
    use ResolvesBusiness;

    public function __construct(private readonly ShopCustomerService $customers) {}

    /** GET /business/customers?search=&page= — newest first. */
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $search = $request->string('search')->trim()->value();

        $page = $business->shopCustomers()
            ->when($search !== '', function ($q) use ($search): void {
                $digits = preg_replace('/\D+/', '', $search) ?? '';
                $q->where(function ($q) use ($search, $digits): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                    if ($digits !== '') {
                        $q->orWhere('phone', 'like', "%{$digits}%");
                    }
                });
            })
            ->withCount('reviews')
            ->latest('id')
            ->paginate(min(max((int) $request->integer('perPage', 20), 1), 100));

        return ApiResponse::success(
            ShopCustomerResource::collection($page->getCollection()),
            'Customers retrieved.',
            meta: [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /** POST /business/customers */
    public function store(SaveShopCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($this->business($request), $request->validated());

        return ApiResponse::success(new ShopCustomerResource($customer), 'Customer added.', status: 201);
    }

    /** PUT /business/customers/{uuid} */
    public function update(SaveShopCustomerRequest $request, string $uuid): JsonResponse
    {
        $customer = $this->find($request, $uuid);
        if ($customer === null) {
            return ApiResponse::error('Customer not found.', status: 404);
        }

        $customer = $this->customers->update($customer, $request->validated());
        $customer->loadCount('reviews');

        return ApiResponse::success(new ShopCustomerResource($customer), 'Customer updated.');
    }

    /** DELETE /business/customers/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $customer = $this->find($request, $uuid);
        if ($customer === null) {
            return ApiResponse::error('Customer not found.', status: 404);
        }

        $this->customers->delete($customer);

        return ApiResponse::success(message: 'Customer removed.');
    }

    private function find(Request $request, string $uuid): ?ShopCustomer
    {
        return $this->business($request)->shopCustomers()->where('uuid', $uuid)->first();
    }
}
