<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            /** @var User $user */
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            $workspaceName = $user->name."'s Workspace";
            $baseSlug = Str::slug($user->name);
            $slug = $baseSlug !== '' ? $baseSlug.'-'.Str::lower(Str::random(6)) : 'workspace-'.Str::lower(Str::random(8));

            /** @var Workspace $workspace */
            $workspace = Workspace::query()->create([
                'name' => $workspaceName,
                'slug' => $slug,
                'owner_id' => $user->id,
            ]);

            $workspace->members()->attach($user->id, ['role' => 'owner']);

            return $user;
        });
    }
}
