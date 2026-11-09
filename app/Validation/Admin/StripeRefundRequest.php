<?php

declare(strict_types=1);

namespace App\Validation\Admin;

use Hyperf\Validation\Request\FormRequest;

class StripeRefundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'refund_id' => 'required|integer|min:1',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'refund_id.required' => '退款记录id不能为空',
            'refund_id.integer' => '退款记录id必须是整数',
            'refund_id.min' => '退款记录id不能小于1',
        ];
    }
}
