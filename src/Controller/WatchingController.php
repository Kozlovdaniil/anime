<?php

namespace App\Controller;

use App\Entity\Title;
use App\Entity\Translation;
use App\Repository\TitleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WatchingController extends AbstractController
{
    private const PER_PAGE = 25;

    /** Сколько тайтлов максимум показывать в блоке «Новые серии». */
    private const NEW_LIMIT = 24;

    #[Route('/watching', name: 'app_watching', methods: ['GET'])]
    public function index(Request $request, TitleRepository $titles): Response
    {
        $query = trim($request->query->get('q', ''));
        $type = $request->query->get('type') ?: null;
        $genre = $request->query->get('genre') ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        [$items, $total] = $titles->searchCatalog($query ?: null, $type, $page, self::PER_PAGE, TitleRepository::SCOPE_WATCHING, $genre);

        $progress = $titles->watchProgressFor(array_map(
            static fn (Title $t): int => (int) $t->getId(),
            $items
        ));

        // Блок «Новые серии»: сериалы из «Смотрю», у которых в текущей озвучке
        // остались вышедшие непросмотренные серии. Считаем по ВСЕМУ списку
        // «Смотрю», а не только по текущей странице.
        $newItems = $titles->findNewEpisodes(self::NEW_LIMIT);
        // «Вышло продолжение»: свежие новые сезоны досмотренных произведений.
        $newSeasons = $titles->findNewSeasons();

        return $this->render('watching/index.html.twig', [
            'items' => $items,
            'newItems' => $newItems,
            'newSeasons' => $newSeasons,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'q' => $query,
            'type' => $type,
            'genre' => $genre,
            'types' => $titles->findDistinctTypesWatching(),
            'genres' => $titles->findDistinctGenresWatching(),
            'progress' => $progress,
        ]);
    }

    #[Route('/watching/{id}/toggle', name: 'app_watching_toggle', methods: ['POST'])]
    public function toggle(Title $title, Request $request, EntityManagerInterface $em, TitleRepository $titles): Response
    {
        $token = (string) $request->headers->get('X-CSRF-TOKEN', (string) $request->request->get('_token', ''));
        if (!$this->isCsrfTokenValid('watching_'.$title->getId(), $token)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Неверный CSRF-токен.'], Response::HTTP_FORBIDDEN);
            }

            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        $title->setWatching(!$title->isWatching());
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'watching' => $title->isWatching(),
                'count' => $titles->countWatching(),
            ]);
        }

        $this->addFlash('success', $title->isWatching()
            ? sprintf('«%s» добавлен в «Смотрю».', $title->getTitle())
            : sprintf('«%s» убран из «Смотрю».', $title->getTitle()));

        return $this->redirect($request->headers->get('referer')
            ?: $this->generateUrl('app_title', ['id' => $title->getId()]));
    }

    #[Route('/watching/{id}/progress', name: 'app_watching_progress', methods: ['POST'])]
    public function progress(Title $title, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $token = (string) $request->headers->get('X-CSRF-TOKEN', (string) $request->request->get('_token', ''));
        if (!$this->isCsrfTokenValid('progress_'.$title->getId(), $token)) {
            return new JsonResponse(['error' => 'Неверный CSRF-токен.'], Response::HTTP_FORBIDDEN);
        }

        $episode = $request->request->getInt('e', 0);
        if ($episode < 1) {
            return new JsonResponse(['error' => 'Неверный номер серии.'], Response::HTTP_BAD_REQUEST);
        }
        $season = $request->request->getInt('s', 1);

        // Прогресс ведётся по озвучке: без корректного id перевода отмечать
        // нечего (прогресс тайтла теперь производный от прогресса озвучек).
        $translationId = $request->request->getInt('t', 0);
        $translation = $translationId > 0 ? $em->find(Translation::class, $translationId) : null;
        if (!$translation instanceof Translation
            || $translation->getTitle()?->getId() !== $title->getId()) {
            return new JsonResponse(['error' => 'Неверная озвучка.'], Response::HTTP_BAD_REQUEST);
        }

        $translation->markWatched($season, $episode);
        $em->flush();

        return new JsonResponse([
            'ok' => true,
            'translation' => $translation->getId(),
            'lastSeason' => $translation->getLastSeason(),
            'lastEpisode' => $translation->getLastEpisode(),
        ]);
    }
}
