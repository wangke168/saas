<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class DuplicateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'software_provider_id' => 'required|exists:software_providers,id',
            'name' => 'required|string|max:255',
            'external_code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'price_source' => 'sometimes|in:manual,api',
            'stay_days' => 'required|integer|min:1|max:30',
            'sale_start_date' => 'required|date',
            'sale_end_date' => 'required|date|after_or_equal:sale_start_date',
            'order_mode' => 'nullable|in:auto,manual,other',
            'order_provider_id' => 'nullable|exists:software_providers,id',
            'is_active' => 'sometimes|boolean',
            'is_realname' => 'sometimes|nullable|boolean',
            'unavailable_periods' => 'sometimes|nullable|array',
            'unavailable_periods.*' => 'array',
            'unavailable_periods.*.start_date' => 'required|date',
            'unavailable_periods.*.end_date' => 'required|date',
            'unavailable_periods.*.note' => 'nullable|string|max:500',
        ];
    }
}
