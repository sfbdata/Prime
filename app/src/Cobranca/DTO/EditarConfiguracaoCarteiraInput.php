<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário de edição da CONFIGURAÇÃO de uma Carteira de Cobrança já existente (SPEC §4/§18).
 * Edita apenas as regras/padrões de operação — não altera nome nem o cliente credor (invariável 4). A
 * resolução da carteira por id (guarda multi-tenant) e a persistência ocorrem no
 * EditarConfiguracaoCarteiraUseCase — aqui só se validam formato e presença dos campos.
 */
final class EditarConfiguracaoCarteiraInput
{
    #[Assert\NotNull(message: 'Informe a carteira.')]
    #[Assert\Positive(message: 'Carteira inválida.')]
    public ?int $carteiraId = null;

    public ModoCarteira $modo = ModoCarteira::Unico;

    public FormaHonorarios $formaHonorarios = FormaHonorarios::SemPercentual;

    /** Percentual padrão (formato "ponto", ex.: "10.50"); casa com o decimal(5,2) — até 3 inteiros e 2 casas. */
    #[Assert\Regex(
        pattern: '/^\d{1,3}(\.\d{1,2})?$/',
        message: 'O percentual de honorários deve ser um número válido (ex.: 10,00).',
    )]
    public ?string $percentualHonorarios = null;

    #[Assert\PositiveOrZero(message: 'A tolerância de atraso não pode ser negativa.')]
    public int $toleranciaAtrasoDias = 0;

    public ?TipoVinculo $tipoVinculoPreferido = null;

    #[Assert\Length(max: 50, maxMessage: 'O rótulo do objeto pode ter no máximo {{ limit }} caracteres.')]
    public ?string $rotuloObjeto = null;

    // --- Encargos por atraso (spec "encargos configuráveis em cascata" §4.1) -------------------
    // Estes 9 campos são o NÍVEL 1 da cascata (Carteira → Objeto → Obrigação): valem como padrão
    // para as NOVAS cobranças; mudá-los aqui não recalcula caso/obrigação já existente (spec §5).
    // Taxas em BASIS POINTS (100 bp = 1%). O teto de 100000 bp (1000% a.m.) é freio de sanidade:
    // taxa absurda no regime composto estoura o motor (EncargosInexequiveisException) — melhor
    // barrar como erro de campo aqui do que deixar chegar no cálculo.

    /** Juros de mora ao mês, em basis points (100 bp = 1% a.m.); pró-rata diária no motor. */
    #[Assert\PositiveOrZero(message: 'A taxa de juros não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'A taxa de juros ao mês é alta demais (máximo 1.000%).')]
    public int $taxaJurosMensalBp = 0;

    /** Regime de capitalização dos juros: simples (default, provado nos dados) ou composto. */
    public RegimeJuros $regimeJuros = RegimeJuros::Simples;

    /** Multa por atraso, em basis points (ex.: 200 bp = 2%); aplicação única, não cresce no tempo. */
    #[Assert\PositiveOrZero(message: 'A taxa de multa não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'A taxa de multa é alta demais (máximo 1.000%).')]
    public int $taxaMultaBp = 0;

    /** Base de incidência da multa: principal puro (fixa) ou base composta (progressiva). */
    public BaseEncargo $baseMulta = BaseEncargo::Principal;

    /** Correção monetária, em basis points; default 0 (não usada na operação de referência). */
    #[Assert\PositiveOrZero(message: 'A taxa de correção não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'A taxa de correção é alta demais (máximo 1.000%).')]
    public int $taxaCorrecaoBp = 0;

    /** Base de incidência da correção monetária: principal puro (fixa) ou composta (progressiva). */
    public BaseEncargo $baseCorrecao = BaseEncargo::Principal;

    /** Base dos honorários: composta (principal + juros + multa + correção) por default. */
    public BaseEncargo $baseHonorarios = BaseEncargo::Composta;

    /** Dias de carência dos honorários; null = usa a tolerância de atraso da própria carteira. */
    #[Assert\PositiveOrZero(message: 'A carência dos honorários não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 3650, message: 'A carência dos honorários pode ter no máximo {{ compared_value }} dias.')]
    public ?int $carenciaHonorariosDias = null;

    /** Dias de carência de juros e multa; default 0 = ambos valem desde o 1º dia de atraso. */
    #[Assert\PositiveOrZero(message: 'A carência de juros e multa não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 3650, message: 'A carência de juros e multa pode ter no máximo {{ compared_value }} dias.')]
    public int $toleranciaJurosMultaDias = 0;
}
