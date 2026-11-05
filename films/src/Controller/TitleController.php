<?php

namespace App\Controller;

use App\Entity\Title;
use App\Repository\TitleRepository;
use App\Service\Amedia\AmediaException;
use App\Service\Amedia\AmediaSync;
use App\Service\Kodik\KodikApiException;
use App\Service\Kodik\KodikSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TitleController extends AbstractController
{
    public function __construct(
        private readonly TitleRepository $titles,
    ) {
    }

    /**
     * Карточка фильма/сериала.
     */
    #[Route('/title/{id}', name: 'app_title', methods: ['GET'])]
    public function show(Title $title, KodikSync $kodikSync, AmediaSync $amediaSync): Response
    {
        if ($title->isFromAmedia()) {
            // amedia-тайтл без озвучек: подтягиваем страницу с сайта
            if ($title->getTranslations()->isEmpty() && $title->getSourceUrl()) {
                try {
                    $amediaSync->importUrl($title->getSourceUrl());
                } catch (AmediaException) {
                    // сайт недоступен — показываем карточку как есть
                }
            }
        } elseif ($title->isSerial() && $title->getTranslations()->isEmpty() === false) {
            // kodik-тайтл: подтягиваем серии, если ещё ни разу не грузили
            $needSync = $title->getTranslations()->exists(
                static fn ($k, $tr) => $tr->getEpisodes()->isEmpty()
            );
            if ($needSync) {
                try {
                    $kodikSync->syncEpisodes($title);
                } catch (KodikApiException) {
                    // токен не задан — показываем карточку без серий
                }
            }
        }

        return $this->render('title/show.html.twig', [
            'title' => $title,
            'seasons' => $this->titles->findSeasonGroup($title),
        ]);
    }

    /**
     * Страница плеера: iframe (amedia/kodik) + своя обвязка
     * (выбор озвучки, серий, перемотка — под пульт Tizen).
     */
    #[Route('/watch/{id}', name: 'app_watch', methods: ['GET'])]
    public function watch(Title $title): Response
    {
        // плеер есть, если задана базовая ссылка или есть озвучки с сериями/ссылками
        $hasPlayer = $title->getPlayerLink()
            || $title->getTranslations()->exists(
                static fn ($k, $tr) => null !== $tr->getLink() || !$tr->getEpisodes()->isEmpty()
            );

        if (!$hasPlayer) {
            $this->addFlash('danger', 'Для этого тайтла нет ссылки на плеер.');

            return $this->redirectToRoute('app_title', ['id' => $title->getId()]);
        }

        return $this->render('title/watch.html.twig', [
            'title' => $title,
        ]);
    }
}
