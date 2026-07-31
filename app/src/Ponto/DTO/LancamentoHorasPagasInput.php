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

    /** Mínimo de caracteres do motivo depois do `trim` (spec §3). */
    public const MOTIVO_MINIMO = 3;

    /**
     * Teto de sanidade, NÃO teto de negócio: o dono recusou limite de negócio de propósito
     * (100.000h são ~11 anos ininterruptos, muito além de qualquer uso real). Sem ele,
     * `horas=100000000` faz `minutosComSinal()` devolver 6.000.000.000, que estoura o `integer` do
     * Postgres na hora do INSERT — erro 500 no lugar de uma mensagem de recusa.
     */
    public const HORAS_MAXIMAS = 100000;

    #[Assert\NotBlank]
    #[Assert\Range(min: 2000, max: 2999)]
    public int $ano = 0;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 12)]
    public int $mes = 0;

    #[Assert\Choice(choices: [self::OPERACAO_DESCONTAR, self::OPERACAO_ACRESCENTAR])]
    public string $operacao = self::OPERACAO_DESCONTAR;

    // Só `notInRangeMessage`: o Range recusa `minMessage`/`maxMessage` quando `min` e `max` estão
    // ambos definidos (ConstraintDefinitionException, 500 em toda submissão do formulário).
    #[Assert\Range(
        min: 0,
        max: self::HORAS_MAXIMAS,
        notInRangeMessage: 'A quantidade de horas tem de ficar entre {{ min }} e {{ max }}.',
    )]
    public int $horas = 0;

    #[Assert\Range(min: 0, max: 59)]
    public int $minutos = 0;

    // `normalizer: 'trim'` nos dois: `NotBlank` sozinho aceita '   ' (só ''/null/[] são vazios para
    // ele) e `Length` contaria os espaços como caracteres — "  a  " passaria pelo mínimo de 3 sem
    // dizer nada. O motivo é a única defesa documental de um lançamento que mexe em verba
    // trabalhista; "ok" não explica pagamento nenhum.
    #[Assert\NotBlank(message: 'Informe o motivo do lançamento.', normalizer: 'trim')]
    #[Assert\Length(
        min: self::MOTIVO_MINIMO,
        minMessage: 'O motivo precisa ter ao menos {{ limit }} caracteres.',
        normalizer: 'trim',
    )]
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
