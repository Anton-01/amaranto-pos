<?php

namespace App\Http\Requests\TicketConfig;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => 'required|string|max:150',
            'rfc' => 'required|string|max:20',
            'address' => 'required|string|max:1000',
            'phone' => 'required|string|max:20',
            'header_message' => 'nullable|string|max:500',
            'footer_message' => 'nullable|string|max:500',
            'logo_url' => 'nullable|string|max:255',
        ];
    }
}
