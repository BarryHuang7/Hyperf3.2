<?php

declare(strict_types=1);

namespace App\Validation\Api;

use Hyperf\Validation\Request\FormRequest;

class ConsumptionPointsRequest extends FormRequest
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
            'feature_type' => 'required|integer|min:1|max:5',
            'duration_seconds' => 'required|numeric|gt:0',
            'width' => 'required|integer|gt:0',
            'height' => 'required|integer|gt:0',
            'fps' => 'required|numeric|gt:0',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'feature_type.required' => '视频处理的类型不能为空',
            'feature_type.integer' => '视频处理的类型必须是整数',
            'feature_type.min' => '视频处理的类型不能小于1',
            'feature_type.max' => '视频处理的类型不能大于5',
            'duration_seconds.required' => '视频处理的时长（秒）不能为空',
            'duration_seconds.numeric' => '视频处理的时长（秒）必须是数字',
            'duration_seconds.gt' => '视频处理的时长（秒）必须大于0',
            'width.required' => '视频分辨率-宽度不能为空',
            'width.integer' => '视频分辨率-宽度必须是整数',
            'width.gt' => '视频分辨率-宽度必须大于0',
            'height.required' => '视频分辨率-高度不能为空',
            'height.integer' => '视频分辨率-高度必须是整数',
            'height.gt' => '视频分辨率-高度必须大于0',
            'fps.required' => '视频帧率不能为空',
            'fps.numeric' => '视频帧率必须是数字',
            'fps.gt' => '视频帧率必须大于0',
        ];
    }
}
