<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PainelDoPushOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\ProcessoForaDaPastaException;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use Psr\Clock\ClockInterface;

/**
 * Os switches de monitoramento do painel do Push (desenho 1.2.3, dc L.2572; spec
 * push-painel-monitoramento §1): o do cabeçalho é o do processo principal, e cada outro processo da
 * pasta tem o seu.
 *
 * História: o advogado não quer mais receber as publicações de um processo (encerrado na prática,
 * substabelecido) e pausa; mais tarde pode retomar. É alternância sobre UM processo DESTA pasta e
 * devolve o cartão já com o estado final. Pausar não apaga nada: só tira o número da captação do
 * DJEN. Guarda quem mexeu por último e quando (pausando ou retomando). Como o processo é
 * compartilhado, a pausa vale para todas as pastas que o têm.
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
     * @throws ProcessoForaDaPastaException    o processo não é desta pasta
     */
    public function executar(Pasta $pasta, int $processoId, User $usuario, Tenant $tenant): PainelDoPushOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $processo = $this->processoDaPasta($pasta, $processoId);
        $agora = $this->relogio->now();
        if ($processo->monitoramentoAtivo()) {
            $processo->pausarMonitoramento($usuario, $agora);
        } else {
            $processo->retomarMonitoramento($usuario, $agora);
        }
        $this->processoRepository->save($processo, true);

        $principal = $pasta->getProcessoPrincipal();
        \assert($principal instanceof Processo);

        return PainelDoPushOutput::daPasta($pasta, $principal, $agora, null);
    }

    private function processoDaPasta(Pasta $pasta, int $processoId): Processo
    {
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            if ($vinculo->getProcesso()->getId() === $processoId) {
                return $vinculo->getProcesso();
            }
        }

        throw new ProcessoForaDaPastaException('Processo não encontrado nesta pasta.');
    }
}
