<?php

namespace App\Modules\Dashboard\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // الحقول الأساسية للطلب من جدول license_applications
            'id'                   => $this->id,
            'application_number'   => $this->application_number,
            'status'               => $this->status,
            'rejection_reason'     => $this->rejection_reason,
            'submitted_at'         => $this->submitted_at ? $this->submitted_at->toIso8601String() : null,
            'approved_at'          => $this->approved_at ? $this->approved_at->toIso8601String() : null,
            'issued_at'            => $this->issued_at ? $this->issued_at->toIso8601String() : null,
            'created_at'           => $this->created_at ? $this->created_at->toIso8601String() : null,
            'updated_at'           => $this->updated_at ? $this->updated_at->toIso8601String() : null,

            // بيانات المواطن كاملة (العلاقة مع جدول users)
            'citizen' => $this->relationLoaded('citizen') && $this->citizen ? [
                'id'                => $this->citizen->id,
                'name'              => $this->citizen->name,
                'email'             => $this->citizen->email,
                'phone'             => $this->citizen->phone ?? null,
                'user_type'         => $this->citizen->user_type,
                'profile_status'    => $this->citizen->profile_status,
                'is_active'         => $this->citizen->is_active,
            ] : null,

            // بيانات فئة الرخصة كاملة مطابقة لـ image_e92f7c.png
            'license_type' => $this->relationLoaded('licenseType') && $this->licenseType ? [
                'id'             => $this->licenseType->id,
                'name'           => $this->licenseType->name,
                'code'           => $this->licenseType->code,
                'minimum_age'    => $this->licenseType->minimum_age,
                'validity_years' => $this->licenseType->validity_years,
                'is_active'      => $this->licenseType->is_active,
            ] : null,

            'service_type' => $this->relationLoaded('serviceType') && $this->serviceType ? [
                'id'          => $this->serviceType->id,
                'name'        => $this->serviceType->name,
                'code'        => $this->serviceType->code,
                'description' => $this->serviceType->description,
                'is_active'   => $this->serviceType->is_active,
            ] : null,

            // حقل الفحص الحالي (nullable) بناءً على الميجريشن الخاص بك
            'current_test_type' => $this->relationLoaded('currentTestType') && $this->currentTestType ? [
                'id'   => $this->currentTestType->id,
                'name' => $this->currentTestType->name,
                'code' => $this->currentTestType->code ?? null,
            ] : null,
        ];
    }
}
