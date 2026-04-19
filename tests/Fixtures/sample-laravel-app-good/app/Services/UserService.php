<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;

class UserService
{
    public function __construct(private readonly UserRepository $repo) {}

    public function all(): iterable
    {
        return $this->repo->all();
    }

    public function create(array $data): User
    {
        return $this->repo->create($data);
    }
}
