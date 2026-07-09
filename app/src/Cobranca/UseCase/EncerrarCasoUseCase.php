<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EncerrarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\SaldoNaoResolvidoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Encerra um Caso de Cobrança (SPEC §17, invariável 17): decisão sempre MANUAL do gestor.
 *
 * História: quem = o gestor do escritório; o quê = muda o caso para `encerrado`; por quê = a
 * situação financeira foi resolvida e a cobrança acabou. É o ÚNICO caminho para `encerrado`, e só é
 * permitido quando o saldo exigível derivado está zerado (invariável 20 — o saldo nunca é digitado).
 * Funciona tanto de caso `ativo` (extrajudicial) quanto `judicializado` — a fase judicial não muda a
 * regra de encerramento. Caso resolvido por id + tenant (guarda multi-tenant, invariável 1). O caso
 * e o evento "encerramento" são commitados juntos (flush único no evento).
 */
final class EncerrarCasoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(EncerrarCasoInput $input, Tenant $tenant, User $usuario): CasoCobranca
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Encerrar é idempotente por bloqueio: um caso já encerrado não reencerra (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Só encerra com a situação financeira resolvida (saldo exigível zero) — de Ativo ou Judicializado.
        $saldo = $this->calculadoraSaldo->saldoExigivel($caso);

        if ($saldo !== 0) {
            throw new SaldoNaoResolvidoException((int) $caso->getId(), $saldo);
        }

        $caso->setStatus(StatusCaso::Encerrado);

        // Persiste sem flush; o registro do evento fecha a transação (caso + evento juntos).
        $this->casoRepository->salvar($caso);

        $dados = null;
        if ($input->observacao !== null && $input->observacao !== '') {
            $dados = ['observacao' => $input->observacao];
        }

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::Encerramento,
            $usuario,
            'Caso encerrado.',
            $dados,
            flush: true,
        );

        return $caso;
    }
}
