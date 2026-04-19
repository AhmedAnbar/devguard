<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(): JsonResponse
    {
        return response()->json($this->users->all());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->users->create($request->validated());
        return response()->json($user, 201);
    }
}
