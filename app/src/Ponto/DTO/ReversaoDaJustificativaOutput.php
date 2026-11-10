<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Service\NomesDasSituacoesDaJustificativa;

/**
 * O que a volta de uma justificativa para análise fez (`AnalisarJustificativaUseCase::reverter`): a situação de antes,
 * lida sob a trava, e o que aconteceu com a batida que o abono do esquecimento criou (E10, plano-e10-ponto §5).
 */
final class ReversaoDaJustificativaOutput
{
    /** A inclusão do abono saiu da conta agora (desconsiderada, com o registro guardado). */
    public const BATIDA_DESCONSIDERADA = 'desconsiderada';

    /** Abono de esquecimento de antes da E10: a batida está em `registro_ponto` e fica (decisão 3 do plano). */
    public const BATIDA_DE_ANTES = 'de_antes';

    /**
     * @param string      $situacaoAnterior a situação lida sob a trava (`abonado`, `rejeitado`...)
     * @param string|null $batidaDoAbono    uma das constantes acima; null quando não há batida do abono a mencionar
     *                                      (não era esquecimento abonado, ou a inclusão já estava fora da conta)
     */
    public function __construct(
        public readonly string $situacaoAnterior,
        public readonly ?string $batidaDoAbono = null,
    ) {
    }

    /** A chave do texto da tela em {@see NomesDasSituacoesDaJustificativa}; recebe a data da justificativa. */
    public function chaveDoTexto(): string
    {
        return match ($this->batidaDoAbono) {
            self::BATIDA_DESCONSIDERADA => 'reverter.feito_desconsiderada',
            self::BATIDA_DE_ANTES       => 'reverter.feito_com_batida',
            default                     => 'reverter.feito',
        };
    }

    /** A batida de antes da E10 continua na folha: a tela avisa, em vez de só confirmar. */
    public function pedeAtencao(): bool
    {
        return $this->batidaDoAbono === self::BATIDA_DE_ANTES;
    }
}
