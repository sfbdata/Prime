<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * O recálculo automático daria um total de encargos MENOR que o já reconhecido, e foi barrado.
 *
 * O cron (`app:cobranca:atualizar-encargos`) existe para os encargos crescerem com o tempo. Encolher
 * é sinal de que a CONFIG mudou, não o tempo: carteira com taxa zerada por engano, override apagado,
 * `--hoje` retroativo digitado errado. Como `definirEncargos()` sobrescreve, deixar passar apagaria
 * dinheiro já reconhecido — em silêncio e em lote.
 *
 * Não é hipótese: a migração `Version20260719140000` precisou congelar 3.271 obrigações com
 * R$ 155.209,73 em encargos justamente porque as carteiras estavam com taxa 0 e a primeira rodada do
 * cron as teria zerado.
 *
 * Reduzir pode ser legítimo (uma taxa estava errada para cima e foi corrigida), mas é decisão de
 * gente — daí a flag `--permitir-reducao`. Sem ela, a obrigação fica intacta e entra no relatório.
 */
final class ReducaoDeEncargosBloqueadaException extends \DomainException
{
    public function __construct(int $obrigacaoId, int $totalAtualCentavos, int $totalCalculadoCentavos)
    {
        parent::__construct(sprintf(
            'Obrigação %d: o recálculo reduziria os encargos de %d para %d centavos e foi bloqueado. '
            . 'Confira a configuração de taxas da carteira; use --permitir-reducao se a redução for intencional.',
            $obrigacaoId,
            $totalAtualCentavos,
            $totalCalculadoCentavos,
        ));
    }
}
