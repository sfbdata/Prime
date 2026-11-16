<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\DivulgacaoDaAssembleiaOutput;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\DTO\PautaNoResumo;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\DTO\ResumoDoResultado;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Monta o resumo para divulgar (decisão C12): só pauta ENCERRADA e apurada entra (a cancelada e a aberta ficam de fora),
 * com resultado, participação e quórum; nenhum dado pessoal. O texto do resultado segue os ramos do `resTx` do desenho
 * (os mesmos de `voto/_texto_do_resultado.html.twig`, aqui em PHP porque a imagem não passa pelo Twig).
 */
final class MontadorDoResumoDoResultado
{
    private const BASE = 'voto.assembleia.gestor.resultado_por_tipo.';

    public function __construct(private readonly TranslatorInterface $tradutor)
    {
    }

    public function montar(AssembleiaNoPainel $assembleia, DivulgacaoDaAssembleiaOutput $divulgacao, \DateTimeImmutable $emitidoEm): ResumoDoResultado
    {
        $pautas = [];
        foreach ($assembleia->pautasEncerradas() as $pauta) {
            if (null !== $pauta->resultado) {
                $pautas[] = $this->pauta($pauta, $pauta->resultado);
            }
        }
        if ([] === $pautas) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.gestor.relatorio.sem_apurada'));
        }
        $conteudo = array_map(static fn (PautaNoResumo $p): array => $p->paraArray(), $pautas);
        $hash = hash('sha256', json_encode([$assembleia->titulo, $divulgacao->codigoDeConferencia(), $conteudo], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return new ResumoDoResultado($assembleia->id, $assembleia->titulo, $divulgacao->link, $divulgacao->codigoDeConferencia(), $emitidoEm, $hash, $pautas);
    }

    private function pauta(PautaNoPainel $pauta, ResultadoDaPauta $r): PautaNoResumo
    {
        $linhas = array_map(static fn ($o): array => ['rotulo' => $o->numero . '  ' . $o->rotulo, 'votos' => $o->unidades], $r->opcoes);
        $linhas[] = ['rotulo' => $this->tradutor->trans(self::BASE . (TipoDaPauta::Deliberacao === $r->tipo ? 'abstencoes' : 'brancos')), 'votos' => $r->abstencoesUnidades];
        $linhas[] = ['rotulo' => $this->tradutor->trans('voto.assembleia.gestor.relatorio.nulos'), 'votos' => $r->nulosUnidades];

        return new PautaNoResumo(
            $pauta->titulo,
            $this->tradutor->trans('voto.assembleia.gestor.relatorio.participacao', ['n' => $pauta->votantes, 'aptos' => $pauta->unidadesAptas, 'pc' => $pauta->pctUnidades()]),
            $pauta->encerradaEm?->format('d/m/Y H:i') ?? '',
            $this->tradutor->trans('voto.assembleia.gestor.relatorio.' . (!$pauta->exigeQuorum() ? 'quorum_nao_exigido' : ($r->quorumOk ? 'quorum_atingido' : 'quorum_nao_atingido'))),
            $this->resultado($r),
            $linhas,
        );
    }

    private function resultado(ResultadoDaPauta $r): string
    {
        $deliberacao = TipoDaPauta::Deliberacao === $r->tipo;
        if (ResultadoDaPauta::QUORUM_NAO_ATINGIDO === $r->situacao) {
            return $this->tradutor->trans(self::BASE . 'nao_deliberada');
        }
        if ($r->vagas > 1) {
            $nomes = implode(', ', array_map(static fn ($o): string => $o->numero . ' ' . $o->rotulo, $r->vencedores));

            return [] !== $r->vencedores ? $this->tradutor->trans('voto.assembleia.gestor.resultados.eleitos', ['nomes' => $nomes]) : $r->situacao;
        }
        if ($r->decidido() && null !== $r->vencedor) {
            $rotulo = mb_strtolower(trim($r->vencedor->rotulo));
            if ($deliberacao && 'sim' === $rotulo) {
                return $this->tradutor->trans(self::BASE . 'aprovada');
            }
            if ($deliberacao && \in_array($rotulo, ['não', 'nao'], true)) {
                return $this->tradutor->trans(self::BASE . 'rejeitada');
            }

            return $this->tradutor->trans(self::BASE . ($deliberacao ? 'opcao_vencedora' : 'eleito'), ['numero' => (string) $r->vencedor->numero, 'rotulo' => $r->vencedor->rotulo]);
        }
        if ($r->empate) {
            return $r->situacao;
        }

        return $this->tradutor->trans(self::BASE . ($deliberacao ? 'rejeitada_criterio' : 'sem_vencedor'));
    }
}
