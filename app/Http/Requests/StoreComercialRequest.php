<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class StoreComercialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normaliza email antes de validar
        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'id_sociedad' => ['required', 'numeric', 'exists:sociedad,id'],
            'comercial_responsable_categoria' => ['nullable', 'boolean'],
            'usuario' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,dns', 'max:255', Rule::unique('comercial', 'email')],
            'responsable' => ['nullable', 'string', 'max:255'],
            'dni' => ['nullable', 'string', 'max:255'],
            'sexo' => ['nullable', 'string', 'max:10'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'fecha_alta' => ['nullable', 'date'],
            'referido' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'poblacion' => ['nullable', 'string', 'max:255'],
            'provincia' => ['nullable', 'string', 'max:255'],
            'cod_postal' => ['nullable', 'string', 'max:10'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'fax' => ['nullable', 'string', 'max:20'],
            'path_licencia_cazador' => ['nullable', 'string', 'max:255'],
            'path_dni' => ['nullable', 'string', 'max:255'],
            'path_justificante_iban' => ['nullable', 'string', 'max:255'],
            'path_otros' => ['nullable', 'string', 'max:255'],
            'path_foto' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif', 'max:4096'],
            'contraseña' => ['required', 'string', 'min:8'], // ideal renombrar a 'password'
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'   => 'Este correo ya está registrado.',
        ];
    }
}
