<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Escopo explícito de "ato de plataforma" para a auditoria: o que for gravado (flush) dentro dele
 * entra no `audit_log` com o escritório escolhido AQUI, e não com o escritório da sessão.
 *
 *  - {@see executar()}: `tenant_id` NULO. Promover e remover Master (spec perfis-1.2-masters M7) é ato
 *    do sistema, não do escritório em que o Master primário está. Com o tenant da sessão, a linha
 *    cairia na auditoria daquele escritório e mostraria a terceiros quem é Master. Sem escritório,
 *    ela aparece só na trilha do Master, como "Plataforma (sem conta)".
 *  - {@see executarComoConta()}: `tenant_id` da CONTA ALVO. Suspender e reativar conta pelo Master
 *    (spec perfis-1.2-contas C6): a linha tem de cair no "Histórico de alterações" da conta afetada,
 *    nunca no escritório em que o Master está navegando.
 *
 * Não mexe na sessão nem no TenantContext: só o AuditLogSubscriber consulta {@see estaLigado()} e
 * {@see contaAlvo()}. Cada escopo sai sempre num `finally` (também quando a ação lança) e aceita
 * aninhamento: vale o escopo mais interno.
 */
final class EscopoDeAuditoriaDePlataforma
{
    /** @var list<?int> pilha de escopos abertos: null = plataforma, int = id da conta alvo */
    private array $pilha = [];

    /**
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    public function executar(callable $acao): mixed
    {
        return $this->dentroDoEscopo(null, $acao);
    }

    /**
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    public function executarComoConta(int $contaId, callable $acao): mixed
    {
        return $this->dentroDoEscopo($contaId, $acao);
    }

    public function estaLigado(): bool
    {
        return $this->pilha !== [];
    }

    /** Id da conta do escopo mais interno; nulo no escopo de plataforma ou fora de escopo. */
    public function contaAlvo(): ?int
    {
        return $this->pilha === [] ? null : $this->pilha[array_key_last($this->pilha)];
    }

    /**
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    private function dentroDoEscopo(?int $contaId, callable $acao): mixed
    {
        $this->pilha[] = $contaId;

        try {
            return $acao();
        } finally {
            array_pop($this->pilha);
        }
    }
}
