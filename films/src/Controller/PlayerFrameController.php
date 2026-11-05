<?php

namespace App\Controller;

use App\Service\Proxy\PlayerProxy;
use App\Service\Proxy\PlayerProxyException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Прокси-фрейм: страница плеера отдаётся с нашего origin.
 *
 * Так фрейм перестаёт быть кросс-доменным: player-shim.js внутри даёт
 * родительской странице время/перемотку/плей-паузу, реклама вырезается
 * из HTML, вложенные iframe плеера тоже проксируются.
 *
 * Непроксируемые хосты (amd.online и пр.) и сбои загрузки → 302 на оригинал,
 * т.е. привычное поведение обычного iframe.
 */
class PlayerFrameController extends AbstractController
{
    #[Route('/player/frame', name: 'app_player_frame', methods: ['GET'])]
    public function frame(Request $request, PlayerProxy $proxy): Response
    {
        $url = (string) $request->query->get('url', '');
        $depth = (int) $request->query->get('d', 0);

        // страховка от HTML-сущностей в ссылке (&amp; вместо &)
        $url = PlayerProxy::decodeUrlEntities($url);

        if (!preg_match('#^https?://#i', $url)) {
            return new Response('Bad url', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if ($depth > 5 || !$proxy->isProxiable($url)) {
            return $this->fallback($proxy, $url);
        }

        try {
            [$html, $finalUrl] = $proxy->fetchHtml($url);
            $html = $proxy->rewrite($html, $finalUrl, $depth);
        } catch (PlayerProxyException $e) {
            error_log('[player-proxy] '.$e->getMessage());
            return $this->fallback($proxy, $url);
        }

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Шлюз для AJAX плеера к его собственному хосту (см. PlayerProxy::passthrough).
     *
     * Документ плеера у нас на своём origin, поэтому его запросы к API
     * апстрима (POST /ftor и т.п.) становятся кросс-доменными, а CORS у API
     * нет. player-shim.js заворачивает такие запросы сюда, мы проксируем их
     * к апстриму с Referer amd.online и отдаём ответ как same-origin.
     */
    #[Route('/player/api', name: 'app_player_api', methods: ['GET', 'POST'])]
    public function api(Request $request, PlayerProxy $proxy): Response
    {
        $url = (string) $request->query->get('url', '');
        $url = PlayerProxy::decodeUrlEntities($url);
        if (!preg_match('#^https?://#i', $url) || !$proxy->isProxiable($url)) {
            return new Response('Bad url', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        try {
            [$status, $contentType, $body] = $proxy->passthrough(
                $url,
                $request->getMethod(),
                $headers,
                $request->getContent(),
            );
        } catch (PlayerProxyException $e) {
            error_log('[player-api] '.$e->getMessage());

            return new Response('Upstream error', 502, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response($body, $status, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function fallback(PlayerProxy $proxy, string $url): Response
    {
        if (!$proxy->isRedirectable($url)) {
            return new Response('Host not allowed', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new RedirectResponse($url);
    }
}
