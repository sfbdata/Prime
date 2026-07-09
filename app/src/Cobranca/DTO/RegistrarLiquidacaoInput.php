<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\TipoLiquidacao;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do registro de uma Liquidação NÃO monetária num Caso de Cobrança (SPEC §11). O caso é
 * resolvido por id + tenant no RegistrarLiquidacaoUseCase (guarda multi-tenant). Valores em CENTAVOS
 * inteiros. Regra central (§11): o valor ATRIBUÍDO ao bem é OPCIONAL e pode DIFERIR do valor
 * RECONHECIDO — quem reduz o saldo é o `valorReconhecido`, nunca o `valorAtribuidoBem`; não se força
 * igualdade nem se deriva um do outro.
 */
final class RegistrarLiquidacaoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotNull(message: 'Informe o tipo da liquidação.')]
    public ?TipoLiquidacao $tipo = null;

    #[Assert\NotBlank(message: 'Descreva o bem ou direito da liquidação.')]
    #[Assert\Length(max: 255, maxMessage: 'A descrição pode ter no máximo {{ limit }} caracteres.')]
    public ?string $descricaoBem = null;

    /** Valor atribuído ao bem em CENTAVOS — OPCIONAL; pode diferir do reconhecido (§11). */
    #[Assert\Positive(message: 'O valor atribuído ao bem deve ser positivo.')]
    public ?int $valorAtribuidoBem = null;

    /** Valor reconhecido como extinto da dívida em CENTAVOS — é o que reduz o saldo (§11). */
    #[Assert\NotNull(message: 'Informe o valor reconhecido da liquidação.')]
    #[Assert\Positive(message: 'O valor reconhecido deve ser positivo.')]
    public ?int $valorReconhecido = null;

    #[Assert\NotNull(message: 'Informe a data da liquidação.')]
    public ?\DateTimeImmutable $data = null;
}
