<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RomperAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Rompe MANUALMENTE um Acordo (SPEC §12.9): decisão do gestor quando o devedor descumpre o acordo.
 *
 * História: o acordo é resolvido por id + tenant (guarda multi-tenant) e só rompe se estiver `ativo`;
 * o motivo é obrigatório e fica registrado. Romper só muda o STATUS — NENHUMA reversão de saldo é
 * feita aqui: a CalculadoraSaldo restaura os originais e descarta as parcelas por derivação
 * (invariável 20). O acordo e o evento "acordo rompido" são commitados juntos (flush único no evento).
 */
final class RomperAcordoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(RomperAcordoInput $input, Tenant $tenant, User $usuario): Acordo
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

        $motivo = trim((string) $input->motivo);
        $acordo->romper($motivo);

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->acordoRepository->salvar($acordo);

        $this->registrarEvento->registrar(
            $acordo->getCaso(),
            TipoEventoHistorico::AcordoRompido,
            $usuario,
            sprintf('Acordo rompido: %s', $motivo),
            ['motivo' => $motivo],
            flush: true,
        );

        return $acordo;
    }
}
