<?php

namespace App\Controller;

use App\Repository\TitleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CatalogController extends AbstractController
{
    /** Сколько тайтлов максимум показывать в блоке «Новые серии». */
    private const NEW_LIMIT = 24;

    #[Route('/', name: 'app_catalog', methods: ['GET'])]
    public function index(Request $request, TitleRepository $titles): Response
    {
        $query = trim($request->query->get('q', ''));
        $type = $request->query->get('type') ?: null;
        $genre = $request->query->get('genre') ?: null;
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 25;

        [$items, $total] = $titles->searchCatalog($query ?: null, $type, $page, $perPage, TitleRepository::SCOPE_ALL, $genre);

        // «Новые серии» из «Смотрю» — выводится сверху главной страницы.
        $newItems = $titles->findNewEpisodes(self::NEW_LIMIT);
        // «Вышло продолжение»: свежие новые сезоны досмотренных произведений.
        $newSeasons = $titles->findNewSeasons();

        return $this->render('catalog/index.html.twig', [
            'items' => $items,
            'newItems' => $newItems,
            'newSeasons' => $newSeasons,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
            'q' => $query,
            'type' => $type,
            'genre' => $genre,
            'types' => $titles->findDistinctTypes(),
            'genres' => $titles->findDistinctGenres(),
            'countAll' => $titles->countAll(),
        ]);
    }
}
