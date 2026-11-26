<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\ParticipacaoDaPautaOutput;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\UnidadeDoLink;
use Psr\Clock\ClockInterface;

/**
 * Participação de uma pauta pelo link pessoal `/votar/{token}`, para a caixa "Acompanhar" se atualizar sozinha.
 *
 * Mesmo acesso da cédula: o token localiza a unidade e só as pautas da assembleia dela existem; pauta de outra
 * assembleia, retirada ou fora dos grupos da unidade devolve null (404). Devolve só contagens, nunca voto por opção.
 * Não encerra pauta com prazo vencido (isso é da cédula): só informa "encerrada" para a tela recarregar.
 */
final class ObterParticipacaoDaPautaUseCase
{
    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(string $token, int $pautaId): ?ParticipacaoDaPautaOutput
    {
        $unidade = $this->link->localizar($token);
        if (null === $unidade) {
            return null;
        }
        $assembleia = $unidade->getAssembleia();
        foreach ($assembleia->getPautas() as $pauta) {
            if ((int) $pauta->getId() !== $pautaId || $pauta->retirada() || !$pauta->aceitaBloco($unidade->getBloco())) {
                continue;
            }
            $votaram = $this->participacoes->contarPorPauta($assembleia)[$pautaId]['unidades'] ?? 0;
            $aptas = $this->unidades->totaisAptos($assembleia, $pauta->getGrupos())['unidadesAptas'];

            return new ParticipacaoDaPautaOutput($this->estado($pauta), $votaram, $aptas);
        }

        return null;
    }

    private function estado(PautaDaAssembleia $pauta): string
    {
        if ($pauta->cancelada()) {
            return 'cancelada';
        }
        if (EstadoDaPauta::Encerrada === $pauta->getEstado() || $pauta->prazoVencidoEm($this->relogio->now())) {
            return 'encerrada';
        }

        return EstadoDaPauta::Aberta === $pauta->getEstado() ? ($pauta->suspensa() ? 'suspensa' : 'aberta') : 'aguardando';
    }
}
