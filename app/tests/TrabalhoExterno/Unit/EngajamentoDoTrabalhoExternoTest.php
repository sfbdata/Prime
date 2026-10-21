<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\RetratoDoEngajamento;
use App\TrabalhoExterno\Service\EngajamentoDoTrabalhoExterno;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O banner, o lembrete e as boas-vindas (desenho 06 l.1013 a 1023, 1054 a 1061, 1111; spec §4.7): os
 * textos e ícones exatos do desenho, a partir do retrato.
 */
#[CoversClass(EngajamentoDoTrabalhoExterno::class)]
#[CoversClass(RetratoDoEngajamento::class)]
final class EngajamentoDoTrabalhoExternoTest extends TestCase
{
    private EngajamentoDoTrabalhoExterno $sut;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->sut = new EngajamentoDoTrabalhoExterno();
        $this->agora = new \DateTimeImmutable('2026-10-19 15:00:00', new \DateTimeZone('UTC'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function horarios(): iterable
    {
        yield '11:59 em Brasília' => ['2026-10-19 14:59:00', 'Bom dia, Ana'];
        yield 'meio-dia em Brasília' => ['2026-10-19 15:00:00', 'Boa tarde, Ana'];
        yield '18:00 em Brasília' => ['2026-10-19 21:00:00', 'Boa noite, Ana'];
        yield '22:00 em Brasília (01:00 UTC do dia seguinte)' => ['2026-10-20 01:00:00', 'Boa noite, Ana'];
    }

    #[DataProvider('horarios')]
    #[TestDox('saudação pelo horário de Brasília, com o primeiro nome: $esperado')]
    public function testSaudacao(string $utc, string $esperado): void
    {
        self::assertSame($esperado, $this->sut->saudacao('Ana Maria Souza', new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))));
        self::assertSame('Bom dia', $this->sut->saudacao('', new \DateTimeImmutable('2026-10-19 12:00:00', new \DateTimeZone('UTC'))));
    }

    #[TestDox('chips do interno: pedidos só com talentos, ofertas ao vivo com o tempo da próxima, semana, média de aceite, primeira vaga')]
    public function testChipsDoInterno(): void
    {
        $r = new RetratoDoEngajamento('Ana', true, $this->agora, totalVagas: 3, abertas: 2, pendentes: 2, talentos: true, ofertasAoVivo: [$this->agora->modify('+125 seconds'), $this->agora->modify('+1 hour')], curriculosNaSemana: 4, candidaturasNaSemana: 5, minutosAteOAceite: 2.46);
        self::assertSame([
            ['ic' => 'bi-inbox', 'cor' => '#9a5b00', 'txt' => '2 pedido(s) de divulgação de vaga para aprovar', 'ir' => ['mod' => 'vagas', 'filtro' => 'pendente']],
            ['ic' => 'bi-broadcast', 'cor' => '#1e7b45', 'txt' => '2 oferta(s) ao vivo · a próxima termina em 02:05', 'ir' => ['mod' => 'vagas', 'filtro' => 'aberta']],
            ['ic' => 'bi-person-plus', 'cor' => '#0a6fa0', 'txt' => '4 currículo(s) novo(s) nesta semana', 'ir' => ['mod' => 'cv', 'filtro' => 'novo']],
            ['ic' => 'bi-send-check', 'cor' => '#5b3f9e', 'txt' => '5 candidatura(s) e aceite(s) na semana', 'ir' => ['mod' => 'vagas', 'filtro' => 'todas']],
            ['ic' => 'bi-stopwatch', 'cor' => '#c76a12', 'txt' => 'Ofertas são aceitas em 2,5 min, em média', 'ir' => null],
        ], $this->sut->chips($r));

        $semTalentos = new RetratoDoEngajamento('Ana', true, $this->agora, totalVagas: 1, pendentes: 2);
        self::assertSame([], $this->sut->chips($semTalentos), 'sem talentos não vê os pedidos (lacuna 14); "Tudo em dia por aqui."');

        $vazio = $this->sut->chips(new RetratoDoEngajamento('Ana', true, $this->agora, podeCriar: true));
        self::assertSame([['ic' => 'bi-megaphone', 'cor' => '#0a6fa0', 'txt' => 'Publique a primeira vaga ou oferta com tempo', 'ir' => ['mod' => 'vagas', 'acao' => 'novaVaga']]], $vazio);
        self::assertNull($this->sut->chips(new RetratoDoEngajamento('Ana', true, $this->agora))[0]['ir'], 'quem não publica só lê o chip');
    }

