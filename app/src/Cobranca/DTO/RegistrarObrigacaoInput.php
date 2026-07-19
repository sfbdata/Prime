<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do lançamento de Obrigação num Caso de Cobrança (SPEC §10). O caso é resolvido por
 * id + tenant no RegistrarObrigacaoUseCase (guarda multi-tenant). O valor é informado em CENTAVOS
 * inteiros e, junto do vencimento, preserva-se como ORIGINAL (invariável 20). A normalização final
 * da referência externa (trim; null se vazio) ocorre no UseCase.
 *
 * Encargos separados (F4, spec §11): a obrigação lançada à mão pode já NASCER com encargos reconhecidos
 * (dívida antiga trazida de outro sistema, boleto já com juros calculados). São opcionais e default 0 —
 * o caso comum continua sendo nascer zerada e deixar o cron calcular.
 */
final class RegistrarObrigacaoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotBlank(message: 'Informe a descrição da obrigação.')]
    #[Assert\Length(max: 255, maxMessage: 'A descrição pode ter no máximo {{ limit }} caracteres.')]
    public ?string $descricao = null;

    /** Valor original em CENTAVOS inteiros (invariável 20). */
    #[Assert\NotNull(message: 'Informe o valor da obrigação.')]
    #[Assert\Positive(message: 'O valor da obrigação deve ser positivo.')]
    public ?int $valorOriginal = null;

    #[Assert\NotNull(message: 'Informe o vencimento original da obrigação.')]
    public ?\DateTimeImmutable $vencimentoOriginal = null;

    #[Assert\Length(max: 255, maxMessage: 'A referência externa pode ter no máximo {{ limit }} caracteres.')]
    public ?string $referenciaExterna = null;

    /**
     * Encargos já reconhecidos no lançamento, em CENTAVOS. Opcionais (default 0) — nenhum pode virar
     * obrigatório: o importador e todo chamador programático montam este DTO sem eles.
     */
    #[Assert\PositiveOrZero(message: 'Os juros não podem ser negativos.')]
    public int $juros = 0;

    #[Assert\PositiveOrZero(message: 'A multa não pode ser negativa.')]
    public int $multa = 0;

    #[Assert\PositiveOrZero(message: 'A correção não pode ser negativa.')]
    public int $correcao = 0;
}
