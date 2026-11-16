<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Entity\Auth\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A impressão da senha gravada na concessão (`docs/specs/mcp-remoto-oauth.md`, seção 4, regra 3): HMAC-SHA256 do hash
 * da senha de agora, com o segredo da aplicação. Trocar a senha (por qualquer caminho) muda o hash e, com ele, a
 * impressão: a concessão autorizada antes deixa de bater ({@see \App\Conector\Entity\ConcessaoDoConector::impressaoDaSenhaBate()})
 * e o conector cai, como as outras sessões do navegador. Nenhum caminho de troca de senha precisa lembrar do conector.
 *
 * Conta sem senha (só Google ou Facebook) tem impressão fixa enquanto continuar sem senha; criar uma muda a impressão.
 * O texto do contexto separa este HMAC de outros usos do mesmo segredo.
 */
final class ImpressaoDaSenha
{
    private const CONTEXTO = 'conector_ia:impressao_da_senha:';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    /** 64 caracteres hexadecimais, o formato da coluna `impressao_da_senha`. */
    public function de(User $user): string
    {
        return hash_hmac('sha256', self::CONTEXTO . ($user->getPassword() ?? ''), $this->segredo);
    }
}
