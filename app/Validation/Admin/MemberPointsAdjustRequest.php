<?php

declare(strict_types=1);

namespace App\Validation\Admin;

use Hyperf\Validation\Request\FormRequest;

class MemberPointsAdjustRequest extends FormRequest
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
            'member_id' => 'required|integer|min:1',
            'flag' => 'required|integer|in:0,1',
            'change_points' => 'required|numeric|min:0.01|max:9999999999.99',
            'remark' => 'required|string|max:200',
            'audit_notes' => 'required|string|max:200',
            'expiration_date' => 'required_if:flag,1|nullable',
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     */
    public function messages(): array
    {
        return [
            'member_id.required' => '会员id不能为空',
            'member_id.integer' => '会员id必须是整数',
            'member_id.min' => '会员id不能小于1',
            'flag.required' => '积分变动方向不能为空',
            'flag.integer' => '积分变动方向必须是整数',
            'flag.in' => '积分变动方向只能是0或1',
            'change_points.required' => '变动积分不能为空',
            'change_points.numeric' => '变动积分必须是数字',
            'change_points.min' => '变动积分不能小于0.01',
            'change_points.max' => '变动积分不能超过9999999999.99',
            'remark.required' => '调整原因不能为空',
            'remark.max' => '调整原因长度不能超过200位',
            'audit_notes.required' => '审计备注不能为空',
            'audit_notes.max' => '审计备注长度不能超过200位',
            'expiration_date.required_if' => '增加积分时过期日不能为空',
        ];
    }
}
