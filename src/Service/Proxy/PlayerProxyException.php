<?php

namespace App\Service\Proxy;

/** Страницу плеера не удалось получить/разобрать — имеет смысл 302 на оригинал. */
class PlayerProxyException extends \RuntimeException
{
}
