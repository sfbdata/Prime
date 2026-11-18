<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * A porta da guarda da consulta de prazos e metas (`docs/specs/mcp-remoto-oauth.md`, 6.4, `ler_agenda` com
 * `minhas_metas`, e 14.i): quem chama a consulta diz qual guarda vale. O chat usa a {@see GuardaDaConsultaDePrazos},
 * sem mudança (módulo `tarefas` e o aceite da BlueJus I.A.); o conector terá a dele (módulo `tarefas` e a liberação
 * do conector, 14.a), porque o aceite da BlueJus I.A. não cobre o conector.
 *
 * Pessoa e escritório chegam explícitos: nenhuma implementação lê sessão, Security ou Request.
 */
interface PortaDaConsultaDePrazos
{
    /**
     * Recusa quando a pessoa não pode consultar os prazos e as metas neste escritório. A exceção é a da
     * implementação: a do chat lança {@see \App\ChatIa\Exception\SemAcessoAsMetasNoChatException}.
     *
     * @throws \Exception
     */
    public function exigir(User $eu, Tenant $tenant): void;
}
