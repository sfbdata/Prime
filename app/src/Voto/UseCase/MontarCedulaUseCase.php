<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\DTO\CedulaOutput;
use App\Voto\DTO\OpcaoNaCedulaOutput;
use App\Voto\DTO\PautaNaCedulaOutput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ApuracaoDaPauta;
use App\Voto\Service\ComprovanteDoVoto;
use App\Voto\Service\OrdemDasCandidaturasNaCedula;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\Service\UnidadeDoLink;
use Psr\Clock\ClockInterface;

/**
 * Monta a cédula da unidade aberta pelo link pessoal `/votar/{token}` (spec docs/specs/voto-assembleia-condominio.md,
 * seção 1, Morador): a assembleia, a unidade e cada pauta com o estado dela.
 *
 * É a "primeira leitura depois do prazo" do contrato: pauta aberta com o prazo vencido é encerrada aqui, com a linha
 * travada (a mesma trava do voto e do encerramento pela mesa), antes de montar a página.
 *
 * Pauta com grupos aptos só aparece para a unidade de um deles, e o peso apto dela (base do quórum) é o dos grupos. A
 * ordem das opções segue a pauta (de registro ou sorteada a cada leitura). Pauta de voto aberto leva o aviso.
 *
 * Mostra quando a unidade votou, nunca em quê. Totais só na pauta encerrada, pelo ApuracaoDaPauta; nunca parcial.
 * Devolve null quando o link não leva a nenhuma cédula (a página responde 404).
 */
