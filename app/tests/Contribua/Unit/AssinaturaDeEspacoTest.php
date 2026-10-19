<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Entity\ConfiguracaoDePagamento;
use App\Contribua\Entity\Plano;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\Service\AtualizacaoDaAssinatura;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssinaturaDeEspaco::class)]
#[CoversClass(ConfiguracaoDePagamento::class)]
#[CoversClass(AtualizacaoDaAssinatura::class)]
final class AssinaturaDeEspacoTest extends TestCase
{
    #[TestDox('Nasce aguardando e não dá espaço nenhum')]
    public function testNasceAguardando(): void
    {
        $a = $this->assinatura();

        self::assertSame(SituacaoDaAssinatura::Aguardando, $a->getSituacao());
        self::assertFalse($a->vigenteEm(new \DateTimeImmutable(), 7));
    }

    #[TestDox('Pago no gateway vira Ativa e vale até o fim do período pago mais a carência')]
    public function testAtivaComCarencia(): void
    {
        $a = $this->assinatura();
        $ate = new \DateTimeImmutable('2026-11-19 10:00');

        self::assertTrue($a->aplicar($this->noGateway(SituacaoDaAssinatura::Ativa, $ate)));
        self::assertTrue($a->vigenteEm($ate->modify('+7 days'), 7));
        self::assertFalse($a->vigenteEm($ate->modify('+8 days'), 7));
    }

    #[TestDox('Cancelada vale só até o fim do período pago, sem carência')]
    public function testCanceladaSemCarencia(): void
    {
        $a = $this->assinatura();
        $ate = new \DateTimeImmutable('2026-11-19 10:00');
        $a->aplicar($this->noGateway(SituacaoDaAssinatura::Ativa, $ate));
        $a->cancelar();

        self::assertTrue($a->vigenteEm($ate, 7));
        self::assertFalse($a->vigenteEm($ate->modify('+1 day'), 7));
        self::assertNotNull($a->getCanceladaEm());
    }

    #[TestDox('O gateway dizendo "aguardando" não desfaz a confirmação manual do Master')]
    public function testAguardandoNaoDesfazConfirmacao(): void
    {
        $a = $this->assinatura();
        $a->confirmarManualmente(new User(), new \DateTimeImmutable('2026-10-19'));

        self::assertFalse($a->aplicar($this->noGateway(SituacaoDaAssinatura::Aguardando, null)));
        self::assertSame(SituacaoDaAssinatura::Ativa, $a->getSituacao());
        self::assertEquals(new \DateTimeImmutable('2026-11-19'), $a->getPagaAte());
    }

    #[TestDox('O período pago nunca recua; inadimplência volta a Ativa quando o pagamento chega')]
    public function testPeriodoNaoRecua(): void
    {
        $a = $this->assinatura();
        $a->aplicar($this->noGateway(SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('2026-12-19')));
        $a->aplicar($this->noGateway(SituacaoDaAssinatura::Inadimplente, new \DateTimeImmutable('2026-11-19')));

        self::assertEquals(new \DateTimeImmutable('2026-12-19'), $a->getPagaAte());
        self::assertNotNull($a->getInadimplenteDesde());

        $a->aplicar($this->noGateway(SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('2027-01-19')));
        self::assertNull($a->getInadimplenteDesde());
    }

    #[TestDox('Cancelada é final: uma reconsulta atrasada dizendo "ativa" não a ressuscita')]
    public function testCanceladaNaoVolta(): void
    {
        $a = $this->assinatura();
        $a->aplicar($this->noGateway(SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('+1 month')));
        $a->cancelar();

        self::assertFalse($a->aplicar($this->noGateway(SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('+2 months'))));
        self::assertSame(SituacaoDaAssinatura::Cancelada, $a->getSituacao());
    }

    #[TestDox('Só confirma à mão o que está aguardando')]
    public function testConfirmarSoAguardando(): void
    {
        $a = $this->assinatura();
        $a->aplicar($this->noGateway(SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('+1 month')));

        $this->expectException(\DomainException::class);
        $a->confirmarManualmente(new User(), new \DateTimeImmutable());
    }

    #[TestDox('Um pedido não troca de assinatura no gateway')]
    public function testNaoTrocaDeAssinatura(): void
    {
        $a = $this->assinatura();
        $a->vincularNoGateway('sub_1', 'cus_1');
        $a->vincularNoGateway('sub_1', null);
        self::assertSame('cus_1', $a->getIdDoCliente());

        $this->expectException(\DomainException::class);
        $a->vincularNoGateway('sub_2', null);
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function referencias(): iterable
    {
        yield 'pedido completo' => ['BJ000007-essencial-42', 7];
        yield 'só o escritório (Asaas)' => ['BJ000123', 123];
        yield 'sem prefixo' => ['000007-essencial', null];
        yield 'conta zero' => ['BJ000000', null];
        yield 'nula' => [null, null];
        yield 'lixo depois do número' => ['BJ7x', null];
    }

    #[DataProvider('referencias')]
    #[TestDox('O escritório sai da referência BJ<conta>')]
    public function testTenantDaReferencia(?string $referencia, ?int $esperado): void
    {
        self::assertSame($esperado, AtualizacaoDaAssinatura::tenantDaReferencia($referencia));
    }

    #[TestDox('Configuração: carência de 0 a 60 dias e link só https')]
    public function testConfiguracaoValida(): void
    {
        $c = new ConfiguracaoDePagamento();
        self::assertSame(7, $c->getDiasDeCarencia());
        self::assertNull($c->getGateway());

        $c->configurar('asaas', null, ['essencial' => 'https://pague.exemplo/x'], 10);
        self::assertSame('https://pague.exemplo/x', $c->linkDoPacote('essencial'));

        $this->expectException(\DomainException::class);
        $c->configurar('asaas', null, ['essencial' => 'http://inseguro.exemplo'], 10);
    }

    #[TestDox('Configuração: carência fora da faixa é recusada')]
    public function testCarenciaForaDaFaixa(): void
    {
        $this->expectException(\DomainException::class);
        (new ConfiguracaoDePagamento())->configurar(null, null, [], 61);
    }

    private function assinatura(): AssinaturaDeEspaco
    {
        return new AssinaturaDeEspaco(new Tenant(), new Plano('essencial', 'Essencial', 20, 1990, 'Para o escritório que cresce', 2, 8), 'falso', 1990, null);
    }

    private function noGateway(SituacaoDaAssinatura $situacao, ?\DateTimeImmutable $pagaAte): SituacaoNoGateway
    {
        return new SituacaoNoGateway('sub_1', $situacao, $pagaAte);
    }
}
