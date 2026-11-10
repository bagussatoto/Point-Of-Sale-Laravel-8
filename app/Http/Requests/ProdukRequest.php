<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProdukRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $produkId = $this->route('produk');

        return [
            'id_kategori' => ['required', 'integer', 'exists:kategori,id_kategori'],
            'nama_produk' => [
                'required',
                'string',
                'max:255',
                Rule::unique('produk', 'nama_produk')->ignore($produkId, 'id_produk'),
            ],
            'merk' => ['nullable', 'string', 'max:255'],
            'harga_beli' => ['required', 'integer', 'min:0'],
            'harga_jual' => ['required', 'integer', 'min:0'],
            'diskon' => ['nullable', 'integer', 'min:0', 'max:100'],
            'stok' => ['required', 'integer', 'min:0'],
        ];
    }
}
