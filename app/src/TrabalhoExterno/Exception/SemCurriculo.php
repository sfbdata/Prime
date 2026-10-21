<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Exception;

/**
 * Quem quer se candidatar ainda não tem currículo (o controller responde 409 `{ok:false,
 * semCurriculo:true}`): a tela abre "Seu currículo para candidatura" com "Salvar e candidatar-se".
 */
final class SemCurriculo extends \DomainException
{
}
