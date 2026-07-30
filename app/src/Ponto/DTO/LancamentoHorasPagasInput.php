<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * O admin informa quantidade e sentido separados — nunca um número com sinal digitado à mão.
 * Errar o sinal num campo que mexe em banco de horas é invisível até alguém reclamar do salário.
 *
 * Propriedades públicas (não readonly) porque o Symfony Form precisa escrever nelas.
 */
final class LancamentoHorasPagasInput
{
    public const OPERACAO_DESCONTAR   = 'descontar';
    public const OPERACAO_ACRESCENTAR = 'acrescentar';

    #[Assert\NotBlank]
    #[Assert\Range(min: 2000, max: 2999)]
    public int $ano = 0;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 12)]
    public int $mes = 0;

    #[Assert\Choice(choices: [self::OPERACAO_DESCONTAR, self::OPERACAO_ACRESCENTAR])]
    public string $operacao = self::OPERACAO_DESCONTAR;

    #[Assert\GreaterThanOrEqual(0)]
    public int $horas = 0;

    #[Assert\Range(min: 0, max: 59)]
    public int $minutos = 0;

    #[Assert\NotBlank(message: 'Informe o motivo do lançamento.')]
    public string $motivo = '';

    /**
     * Quantidade total em minutos, já com o sinal da operação.
     */
    public function minutosComSinal(): int
    {
        $total = ($this->horas * 60) + $this->minutos;

        return $this->operacao === self::OPERACAO_DESCONTAR ? -$total : $total;
    }
}
