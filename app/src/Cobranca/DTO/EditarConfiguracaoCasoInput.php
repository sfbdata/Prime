<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada do formulário de edição dos HONORÁRIOS de um Caso de Cobrança já existente (Ajuste 2,
 * Fatia A / decisão D-A2-2). Edita SÓ a config de honorários do caso âncora — forma, percentual,
 * base e carência —; juros/multa/correção por caso ficam fora do escopo (o caso os herda da
 * carteira). A resolução do caso por id (guarda multi-tenant), a persistência e o recálculo
 * imediato das automáticas ocorrem no EditarConfiguracaoCasoUseCase — aqui só se validam formato e
 * presença dos campos.
 */
final class EditarConfiguracaoCasoInput
{
    #[Assert\NotNull(message: 'Informe o caso.')]
    #[Assert\Positive(message: 'Caso inválido.')]
    public ?int $casoId = null;

    /** Snapshot da forma de honorários do caso (NOT NULL na entidade). */
    public FormaHonorarios $formaHonorarios = FormaHonorarios::SemPercentual;

    /** Percentual (formato "ponto", ex.: "10.50"); casa com o decimal(5,2) — até 3 inteiros e 2 casas. */
    #[Assert\Regex(
        pattern: '/^\d{1,3}(\.\d{1,2})?$/',
        message: 'O percentual de honorários deve ser um número válido (ex.: 10,00).',
    )]
    public ?string $percentualHonorarios = null;

    /** Base dos honorários (override nullable no caso): null = herda a carteira. */
    public ?BaseEncargo $baseHonorarios = null;

    /** Dias de carência dos honorários (override nullable): null = herda a carência da carteira. */
    #[Assert\PositiveOrZero(message: 'A carência dos honorários não pode ser negativa.')]
    #[Assert\LessThanOrEqual(value: 3650, message: 'A carência dos honorários pode ter no máximo {{ compared_value }} dias.')]
    public ?int $carenciaHonorariosDias = null;

    /**
     * Footgun de dinheiro (achado da revisão da Fatia A): uma forma percentual (ex.: acréscimo à dívida)
     * com o percentual EM BRANCO faz a alíquota resolver para 0 e o recálculo imediato ZERAR os honorários
     * de todas as automáticas vivas do caso, na hora e em silêncio. Ao contrário da carteira (onde o
     * efeito só atinge cobranças novas), aqui o dinheiro já materializado muda no mesmo POST. Então: forma
     * que EXIGE percentual obriga percentual preenchido. `SemPercentual` (zerar de propósito) segue livre.
     */
    #[Assert\Callback]
    public function validarPercentualQuandoExigido(ExecutionContextInterface $context): void
    {
        if (!$this->formaHonorarios->exigePercentual()) {
            return;
        }

        if ($this->percentualHonorarios === null || trim($this->percentualHonorarios) === '') {
            $context->buildViolation('Esta forma de honorários exige um percentual.')
                ->atPath('percentualHonorarios')
                ->addViolation();
        }
    }
}
