<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class RegistrationCodeRequest extends FormRequest
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
            // 兼容前端传 registration_code 或 code
            'registration_code' => 'required_without:code|string|max:191',
            'code' => 'required_without:registration_code|string|max:191',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'registration_code.required_without' => '注册码不能为空',
            'registration_code.string' => '注册码格式不正确',
            'registration_code.max' => '注册码长度不能超过191位',
            'code.required_without' => '注册码不能为空',
            'code.string' => '注册码格式不正确',
            'code.max' => '注册码长度不能超过191位',
        ];
    }
}
