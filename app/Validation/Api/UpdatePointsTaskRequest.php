<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class UpdatePointsTaskRequest extends FormRequest
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
            'platform_id' => 'required|string|max:255',
            'platform_type' => 'required|integer|min:1|max:6',
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
            'platform_id.required' => '平台ID不能为空',
            'platform_id.max' => '平台ID不能超过255个字符',
            'platform_type.required' => '平台类型不能为空',
            'platform_type.integer' => '平台类型必须是整数',
            'platform_type.min' => '平台类型必须在1到6之间',
            'platform_type.max' => '平台类型必须在1到6之间',
        ];
    }
}
