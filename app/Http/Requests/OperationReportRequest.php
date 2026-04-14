<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OperationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'in:realtime,day,week,month,custom'],
            'date_type' => ['nullable', 'in:booking,arrival'],
            'scenic_spot_id' => ['nullable', 'integer', 'exists:scenic_spots,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
