<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CancelarAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AcordoComParcelasRenegociadasException;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cancela MANUALMENTE um Acordo (SPEC §12): decisão do gestor de descartar o acordo (ex.: firmado
 * por engano).
 *
 * História: o acordo é resolvido por id + tenant (guarda multi-tenant) e só cancela se estiver
 * `ativo`; o motivo é OPCIONAL (diferente do rompimento). Cancelar só muda o STATUS — NENHUMA
 * reversão de saldo é feita aqui: a CalculadoraSaldo restaura os originais e descarta as parcelas
 * por derivação (invariável 20). O acordo e o evento "acordo cancelado" são commitados juntos
 * (flush único no evento).
 */
final class CancelarAcordoUseCase
{
    public function __construct(
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(CancelarAcordoInput $input, Tenant $tenant, User $usuario): Acordo
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

        // Ajuste 9 §2.1: mesmo vetor do rompimento — cancelar também deixa o acordo NÃO vigente, e com
        // parcelas renegociadas por um acordo vigente isso duplicaria a dívida no saldo. Só alcança dado
        // legado (a criação do estado está bloqueada por INV-I).
        $renegociadas = $this->obrigacaoRepository->parcelasRenegociadasPorAcordoVigente($acordo);

        if ($renegociadas !== []) {
            throw new AcordoComParcelasRenegociadasException(
                (int) $acordo->getId(),
                (int) $renegociadas[0]->getAcordoSubstituto()?->getId(),
            );
        }

        $motivo = $this->normalizarMotivo($input->motivo);
        $acordo->cancelar($motivo);

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->acordoRepository->salvar($acordo);

        $this->registrarEvento->registrar(
            $acordo->getCaso(),
            TipoEventoHistorico::AcordoCancelado,
            $usuario,
            'Acordo cancelado',
            ['motivo' => $motivo],
            flush: true,
        );

        return $acordo;
    }

    private function normalizarMotivo(?string $motivo): ?string
    {
        if ($motivo === null) {
            return null;
        }

        $motivo = trim($motivo);

        return $motivo !== '' ? $motivo : null;
    }
}
