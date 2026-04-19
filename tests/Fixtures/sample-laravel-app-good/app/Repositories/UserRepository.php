<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function all(): iterable
    {
        return User::all();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }
}
