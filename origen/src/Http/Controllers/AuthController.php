<?php

namespace Origen\Http\Controllers;

use Origen\Http\Request;
use Origen\Http\Response;
use Origen\Services\AuthTokenService;
use Origen\Storage\Database\UserRepository;

class AuthController
{
    public function __construct(
        private AuthTokenService $authTokenService,
        private UserRepository $userRepository,
    ) {}

    public function login(Request $request): Response
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $site = $request->input('current_site');

        if (!$email || !$password) {
            return Response::json(['error' => 'Email and password are required.'], 422);
        }

        $user = $this->userRepository->findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return Response::json(['error' => 'Invalid credentials.'], 401);
        }

        $membership = $this->userRepository->findMembership($user['id'], $site['id']);

        if (!$membership) {
            return Response::json(['error' => 'No access to this site.'], 403);
        }

        $token = $this->authTokenService->issue($user, $site, $membership['role']);

        return Response::json([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $membership['role'],
            ],
        ]);
    }

    public function me(Request $request): Response
    {
        $authHeader = $request->header('authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return Response::json(['error' => 'Authorization header required.'], 401);
        }

        try {
            $claims = $this->authTokenService->validate(substr($authHeader, 7));
        } catch (\Exception $e) {
            return Response::json(['error' => 'Invalid token: ' . $e->getMessage()], 401);
        }

        return Response::json([
            'user' => [
                'id' => $claims['user_id'],
                'name' => $claims['name'],
                'email' => $claims['email'],
                'role' => $claims['role'],
                'tenant_id' => $claims['tenant_id'],
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        // Stateless — just acknowledge
        return Response::json(['message' => 'Logged out.']);
    }
}
