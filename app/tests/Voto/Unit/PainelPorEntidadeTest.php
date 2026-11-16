<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\FiltroDoPainel;
use App\Voto\DTO\PautaNoPainel;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Service\PainelPorEntidade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Agrupamento por entidade, filtros, ordem dos grupos, resumo e KPIs do painel da Gestão eleitoral (A7 e A8). */
#[CoversClass(PainelPorEntidade::class)]
#[CoversClass(FiltroDoPainel::class)]
#[CoversClass(AssembleiaNoPainel::class)]
final class PainelPorEntidadeTest extends TestCase
{
    private int $proximoId = 1;

    #[TestDox('Agrupa por nome sem acento e CNPJ: mesmo nome com CNPJ diferente são grupos diferentes')]
    public function testAgrupaPorNomeECnpj(): void
    {
        $a = $this->assembleia('AGO 2026', 'Residencial Jardim', '11222333000181');
        $b = $this->assembleia('AGE 2026', 'RESIDENCIAL JARDIM', '11222333000181');
        $c = $this->assembleia('AGO Outra', 'Residencial Jardim', '45723174000110');

        $painel = (new PainelPorEntidade())->agrupar([$a, $b, $c], new FiltroDoPainel());

        self::assertCount(2, $painel->grupos);
        $tamanhos = array_map(static fn ($g): int => \count($g->assembleias), $painel->grupos);
        sort($tamanhos);
        self::assertSame([1, 2], $tamanhos);
        self::assertTrue($painel->temFiltroDeEntidade());
    }

    #[TestDox('Assembleia sem entidade cai no grupo "sem nome"; com uma só entidade não há filtro de Entidade')]
    public function testSemEntidade(): void
    {
        $painel = (new PainelPorEntidade())->agrupar([$this->assembleia('AGO', null, null), $this->assembleia('AGE', '  ', null)], new FiltroDoPainel());

        self::assertCount(1, $painel->grupos);
        self::assertTrue($painel->grupos[0]->semNome());
        self::assertFalse($painel->temFiltroDeEntidade());
        self::assertSame('', $painel->grupos[0]->cnpj);
    }

    #[TestDox('O CNPJ do grupo sai com máscara')]
    public function testCnpjFormatado(): void
    {
        $painel = (new PainelPorEntidade())->agrupar([$this->assembleia('AGO', 'Jardim', '11222333000181')], new FiltroDoPainel());

        self::assertSame('11.222.333/0001-81', $painel->grupos[0]->cnpj);
    }

    #[TestDox('Busca por nome da assembleia, entidade, pauta e CNPJ (com ou sem máscara), sem acento nem caixa')]
    public function testBusca(): void
    {
        $a = $this->assembleia('Assembleia Ordinária', 'Residencial Jardim', '11222333000181', [$this->pauta(EstadoDaPauta::Aguardando, 'Aprovação das contas')]);
        $b = $this->assembleia('Outra', 'Edifício Sol', '45723174000110');
        $servico = new PainelPorEntidade();

        foreach (['ordinaria' => 1, 'JARDIM' => 1, 'aprovacao das contas' => 1, '11.222.333/0001-81' => 1, '11222333' => 1, '45723174000110' => 1, 'sol' => 1, 'nada' => 0] as $busca => $esperado) {
            $achadas = array_sum(array_map(static fn ($g): int => \count($g->assembleias), $servico->agrupar([$a, $b], FiltroDoPainel::de((string) $busca, '', ''))->grupos));
            self::assertSame($esperado, $achadas, 'busca: ' . $busca);
        }
    }

    #[TestDox('Filtro de entidade usa a chave do grupo; situação: em andamento, rascunhos e concluídas')]
    public function testFiltros(): void
    {
        $rascunho = $this->assembleia('Rasc', 'Jardim', null);
        $andamento = $this->assembleia('Abre', 'Sol', null, [$this->pauta(EstadoDaPauta::Aberta)], true);
        $espera = $this->assembleia('Espera', 'Sol', null, [$this->pauta(EstadoDaPauta::Aguardando)], true);
        $concluida = $this->assembleia('Fim', 'Lua', null, [$this->pauta(EstadoDaPauta::Encerrada)], true);
        $todas = [$rascunho, $andamento, $espera, $concluida];
        $servico = new PainelPorEntidade();
        $titulos = static fn ($painel): array => array_merge(...array_map(static fn ($g): array => array_map(static fn (AssembleiaNoPainel $a): string => $a->titulo, $g->assembleias), $painel->grupos));

        self::assertEqualsCanonicalizing(['Abre', 'Espera'], $titulos($servico->agrupar($todas, FiltroDoPainel::de('', '', 'and'))));
        self::assertSame(['Rasc'], $titulos($servico->agrupar($todas, FiltroDoPainel::de('', '', 'ras'))));
        self::assertSame(['Fim'], $titulos($servico->agrupar($todas, FiltroDoPainel::de('', '', 'con'))));
        self::assertEqualsCanonicalizing(['Abre', 'Espera'], $titulos($servico->agrupar($todas, FiltroDoPainel::de('', PainelPorEntidade::chave($andamento), ''))));
        self::assertSame([], $titulos($servico->agrupar($todas, FiltroDoPainel::de('', PainelPorEntidade::chave($andamento), 'con'))));
        self::assertSame(3, \count($servico->agrupar($todas, FiltroDoPainel::de('', '', 'xx'))->entidades), 'situação inválida vira "todas"; as opções vêm antes dos filtros');
        self::assertTrue($servico->agrupar($todas, FiltroDoPainel::de('', '', 'con'))->haAssembleias);
    }

