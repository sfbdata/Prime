<?php

declare(strict_types=1);

namespace App\Voto\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O link pessoal da unidade, `/votar/{token}` (spec docs/specs/voto-assembleia-condominio.md, seção 4).
 *
 * O banco guarda a semente e o hash do token, nunca o token. O token é recalculado da semente com o segredo do
 * sistema, então o link pode ser mostrado de novo ao gestor; quem lê o banco sem o segredo não chega ao link. "Gerar
 * novo link" troca a semente.
 */
final class TokenDaUnidade
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    /** 32 hex. */
    public function novaSemente(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 40 hex, o que vai no link. */
    public function token(string $semente): string
    {
        return substr(hash_hmac('sha256', 'voto-assembleia:' . $semente, $this->segredo), 0, 40);
    }

    /** 64 hex, o que o banco guarda (`token_hash`) e o que a cédula procura. */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
