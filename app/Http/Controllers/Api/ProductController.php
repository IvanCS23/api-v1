<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;

class ProductController extends Controller
{

    /**
     * Obtener todos los productos
     */
    public function index()
    {
        $products = Product::all();

        return response()->json($products);
    }

    /**
     * Crear producto
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'no_identificacion' => 'nullable|string|max:255|unique:products,no_identificacion',
            'descripcion' => 'required|string|max:255',
            'precio_unitario' => 'required|numeric|min:0',
            'cuenta_predial' => 'nullable|string|max:255',
            'clave_producto' => 'required|string|size:8|unique:products,clave_producto',
            'clave_unidad' => 'nullable|string|max:10',
            'objeto_imp' => 'nullable|string|max:10',
            'no_pedimento' => 'nullable|string|max:255',
            'impuesto_local' => 'nullable|string|max:255',
            'iva' => 'nullable|numeric|min:0',
            'iva_retenido' => 'nullable|numeric|min:0',
            'ieps' => 'nullable|numeric|min:0',
            'isr' => 'nullable|numeric|min:0',
        ]);

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    /**
     * Mostrar producto específico
     */
    public function show(string $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'message' => 'Producto no encontrado'
            ], 404);
        }

        return response()->json($product);
    }

    /**
     * Actualizar producto
     */
    public function update(Request $request, string $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'message' => 'Producto no encontrado'
            ], 404);
        }

        $validated = $request->validate([
    'name' => 'sometimes|string|max:255',

    'no_identificacion' => 'nullable|string|max:255|unique:products,no_identificacion,' . $product->id,

    'descripcion' => 'sometimes|string|max:255',

    'precio_unitario' => 'sometimes|numeric|min:0',

    'cuenta_predial' => 'nullable|string|max:255',

    'clave_producto' => 'sometimes|string|size:8|unique:products,clave_producto,' . $product->id,

    'clave_unidad' => 'nullable|string|max:10',
    'objeto_imp' => 'nullable|string|max:10',
    'no_pedimento' => 'nullable|string|max:255',
    'impuesto_local' => 'nullable|string|max:255',

    'iva' => 'nullable|numeric|min:0',
    'iva_retenido' => 'nullable|numeric|min:0',
    'ieps' => 'nullable|numeric|min:0',
    'isr' => 'nullable|numeric|min:0',
]);

        $product->update($validated);

        return response()->json($product);
    }

    /**
     * Eliminar producto
     */
    public function destroy(string $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'message' => 'Producto no encontrado'
            ], 404);
        }

        $product->delete();

        return response()->json([
            'message' => 'Producto eliminado correctamente'
        ]);
    }
}