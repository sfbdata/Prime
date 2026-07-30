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
 * Taxa por-obrigação (spec taxa-por-obrigacao): o gestor não digita mais um VALOR de encargo — para
 * cada um dos quatro encargos (juros/multa/correção/honorários) ele pode definir uma TAXA própria
 * desta obrigação, por `modo` ('herda'|'percent'|'reais'). Em 'percent' o `xBp` (basis points) é
 * gravado direto; em 'reais' o `xReais` (centavos) é convertido em bp À DATA DE HOJE pelo
 * `ConversorTaxaEncargo` (o espelho %↔R$ na tela é só JS de preview). `modo === 'herda'` (default)
 * grava `null` — a obrigação nasce sem override próprio, usando a taxa efetiva da cascata
 * Carteira→Caso. Todas as taxas são opcionais; o caso comum continua sendo nascer sem nenhum
 * override e deixar o motor calcular a partir da configuração herdada.
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
     * Competência (`MM/AAAA`) — o mês a que a dívida se refere. Junto da referência externa forma a chave
     * de idempotência da importação (spec `cobranca-importar-chave-competencia.md`). Nula em cadastro
     * manual; a entidade normaliza e descarta formato inválido.
     */
    #[Assert\Regex(pattern: '#^\d{2}/\d{4}$#', message: 'A competência deve estar no formato MM/AAAA.')]
    public ?string $competencia = null;

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
}
