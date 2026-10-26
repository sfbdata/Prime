<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\MetaDeDivulgacao;
use App\Master\Exception\MetaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetaDeDivulgacao::class)]
final class MetaDeDivulgacaoTest extends TestCase
{
    private function agora(string $data = '2026-10-26 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($data);
    }

    #[TestDox('nasce com os padrões do desenho: mercado 1.510.000, meta 1.000, automática ligada e mensal')]
    public function testPadroes(): void
    {
        $m = new MetaDeDivulgacao();

        self::assertSame(1510000, $m->getMercado());
        self::assertSame(1000, $m->getAlvo());
        self::assertTrue($m->isAutomatica());
        self::assertSame('mensal', $m->getFrequencia());
        self::assertFalse($m->isMercadoManual());
    }

    #[TestDox('ajustar fixa o mercado à mão e a atualização automática não o sobrescreve')]
    public function testAjusteManualProtegeMercado(): void
    {
        $m = new MetaDeDivulgacao();
        $m->ajustar(2000000, 5000, $this->agora());

        $m->registrarAtualizacaoAutomatica(900000, $this->agora());

        self::assertSame(2000000, $m->getMercado());
        self::assertSame(5000, $m->getAlvo());
        self::assertTrue($m->isMercadoManual());
    }

    #[TestDox('atualização automática troca o mercado só com total acima do mínimo')]
    public function testAtualizacaoAutomatica(): void
    {
        $m = new MetaDeDivulgacao();

        $m->registrarAtualizacaoAutomatica(50000, $this->agora());
        self::assertSame(1510000, $m->getMercado());

        $m->registrarAtualizacaoAutomatica(1600000, $this->agora());
        self::assertSame(1600000, $m->getMercado());
        self::assertNotNull($m->getUltimaAutomatica());
    }

    #[TestDox('devida: nunca executada vale sempre; depois só com chave ligada e frequência vencida')]
    public function testAtualizacaoDevida(): void
    {
        $m = new MetaDeDivulgacao();
        self::assertTrue($m->atualizacaoDevida($this->agora()));

        $m->definirFrequencia('semanal');
        $m->registrarAtualizacaoAutomatica(1600000, $this->agora('2026-10-01 00:00:00'));
        self::assertFalse($m->atualizacaoDevida($this->agora('2026-10-05 00:00:00')));
        self::assertTrue($m->atualizacaoDevida($this->agora('2026-10-09 00:00:00')));

        $m->ligarAutomatica(false);
        self::assertFalse($m->atualizacaoDevida($this->agora('2026-12-01 00:00:00')));

        $m->pedirAtualizacao();
        self::assertTrue($m->atualizacaoDevida($this->agora('2026-10-02 00:00:00')));
    }

    #[TestDox('voltar a automático solta o mercado manual')]
    public function testVoltarAoAutomatico(): void
    {
        $m = new MetaDeDivulgacao();
        $m->ajustar(2000000, 5000, $this->agora());

        $m->voltarAoAutomatico();

        self::assertFalse($m->isMercadoManual());
        self::assertTrue($m->atualizacaoDevida($this->agora()));
    }

    #[TestDox('número fora de 1 a 100.000.000 é recusado')]
    public function testNumeroRecusado(): void
    {
        $this->expectException(MetaRecusada::class);

        (new MetaDeDivulgacao())->definirAlvo(0);
    }

    #[TestDox('frequência, estado e cor inválidos são recusados')]
    public function testRecusas(): void
    {
        $m = new MetaDeDivulgacao();
        foreach ([
            fn () => $m->definirFrequencia('diaria'),
            fn () => $m->definirMetaDaUf('XX', 10),
            fn () => $m->definirDestaque('SP', '#000000'),
            fn () => $m->definirMetaDaCidade('SP', '  ', 10),
        ] as $acao) {
            try {
                $acao();
                self::fail('Devia recusar');
            } catch (MetaRecusada) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('meta por estado, por cidade e destaque: definir e remover')]
    public function testMetasEDestaques(): void
    {
        $m = new MetaDeDivulgacao();

        $m->definirMetaDaUf('SP', 300);
        $m->definirMetaDaCidade('SP', 'Campinas', 40);
        $m->definirDestaque('SP', '#2e86de');
        self::assertSame(['SP' => 300], $m->getMetasPorUf());
        self::assertSame(['SP' => ['Campinas' => 40]], $m->getMetasPorCidade());
        self::assertSame(['SP' => '#2e86de'], $m->getDestaques());

        $m->definirMetaDaUf('SP', null);
        $m->definirMetaDaCidade('SP', 'Campinas', null);
        $m->definirDestaque('SP', null);
        self::assertSame([], $m->getMetasPorUf());
        self::assertSame([], $m->getMetasPorCidade());
        self::assertSame([], $m->getDestaques());
    }
}
