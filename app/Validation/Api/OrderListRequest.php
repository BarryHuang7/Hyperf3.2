<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class OrderListRequest extends FormRequest
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
            'page' => 'required|integer|min:1',
            'page_size' => 'required|integer|min:1|max:50',
            'change_type' => 'integer|min:1|max:2',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'page.required' => '页码不能为空',
            'page.integer' => '页码必须是整数',
            'page.min' => '页码不能小于1',
            'page_size.required' => '每页数量不能为空',
            'page_size.integer' => '每页数量必须是整数',
            'page_size.min' => '每页数量不能小于1',
            'page_size.max' => '每页数量不能超过50',
            'change_type.integer' => '订单类型必须是整数',
            'change_type.min' => '订单类型不能小于1',
            'change_type.max' => '订单类型不能超过2',
        ];
    }
}
