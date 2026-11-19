<?php

declare(strict_types=1);

namespace App\Tenant\Enum;

/**
 * Quais pastas um perfil secundário alcança (spec `docs/specs/acesso-restrito-e-compartilhar.md`, §4.1).
 *
 * - todas: o comportamento de sempre; as outras camadas (módulo, recurso, cadeado) decidem.
 * - responsavel: "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE". Só as pastas em que a pessoa é a responsável
 *   (e, a partir da fatia 5, as compartilhadas com ela); módulos e administração fora da lista de liberação
 *   ficam fechados (`PermissoesDoAcessoRestrito`).
 */
enum EscopoDePastas: string
{
    case Todas = 'todas';
    case Responsavel = 'responsavel';
}
