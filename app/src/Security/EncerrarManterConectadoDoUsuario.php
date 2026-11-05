<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\Contract\ManterConectadoDoUsuario;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see ManterConectadoDoUsuario} sobre a tabela `rememberme_token` do {@see TokenDoManterConectado}: a série guarda o
 * identificador exato do usuário (o e-mail), e a cópia `série_` da troca concorrente sai junto.
 */
#[AsAlias(ManterConectadoDoUsuario::class)]
final class EncerrarManterConectadoDoUsuario implements ManterConectadoDoUsuario
{
    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function encerrarJunto(string $identificador, callable $gravar): mixed
    {
        return $this->conexao->transactional(function () use ($identificador, $gravar): mixed {
            // Primeiro o DELETE: quem grava (o corte) lê a hora depois dele.
            $this->conexao->executeStatement('DELETE FROM rememberme_token WHERE username = ?', [$identificador]);

            return $gravar();
        });
    }
}