    #[TestDox('chips do externo: disputadas, novas desde a visita e o currículo (cadastre, X% com as duas primeiras faltas, 100%)')]
    public function testChipsDoExterno(): void
    {
        $sem = $this->sut->chips(new RetratoDoEngajamento('Ana', false, $this->agora, ofertasAoVivo: [$this->agora], novasDesdeAVisita: 3));
        self::assertSame(['1 vaga(s) disputada(s) disponível(is) agora', '3 vaga(s) nova(s) desde sua última visita', 'Cadastre seu currículo para aceitar ofertas em 1 clique'], array_column($sem, 'txt'));
        self::assertSame(['mod' => 'cv', 'acao' => 'editarCv'], $sem[2]['ir']);

        $meio = $this->sut->chips(new RetratoDoEngajamento('Ana', false, $this->agora, percentual: ['pct' => 64, 'faltas' => ['telefone', 'resumo', 'foto de perfil']]));
        self::assertSame([['ic' => 'bi-graph-up-arrow', 'cor' => '#c76a12', 'txt' => 'Currículo 64% completo · falta telefone e resumo', 'ir' => ['mod' => 'cv', 'acao' => 'editarCv']]], $meio);

        $cheio = $this->sut->chips(new RetratoDoEngajamento('Ana', false, $this->agora, percentual: ['pct' => 100, 'faltas' => []]));
        self::assertSame([['ic' => 'bi-patch-check', 'cor' => '#1e7b45', 'txt' => 'Currículo 100% completo · você aparece primeiro nas buscas', 'ir' => null]], $cheio);
        self::assertSame('Oportunidades para você na rede BlueJus.', $this->sut->subtitulo(false));
        self::assertSame('Veja o que aconteceu na Central desde a sua última visita.', $this->sut->subtitulo(true));
    }

    #[TestDox('lembrete: cadastrar, completar, revisar (mais de 30 dias pelo atualizadoEm) e em dia, com as vagas abertas')]
    public function testLembrete(): void
    {
        self::assertSame(['estado' => 'cadastrar', 'pct' => 0, 'faltas' => [], 'dias' => 0, 'vagasAbertas' => 2], $this->sut->lembrete(new RetratoDoEngajamento('Ana', false, $this->agora, abertas: 2)));
        self::assertSame('completar', $this->sut->lembrete(new RetratoDoEngajamento('Ana', false, $this->agora, percentual: ['pct' => 91, 'faltas' => ['resumo']], atualizadoEm: $this->agora->modify('-40 days')))['estado']);
        $velho = $this->sut->lembrete(new RetratoDoEngajamento('Ana', false, $this->agora, percentual: ['pct' => 100, 'faltas' => []], atualizadoEm: $this->agora->modify('-31 days')));
        self::assertSame(['revisar', 31], [$velho['estado'], $velho['dias']]);
        self::assertSame('emDia', $this->sut->lembrete(new RetratoDoEngajamento('Ana', false, $this->agora, percentual: ['pct' => 100, 'faltas' => []], atualizadoEm: $this->agora->modify('-30 days')))['estado']);
    }

    #[TestDox('boas-vindas: título pelo cadastro, texto pelas indicadas abertas, passos feitos e o botão de cada caso')]
    public function testBoasVindas(): void
    {
        $novo = $this->sut->boasVindas(new RetratoDoEngajamento('Ana', false, $this->agora, abertas: 3), true, ['VG-0001']);
        self::assertSame('Bem-vindo ao BlueJus!', $novo['titulo']);
        self::assertSame('A vaga que indicaram para você continua aberta. Cadastre seu currículo uma vez e candidate-se em 1 clique.', $novo['texto']);
        self::assertSame([false, false, false], array_column($novo['passos'], 'feito'));
        self::assertSame(['Cadastre seu currículo', 'Candidate-se às vagas', 'Ative os avisos de vaga'], array_column($novo['passos'], 'rot'));
        self::assertSame(['rot' => 'Cadastrar currículo e me candidatar', 'acao' => 'cadastrarCv', 'codigo' => 'VG-0001'], $novo['botao']);

        $volta = $this->sut->boasVindas(new RetratoDoEngajamento('Ana', false, $this->agora, abertas: 3, percentual: ['pct' => 100, 'faltas' => []], candidatou: true, alertaLigado: true), false, ['VG-0001', 'VG-0002']);
        self::assertSame(['Que bom ter você de volta!', '2 vagas que indicaram para você continuam abertas. Cadastre seu currículo uma vez e candidate-se em 1 clique.'], [$volta['titulo'], $volta['texto']]);
        self::assertSame([true, true, true], array_column($volta['passos'], 'feito'));
        self::assertSame(['rot' => 'Ver a vaga indicada', 'acao' => 'abrirVaga', 'codigo' => 'VG-0001'], $volta['botao']);

        $outras = $this->sut->boasVindas(new RetratoDoEngajamento('Ana', false, $this->agora, abertas: 1, percentual: ['pct' => 50, 'faltas' => []]), false, []);
        self::assertSame('As vagas que te indicaram já foram preenchidas, mas há outras abertas agora. Cadastre seu currículo e ative os avisos para não perder as próximas.', $outras['texto']);
        self::assertSame(['rot' => 'Ver vagas abertas', 'acao' => 'verVagas'], $outras['botao']);

        $nada = $this->sut->boasVindas(new RetratoDoEngajamento('Ana', false, $this->agora), false, []);
        self::assertSame('As vagas que te indicaram já foram preenchidas. Cadastre seu currículo e ative os avisos para receber as próximas.', $nada['texto']);
        self::assertSame(['rot' => 'Cadastrar meu currículo', 'acao' => 'cadastrarCv'], $nada['botao']);
    }
}
