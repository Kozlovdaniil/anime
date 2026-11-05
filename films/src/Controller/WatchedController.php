<?php

namespace App\Controller;

use App\Entity\Title;
use App\Repository\TitleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Просмотрено» — тайтлы, которые запускали (есть прогресс хотя бы по одной
 * серии), но не добавили в «Смотрю».
 *
 * За новыми сериями такие тайтлы не следят: блок «Новые серии» на главной
 * строится только по разделу «Смотрю» (TitleRepository::findNewEpisodes()).
 */
class WatchedController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/watched', name: 'app_watched', methods: ['GET'])]
    public function index(Request $request, TitleRepository $titles): Response
    {
        $query = trim($request->query->get('q', ''));
        $type = $request->query->get('type') ?: null;
        $genre = $request->query->get('genre') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        [$items, $total] = $titles->searchCatalog(
            $query ?: null,
            $type,
            $page,
            self::PER_PAGE,
            TitleRepository::SCOPE_WATCHED,
            $genre
        );

        // Прогресс по «текущей» озвучке: что и сколько уже посмотрено.
        $progress = $titles->watchProgressFor(array_map(
            static fn (Title $t): int => (int) $t->getId(),
            $items
        ));

        return $this->render('watched/index.html.twig', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'q' => $query,
            'type' => $type,
            'genre' => $genre,
            'progress' => $progress,
        ]);
    }
}
