<?php

namespace App\Http\Requests;

use App\Enums\EquipmentCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEquipmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('equipment', 'name')],
            'category' => ['required', Rule::enum(EquipmentCategory::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'asset_tag' => ['nullable', 'string', 'max:255', Rule::unique('equipment', 'asset_tag')],
        ];
    }
}
