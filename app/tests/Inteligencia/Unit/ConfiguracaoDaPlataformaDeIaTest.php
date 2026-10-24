<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Shared\Contract\Auditavel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A linha da plataforma (chave geral, modelo, retenção) e o que o Master grava em cada escritório. */
#[CoversClass(ConfiguracaoDaPlataformaDeIa::class)]
#[CoversClass(ConfiguracaoDeInteligencia::class)]
final class ConfiguracaoDaPlataformaDeIaTest extends TestCase
{
    #[TestDox('nasce com o id 1, a chave geral desligada, o Haiku 5.5, 12 meses de retenção, e é auditável')]
    public function testPadroes(): void
    {
        $c = new ConfiguracaoDaPlataformaDeIa();

        self::assertSame(1, $c->getId());
        self::assertFalse($c->isLigadaParaTodos());
        self::assertSame('claude-haiku-5-5', $c->getModelo());
        self::assertSame(ModeloDeIa::Haiku55, $c->modeloEscolhido());
        self::assertSame(12, $c->getMesesDeRetencao());
        self::assertNull($c->getAlteradaPor());
        self::assertInstanceOf(Auditavel::class, $c);
    }

    #[TestDox('ligar, desligar, escolher o modelo e a retenção gravam quem e quando')]
    public function testAlteracoesRegistramQuem(): void
    {
        $c = new ConfiguracaoDaPlataformaDeIa();
        $master = new User();
        $outro = new User();
        $agora = new \DateTimeImmutable('2026-10-24 10:00');

        $c->ligarParaTodos($master, $agora);
        self::assertTrue($c->isLigadaParaTodos());
        self::assertSame($master, $c->getAlteradaPor());
        self::assertEquals($agora, $c->getAlteradaEm());

        $c->escolherModelo(ModeloDeIa::Opus55, $outro, $agora->modify('+1 hour'));
        self::assertSame('claude-opus-5-5', $c->getModelo());
        self::assertSame($outro, $c->getAlteradaPor());

        $c->definirRetencao(24, $master, $agora->modify('+2 hours'));
        self::assertSame(24, $c->getMesesDeRetencao());
        self::assertEquals($agora->modify('+2 hours'), $c->getAlteradaEm());

        $c->desligarParaTodos($master, $agora);
        self::assertFalse($c->isLigadaParaTodos());
    }

    /** @return iterable<string, array{int}> */
    public static function retencoesInvalidas(): iterable
    {
        yield 'zero' => [0];
        yield 'negativa' => [-3];
        yield 'acima de 120' => [121];
    }

    #[DataProvider('retencoesInvalidas')]
    #[TestDox('retenção fora de 1 a 120 meses ($_dataName) é recusada e nada muda')]
    public function testRetencaoForaDaFaixa(int $meses): void
    {
        $c = new ConfiguracaoDaPlataformaDeIa();

        try {
            $c->definirRetencao($meses, new User(), new \DateTimeImmutable());
            self::fail('deveria recusar');
        } catch (\InvalidArgumentException) {
            self::assertSame(12, $c->getMesesDeRetencao());
        }
    }

    #[TestDox('escritório: nasce não liberado, incluso, sem orçamento; liberar e bloquear não mexem no aceite')]
    public function testLiberacaoDoEscritorio(): void
    {
        $user = new User();
        $c = new ConfiguracaoDeInteligencia(new Tenant());

        self::assertFalse($c->isLiberadaPeloMaster());
        self::assertSame(ModoDeCobrancaDaIa::Incluso, $c->getModoDeCobranca());
        self::assertNull($c->getOrcamentoMensalCentavos());

        $c->atualizar(true, 50, 500, true, $user);
        $aceite = $c->getConsentimentoEnvioExternoEm();
        $c->liberarPeloMaster();
        self::assertTrue($c->isLiberadaPeloMaster());
        $c->bloquearPeloMaster();
        self::assertFalse($c->isLiberadaPeloMaster());
        self::assertSame($aceite, $c->getConsentimentoEnvioExternoEm(), 'o aceite é do escritório; o Master não o apaga');
        self::assertTrue($c->isHabilitada());
    }

    #[TestDox('cobrança: modo e orçamento gravam; nulo é sem orçamento; 0 e o teto valem')]
    public function testCobranca(): void
    {
        $c = new ConfiguracaoDeInteligencia(new Tenant());

        $c->definirCobranca(ModoDeCobrancaDaIa::LimiteGratis, 1250);
        self::assertSame(ModoDeCobrancaDaIa::LimiteGratis, $c->getModoDeCobranca());
        self::assertSame(1250, $c->getOrcamentoMensalCentavos());
        $c->definirCobranca(ModoDeCobrancaDaIa::Adicional, null);
        self::assertNull($c->getOrcamentoMensalCentavos());
        $c->definirCobranca(ModoDeCobrancaDaIa::Adicional, 0);
        self::assertSame(0, $c->getOrcamentoMensalCentavos());
        $c->definirCobranca(ModoDeCobrancaDaIa::Adicional, 10_000_000);
        self::assertSame(10_000_000, $c->getOrcamentoMensalCentavos());
    }

    /** @return iterable<string, array{int}> */
    public static function orcamentosInvalidos(): iterable
    {
        yield 'negativo' => [-1];
        yield 'acima do teto' => [10_000_001];
    }

    #[DataProvider('orcamentosInvalidos')]
    #[TestDox('orçamento $_dataName é recusado e nada muda')]
    public function testOrcamentoInvalido(int $centavos): void
    {
        $c = new ConfiguracaoDeInteligencia(new Tenant());

        try {
            $c->definirCobranca(ModoDeCobrancaDaIa::Adicional, $centavos);
            self::fail('deveria recusar');
        } catch (\InvalidArgumentException) {
            self::assertSame(ModoDeCobrancaDaIa::Incluso, $c->getModoDeCobranca());
            self::assertNull($c->getOrcamentoMensalCentavos());
        }
    }
}
