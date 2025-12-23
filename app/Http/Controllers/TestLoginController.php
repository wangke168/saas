<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class TestLoginController extends Controller
{
    /**
     * 测试登录 - 用于调试
     */
    public function test(Request $request)
    {
        $email = $request->input('email', 'admin@example.com');
        $password = $request->input('password', 'admin123456');
        
        $user = User::where('email', $email)->first();
        
        $result = [
            'user_exists' => $user !== null,
            'email' => $email,
            'password_provided' => $password,
        ];
        
        if ($user) {
            $result['user_id'] = $user->id;
            $result['user_name'] = $user->name;
            $result['user_email'] = $user->email;
            $result['is_active'] = $user->is_active;
            $result['role'] = $user->role?->value ?? 'null';
            $result['password_hash'] = substr($user->password, 0, 20) . '...';
            $result['password_check'] = Hash::check($password, $user->password);
            $result['password_length'] = strlen($user->password);
        }
        
        Log::info('Test login', $result);
        
        return response()->json($result);
    }
}

