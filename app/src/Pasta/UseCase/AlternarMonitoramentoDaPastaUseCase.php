<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PainelDoPushOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Processo\Repository\ProcessoRepository;
use Psr\Clock\ClockInterface;

/**
 * O switch "Monitoramento" do painel do Push (desenho 1.2.3, dc L.2572; spec
 * push-painel-monitoramento §1).
 *
 * História: o advogado não quer mais receber as publicações de um processo (encerrado na prática,
 * substabelecido) e pausa; mais tarde pode retomar. É alternância sobre o processo PRINCIPAL da
 * pasta e devolve o estado final. Pausar não apaga nada: só tira o número da captação do DJEN.
 * Como o processo é compartilhado, a pausa vale para todas as pastas que o têm.
 */
final class AlternarMonitoramentoDaPastaUseCase
{
    public function __construct(
        private readonly ProcessoRepository $processoRepository,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws PastaDeOutroEscritorioException pasta de outro escritório
     * @throws \DomainException                pasta sem processo vinculado
     */
    public function executar(Pasta $pasta, User $usuario, Tenant $tenant): PainelDoPushOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $principal = $pasta->getProcessoPrincipal();
        if ($principal === null) {
            throw new \DomainException('Vincule um processo para monitorar.');
        }

        $agora = $this->relogio->now();
        if ($principal->monitoramentoAtivo()) {
            $principal->pausarMonitoramento($usuario, $agora);
        } else {
            $principal->retomarMonitoramento();
        }
        $this->processoRepository->save($principal, true);

        return PainelDoPushOutput::doProcesso($principal, $agora, null);
    }
}
