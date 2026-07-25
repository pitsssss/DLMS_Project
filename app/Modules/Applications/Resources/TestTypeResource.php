<?php

namespace App\Modules\Applications\Resources;

use App\Support\EmployeeMessageTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class TestTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => EmployeeMessageTranslator::get('employee.test_types.' . $this->code),
            'code' => $this->code,
        ];
    }
}
