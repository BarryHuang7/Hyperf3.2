<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class LoginRequest extends FormRequest
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
            'password' => 'required|string',
            'verify_code' => 'required|string|max:6',
            'temp_token' => 'string',
            '2fa_code' => 'string|max:6',
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
            'verify_code.required' => '验证码不能为空',
            'verification_code.max' => '验证码为6位',
            '2fa_code.max' => 'Google 2FA验证码为6位',
        ];
    }
}