    #[TestDox('Vazios: nada criado e nada com os filtros são casos diferentes')]
    public function testVazios(): void
    {
        $servico = new PainelPorEntidade();
        $nenhuma = $servico->agrupar([], new FiltroDoPainel());
        self::assertTrue($nenhuma->vazio());
        self::assertFalse($nenhuma->haAssembleias);

        $semAchar = $servico->agrupar([$this->assembleia('AGO', 'Jardim', null)], FiltroDoPainel::de('zzz', '', ''));
        self::assertTrue($semAchar->vazio());
        self::assertTrue($semAchar->haAssembleias);
        self::assertTrue(FiltroDoPainel::de('zzz', '', '')->ativo());
        self::assertFalse((new FiltroDoPainel())->ativo());
    }

    #[TestDox('Grupos com votação aberta primeiro; depois publicada, rascunho e concluída; empate pelo nome')]
    public function testOrdemDosGrupos(): void
    {
        $painel = (new PainelPorEntidade())->agrupar([
            $this->assembleia('A', 'Zeta', null, [$this->pauta(EstadoDaPauta::Encerrada)], true),
            $this->assembleia('B', 'Beta', null),
            $this->assembleia('C', 'Alfa', null, [$this->pauta(EstadoDaPauta::Aguardando)], true),
            $this->assembleia('D', 'Omega', null, [$this->pauta(EstadoDaPauta::Aberta)], true),
            $this->assembleia('E', 'Alfa Dois', null),
        ], new FiltroDoPainel());

        self::assertSame(['Omega', 'Alfa', 'Alfa Dois', 'Beta', 'Zeta'], array_map(static fn ($g): string => $g->nome, $painel->grupos));
    }

    #[TestDox('Com mais de 3 grupos só ficam abertos os com algo em andamento (ou todos, se houver busca)')]
    public function testGruposAbertos(): void
    {
        $lista = [
            $this->assembleia('A', 'Um', null, [$this->pauta(EstadoDaPauta::Encerrada)], true),
            $this->assembleia('B', 'Dois', null, [$this->pauta(EstadoDaPauta::Encerrada)], true),
            $this->assembleia('C', 'Tres', null, [$this->pauta(EstadoDaPauta::Encerrada)], true),
            $this->assembleia('D', 'Quatro', null, [$this->pauta(EstadoDaPauta::Aberta)], true),
        ];
        $servico = new PainelPorEntidade();

        $abertos = array_map(static fn ($g): bool => $g->aberto, $servico->agrupar($lista, new FiltroDoPainel())->grupos);
        self::assertSame([true, false, false, false], $abertos);
        self::assertSame([true, true, true, true], array_map(static fn ($g): bool => $g->aberto, $servico->agrupar($lista, FiltroDoPainel::de('a', '', ''))->grupos), 'com busca, todos abertos');
        self::assertSame([true, true], array_map(static fn ($g): bool => $g->aberto, $servico->agrupar(\array_slice($lista, 0, 2), new FiltroDoPainel())->grupos), 'até 3 grupos: todos abertos');
    }

    #[TestDox('Resumo conta as pautas por situação, sem as retiradas; assembleia sem pauta conta como rascunho')]
    public function testResumo(): void
    {
        $retirada = $this->pauta(EstadoDaPauta::Aguardando, 'Retirada', retirada: true);
        $a = $this->assembleia('A', 'Jardim', null, [$this->pauta(EstadoDaPauta::Aberta), $this->pauta(EstadoDaPauta::Aguardando), $this->pauta(EstadoDaPauta::Encerrada), $retirada], true);
        $b = $this->assembleia('B', 'Jardim', null);

        $grupo = (new PainelPorEntidade())->agrupar([$a, $b], new FiltroDoPainel())->grupos[0];

        self::assertSame(['aberta' => 1, 'publicada' => 1, 'rascunho' => 1, 'encerrada' => 1], $grupo->resumo);
    }

