<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da EDIÇÃO (correção de cadastro) de uma obrigação (ajuste 5). Permite corrigir os campos de
 * cadastro (descrição, valor, vencimento, referência) e a TAXA própria da obrigação. NÃO é movimento
 * operacional: é correção auditada, com `motivo` obrigatório (SPEC §10, "ajuste manual exige motivo e
 * preserva histórico"). Os guards (caso encerrado, parcela/substituída, valor abaixo do alocado) e a
 * resolução por tenant vivem no EditarObrigacaoUseCase. `obrigacaoId` vem da rota.
 *
 * Taxa por-obrigação (spec taxa-por-obrigacao): o gestor não digita mais um VALOR de encargo — para
 * cada um dos quatro encargos (juros/multa/correção/honorários) ele ajusta a TAXA própria desta
 * obrigação, por `modo` ('herda'|'percent'|'reais'). Em 'percent' o `xBp` é gravado direto; em 'reais'
 * o `xReais` (centavos) é convertido em bp À DATA DE HOJE pelo `ConversorTaxaEncargo`. `modo ===
 * 'herda'` (default) preserva o override existente (ou a herança, se nunca houve override) —
 * reabrir o modal sem tocar em nenhum campo não apaga nada. O agregado `encargosReconhecidos` é um
 * DERIVADO (`Obrigacao::getEncargosReconhecidos()` = juros + multa + correção, INV-E1); o cache dos
 * quatro encargos é sempre recalculado pelo motor com a config já com o override — a obrigação NUNCA
 * congela ao editar (D6).
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
     * Taxas por-obrigação (spec taxa-por-obrigacao). Por encargo: `modo` ('herda'|'percent'|'reais'),
     * o bp (quando %) e o R$ em centavos (quando R$). O UseCase chama `entradaTaxas()` e o
     * ConversorTaxaEncargo grava o override. Default 'herda' = usa a taxa do caso. Nada é obrigatório.
     */
    public string $modoJuros = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de juros não pode ser negativa.')]
    public ?int $jurosBp = null;
    #[Assert\PositiveOrZero(message: 'Os juros não podem ser negativos.')]
    public ?int $jurosReais = null;

    public string $modoMulta = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de multa não pode ser negativa.')]
    public ?int $multaBp = null;
    #[Assert\PositiveOrZero(message: 'A multa não pode ser negativa.')]
    public ?int $multaReais = null;

    public string $modoCorrecao = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de correção não pode ser negativa.')]
    public ?int $correcaoBp = null;
    #[Assert\PositiveOrZero(message: 'A correção não pode ser negativa.')]
    public ?int $correcaoReais = null;

    public string $modoHonorarios = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de honorários não pode ser negativa.')]
    public ?int $honorariosBp = null;
    #[Assert\PositiveOrZero(message: 'O honorário não pode ser negativo.')]
    public ?int $honorariosReais = null;

    public function entradaTaxas(): EntradaTaxaEncargos
    {
        return new EntradaTaxaEncargos(
            modoJuros: $this->modoJuros,
            jurosBp: $this->jurosBp,
            jurosReais: $this->jurosReais,
            modoMulta: $this->modoMulta,
            multaBp: $this->multaBp,
            multaReais: $this->multaReais,
            modoCorrecao: $this->modoCorrecao,
            correcaoBp: $this->correcaoBp,
            correcaoReais: $this->correcaoReais,
            modoHonorarios: $this->modoHonorarios,
            honorariosBp: $this->honorariosBp,
            honorariosReais: $this->honorariosReais,
        );
    }

    #[Assert\NotBlank(message: 'Informe o motivo da correção.')]
    #[Assert\Length(max: 255)]
    public ?string $motivo = null;
}
