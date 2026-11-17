<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * Uma conexão de I.A. da pessoa, para a lista "Conexões de I.A." do Meu Perfil (`docs/specs/mcp-remoto-oauth.md`, 13.1
 * linha do PR 8, e 14.e). Uma linha por consentimento ativo, de qualquer escritório da pessoa, com o escritório na linha.
 *
 * O app aparece pelo host do `client_id` ({@see \App\Conector\Entity\ClienteDoConector::getIdentificador}), como na tela
 * de autorização ({@see PedidoDeAutorizacao::hostDoCliente}); nunca pelo `client_name`, que é autodeclarado (9.4).
 */
final readonly class ConexaoDeIaOutput
{
    /**
     * @param int          $id           o id do consentimento: é ele que a pessoa revoga
     * @param list<string> $escopos
     * @param bool         $vinculoAtivo a pessoa ainda tem vínculo aprovado com o escritório; sem ele a conexão aparece
     *                                   e pode ser revogada, mas não funciona
     */
    public function __construct(
        public int $id,
        public string $hostDoApp,
        public string $nomeDoEscritorio,
        public int $tenantId,
        public array $escopos,
        public \DateTimeImmutable $concedidoEm,
        public ?\DateTimeImmutable $ultimoUsoEm,
        public bool $vinculoAtivo,
    ) {
    }
}
