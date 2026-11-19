<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Psr\Clock\ClockInterface;

/**
 * "Encerrar acesso" (desenho L4) e "Deixar de colaborar" (L6): encerra o compartilhamento na hora. Encerram: quem
 * compartilhou, o responsável da pasta, o primário e o Master; deixa: o próprio destino (spec §4.4).
 */
final class EncerrarCompartilhamentoDaPastaUseCase
{
    public function __construct(
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $pastaId, int $compartilhamentoId, User $quem, Tenant $tenant): PastaCompartilhamento
    {
        $compartilhamento = $this->compartilhamentos->doEscritorio($compartilhamentoId, $tenant);
        if ($compartilhamento === null || $compartilhamento->getPasta()->getId() !== $pastaId || !$this->podeEncerrar($compartilhamento, $quem, $tenant)) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::NAO_ENCERRA, daPessoa: true);
        }
        $compartilhamento->encerrar($quem, $this->relogio->now());
        $this->compartilhamentos->salvar($compartilhamento, flush: true);

        return $compartilhamento;
    }

    private function podeEncerrar(PastaCompartilhamento $compartilhamento, User $quem, Tenant $tenant): bool
    {
        $id = $quem->getId();
        if (in_array($id, [$compartilhamento->getOrigem()->getId(), $compartilhamento->getDestino()->getId(), $compartilhamento->getPasta()->getResponsavel()?->getId()], true)) {
            return true;
        }
        if ($this->regraDosPrimarios->ehMaster($quem)) {
            return true;
        }
        $vinculo = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);

        return $vinculo !== null && $this->regraDosPrimarios->ehPrimario($vinculo);
    }
}
