<?php

namespace App\Modules\Content\Services;

use App\Models\ContactMessage;
use App\Models\User;

class ContactMessageService
{
    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, subject: string, message: string}  $data
     */
    public function store(array $data, ?User $user = null): ContactMessage
    {
        return ContactMessage::create([
            'user_id' => $user?->id,
            'name' => $data['name'],
            'email' => $data['email'] ?? $user?->email,
            'phone' => $data['phone'] ?? $user?->phone,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => 'new',
        ]);
    }
}
