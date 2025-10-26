<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     * Normalize or transform incoming data before validation.
     */
    protected function prepareForValidation()
    {
        if ($this->has('accounts')) {
            $accounts = $this->input('accounts');

            foreach ($accounts as &$account) {
                if (isset($account['status'])) {
                    $account['status'] = strtoupper(trim($account['status']));
                }
            }

            // Reindex and merge back
            $this->merge(['accounts' => array_values($accounts)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Get current valid status codes from database
        $validStatusCodes = DB::table('status_code')
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim($code)))
            ->toArray();

        $id = $this->route('concessionaire');

        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => 'required|min:8|confirmed',
            'confirm_password' => 'nullable|same:password',
            'contact_no' => 'required|string',

            'accounts' => 'required|array',
            'accounts.*.id' => 'nullable|exists:concessioner_accounts,id',
            'accounts.*.account_no' => 'required|string',
            'accounts.*.address' => 'required|string|max:255',
            'accounts.*.property_type' => 'required|exists:property_types,id',
            'accounts.*.rate_code' => 'required|numeric|gt:0',
            'accounts.*.status' => ['required', Rule::in($validStatusCodes),],
            'accounts.*.sc_no' => 'required|string',
            'accounts.*.meter_brand' => 'nullable|string|max:256',
            'accounts.*.meter_serial_no' => 'required|string',
            'accounts.*.date_connected' => 'required|date',
            'accounts.*.sequence_no' => 'required|string',

            'accounts.*.meter_type' => 'nullable|string|max:120',
            'accounts.*.meter_wire' => 'nullable|string|max:120',
            'accounts.*.meter_form' => 'nullable|string|max:120',
            'accounts.*.meter_class' => 'nullable|string|max:120',
            'accounts.*.lat_long' => 'nullable|string|max:120',
            'accounts.*.isErcSealed' => 'nullable|boolean',
            'accounts.*.inspectionImage' => 'nullable|image|mimes:jpg,png,jpeg,gif|max:2048',
        ];
    }

    /**
     * Custom error messages for validation failures.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The name field is required.',
            'email.required' => 'The email field is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email is already in use.',
            'password.min' => 'The password must be at least 8 characters long.',
            'confirm_password.same' => 'The confirm password must match the password.',
            'contact_no.required' => 'The contact number field is required.',

            'accounts.required' => 'At least one account must be provided.',
            'accounts.array' => 'Accounts must be a valid array.',

            'accounts.*.account_no.required' => 'The account number is required.',
            'accounts.*.address.required' => 'The address is required.',
            'accounts.*.property_type.required' => 'The property type is required.',
            'accounts.*.property_type.exists' => 'The selected property type does not exist.',
            'accounts.*.rate_code.required' => 'The rate code is required.',
            'accounts.*.rate_code.numeric' => 'The rate code must be a number.',
            'accounts.*.rate_code.gt' => 'The rate code must be greater than 0.',

            'accounts.*.status.required' => 'The status field is required.',
            'accounts.*.status.in' => 'The selected status is invalid. Please choose a valid status from the list.',

            'accounts.*.sc_no.required' => 'The SC number is required.',
            'accounts.*.meter_serial_no.required' => 'The meter serial number is required.',
            'accounts.*.date_connected.required' => 'The date connected field is required.',
            'accounts.*.sequence_no.required' => 'The sequence number field is required.',

            'accounts.*.inspectionImage.image' => 'The uploaded file must be an image.',
            'accounts.*.inspectionImage.mimes' => 'The image must be a JPG, PNG, JPEG, or GIF file.',
        ];
    }
}
