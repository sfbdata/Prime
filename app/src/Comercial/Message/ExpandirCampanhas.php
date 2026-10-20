<?php

declare(strict_types=1);

namespace App\Comercial\Message;

/**
 * Um passo da cadeia do expansor de campanhas (spec `comercial-e-emails.md` §8.5), no transporte `comercial`. Sem
 * dados: o handler reivindica a vez na linha 1 de `comercial_expansor`; um passo que não reivindica morre.
 */
final class ExpandirCampanhas
{
}
