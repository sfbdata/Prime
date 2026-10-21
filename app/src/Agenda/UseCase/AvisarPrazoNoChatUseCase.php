<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Service\AvisoDoCompromisso;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\UserRepository;

/**
 * História: na Agenda, no detalhe de um prazo ou audiência de pasta, a pessoa clica em "Avisar no Chat"
 * (desenho 09, L238: todo item que não é data do sistema) e escolhe colegas. Cada um recebe o resumo e o
 * atalho para a pasta. Colegas de outro escritório ou desligados são ignorados. O prazo não guarda a lista
 * de avisados (seria coluna nova na pasta): avisar de novo avisa de novo.
 *
 * Quem chama já conferiu que a pessoa tem "Ver prazos na agenda" (a Agenda só mostra prazos a quem tem). O
 * cadeado vale por cima: prazo de pasta oculta para a pessoa não existe para ela (spec pasta-cadeado D3).
 */
final class AvisarPrazoNoChatUseCase
{
    public function __construct(
        private readonly PastaPrazoRepository $prazos,
        private readonly UserRepository $usuarios,
        private readonly AvisoDoCompromisso $aviso,
        private readonly ?AcessoAPasta $acessoAPasta = null,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return int quantos foram avisados
     */
    public function executar(int $prazoId, array $ids, User $eu, Tenant $tenant): int
    {
        $prazo = $this->prazos->doEscritorio($prazoId, $tenant) ?? throw new CompromissoNaoEncontradoException();
        if (in_array($prazo->getPasta()->getId(), $this->acessoAPasta?->idsOcultosPara($eu, (int) $tenant->getId()) ?? [], true)) {
            throw new CompromissoNaoEncontradoException();
        }
        $ativos = array_filter($this->usuarios->findColaboradoresAtivosPorTenant($tenant), static fn (User $u): bool => in_array($u->getId(), $ids, true));

        return $this->aviso->avisarSobrePrazo($prazo, $ativos, $eu);
    }
}
