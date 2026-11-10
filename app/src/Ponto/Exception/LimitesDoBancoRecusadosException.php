<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\LimitesDoBancoDeHorasDoEscritorio;
use App\Ponto\UseCase\AlterarLimitesDoBancoDoEscritorioUseCase;

/**
 * O limite ou o prazo do banco de horas pedido está fora da faixa (E17, P73): 422, e nada muda. A mensagem é escrita
 * para quem está na tela e aparece embaixo do campo.
 */
final class LimitesDoBancoRecusadosException extends \DomainException
{
    public static function limiteAbaixoDoMinimo(): self
    {
        return new self(sprintf('O limite do banco precisa ser de pelo menos %d hora.', AlterarLimitesDoBancoDoEscritorioUseCase::LIMITE_MINIMO_HORAS));
    }

    public static function limiteAcimaDoMaximo(): self
    {
        return new self(sprintf('O limite do banco pode ser de até %d horas.', AlterarLimitesDoBancoDoEscritorioUseCase::LIMITE_MAXIMO_HORAS));
    }

    public static function prazoForaDaFaixa(): self
    {
        return new self(sprintf(
            'O prazo de compensação precisa ser de %d a %d meses.',
            LimitesDoBancoDeHorasDoEscritorio::PRAZO_MINIMO_MESES,
            LimitesDoBancoDeHorasDoEscritorio::PRAZO_MAXIMO_MESES,
        ));
    }
}
