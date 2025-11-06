<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Rate limits login attempts to prevent brute force attacks.
 *
 * Enforces a sliding window rate limit of 3 failed login attempts per 5 minutes per IP address.
 * Rate limit is checked before authentication and incremented on login failures.
 * Rate limit is reset on successful login.
 */
class LoginRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $loginLimiter,
        private RequestStack $requestStack
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['onCheckPassport', 256],
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    /**
     * Check rate limit before authentication attempt.
     *
     * @throws TooManyRequestsHttpException if rate limit is exceeded
     */
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return;
        }

        // Only rate limit login attempts (not other authentication methods)
        if ($request->attributes->get('_route') !== 'app_login') {
            return;
        }

        // Get client IP for rate limiting
        $clientIp = $request->getClientIp() ?? 'unknown';

        // Get rate limiter for this IP
        $limiter = $this->loginLimiter->create($clientIp);

        // Check if rate limit is exceeded (peek without consuming)
        if (!$limiter->consume(0)->isAccepted()) {
            $limit = $limiter->consume(0);
            $retryAfter = $limit->getRetryAfter();

            $minutes = $retryAfter ? ceil($retryAfter->getTimestamp() - time()) / 60 : 5;

            throw new TooManyRequestsHttpException(
                $retryAfter?->getTimestamp() - time(),
                sprintf(
                    'Too many failed login attempts. Please try again in %d minute(s).',
                    (int) ceil($minutes)
                )
            );
        }
    }

    /**
     * Consume rate limit token on login failure.
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $clientIp = $request->getClientIp() ?? 'unknown';

        // Consume a token from the rate limiter
        $limiter = $this->loginLimiter->create($clientIp);
        $limiter->consume(1);
    }

    /**
     * Reset rate limit on successful login.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $clientIp = $request->getClientIp() ?? 'unknown';

        // Reset the rate limiter for this IP
        $limiter = $this->loginLimiter->create($clientIp);
        $limiter->reset();
    }
}
