<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Contribua\UseCase\ConcederBonusDeEspacoPorIndicacaoUseCase;
use App\Indicacao\Repository\IndicacaoRepository;
use Psr\Log\LoggerInterface;

/**
 * Liga a indicação do cadastro ao bônus de espaço do Contribua (spec `bonus-de-espaco-por-indicacao.md`): acha a
 * indicação da conta nova e o escritório dela e chama o caso de uso do Contribua, que confere o e-mail confirmado e
 * concede uma vez só. Chamado pelos dois gatilhos (cadastro já confirmado e confirmação do e-mail).
 *
 * Nunca derruba o cadastro nem a confirmação: qualquer falha vai ao log só com o id da conta. A concessão é
 * idempotente, então um novo evento reprocessa.
 */
final class BonusDoCadastroPorIndicacao
{
    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly ConcederBonusDeEspacoPorIndicacaoUseCase $conceder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Devolve se concedeu agora. */
    public function tentar(int $userId): bool
    {
        try {
            $atribuicao = $this->indicacoes->atribuicaoDoCadastro($userId);
            if ($atribuicao === null) {
                return false;
            }

            return $this->conceder->executar($userId, $atribuicao['indicacaoId'], $atribuicao['tenantId']);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao conceder o bônus de espaço da indicação.', ['exception' => $e, 'userId' => $userId]);

            return false;
        }
    }
}
