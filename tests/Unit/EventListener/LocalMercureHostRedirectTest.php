<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\LocalMercureHostRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LocalMercureHostRedirectTest extends TestCase
{
    #[DataProvider('redirectCases')]
    public function testRedirectsLoopbackBrowserRequestsToTheConfiguredHubHost(string $publicUrl, string $requestUrl, string $expectedUrl): void
    {
        $event = $this->event($requestUrl);
        (new LocalMercureHostRedirect($publicUrl))($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(302, $event->getResponse()->getStatusCode());
        self::assertSame($expectedUrl, $event->getResponse()->headers->get('Location'));
    }

    public static function redirectCases(): iterable
    {
        yield ['http://localhost/.well-known/mercure', 'http://127.0.0.1/proposals?status=pending', 'http://localhost/proposals?status=pending'];
        yield ['http://localhost:18086/.well-known/mercure', 'http://127.0.0.1:18086/login', 'http://localhost:18086/login'];
        yield ['http://127.0.0.1/.well-known/mercure', 'http://localhost/', 'http://127.0.0.1/'];
    }

    #[DataProvider('ignoredCases')]
    public function testDoesNotRedirectOtherOriginsOrRequests(string $publicUrl, string $requestUrl, string $method): void
    {
        $event = $this->event($requestUrl, $method);
        (new LocalMercureHostRedirect($publicUrl))($event);

        self::assertNull($event->getResponse());
    }

    public static function ignoredCases(): iterable
    {
        yield ['http://localhost/.well-known/mercure', 'http://localhost/proposals', 'GET'];
        yield ['https://hub.example.com/.well-known/mercure', 'http://127.0.0.1/proposals', 'GET'];
        yield ['http://localhost:18086/.well-known/mercure', 'http://127.0.0.1:18087/proposals', 'GET'];
        yield ['http://localhost/.well-known/mercure', 'https://127.0.0.1/proposals', 'GET'];
        yield ['http://localhost/.well-known/mercure', 'http://127.0.0.1/login', 'POST'];
        yield ['http://localhost/.well-known/mercure', 'http://127.0.0.1/mcp', 'GET'];
    }

    private function event(string $url, string $method = 'GET'): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create($url, $method), HttpKernelInterface::MAIN_REQUEST);
    }
}