    #[TestDox('KPIs: votações abertas (suspensa incluída) e cédulas das pautas que saíram da espera; retirada e arquivada ficam fora')]
    public function testKpis(): void
    {
        $agora = new \DateTimeImmutable('2026-11-16 10:00:00');
        $aberta = $this->pauta(EstadoDaPauta::Aberta, 'Contas', votantes: 7, aptos: 10, prazoAte: $agora->modify('+90 seconds'));
        $suspensa = $this->pauta(EstadoDaPauta::Aberta, 'Obras', votantes: 2, aptos: 10, suspensa: true);
        $encerrada = $this->pauta(EstadoDaPauta::Encerrada, 'Síndico', votantes: 9, aptos: 10);
        $espera = $this->pauta(EstadoDaPauta::Aguardando, 'Espera', votantes: 0, aptos: 10);
        $retirada = $this->pauta(EstadoDaPauta::Aberta, 'Retirada', votantes: 5, aptos: 10, retirada: true);
        $a = $this->assembleia('AGO', 'Jardim', null, [$aberta, $suspensa, $encerrada, $espera, $retirada], true);
        $arquivada = $this->assembleia('Velha', 'Jardim', null, [$this->pauta(EstadoDaPauta::Encerrada, 'Velha', votantes: 50, aptos: 60)], true, true);
        $rascunho = $this->assembleia('Rasc', 'Jardim', null);

        $kpis = (new PainelPorEntidade())->kpis([$a, $arquivada, $rascunho], $agora);

        self::assertSame(2, $kpis->votacoesAbertas());
        self::assertSame(18, $kpis->totalDeCedulas);
        self::assertSame(['Contas', 'Obras'], array_map(static fn ($l): string => $l->titulo, $kpis->abertas));
        self::assertSame(['Contas', 'Obras', 'Síndico'], array_map(static fn ($l): string => $l->titulo, $kpis->cedulas));
        self::assertSame(90, $kpis->abertas[0]->segundos);
        self::assertSame('suspensa', $kpis->abertas[1]->situacao);
        self::assertSame('AGO', $kpis->abertas[0]->assembleia);
    }

    #[TestDox('KPIs: a simulação conta, como no protótipo (só o Master a ignora, na W2-7)')]
    public function testKpisContamSimulacao(): void
    {
        $agora = new \DateTimeImmutable('2026-11-16 10:00:00');
        $real = $this->assembleia('Real', 'Jardim', null, [$this->pauta(EstadoDaPauta::Aberta, 'Contas', votantes: 3)], true);
        $simulacao = $this->assembleia('Treino', 'Jardim', null, [$this->pauta(EstadoDaPauta::Aberta, 'Treino', votantes: 8)], true, simulacao: true);

        $kpis = (new PainelPorEntidade())->kpis([$real, $simulacao], $agora);

        self::assertSame(2, $kpis->votacoesAbertas());
        self::assertSame(11, $kpis->totalDeCedulas);
        self::assertSame(['Contas', 'Treino'], array_map(static fn ($l): string => $l->titulo, $kpis->cedulas));
    }

    /** @param list<PautaNoPainel> $pautas */
    private function assembleia(string $titulo, ?string $entidade, ?string $cnpj, array $pautas = [], bool $publicada = false, bool $arquivada = false, bool $simulacao = false): AssembleiaNoPainel
    {
        return new AssembleiaNoPainel(
            id: $this->proximoId++,
            titulo: $titulo,
            peso: PesoDoVoto::Unidade,
            estado: $publicada ? EstadoDaAssembleia::Publicada : EstadoDaAssembleia::Rascunho,
            criadaPorNome: 'G',
            criadaEm: new \DateTimeImmutable('2026-11-01'),
            publicadaEm: $publicada ? new \DateTimeImmutable('2026-11-02') : null,
            podeAlterarPautas: !$publicada,
            podeAlterarUnidades: true,
            unidadesAptas: 10,
            unidadesInaptas: 0,
            pesoApto: '10.000000',
            pautas: array_map(static fn (PautaNoPainel $p): PautaNoPainel => $p, $pautas),
            arquivadaEm: $arquivada ? new \DateTimeImmutable('2026-11-10') : null,
            entidade: $entidade,
            cnpj: $cnpj,
            simulacao: $simulacao,
        );
    }

    private function pauta(EstadoDaPauta $estado, string $titulo = 'Pauta', int $votantes = 0, int $aptos = 10, ?\DateTimeImmutable $prazoAte = null, bool $suspensa = false, bool $retirada = false): PautaNoPainel
    {
        return new PautaNoPainel(
            id: $this->proximoId++,
            ordem: 1,
            titulo: $titulo,
            descricao: null,
            estado: $estado,
            assembleiaPublicada: true,
            convocacao: null,
            quorumExigido: null,
            abertaEm: null,
            abertaPorNome: null,
            prazoAte: $prazoAte,
            encerradaEm: null,
            encerradaPorNome: null,
            motivoEncerramento: null,
            opcoes: [],
            votantes: $votantes,
            pesoVotante: '0.000000',
            unidadesAptas: $aptos,
            pesoApto: '10.000000',
            resultado: null,
            suspensaEm: $suspensa ? new \DateTimeImmutable('2026-11-16') : null,
            retiradaEm: $retirada ? new \DateTimeImmutable('2026-11-16') : null,
        );
    }
}
