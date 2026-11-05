<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\Contract\ManterConectadoDoEscritorio;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Implementação de {@see ManterConectadoDoEscritorio} sobre a tabela `rememberme_token` do {@see TokenDoManterConectado}.
 * A série guarda o identificador do usuário (o e-mail exato, como o UserProvider o devolve); a cópia `série_` da troca
 * concorrente tem o mesmo e-mail e sai junto. A transação é a da conexão: o flush do Doctrine dentro dela não abre outra.
 */
#[AsAlias(ManterConectadoDoEscritorio::class)]
final class EncerrarManterConectadoDoEscritorio implements ManterConectadoDoEscritorio
{
    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function encerrarJunto(int $tenantId, callable $gravar): mixed
    {
        return $this->conexao->transactional(function () use ($tenantId, $gravar): mixed {
            $resultado = $gravar();
            $this->conexao->executeStatement(
                'DELETE FROM rememberme_token WHERE username IN (SELECT u.email FROM "user" u INNER JOIN user_tenant ut ON ut.user_id = u.id WHERE ut.tenant_id = ?)',
                [$tenantId],
            );

            return $resultado;
        });
    }
}
