<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário /admin/inteligencia. Propriedades públicas e mutáveis porque o Form
 * Component escreve nelas (exceção documentada na skill `criar-dto`).
 */
final class ConfiguracaoDeInteligenciaInput
{
    public bool $habilitada = false;

    #[Assert\NotNull(message: 'Informe o limite diário.')]
    #[Assert\Range(min: 0, max: 100000, notInRangeMessage: 'O limite diário deve ficar entre {{ min }} e {{ max }}.')]
    public ?int $limiteDiario = 50;

    #[Assert\NotNull(message: 'Informe o limite mensal.')]
    #[Assert\Range(min: 0, max: 1000000, notInRangeMessage: 'O limite mensal deve ficar entre {{ min }} e {{ max }}.')]
    public ?int $limiteMensal = 500;

    public bool $mascararDadosPessoais = true;

    public static function fromEntity(?ConfiguracaoDeInteligencia $configuracao): self
    {
        $input = new self();
        if ($configuracao === null) {
            return $input;
        }

        $input->habilitada = $configuracao->isHabilitada();
        $input->limiteDiario = $configuracao->getLimiteDiario();
        $input->limiteMensal = $configuracao->getLimiteMensal();
        $input->mascararDadosPessoais = $configuracao->isMascararDadosPessoais();

        return $input;
    }
}
