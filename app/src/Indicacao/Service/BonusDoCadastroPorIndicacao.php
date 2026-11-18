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
 * Quando concede, avisa quem indicou ({@see AvisoDeColegaCadastradoAoIndicador}): uma vez por conta nova.
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
        private readonly AvisoDeColegaCadastradoAoIndicador $aviso,
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

            $concedeu = $this->conceder->executar($userId, $atribuicao['indicacaoId'], $atribuicao['tenantId']);
            if ($concedeu) {
                $this->avisarOIndicador($atribuicao['indicacaoId'], $userId);
            }

            return $concedeu;
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao conceder o bônus de espaço da indicação.', ['exception' => $e, 'userId' => $userId]);

            return false;
        }
    }

    /**
     * A concessão do bônus acontece uma vez só por indicação, e só com o e-mail confirmado: é o momento em que a
     * indicação passa a contar para a I.A. de quem indicou, então é aqui que o aviso sai, sem tabela de controle.
     * Falha no aviso vai ao log e não desfaz nada.
     */
    private function avisarOIndicador(int $indicacaoId, int $userId): void
    {
        try {
            $this->aviso->avisar($indicacaoId);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao avisar quem indicou que o colega se cadastrou.', ['exception' => $e, 'userId' => $userId]);
        }
    }
}
