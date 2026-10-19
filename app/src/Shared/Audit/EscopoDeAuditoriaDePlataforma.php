<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Escopo explícito de "ato de plataforma" para a auditoria: o que for gravado (flush) dentro de
 * {@see executar()} entra no `audit_log` com `tenant_id` NULO, em vez do escritório da sessão.
 *
 * Por quê: promover e remover Master (spec perfis-1.2-masters M7) é ato do sistema, não do escritório
 * em que o Master primário está. Com o tenant da sessão, a linha cairia na auditoria daquele
 * escritório e mostraria a terceiros quem é Master (na remoção, o alvo pode nem ser de lá). Sem
 * escritório, ela aparece só na trilha do Master, como "Plataforma (sem conta)".
 *
 * Não mexe na sessão nem no TenantContext: só o AuditLogSubscriber consulta {@see estaLigado()}.
 * Desliga sempre num `finally` (também quando a ação lança) e aceita aninhamento.
 */
final class EscopoDeAuditoriaDePlataforma
{
    private int $profundidade = 0;

    /**
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    public function executar(callable $acao): mixed
    {
        ++$this->profundidade;

        try {
            return $acao();
        } finally {
            --$this->profundidade;
        }
    }

    public function estaLigado(): bool
    {
        return $this->profundidade > 0;
    }
}
