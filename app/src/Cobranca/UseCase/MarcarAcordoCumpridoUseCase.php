<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\MarcarAcordoCumpridoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Marca um Acordo como cumprido (SPEC §12/§28): decisão MANUAL do gestor quando as parcelas foram
 * quitadas.
 *
 * História: o acordo é resolvido por id + tenant (guarda multi-tenant) e só transiciona se estiver
 * `ativo`. Cumprido é um estado VIGENTE — a substituição das obrigações e as parcelas continuam no
 * saldo derivado (invariável 20); nada é revertido aqui. O acordo e o evento "acordo cumprido" são
 * commitados juntos (flush único no evento).
 */
final class MarcarAcordoCumpridoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(MarcarAcordoCumpridoInput $input, Tenant $tenant, User $usuario): Acordo
    {
        // Guarda multi-tenant: o acordo tem de pertencer ao próprio escritório.
        $acordo = $this->acordoRepository->findOneByIdDoTenant((int) $input->acordoId, $tenant);

        if ($acordo === null) {
            throw new AcordoNaoEncontradoException((int) $input->acordoId);
        }

        // Só um acordo ativo transiciona de estado (SPEC §12).
        if (!$acordo->estaAtivo()) {
            throw new AcordoNaoAtivoException((int) $acordo->getId(), $acordo->getStatus());
        }

        $acordo->marcarCumprido();

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->acordoRepository->salvar($acordo);

        $this->registrarEvento->registrar(
            $acordo->getCaso(),
            TipoEventoHistorico::AcordoCumprido,
            $usuario,
            'Acordo cumprido',
            null,
            flush: true,
        );

        return $acordo;
    }
}
