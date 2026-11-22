<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[When('dev')]
#[AsEventListener(event: KernelEvents::REQUEST, priority: 40)]
final readonly class LocalMercureHostRedirect
{
    public function __construct(
        #[Autowire('%env(default::MERCURE_PUBLIC_URL)%')]
        private string $publicUrl,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $hub = parse_url($this->publicUrl);
        $path = $request->getPathInfo();

        if (!$event->isMainRequest()
            || !in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || ('/' !== $path && '/login' !== $path && '/proposals' !== $path && !str_starts_with($path, '/proposals/'))
            || !is_array($hub)
            || 'http' !== ($hub['scheme'] ?? null)
            || 'http' !== $request->getScheme()
            || !in_array($hub['host'] ?? null, ['localhost', '127.0.0.1'], true)
            || !in_array($request->getHost(), ['localhost', '127.0.0.1'], true)
            || $hub['host'] === $request->getHost()
            || ($hub['port'] ?? 80) !== $request->getPort()
        ) {
            return;
        }

        $port = 80 === $request->getPort() ? '' : ':'.$request->getPort();
        $event->setResponse(new RedirectResponse('http://'.$hub['host'].$port.$request->getRequestUri()));
    }
}
