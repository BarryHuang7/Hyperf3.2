<?php

declare(strict_types=1);

namespace App\Validation\Admin;

use Hyperf\Validation\Request\FormRequest;

class MemberPointsListRequest extends FormRequest
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
            'email' => 'nullable|string|max:255',
            'member_id' => 'nullable|integer|min:1',
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
            'email.string' => '邮箱格式不正确',
            'email.max' => '邮箱长度不能超过255位',
            'member_id.integer' => '会员id必须是整数',
            'member_id.min' => '会员id不能小于1',
        ];
    }
}
