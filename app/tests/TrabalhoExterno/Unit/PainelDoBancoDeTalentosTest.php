<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\DTO\RetratoDoPainel;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Service\EngajamentoDoTrabalhoExterno;
use App\TrabalhoExterno\Service\PainelDoBancoDeTalentos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O painel do Master (desenho 06 l.1120 a 1146; spec VS 18.10.26 §4.9) a partir de um banco montado à
 * mão: rótulos, ícones, cores e subtítulos exatos do desenho, com as correções da §4.9 (base sem
 * arquivados, COM OAB com adv e estOab, termos 0/0) e os cliques de cada KPI e barra.
 */
#[CoversClass(PainelDoBancoDeTalentos::class)]
#[CoversClass(RetratoDoPainel::class)]
#[CoversClass(EngajamentoDoTrabalhoExterno::class)]
final class PainelDoBancoDeTalentosTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-10-19 15:00:00', new \DateTimeZone('UTC'));
    }

    #[TestDox('os 8 KPIs: rótulo, ícone, cor, número, subtítulo e clique do desenho; arquivado fora de todos os de currículo')]
    public function testKpis(): void
    {
        $kpis = (new PainelDoBancoDeTalentos())->montar($this->retrato())['kpis'];

        self::assertSame([
            ['k' => 'ativos', 'rot' => 'CURRÍCULOS ATIVOS', 'ic' => 'bi-person-vcard', 'cor' => '#0a6fa0', 'n' => 3, 'sub' => '2 nos últimos 30 dias', 'ir' => ['mod' => 'cv', 'filtro' => ['cf' => 'ativos']]],
            ['k' => 'novos', 'rot' => 'NOVOS NA SEMANA', 'ic' => 'bi-person-plus', 'cor' => '#0a6fa0', 'n' => 1, 'sub' => '1 ainda não analisados', 'ir' => ['mod' => 'cv', 'filtro' => ['cf' => 'novo']]],
            ['k' => 'oab', 'rot' => 'COM OAB', 'ic' => 'bi-award', 'cor' => '#5b3f9e', 'n' => 2, 'sub' => '1 estudantes · 0 bacharéis', 'ir' => ['mod' => 'cv', 'filtro' => ['cs' => 'oab']]],
            ['k' => 'vol', 'rot' => 'VOLUNTÁRIOS', 'ic' => 'bi-heart', 'cor' => '#a3235f', 'n' => 1, 'sub' => '0 termo(s) aceito(s) · 0 aguardando', 'ir' => ['mod' => 'cv', 'filtro' => ['cf' => 'vol']]],
            ['k' => 'vagas', 'rot' => 'VAGAS ABERTAS', 'ic' => 'bi-megaphone', 'cor' => '#1e7b45', 'n' => 2, 'sub' => '4 candidatura(s) no total', 'ir' => ['mod' => 'vagas', 'vf' => 'aberta']],
            ['k' => 'alertas', 'rot' => 'ALERTAS POR E-MAIL', 'ic' => 'bi-envelope-paper', 'cor' => '#0a6fa0', 'n' => 5, 'sub' => '7 visita(s) pelo link · 3 clique(s) em criar conta · clique para campanha', 'ir' => ['acao' => 'campanha']],
            ['k' => 'compartilhadas', 'rot' => 'VAGAS COMPARTILHADAS', 'ic' => 'bi-share', 'cor' => '#1e7b45', 'n' => 4, 'sub' => '2 vaga(s) divulgada(s)', 'ir' => ['mod' => 'vagas', 'vf' => 'todas']],
            ['k' => 'ofertas', 'rot' => 'OFERTAS COM TEMPO', 'ic' => 'bi-stopwatch', 'cor' => '#c76a12', 'n' => 2, 'sub' => '50% aceitas · 2,5 min em média', 'ir' => ['mod' => 'vagas', 'vf' => 'todas']],
        ], $kpis);
    }

    #[TestDox('VOLUNTÁRIOS: o subtítulo conta os termos mais recentes aceitos e aguardando (sem nome, CPF nem RG)')]
    public function testSubtituloDosTermos(): void
    {
        $r = $this->retrato();
        $comTermos = new RetratoDoPainel($r->agora, $r->curriculos, $r->vagas, $r->candidaturas, termos: [1 => 'aceito', 2 => 'aceito', 3 => 'enviado']);
        $kpi = (new PainelDoBancoDeTalentos())->montar($comTermos)['kpis'][3];

        self::assertSame('2 termo(s) aceito(s) · 1 aguardando', $kpi['sub']);
        self::assertStringNotContainsString('CPF', json_encode($kpi, JSON_THROW_ON_ERROR));
    }

    #[TestDox('as 6 barras: contagem sobre os ativos, do maior para o menor, rótulos da situação e cliques de filtro')]
    public function testBarras(): void
    {
        $barras = (new PainelDoBancoDeTalentos())->montar($this->retrato())['barras'];

        self::assertSame(
            [['Por área de atuação', 'bi-bookmark'], ['Por situação acadêmica e OAB', 'bi-mortarboard'], ['Por estado (UF)', 'bi-geo-alt'], ['Por cargo pretendido', 'bi-person-badge'], ['Funil das candidaturas', 'bi-funnel'], ['Voluntariado', 'bi-heart']],
            array_map(static fn (array $b): array => [$b['tit'], $b['ic']], $barras),
        );
        self::assertSame([['Cível', 2], ['Trabalhista', 1], ['Família', 1]], self::rotN($barras[0]), 'o arquivado (Cível) não conta');
        self::assertSame(['mod' => 'cv', 'filtro' => ['ca' => 'Cível']], $barras[0]['itens'][0]['ir']);
        self::assertSame('#0a6fa0', $barras[0]['itens'][0]['cor']);
        self::assertSame([['Advogado(a)', 1], ['Estagiário(a) com OAB', 1], ['Estudante', 1]], self::rotN($barras[1]));
        self::assertSame(['mod' => 'cv', 'filtro' => ['cs' => 'estOab']], $barras[1]['itens'][1]['ir']);
        self::assertSame([['DF', 2], ['SP', 1]], self::rotN($barras[2]));
        self::assertSame(['mod' => 'cv', 'filtro' => ['cu' => 'DF']], $barras[2]['itens'][0]['ir']);
        self::assertSame([['Estagiário(a)', 2], ['Advogado(a)', 1]], self::rotN($barras[3]));
        self::assertSame(['mod' => 'cv', 'filtro' => ['busca' => 'Estagiário(a)']], $barras[3]['itens'][0]['ir']);
        self::assertSame([['Recebida', 1, '#0a6fa0'], ['Em análise', 1, '#9a5b00'], ['Entrevista', 0, '#5b3f9e'], ['Aprovado', 1, '#1e7b45']], self::rotNCor($barras[4]), 'só as quatro etapas do desenho');
        self::assertSame(['mod' => 'vagas', 'vf' => 'todas'], $barras[4]['itens'][0]['ir']);
        self::assertSame([['Aceita voluntário', 1, '#a3235f'], ['Talvez', 1, '#c76a12'], ['Não aceita', 1, '#6b8494']], self::rotNCor($barras[5]));
        self::assertSame([['mod' => 'cv', 'filtro' => ['cf' => 'vol']], ['mod' => 'cv'], ['mod' => 'cv']], array_column($barras[5]['itens'], 'ir'));
    }

    #[TestDox('top 8: corta depois do oitavo; o empate fica na ordem do banco')]
    public function testTopOito(): void
    {
        $muitas = $this->cv(['areas' => array_slice(DadosDaVaga::AREAS, 2, 10)], '-1 day');
        $bancario = $this->cv(['areas' => ['Bancário']], '-1 day');
        $barras = (new PainelDoBancoDeTalentos())->montar(new RetratoDoPainel($this->agora, curriculos: [$muitas, $bancario]))['barras'];

        self::assertCount(8, $barras[0]['itens']);
        self::assertSame(['Bancário', 'Previdenciário', 'Família', 'Criminal', 'Tributário', 'Empresarial', 'Consumidor', 'Imobiliário'], array_column($barras[0]['itens'], 'rot'));
    }

    #[TestDox('crescimento: contagens por AAAA-MM no horário de Brasília; currículos e voluntários com os arquivados, candidaturas pelo envio')]
    public function testCrescimento(): void
    {
        $c = (new PainelDoBancoDeTalentos())->montar($this->retrato())['crescimento'];

        self::assertSame(['2026-09' => 1, '2026-10' => 3], $c['cvs']);
        self::assertSame(['2026-09' => 1, '2026-10' => 3], $c['cands'], '01/10 01:00 UTC é 30/09 em Brasília');
        self::assertSame(['2026-10' => 2], $c['vols']);
    }

    #[TestDox('banco vazio: "nenhuma publicada", barras sem itens, funil e voluntariado zerados, crescimento como objeto vazio')]
    public function testVazio(): void
    {
        $p = (new PainelDoBancoDeTalentos())->montar(new RetratoDoPainel($this->agora));

        self::assertSame('nenhuma publicada', $p['kpis'][7]['sub']);
        self::assertSame('0 visita(s) pelo link · 0 clique(s) em criar conta · clique para campanha', $p['kpis'][5]['sub']);
        self::assertSame([[], [], [], []], array_map(static fn (array $b): array => $b['itens'], array_slice($p['barras'], 0, 4)));
        self::assertSame([0, 0, 0, 0], array_column($p['barras'][4]['itens'], 'n'));
        self::assertSame([0, 0, 0], array_column($p['barras'][5]['itens'], 'n'));
        self::assertSame('{"cvs":{},"cands":{},"vols":{}}', json_encode($p['crescimento']));
        self::assertSame(0, $p['inscritos']);
    }

    #[TestDox('ofertas sem aceite: 0% aceitas e sem a média')]
    public function testOfertasSemAceite(): void
    {
        $oferta = $this->vaga(9, ['oferta' => true, 'duracaoDaOferta' => 10], '-1 minute');
        $p = (new PainelDoBancoDeTalentos())->montar(new RetratoDoPainel($this->agora, vagas: [$oferta]));

        self::assertSame([1, '0% aceitas'], [$p['kpis'][7]['n'], $p['kpis'][7]['sub']]);
    }

    #[TestDox('a média de aceite é a do chip do banner: do início da rodada ao primeiro aceite, em minutos com uma casa')]
    public function testMediaDoAceite(): void
    {
        self::assertNull(EngajamentoDoTrabalhoExterno::minutosAteOAceite([]));
        $media = EngajamentoDoTrabalhoExterno::minutosAteOAceite([
            ['inicio' => $this->agora, 'aceite' => $this->agora->modify('+60 seconds')],
            ['inicio' => $this->agora, 'aceite' => $this->agora->modify('+240 seconds')],
            ['inicio' => $this->agora, 'aceite' => $this->agora->modify('-30 seconds')],
        ]);
        self::assertSame('1,7', EngajamentoDoTrabalhoExterno::minutos((float) $media), 'o aceite antes do início conta zero');
        self::assertSame('3', EngajamentoDoTrabalhoExterno::minutos(3.0));
    }

    private function retrato(): RetratoDoPainel
    {
        $cv1 = $this->cv(['situacao' => 'adv', 'uf' => 'DF', 'areas' => ['Cível', 'Trabalhista'], 'cargo' => 'Advogado(a)', 'vol' => 'sim'], '-1 day');
        $cv2 = $this->cv(['situacao' => 'estOab', 'uf' => 'SP', 'areas' => ['Cível'], 'cargo' => 'Estagiário(a)', 'vol' => 'talvez'], '-10 days', StatusCurriculo::Analise);
        $cv3 = $this->cv(['situacao' => 'est', 'uf' => 'DF', 'areas' => ['Família'], 'cargo' => 'Estagiário(a)', 'vol' => 'nao'], '-40 days', StatusCurriculo::Favorito);
        $arquivado = $this->cv(['situacao' => 'bach', 'uf' => 'RJ', 'areas' => ['Cível'], 'cargo' => 'Correspondente', 'vol' => 'sim'], '-2 days', StatusCurriculo::Arquivado);

        $comum = $this->vaga(1, [], '-1 day');
        foreach (['WhatsApp', 'E-mail', 'Link'] as $canal) {
            $comum->registrarDivulgacao($canal, 'Ana', $this->agora);
        }
        $aoVivo = $this->vaga(2, ['oferta' => true, 'duracaoDaOferta' => 10], '+0 seconds');
        $aoVivo->registrarDivulgacao('Link', 'Ana', $this->agora);
        $expirada = $this->vaga(3, ['oferta' => true, 'duracaoDaOferta' => 10], '-1 hour');
        $pendente = Vaga::doExterno(Apoio::tenant(1), Apoio::usuario(2), 'Bia', $this->dadosDaVaga([]), $this->agora);

        return new RetratoDoPainel(
            agora: $this->agora,
            curriculos: [$cv1, $cv2, $cv3, $arquivado],
            vagas: [$comum, $aoVivo, $expirada, $pendente],
            candidaturas: [
                ['status' => 'recebida', 'enviadaEm' => new \DateTimeImmutable('2026-10-19 10:00:00', new \DateTimeZone('UTC'))],
                ['status' => 'analise', 'enviadaEm' => new \DateTimeImmutable('2026-10-01 01:00:00', new \DateTimeZone('UTC'))],
                ['status' => 'aprovado', 'enviadaEm' => new \DateTimeImmutable('2026-10-10 12:00:00', new \DateTimeZone('UTC'))],
                ['status' => 'nao', 'enviadaEm' => new \DateTimeImmutable('2026-10-12 12:00:00', new \DateTimeZone('UTC'))],
            ],
            aceitesPorVaga: [2 => 1, 3 => 0],
            minutosAteOAceite: 2.46,
            inscritos: 5,
            visitas: 7,
            cliques: 3,
        );
    }

    /** @param array<string, mixed> $dados */
    private function cv(array $dados, string $recebido, StatusCurriculo $status = StatusCurriculo::Novo): Curriculo
    {
        static $id = 0;
        $cv = Curriculo::criar(Apoio::usuario(100 + ++$id), DadosDoCurriculo::deArray($dados + ['nome' => 'Pessoa', 'email' => 'p@x.test', 'situacao' => 'adv', 'lgpd' => true]), $this->agora->modify($recebido));
        $cv->mudarStatus($status);

        return $cv;
    }

    /** @param array<string, mixed> $extra */
    private function vaga(int $id, array $extra, string $publicada): Vaga
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', $this->dadosDaVaga($extra), $this->agora->modify($publicada));
        (new \ReflectionProperty($v, 'id'))->setValue($v, $id);

        return $v;
    }

    /** @param array<string, mixed> $extra */
    private function dadosDaVaga(array $extra): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado trabalhista', 'cargo' => 'Advogado(a)', 'ofertante' => ['nome' => 'Escritório']]);
    }

    /** @return list<array{string, int}> */
    private static function rotN(array $barra): array
    {
        return array_map(static fn (array $i): array => [$i['rot'], $i['n']], $barra['itens']);
    }

    /** @return list<array{string, int, string}> */
    private static function rotNCor(array $barra): array
    {
        return array_map(static fn (array $i): array => [$i['rot'], $i['n'], $i['cor']], $barra['itens']);
    }
}
