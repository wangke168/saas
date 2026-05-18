<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'ota_platform_id' => ['nullable', 'integer', 'exists:ota_platforms,id'],
            'scenic_spot_id' => ['nullable', 'integer', 'exists:scenic_spots,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'order_no' => ['nullable', 'string', 'max:100'],
            'ota_order_no' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'check_in_date_start' => ['nullable', 'date', 'required_with:check_in_date_end'],
            'check_in_date_end' => ['nullable', 'date', 'required_with:check_in_date_start', 'after_or_equal:check_in_date_start'],
            'created_at_start' => ['nullable', 'date', 'required_with:created_at_end'],
            'created_at_end' => ['nullable', 'date', 'required_with:created_at_start', 'after_or_equal:created_at_start'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_in_date_start.required_with' => '请填写完整的入住日期范围',
            'check_in_date_end.required_with' => '请填写完整的入住日期范围',
            'created_at_start.required_with' => '请填写完整的预定日期范围',
            'created_at_end.required_with' => '请填写完整的预定日期范围',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasCheckIn = $this->filled('check_in_date_start') && $this->filled('check_in_date_end');
            $hasCreated = $this->filled('created_at_start') && $this->filled('created_at_end');

            if (! $hasCheckIn && ! $hasCreated) {
                $validator->errors()->add('date_range', '请至少选择入住日期范围或预定日期范围');

                return;
            }

            if ($hasCheckIn) {
                $this->assertMaxThreeMonths(
                    $validator,
                    'check_in_date_start',
                    (string) $this->input('check_in_date_start'),
                    (string) $this->input('check_in_date_end'),
                    '入住日期范围最长不能超过3个月'
                );
            }

            if ($hasCreated) {
                $this->assertMaxThreeMonths(
                    $validator,
                    'created_at_start',
                    (string) $this->input('created_at_start'),
                    (string) $this->input('created_at_end'),
                    '预定日期范围最长不能超过3个月'
                );
            }
        });
    }

    private function assertMaxThreeMonths($validator, string $field, string $start, string $end, string $message): void
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();

        if ($startDate->copy()->addMonths(3)->lt($endDate)) {
            $validator->errors()->add($field, $message);
        }
    }
}
