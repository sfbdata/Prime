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
        $aptos = $this->unidades->totaisAptos($unidade->getAssembleia());
        $presencas = $this->participacoes->contarPorPauta($unidade->getAssembleia());
        $pautas = [];
        foreach ($unidade->getAssembleia()->getPautas() as $pauta) {
            if (self::encerrada($pauta)) { $this->pautas->relerContadores($pauta); }
            $pautas[] = new PautaNaCedulaOutput(
                id: (int) $pauta->getId(),
                titulo: $pauta->getTitulo(),
                descricao: $pauta->getDescricao(),
                estado: $pauta->getEstado(),
                votouEm: $votouEm[(int) $pauta->getId()] ?? null,
                segundosAtePrazo: self::segundosAtePrazo($pauta, $agora),
                opcoes: $pauta->aceitaVotoEm($agora) ? self::opcoes($pauta) : [],
                resultado: self::encerrada($pauta) ? $this->apuracao->apurar($pauta, $aptos['pesoApto'], $aptos['unidadesAptas'], $presencas[(int) $pauta->getId()] ?? null) : null,
                vagas: $pauta->getVagas(),
                digitos: $pauta->getDigitos(),
            );
        }

        return $pautas;
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
        );
    }

    /** @return list<OpcaoNaCedulaOutput> as opções de voto pelo número e a abstenção por último */
    private static function opcoes(PautaDaAssembleia $pauta): array
    {
        $opcoes = $pauta->getOpcoesDeVoto();
        $abstencao = $pauta->getAbstencao();
        if (null !== $abstencao) {
            $opcoes[] = $abstencao;
        }

        return array_map(static fn (OpcaoDaPauta $o): OpcaoNaCedulaOutput => new OpcaoNaCedulaOutput(
            (int) $o->getId(),
            $o->getNumero(),
            $o->getRotulo(),
            $o->isAbstencao(),
        ), $opcoes);
    }

    private static function segundosAtePrazo(PautaDaAssembleia $pauta, \DateTimeImmutable $agora): ?int
    {
        $prazo = $pauta->getPrazoAte();
        if (null === $prazo || !$pauta->aceitaVotoEm($agora)) {
            return null;
        }

        return max(0, $prazo->getTimestamp() - $agora->getTimestamp());
    }

    private static function encerrada(PautaDaAssembleia $pauta): bool
    {
        return EstadoDaPauta::Encerrada === $pauta->getEstado();
    }
}
