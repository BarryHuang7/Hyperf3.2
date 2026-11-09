<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class RegisterRequest extends FormRequest
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
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:6|max:64',
            'member_name' => 'required|string|max:65',
            'verification_code' => 'required|int|digits:6',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'password.required' => '密码不能为空',
            'password.min' => '密码长度不能少于6位',
            'member_name.required' => '用户名不能为空',
            'verification_code.required' => '验证码不能为空',
            'verification_code.digits' => '验证码为6位',
        ];
    }
}
