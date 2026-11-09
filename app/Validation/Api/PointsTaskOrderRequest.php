<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class PointsTaskOrderRequest extends FormRequest
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
            'points_task_id' => 'required|integer|min:1',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'points_task_id.required' => '页码不能为空',
            'points_task_id.integer' => '页码必须是整数',
            'points_task_id.min' => '页码不能小于1',
        ];
    }
}
