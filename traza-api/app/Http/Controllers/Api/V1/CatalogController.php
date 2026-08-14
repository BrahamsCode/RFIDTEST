<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SerialReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Catálogo de productos y variantes. Tarea 4.6. */
final class CatalogController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly SerialReservationService $serials,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with('variants')
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->when(
                $request->has('active'),
                fn ($q) => $q->where('is_active', $request->boolean('active')),
            )
            ->orderBy('code');

        return response()->json($this->paginated($query, $request, fn (Product $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'brand' => $p->brand,
            'composition' => $p->composition,
            // 1 fácil (algodón), 5 difícil (metálico). Es el dato que explica
            // por qué una prenda concreta no se lee.
            'rfid_difficulty' => $p->rfid_difficulty,
            'is_active' => $p->is_active,
            'variants' => $p->variants->map(fn (ProductVariant $v) => $this->variantPayload($v))->all(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('tag.commission')) {
            return Problem::forbidden('No tienes permiso para gestionar el catálogo.');
        }

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:48',
                Rule::unique('products', 'code')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'brand' => ['nullable', 'string', 'max:80'],
            'composition' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'season_id' => ['nullable', 'integer', 'exists:seasons,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'rfid_difficulty' => ['nullable', 'integer', 'between:1,5'],
        ]);

        $product = Product::create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'is_active' => true,
        ]);

        return response()->json(['id' => $product->id, 'code' => $product->code], 201);
    }

    public function storeVariant(Request $request, Product $product): JsonResponse
    {
        if (! $request->user()->hasPermission('tag.commission')) {
            return Problem::forbidden('No tienes permiso para gestionar el catálogo.');
        }

        if ($product->organization_id !== $request->user()->organization_id) {
            return Problem::forbidden('Ese producto no es de tu organización.');
        }

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', Rule::unique('product_variants', 'sku')],
            'size' => ['nullable', 'string', 'max:24'],
            'color' => ['nullable', 'string', 'max:48'],
            'color_hex' => ['nullable', 'string', 'size:7'],
            'barcode' => ['nullable', 'string', 'max:20'],
            'gtin13' => ['nullable', 'string', 'max:14', Rule::unique('product_variants', 'gtin13')],
            // La referencia entra en el EPC y no puede cambiarse después: un
            // cambio invalidaría la decodificación de todo lo ya tarado.
            'item_reference' => ['required', 'string', 'max:8', 'regex:/^[0-9]+$/'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $variant = ProductVariant::create([
            ...$data,
            'product_id' => $product->id,
            'currency' => 'PEN',
            'is_active' => true,
        ]);

        return response()->json($this->variantPayload($variant), 201);
    }

    public function updateVariant(Request $request, ProductVariant $variant): JsonResponse
    {
        if (! $request->user()->hasPermission('tag.commission')) {
            return Problem::forbidden('No tienes permiso para gestionar el catálogo.');
        }

        $data = $request->validate([
            'size' => ['sometimes', 'nullable', 'string', 'max:24'],
            'color' => ['sometimes', 'nullable', 'string', 'max:48'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_stock' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /*
         * `item_reference` y `sku` no están en la lista y no es un olvido.
         * La referencia va dentro del EPC de cada etiqueta ya impresa;
         * cambiarla haría que el sistema decodificara esas prendas como otra
         * variante distinta, en silencio y sin forma de detectarlo.
         */
        $variant->update($data);

        return response()->json($this->variantPayload($variant->refresh()));
    }

    /** @return array<string, mixed> */
    private function variantPayload(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'size' => $variant->size,
            'color' => $variant->color,
            'color_hex' => $variant->color_hex,
            'barcode' => $variant->barcode,
            'item_reference' => $variant->item_reference,
            'cost_price' => $variant->cost_price,
            'sale_price' => $variant->sale_price,
            'min_stock' => $variant->min_stock,
            'is_active' => $variant->is_active,
            // Cuántas etiquetas más admite antes de agotar su espacio de
            // seriales. Con SGTIN-96 son 2^38, así que en la práctica nunca;
            // con GID-96 el margen es menor y conviene verlo.
            'serials_remaining' => $this->serials->remaining($variant),
        ];
    }
}
