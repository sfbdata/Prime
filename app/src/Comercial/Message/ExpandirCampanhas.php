<?php

declare(strict_types=1);

namespace App\Comercial\Message;

/**
 * Uma volta do expansor de campanhas (spec `comercial-e-emails.md` §8.5), no transporte `comercial`, a cada minuto pelo
 * schedule `cadeias`. Sem dados: o handler reivindica a vez na linha 1 de `comercial_expansor`; a que não reivindica
 * não faz nada.
 */
final class ExpandirCampanhas
{
}
