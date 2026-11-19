<?php

declare(strict_types=1);

namespace App\Inteligencia\Service\Saldo;

use App\ChatIa\Repository\MensagemDeChatRepository;
use App\Entity\Auth\User;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Mede o uso por tokens (`tokens_entrada + tokens_saida`), D2 da spec `ia-liberada-por-indicacao.md`: o que a pessoa
 * pediu em análises (`inteligencia_analise.solicitante_id`) mais as respostas do Chat I.A. das conversas dela
 * (`chat_ia_mensagem`, papel assistente, inclusive interrompida ou refeita: o gasto aconteceu). 100.000 tokens são
 * 3 peças (seção 2).
 */
#[AsAlias(MedidaDoUsoDeIa::class)]
final class ConsumoEmTokens implements MedidaDoUsoDeIa
{
    public const FRANQUIA_EM_TOKENS = 100_000;
    public const TOKENS_POR_COLEGA = 100_000;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly MensagemDeChatRepository $mensagens,
    ) {
    }

    public function unidade(): string
    {
        return 'tokens';
    }

    public function franquia(): int
    {
        return self::FRANQUIA_EM_TOKENS;
    }

    public function porColega(): int
    {
        return self::TOKENS_POR_COLEGA;
    }

    public function usado(User $user, array $tenantIdsSemPlanoPago): int
    {
        if ($tenantIdsSemPlanoPago === []) {
            return 0;
        }
        $userId = (int) $user->getId();

        return $this->analises->somarTokensDoSolicitanteNosEscritorios($userId, $tenantIdsSemPlanoPago)
            + $this->mensagens->somarTokensDoAssistenteDaPessoaNosEscritorios($userId, $tenantIdsSemPlanoPago);
    }
}
