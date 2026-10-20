<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use Doctrine\DBAL\Connection;

/**
 * Quantas pessoas um escritório tem, pela mesma regra do Painel do Master
 * (`PainelMasterRepository::usuariosPorEscritorio()`): vínculo ativo de pessoa ativa.
 */
// Não-final: os testes de UseCase mockam o repositório.
class UsuariosDoEscritorioRepository
{
    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function quantos(int $tenantId): int
    {
        return (int) $this->conexao->fetchOne(
            'SELECT COUNT(DISTINCT ut.user_id)
               FROM user_tenant ut
               JOIN "user" u ON u.id = ut.user_id AND u.is_active = TRUE
              WHERE ut.tenant_id = :tenant AND ut.is_active = TRUE',
            ['tenant' => $tenantId],
        );
    }
}
