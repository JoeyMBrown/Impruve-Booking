<?php

namespace App\Http\Requests;

use App\Enums\EquipmentCategory;
use App\Models\Equipment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Equipment $equipment */
        $equipment = $this->route('equipment');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('equipment', 'name')->ignore($equipment)],
            'category' => ['required', Rule::enum(EquipmentCategory::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'asset_tag' => ['nullable', 'string', 'max:255', Rule::unique('equipment', 'asset_tag')->ignore($equipment)],
        ];
    }
}
