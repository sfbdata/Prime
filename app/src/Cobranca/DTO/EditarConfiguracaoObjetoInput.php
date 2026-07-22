<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário de edição da CONFIGURAÇÃO DE ENCARGOS de um Objeto de Cobrança já existente
 * (spec "cascata de encargos ao vivo sem snapshot" §4, #9-T3). O Objeto é o NÍVEL 2 (o "meio") da
 * cascata `Carteira → Objeto → Obrigação` (#9-T1): os 10 campos são TODOS overrides opcionais — vazio
 * (`null`) = herda a carteira, preenchido = vale para TODAS as obrigações de TODOS os casos deste
 * objeto. A resolução do objeto por id (guarda multi-tenant) e a persistência ocorrem no
 * EditarConfiguracaoObjetoUseCase — aqui só se validam formato e faixa dos campos.
 *
 * DESVIO da spec §4 (decisão registrada no relatório da T3): a spec pedia %↔R$ via
 * `ConversorTaxaEncargo`, que precisa de principal + vencimento + data de referência — dados que o
 * OBJETO não tem (ele agrega várias obrigações, sem um principal/vencimento únicos). Aqui só %
 * (basis points) via `TaxaBpType`, o mesmo estilo já usado na config da Carteira (nível 1).
 */
final class EditarConfiguracaoObjetoInput
{
    #[Assert\NotNull(message: 'Informe o objeto.')]
    #[Assert\Positive(message: 'Objeto inválido.')]
    public ?int $objetoId = null;

    /** Override do juros de mora ao mês, em basis points (100 bp = 1% a.m.); null = herda a carteira. */
    #[Assert\PositiveOrZero(message: 'A taxa de juros não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'A taxa de juros ao mês é alta demais (máximo 1.000%).')]
    public ?int $taxaJurosMensalBp = null;

    /** Override do regime de capitalização dos juros; null = herda a carteira. */
    public ?RegimeJuros $regimeJuros = null;

    /** Override da multa por atraso, em basis points; null = herda a carteira. */
    #[Assert\PositiveOrZero(message: 'A taxa de multa não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'A taxa de multa é alta demais (máximo 1.000%).')]
    public ?int $taxaMultaBp = null;

    /** Override da base de incidência da multa; null = herda a carteira. */
    public ?BaseEncargo $baseMulta = null;

    /** Override da correção monetária, em basis points; null = herda a carteira. */
    #[Assert\PositiveOrZero(message: 'A taxa de correção não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'A taxa de correção é alta demais (máximo 1.000%).')]
    public ?int $taxaCorrecaoBp = null;

    /** Override da base de incidência da correção monetária; null = herda a carteira. */
    public ?BaseEncargo $baseCorrecao = null;

    /**
     * Override da alíquota de honorários, em basis points (supersede D2, nível 2 da cascata — a mesma
     * amarração que a Obrigação já tem, spec "taxa por-obrigação"); null = herda a carteira (forma +
     * percentual convertidos em bp por `ResolvedorConfigEncargos::resolverDaCarteira`).
     */
    #[Assert\PositiveOrZero(message: 'A taxa de honorários não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'A taxa de honorários é alta demais (máximo 1.000%).')]
    public ?int $taxaHonorariosBp = null;

    /** Override da base de incidência dos honorários; null = herda a carteira. */
    public ?BaseEncargo $baseHonorarios = null;

    /** Override dos dias de carência dos honorários; null = herda a carteira. */
    #[Assert\PositiveOrZero(message: 'A carência dos honorários não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 3650, message: 'A carência dos honorários pode ter no máximo {{ compared_value }} dias.')]
    public ?int $carenciaHonorariosDias = null;

    /** Override dos dias de carência de juros e multa; null = herda a carteira. */
    #[Assert\PositiveOrZero(message: 'A carência de juros e multa não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 3650, message: 'A carência de juros e multa pode ter no máximo {{ compared_value }} dias.')]
    public ?int $toleranciaJurosMultaDias = null;
}
