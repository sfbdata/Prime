<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\UseCase\RegravarNomeDasPastasJudiciaisUseCase;
use App\Entity\Auth\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegravarNomeDasPastasJudiciaisUseCase::class)]
final class RegravarNomeDasPastasJudiciaisUseCaseTest extends TestCase
{
    #[Test]
    #[TestDox('Simulação lista a mudança e não grava nada')]
    public function simulacaoNaoGrava(): void
    {
        $pasta = $this->pasta(10, 'APLC TOP LIFE 1 - CLAUDIO');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $mudancas = $this->useCase([$this->caso($pasta, 'APLC TOP LIFE 1')], $em)->executar(new Tenant(), false);

        self::assertSame([['pastaId' => 10, 'antes' => 'APLC TOP LIFE 1 - CLAUDIO', 'depois' => 'APLC TOP LIFE 1']], $mudancas);
        self::assertSame('APLC TOP LIFE 1 - CLAUDIO', $pasta->getNomeCliente());
    }

    #[Test]
    #[TestDox('Aplicar regrava o nome e dá flush uma vez')]
    public function aplicarRegrava(): void
    {
        $pasta = $this->pasta(10, 'MARIA (unidade 03-08)');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->useCase([$this->caso($pasta, 'APLC TOP LIFE 1')], $em)->executar(new Tenant(), true);

        self::assertSame('APLC TOP LIFE 1', $pasta->getNomeCliente());
    }

    #[Test]
    #[TestDox('Pasta já no padrão não muda, e pasta com dois casos conta uma vez')]
    public function jaNoPadraoEPastaComDoisCasos(): void
    {
        $certa = $this->pasta(10, 'APLC TOP LIFE 1');
        $dupla = $this->pasta(11, 'ANTIGO');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $mudancas = $this->useCase([
            $this->caso($certa, 'APLC TOP LIFE 1'),
            $this->caso($dupla, 'CREDOR A'),
            $this->caso($dupla, 'CREDOR B'),
        ], $em)->executar(new Tenant(), true);

        self::assertCount(1, $mudancas);
        self::assertSame('CREDOR A', $dupla->getNomeCliente(), 'vale o primeiro caso da pasta');
    }

    #[Test]
    #[TestDox('Caso sem cliente de carteira não apaga o nome gravado')]
    public function casoSemClienteNaoMuda(): void
    {
        $pasta = $this->pasta(10, 'TEXTO');
        $caso = (new CasoCobranca())->setPastaJudicial($pasta);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        self::assertSame([], $this->useCase([$caso], $em)->executar(new Tenant(), true));
        self::assertSame('TEXTO', $pasta->getNomeCliente());
    }

    /** @param list<CasoCobranca> $casos */
    private function useCase(array $casos, EntityManagerInterface $em): RegravarNomeDasPastasJudiciaisUseCase
    {
        $repo = $this->createStub(CasoCobrancaRepository::class);
        $repo->method('comPastaJudicial')->willReturn($casos);

        return new RegravarNomeDasPastasJudiciaisUseCase($repo, new ComporNomeDaPastaJudicial(), $em);
    }

    private function pasta(int $id, string $nome): Pasta
    {
        $pasta = new Pasta();
        (new \ReflectionProperty($pasta, 'id'))->setValue($pasta, $id);
        $pasta->setNomeCliente($nome);

        return $pasta;
    }

    private function caso(Pasta $pasta, string $fantasia): CasoCobranca
    {
        $cliente = new ClientePJ();
        $cliente->setNomeFantasia($fantasia);
        $cliente->setRazaoSocial('RAZAO LTDA');
        $carteira = new Carteira();
        $carteira->setCliente($cliente);
        $objeto = new ObjetoCobranca();
        $objeto->setCarteira($carteira);
        $caso = new CasoCobranca();
        $caso->setObjeto($objeto);
        $caso->setPastaJudicial($pasta);

        return $caso;
    }
}
