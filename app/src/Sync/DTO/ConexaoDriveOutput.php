<?php

declare(strict_types=1);

namespace App\Sync\DTO;

use App\Sync\Entity\TenantDriveConexao;

/**
 * Projeção da {@see TenantDriveConexao} para a tela "Conectar meu Drive" — só o que a view precisa.
 * NÃO expõe o `refreshTokenCifrado`: o token (mesmo cifrado) nunca chega ao template.
 */
final readonly class ConexaoDriveOutput
{
    public function __construct(
        public bool $conectada,
        public bool $ativa,
        public ?string $contaEmail,
        public ?string $rootFolderId,
        public ?\DateTimeImmutable $conectadoEm,
    ) {
    }

    public static function fromEntity(?TenantDriveConexao $conexao): ?self
    {
        if ($conexao === null) {
            return null;
        }

        return new self(
            conectada: $conexao->getRefreshTokenCifrado() !== '',
            ativa: $conexao->estaPronta(),
            contaEmail: $conexao->getContaEmail(),
            rootFolderId: $conexao->getRootFolderId(),
            conectadoEm: $conexao->getConectadoEm(),
        );
    }
}
