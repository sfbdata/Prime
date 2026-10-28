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
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
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

    #[Test]
    #[TestDox('Pasta comum com cliente principal e texto composto passa a guardar o nome do cliente')]
    public function pastaNaoJudicialRecebeONomeDoPrincipal(): void
    {
        $pasta = $this->pastaComPrincipal(20, 'ACME LTDA - JOAO', 'ACME LTDA');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $mudancas = $this->useCase([], $em, [$pasta])->executar(new Tenant(), true);

        self::assertSame([['pastaId' => 20, 'antes' => 'ACME LTDA - JOAO', 'depois' => 'ACME LTDA']], $mudancas);
        self::assertSame('ACME LTDA', $pasta->getNomeCliente());
    }

    #[Test]
    #[TestDox('Pasta comum: simulação não grava e o segundo uso não muda nada (idempotente)')]
    public function pastaNaoJudicialSimulaEEIdempotente(): void
    {
        $pasta = $this->pastaComPrincipal(20, 'ACME LTDA - JOAO', 'ACME LTDA');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $useCase = $this->useCase([], $em, [$pasta]);

        self::assertCount(1, $useCase->executar(new Tenant(), false));
        self::assertSame('ACME LTDA - JOAO', $pasta->getNomeCliente());

        $useCase->executar(new Tenant(), true);
        self::assertSame([], $useCase->executar(new Tenant(), true));
    }

    #[Test]
    #[TestDox('Pasta judicial que o repositório também devolve como composta não é regravada duas vezes')]
    public function pastaJudicialNaoPassaPelaRegraDoPrincipal(): void
    {
        $pasta = $this->pastaComPrincipal(10, 'CREDOR - DEVEDOR', 'RAZAO DO PRINCIPAL');
        $em = $this->createMock(EntityManagerInterface::class);

        $mudancas = $this->useCase([$this->caso($pasta, 'CREDOR')], $em, [$pasta])->executar(new Tenant(), true);

        self::assertSame([['pastaId' => 10, 'antes' => 'CREDOR - DEVEDOR', 'depois' => 'CREDOR']], $mudancas);
        self::assertSame('CREDOR', $pasta->getNomeCliente());
    }

    /**
     * @param list<CasoCobranca> $casos
     * @param list<Pasta>        $compostas
     */
    private function useCase(array $casos, EntityManagerInterface $em, array $compostas = []): RegravarNomeDasPastasJudiciaisUseCase
    {
        $repo = $this->createStub(CasoCobrancaRepository::class);
        $repo->method('comPastaJudicial')->willReturn($casos);
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('comNomeCompostoEClientePrincipal')->willReturn($compostas);

        return new RegravarNomeDasPastasJudiciaisUseCase($repo, new ComporNomeDaPastaJudicial(), $em, $pastas);
    }

    private function pastaComPrincipal(int $id, string $gravado, string $razaoSocial): Pasta
    {
        $pasta = $this->pasta($id, $gravado);
        $cliente = new ClientePJ();
        $cliente->setRazaoSocial($razaoSocial);
        $pasta->addCliente($cliente);

        return $pasta;
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
