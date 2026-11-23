<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\LimitesDoBancoDeHorasDoEscritorio;
use App\Ponto\UseCase\AlterarLimitesDoBancoDoEscritorioUseCase;

/**
 * O limite ou o prazo do banco de horas pedido está fora da faixa (E17, P73): 422, e nada muda. A mensagem é escrita
 * para quem está na tela e aparece embaixo do campo.
 */
final class LimitesDoBancoRecusadosException extends RecusaDoPonto
{
    public const RECUSA_LIMITE_ABAIXO_DO_MINIMO = 'O limite do banco precisa ser de pelo menos %d hora.';
    public const RECUSA_LIMITE_ACIMA_DO_MAXIMO = 'O limite do banco pode ser de até %d horas.';
    public const RECUSA_PRAZO_FORA_DA_FAIXA = 'O prazo de compensação precisa ser de %d a %d meses.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_LIMITE_ABAIXO_DO_MINIMO => 'ponto_servidor.recusa.limites_banco.limite_minimo',
        self::RECUSA_LIMITE_ACIMA_DO_MAXIMO => 'ponto_servidor.recusa.limites_banco.limite_maximo',
        self::RECUSA_PRAZO_FORA_DA_FAIXA => 'ponto_servidor.recusa.limites_banco.prazo',
    ];

    public static function limiteAbaixoDoMinimo(): self
    {
        return new self(self::RECUSA_LIMITE_ABAIXO_DO_MINIMO, ['minimo' => AlterarLimitesDoBancoDoEscritorioUseCase::LIMITE_MINIMO_HORAS]);
    }

    public static function limiteAcimaDoMaximo(): self
    {
        return new self(self::RECUSA_LIMITE_ACIMA_DO_MAXIMO, ['maximo' => AlterarLimitesDoBancoDoEscritorioUseCase::LIMITE_MAXIMO_HORAS]);
    }

    public static function prazoForaDaFaixa(): self
    {
        return new self(self::RECUSA_PRAZO_FORA_DA_FAIXA, [
            'minimo' => LimitesDoBancoDeHorasDoEscritorio::PRAZO_MINIMO_MESES,
            'maximo' => LimitesDoBancoDeHorasDoEscritorio::PRAZO_MAXIMO_MESES,
        ]);
    }
}
