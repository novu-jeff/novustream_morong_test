<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Dynamically fetch all valid status codes from DB
        $validStatuses = DB::table('status_code')->pluck('code')->toArray();

        return [
            'name' => 'required|string|max:255',
            'email' => 'required|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'contact_no' => 'required',

            'accounts' => 'required|array',
            'accounts.*.account_no' => 'required|unique:concessioner_accounts,account_no',
            'accounts.*.property_type' => 'required|exists:property_types,id',
            'accounts.*.address' => 'required|string|max:255',
            'accounts.*.rate_code' => 'required|numeric|gt:0',
            'accounts.*.status' => ['required', Rule::in($validStatuses)],
            'accounts.*.sc_no' => 'required',
            'accounts.*.meter_brand' => 'nullable|string|max:256',
            'accounts.*.meter_serial_no' => 'required',
            'accounts.*.date_connected' => 'required',
            'accounts.*.sequence_no' => 'required',
            'accounts.*.meter_type' => 'nullable|string|max:120',
            'accounts.*.meter_wire' => 'nullable|string|max:120',
            'accounts.*.meter_form' => 'nullable|string|max:120',
            'accounts.*.meter_class' => 'nullable|string|max:120',
            'accounts.*.lat_long' => 'nullable|string|max:120',
            'accounts.*.isErcSealed' => 'nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'accounts.required' => 'At least one account is required.',
            'accounts.*.account_no.required' => 'The account number is required.',
            'accounts.*.account_no.unique' => 'The account number must be unique.',
            'password.confirmed' => 'The password and confirmation do not match.',
            'accounts.*.property_type.required' => 'The property type is required.',
            'accounts.*.property_type.exists' => 'The selected property type is invalid.',
            'accounts.*.address.required' => 'The address is required.',
            'accounts.*.rate_code.required' => 'The rate code is required.',
            'accounts.*.rate_code.numeric' => 'The rate code must be a number.',
            'accounts.*.rate_code.gt' => 'The rate code must be greater than 0.',
            'accounts.*.status.required' => 'The status field is required.',
            'accounts.*.status.in' => 'The selected status is invalid.',
            'accounts.*.sc_no.required' => 'The SC number is required.',
            'accounts.*.meter_serial_no.required' => 'The meter serial number is required.',
            'accounts.*.date_connected.required' => 'The date connected is required.',
            'accounts.*.sequence_no.required' => 'The sequence number is required.',
        ];
    }
}
