<?php

namespace App\Actions\Fortify;

use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'email' => 'required|string|lowercase|email|max:255|unique:users',
            'password' => ['required', 'string', 'min:8'],
        ])->validate();

        $user = User::create([
            'email' => $input['email'],
            'password' => $input['password'],
            'provider' => 'email',
        ]);

        // Claim any guest reports with this email
        Report::where('guest_email', $user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        return $user;
    }
}
