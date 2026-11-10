<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PengeluaranRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'deskripsi' => ['required', 'string'],
            'nominal' => ['required', 'integer', 'min:0'],
        ];
    }
}
