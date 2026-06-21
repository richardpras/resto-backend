<?php

namespace App\Http\Middleware;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\User;
use App\Modules\Hardware\Services\HardwareDeviceAuthService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Token;
use Laravel\Passport\TokenRepository;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHardwareBridge
{
    public function __construct(
        private readonly HardwareDeviceAuthService $deviceAuthService,
        private readonly ResourceServer $resourceServer,
        private readonly TokenRepository $tokenRepository,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null && $bearer !== '') {
            $device = $this->deviceAuthService->authenticateAccessToken($bearer);
            if ($device instanceof HardwareBridgeDevice) {
                $request->attributes->set('hardware_bridge_device', $device);

                return $next($request);
            }

            $user = $this->resolvePassportUser($request);
            if ($user instanceof User) {
                Auth::guard('api')->setUser($user);
                $request->setUserResolver(static fn (): User => $user);

                return $next($request);
            }

            throw new AuthenticationException('Unauthenticated.');
        }

        $guardUser = Auth::guard('api')->user();
        if ($guardUser instanceof User) {
            $request->setUserResolver(static fn (): User => $guardUser);

            return $next($request);
        }

        throw new AuthenticationException('Unauthenticated.');
    }

    private function resolvePassportUser(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return null;
        }

        try {
            $psr17Factory = new Psr17Factory;
            $psrFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
            $psr = $psrFactory->createRequest($request);
            $validated = $this->resourceServer->validateAuthenticatedRequest($psr);
            $tokenId = $validated->getAttribute('oauth_access_token_id');
            if (! is_string($tokenId) || $tokenId === '') {
                return null;
            }

            $token = $this->tokenRepository->find($tokenId);
            if (! $token instanceof Token || $token->revoked || $token->expires_at?->isPast()) {
                return null;
            }

            $user = $token->user;
            if ($user instanceof User) {
                return $user;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