final class MontarCedulaUseCase
{
    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly ParticipacaoNaPautaRepository $participacoes,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly ApuracaoDaPauta $apuracao,
        private readonly TransacaoDoVoto $transacao,
        private readonly ClockInterface $relogio,
        private readonly ComprovanteDoVoto $comprovante,
        private readonly OrdemDasCandidaturasNaCedula $ordem,
    ) {
    }

    public function executar(string $token): ?CedulaOutput
    {
        $unidade = $this->link->localizar($token);
        if (null === $unidade) {
            return null;
        }
        $assembleia = $unidade->getAssembleia();
        $agora = $this->relogio->now();
        $this->encerrarPautasComPrazoVencido($assembleia, $agora);

        return $this->cedula($unidade, $this->pautasDaCedula($unidade, $agora));
    }

    private function encerrarPautasComPrazoVencido(Assembleia $assembleia, \DateTimeImmutable $agora): void
    {
        foreach ($assembleia->getPautas() as $pauta) {
            if ($pauta->prazoVencidoEm($agora)) {
                $this->encerrarComATravaDaPauta((int) $pauta->getId(), $assembleia, $agora);
            }
        }
    }

    private function encerrarComATravaDaPauta(int $pautaId, Assembleia $assembleia, \DateTimeImmutable $agora): void
    {
        // Relida com a trava: a mesa pode ter encerrado antes, com outro motivo, e ele vale.
        $this->transacao->executar(
            fn (): bool => $this->pautas->buscarTravadaDaAssembleia($pautaId, $assembleia)?->encerrarSePrazoVenceu($agora) ?? false,
        );
    }

    /** @return list<PautaNaCedulaOutput> */
    private function pautasDaCedula(UnidadeDaAssembleia $unidade, \DateTimeImmutable $agora): array
    {
        $votouEm = $this->participacoes->horariosDaUnidade($unidade);
        $aptosPorGrupos = [];
        $presencas = $this->participacoes->contarPorPauta($unidade->getAssembleia());
        $pautas = [];
        foreach ($unidade->getAssembleia()->getPautas() as $pauta) {
            // Retirada da ordem do dia some da cédula; fora dos grupos aptos da pauta a unidade nem a vê.
            if ($pauta->retirada() || !$pauta->aceitaBloco($unidade->getBloco())) {
                continue;
            }
            $aptos = $aptosPorGrupos[implode('|', $pauta->getGrupos())] ??= $this->unidades->totaisAptos($unidade->getAssembleia(), $pauta->getGrupos());
            if (self::apurada($pauta)) {
                $this->pautas->relerContadores($pauta);
            }
            $horario = $votouEm[(int) $pauta->getId()] ?? null;
            $pautas[] = $this->pautaNaCedula($pauta, $unidade, $agora, $horario, $aptos, $presencas[(int) $pauta->getId()] ?? null);
        }

        return $pautas;
    }

    /**
     * @param array{pesoApto: string, unidadesAptas: int}   $aptos     o peso apto dos grupos da pauta
     * @param array{unidades: int, peso: string}|null       $presenca
     */
    private function pautaNaCedula(PautaDaAssembleia $pauta, UnidadeDaAssembleia $unidade, \DateTimeImmutable $agora, ?\DateTimeImmutable $votouEm, array $aptos, ?array $presenca): PautaNaCedulaOutput
    {
        return new PautaNaCedulaOutput(
            id: (int) $pauta->getId(),
            titulo: $pauta->getTitulo(),
            descricao: $pauta->getDescricao(),
            estado: $pauta->getEstado(),
            votouEm: $votouEm,
            segundosAtePrazo: self::segundosAtePrazo($pauta, $agora),
            opcoes: $pauta->aceitaVotoEm($agora) ? $this->opcoes($pauta) : [],
            resultado: self::apurada($pauta) ? $this->apuracao->apurar($pauta, $aptos['pesoApto'], $aptos['unidadesAptas'], $presenca) : null,
            vagas: $pauta->getVagas(),
            digitos: $pauta->getDigitos(),
            tipo: $pauta->getTipo(),
            suspensa: $pauta->suspensa(),
            cancelada: $pauta->cancelada(),
            votoAberto: $pauta->votoAberto(),
            grupos: $pauta->getGrupos(),
            comprovante: null === $votouEm ? null : $this->comprovante->codigo((int) $unidade->getId(), (int) $pauta->getId(), $votouEm),
        );
    }

    /** @param list<PautaNaCedulaOutput> $pautas */
    private function cedula(UnidadeDaAssembleia $unidade, array $pautas): CedulaOutput
    {
        $assembleia = $unidade->getAssembleia();

        return new CedulaOutput(
            assembleia: $assembleia->getTitulo(),
            assembleiaPublicada: EstadoDaAssembleia::Publicada === $assembleia->getEstado(),
            unidade: $unidade->getIdentificacao(),
            bloco: $unidade->getBloco(),
            apta: $unidade->isApta(),
            porFracao: PesoDoVoto::Fracao === $assembleia->getPeso(),
            pautas: $pautas,
            email: $unidade->getEmail() ?? '',
            entidade: $assembleia->getEntidadeNome() ?? '',
            cenario: $assembleia->getCenario(),
            simulacao: $assembleia->isSimulacao(),
        );
    }

    /** @return list<OpcaoNaCedulaOutput> as opções de voto pelo número e a abstenção por último */
    private function opcoes(PautaDaAssembleia $pauta): array
    {
        return array_map(static fn (OpcaoDaPauta $o): OpcaoNaCedulaOutput => new OpcaoNaCedulaOutput(
            (int) $o->getId(),
            $o->getNumero(),
            $o->getRotulo(),
            $o->isAbstencao(),
            $o->getDescricao(),
            $o->getComposicao(),
            null !== $o->getFoto(),
        ), $this->ordem->ordenar($pauta));
    }

    private static function segundosAtePrazo(PautaDaAssembleia $pauta, \DateTimeImmutable $agora): ?int
    {
        $prazo = $pauta->getPrazoAte();
        if (null === $prazo || !$pauta->aceitaVotoEm($agora)) {
            return null;
        }

        return max(0, $prazo->getTimestamp() - $agora->getTimestamp());
    }

    /** Encerrada e não cancelada: a cancelada tem as cédulas lacradas e não é apurada. */
    private static function apurada(PautaDaAssembleia $pauta): bool
    {
        return EstadoDaPauta::Encerrada === $pauta->getEstado() && !$pauta->cancelada();
    }
}
