<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreQosTestRequest extends FormRequest
{
    public const RELAYS = ['direct' => 0, 'one_relay' => 1, 'two_relays' => 2];

    public function authorize(): bool
    {
        return true; // akses dijaga middleware AdminAuth di route
    }

    public function rules(): array
    {
        return [
            'name'                  => 'required|string|max:150',
            'description'           => 'nullable|string',
            'topology_type'         => 'required|in:direct,one_relay,two_relays',
            'nodes'                 => 'required|array|min:2|max:4',
            'nodes.*'               => 'required|string|max:30|distinct',
            'radio_config'          => 'nullable|array',
            'radio_config.sf'       => 'nullable|integer|between:6,12',
            'radio_config.bw'       => 'nullable|numeric',
            'radio_config.cr'       => 'nullable|integer|between:5,8',
            'radio_config.preamble' => 'nullable|integer|min:0',
            'notes'                 => 'nullable|string',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $type  = $this->input('topology_type');
            $nodes = $this->input('nodes', []);

            if (isset(self::RELAYS[$type]) && count($nodes) !== self::RELAYS[$type] + 2) {
                $v->errors()->add('nodes', 'Jumlah node tidak sesuai dengan topologi yang dipilih.');
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors'  => $validator->errors(),
        ], 422));
    }
}