<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class EncodePointsTaskNoRequest extends FormRequest
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
            'points_task_no' => 'required|string|max:64',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'points_task_no.required' => '任务编号不能为空',
            'points_task_no.max' => '任务编号不能超过64个字符',
        ];
    }
}
