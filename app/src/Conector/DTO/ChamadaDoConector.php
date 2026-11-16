<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * Uma requisição do `/mcp` já aceita (`docs/specs/mcp-remoto-oauth.md`, 4 e 6.2): quem age e onde (o
 * {@see ContextoDoConector} que só o portão produz) e o que o token traz (concessão, consentimento, host do app de
 * I.A., escopos e até quando a conexão vale). Toda ferramenta recebe esta chamada; nada vem de sessão ou de Request.
 *
 * Ids e host vão ao log `mcp` (6.2, regra 9); nenhum deles é conteúdo.
 */
final class ChamadaDoConector
{
    /**
     * @param list<string> $escopos os escopos concedidos ao token
     */
    public function __construct(
        public readonly ContextoDoConector $contexto,
        public readonly int $concessaoId,
        public readonly int $consentimentoId,
        public readonly string $hostDoCliente,
        public readonly array $escopos,
        public readonly \DateTimeImmutable $conexaoValeAte,
    ) {
    }

    public function temEscopo(string $escopo): bool
    {
        return \in_array($escopo, $this->escopos, true);
    }
}
