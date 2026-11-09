<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class ApplyRefundRequest extends FormRequest
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
            'order_no' => 'required|string|max:64',
            'reason_for_application' => 'required|string|max:255',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'order_no.required' => '订单编号不能为空',
            'order_no.string' => '订单编号格式不正确',
            'order_no.max' => '订单编号长度不能超过64位',
            'reason_for_application.required' => '申请原因不能为空',
            'reason_for_application.string' => '申请原因格式不正确',
            'reason_for_application.max' => '申请原因长度不能超过255位',
        ];
    }
}
