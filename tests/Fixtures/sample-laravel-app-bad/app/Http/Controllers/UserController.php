<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = DB::table('users')->get();
        return response()->json($users);
    }

    public function store(Request $request)
    {
        if ($request->name === '') {
            return response()->json(['error' => 'name required'], 422);
        }
        if ($request->email === '') {
            return response()->json(['error' => 'email required'], 422);
        }
        if (! str_contains($request->email, '@')) {
            return response()->json(['error' => 'bad email'], 422);
        }
        if (strlen($request->password) < 8) {
            return response()->json(['error' => 'password too short'], 422);
        }
        if ($request->password !== $request->password_confirmation) {
            return response()->json(['error' => 'password mismatch'], 422);
        }
        if ($request->age && $request->age < 18) {
            return response()->json(['error' => 'too young'], 422);
        }

        $existing = DB::table('users')->where('email', $request->email)->first();
        if ($existing) {
            return response()->json(['error' => 'exists'], 409);
        }

        try {
            $id = DB::table('users')->insertGetId([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'insert failed'], 500);
        }

        if ($request->send_email) {
            // pretend to send email
        } elseif ($request->send_sms) {
            // pretend to send sms
        }

        return response()->json(['id' => $id], 201);
    }
}
