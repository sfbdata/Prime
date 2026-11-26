<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\ParticipacaoDaPautaOutput;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\UnidadeDoLink;
use Psr\Clock\ClockInterface;

/**
 * Participação das pautas visíveis à unidade do link pessoal `/votar/{token}`, para a caixa "Acompanhar" se atualizar
 * sozinha com UMA requisição por cédula.
 *
 * Mesmo acesso da cédula: o token localiza a unidade e só as pautas da assembleia dela existem; retirada ou fora dos
 * grupos da unidade não entram. Assembleia não publicada, token inválido ou escritório sem o Voto liberado: null (404).
 * Devolve só contagens de unidades aptas, nunca voto por opção. Não encerra pauta com prazo vencido (isso é da cédula):
 * só informa "encerrada" para a tela recarregar.
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

    /** @return list<ParticipacaoDaPautaOutput>|null */
    public function executar(string $token): ?array
    {
        $unidade = $this->link->localizar($token);
        $assembleia = $unidade?->getAssembleia();
        if (null === $unidade || null === $assembleia || EstadoDaAssembleia::Publicada !== $assembleia->getEstado()) {
            return null;
        }
        $votaram = $this->participacoes->contarAptasPorPauta($assembleia);
        $aptosPorGrupos = [];
        $lista = [];
        foreach ($assembleia->getPautas() as $pauta) {
            if ($pauta->retirada() || !$pauta->aceitaBloco($unidade->getBloco())) {
                continue;
            }
            $aptas = $aptosPorGrupos[implode('|', $pauta->getGrupos())] ??= $this->unidades->totaisAptos($assembleia, $pauta->getGrupos())['unidadesAptas'];
            $id = (int) $pauta->getId();
            $lista[] = new ParticipacaoDaPautaOutput($id, $this->estado($pauta), min($votaram[$id] ?? 0, $aptas), $aptas);
        }

        return $lista;
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
