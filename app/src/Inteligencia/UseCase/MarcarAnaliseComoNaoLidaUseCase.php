<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * "Marcar como não lido" no menu e nas ações do cartão (IA-011, desenho dc L5159-5160). Quem: quem
 * pode ver a pasta e usar a IA. O quê: zera `lida_em` e `lida_por` da análise. Idempotente.
 *
 * Mesma guarda de IDOR do "marcar como lida": a análise tem de ser do escritório E da pasta da
 * URL e não estar excluída.
 *
 * @throws AnaliseNaoEncontradaException
 */
final class MarcarAnaliseComoNaoLidaUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(int $analiseId, int $pastaId, Tenant $tenant): AnaliseOutput
    {
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId);
        if ($analise === null || $analise->estaExcluida()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $analise->marcarNaoLida();
        $this->analises->salvar($analise, true);

        return AnaliseOutput::fromEntity($analise);
    }
}
