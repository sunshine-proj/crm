<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly string $loginEmail,
        private readonly string $loginPasswordHash,
    ) {
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            $credentials = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'Request body must be valid JSON.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!is_string($email) || !is_string($password) || $email === '' || $password === '') {
            return $this->json([
                'error' => 'Both email and password are required.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (
            !hash_equals($this->loginEmail, $email)
            || $this->loginPasswordHash === ''
            || !password_verify($password, $this->loginPasswordHash)
        ) {
            return $this->json(['error' => 'Invalid email or password.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'message' => 'Login successful.',
            'user' => ['email' => $this->loginEmail],
        ]);
    }
}