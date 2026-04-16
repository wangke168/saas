<?php

namespace App\Http\Requests\Channel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomSwitchSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $provider = (string) $this->route('provider', '');
        $source = (string) config("channel_sync.providers.{$provider}.source", '');

        return [
            'request_id' => ['required', 'string', 'max:64'],
            'sync_time' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'source' => ['required', 'string', Rule::in([$source])],
            'data' => ['required', 'array', 'min:1'],
            'data.*.hotel_name' => ['required', 'string', 'max:100'],
            'data.*.poi_id' => ['nullable', 'string', 'max:64'],
            'data.*.room_types' => ['required', 'array', 'min:1'],
            'data.*.room_types.*.room_type_name' => ['required', 'string', 'max:100'],
            'data.*.room_types.*.availability' => ['required', 'array', 'min:1'],
            'data.*.room_types.*.availability.*.date' => ['required', 'date_format:Y-m-d'],
            'data.*.room_types.*.availability.*.status' => ['required', 'integer', Rule::in([0, 1])],
        ];
    }

    public function messages(): array
    {
        return [
            'source.in' => 'source 与 provider 配置不匹配',
            'data.*.room_types.*.availability.*.status.in' => 'status 仅支持 0 或 1',
        ];
    }
}
