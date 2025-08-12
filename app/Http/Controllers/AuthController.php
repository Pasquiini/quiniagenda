<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'fantasia' => 'required|string|max:255',
            'phone' => 'required|string|max:20', // Ajustamos a validação do telefone
            'document' => 'required|string|max:20', // Adicionamos o documento (CPF/CNPJ)
            'address' => 'required|string|max:255', // Adicionamos o endereço
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'fantasia' => $request->fantasia,
            'phone' => $request->phone,
            'document' => $request->document,
            'address' => $request->address,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'plan_id' => 9,
        ]);

        return response()->json(['message' => 'Usuário registrado com sucesso!'], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json(['error' => 'Credenciais inválidas'], 401);
        }

        return $this->respondWithToken($token);
    }

    public function logout()
    {
        Auth::logout();
        return response()->json(['message' => 'Sessão encerrada com sucesso!']);
    }

    public function user()
    {
        $user = Auth::user();
        if ($user) {
            $user->load('plan');
            return response()->json($user);
        }
        return response()->json(['error' => 'Não autenticado'], 401);
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::factory()->getTTL() * 60,
            'user' => Auth::user()->load('plan'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Validação dos dados
        $request->validate([
            'name' => 'required|string|max:255',
            'fantasia' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'document' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
        ]);

        // Atualiza os dados do usuário
        $user->update($request->all());

        return response()->json(['message' => 'Perfil atualizado com sucesso!']);
    }

    public function profile(Request $request)
    {
        // Retorna os dados do usuário autenticado
        return response()->json($request->user());
    }

    public function hasPix(int $userId)
    {
        $profissional = User::find($userId);

        if (!$profissional) {
            return response()->json(['message' => 'Profissional não encontrado.'], 404);
        }

        $hasPixKey = (bool) $profissional->pixConfig;

        return response()->json(['hasPixKey' => $hasPixKey]);
    }
}
