<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da EDIÇÃO (correção de cadastro) de uma obrigação (ajuste 5). Permite corrigir todos os campos
 * de cadastro, inclusive os encargos reconhecidos (unificado — "Reconhecer valor" foi aposentado). NÃO é
 * movimento operacional: é correção auditada, com `motivo` obrigatório (SPEC §10, "ajuste manual exige
 * motivo e preserva histórico"). Os guards (caso encerrado, parcela/substituída, valor abaixo do alocado)
 * e a resolução por tenant vivem no EditarObrigacaoUseCase. `obrigacaoId` vem da rota.
 *
 * Encargos separados (F4, spec §11): o agregado `encargosReconhecidos` SAIU daqui — a UI edita
 * juros/multa/correção um a um, e o agregado volta a ser o que sempre deveria ter sido, um DERIVADO
 * (`Obrigacao::getEncargosReconhecidos()` = juros + multa + correção, INV-E1). Os honorários NÃO entram:
 * são materializados pelo motor de cálculo e a UI de edição não os toca.
 */
final class EditarObrigacaoInput
{
    #[Assert\NotNull(message: 'Informe a obrigação.')]
    #[Assert\Positive(message: 'Obrigação inválida.')]
    public ?int $obrigacaoId = null;

    #[Assert\NotBlank(message: 'Informe a descrição.')]
    #[Assert\Length(max: 255)]
    public ?string $descricao = null;

    /** Valor original em CENTAVOS. */
    #[Assert\NotNull(message: 'Informe o valor original.')]
    #[Assert\Positive(message: 'O valor original deve ser positivo.')]
    public ?int $valorOriginal = null;

    #[Assert\NotNull(message: 'Informe o vencimento original.')]
    public ?\DateTimeImmutable $vencimentoOriginal = null;

    #[Assert\Length(max: 255)]
    public ?string $referenciaExterna = null;

    /**
     * Encargos reconhecidos, SEPARADOS, em CENTAVOS. Zero é válido (zera o reconhecimento anterior) e é
     * o default: nenhum dos três pode ser obrigatório, senão todo POST que não os manda (payload antigo,
     * teste de guard, chamador programático) passaria a morrer na validação em vez de chegar ao UseCase.
     */
    #[Assert\PositiveOrZero(message: 'Os juros não podem ser negativos.')]
    public int $juros = 0;

    #[Assert\PositiveOrZero(message: 'A multa não pode ser negativa.')]
    public int $multa = 0;

    #[Assert\PositiveOrZero(message: 'A correção não pode ser negativa.')]
    public int $correcao = 0;

    #[Assert\NotBlank(message: 'Informe o motivo da correção.')]
    #[Assert\Length(max: 255)]
    public ?string $motivo = null;
}
