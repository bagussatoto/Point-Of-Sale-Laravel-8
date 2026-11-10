<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MemberRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'telepon' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
        ];
    }
}
