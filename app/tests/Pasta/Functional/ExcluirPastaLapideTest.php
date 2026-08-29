<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Pasta\UseCase\GerarNumeroDePasta;
use App\Pasta\UseCase\ResultadoExclusaoPasta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Funcional de propósito: o que precisa ser provado aqui é a expressão SQL que decide entre lápide
 * e exclusão real, o recorte por tenant_id dela, e o efeito disso na numeração — nada disso existe
 * com o banco mockado. É o caso de produção de 28/08/2026 virado teste.
 */
#[CoversClass(ExcluirPastaUseCase::class)]
#[CoversClass(\App\Pasta\Service\NumeracaoDePasta::class)]
final class ExcluirPastaLapideTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function numeracao(): NumeracaoDePastaInterface
    {
        return static::getContainer()->get(NumeracaoDePastaInterface::class);
    }

    private function excluir(Pasta $pasta, Tenant $tenant): ResultadoExclusaoPasta
    {
        return static::getContainer()->get(ExcluirPastaUseCase::class)
            ->executar($pasta, UserFactory::createOne()->_real(), $tenant);
    }

    private function proximoNumero(Tenant $tenant): string
    {
        $gerador = static::getContainer()->get(GerarNumeroDePasta::class);

        return $this->em()->wrapInTransaction(fn (): string => $gerador->executar($tenant));
    }

    #[TestDox('Pasta do MEIO vira lápide: a linha continua no banco, riscada e arquivada')]
    public function testPastaComPosteriorViraLapide(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $doMeio = PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant])->_real();
        PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant]);

        self::assertSame(ResultadoExclusaoPasta::Lapide, $this->excluir($doMeio, $tenant));

        $recarregada = $this->em()->find(Pasta::class, $doMeio->getId());
        self::assertNotNull($recarregada, 'A linha da lápide não pode sumir do banco.');
        self::assertTrue($recarregada->estaExcluida());
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $recarregada->getSituacao());
        self::assertNotNull($recarregada->getExcluidaEm());
        self::assertNotNull($recarregada->getExcluidaPor());
    }

    #[TestDox('Pasta ÚLTIMA é apagada de verdade: a linha some do banco')]
    public function testUltimaPastaEhRemovidaDeVerdade(): void
    {
        self::bootKernel();
        $tenant  = TenantFactory::createOne()->_real();
        PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant]);
        $ultima  = PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant])->_real();
        $idDela  = $ultima->getId();

        self::assertSame(ResultadoExclusaoPasta::Removida, $this->excluir($ultima, $tenant));

        $this->em()->clear();
        self::assertNull($this->em()->find(Pasta::class, $idDela));
    }

    #[TestDox('O CASO DE PRODUÇÃO: apagar a última devolve o número, apagar a do meio não')]
    public function testNumeroVoltaSoQuandoAExcluidaEraAUltima(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $p1238  = PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant])->_real();
        $p1239  = PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant])->_real();

        // Excluir a última (1239) devolve o número: é o comportamento que o dono quis manter.
        self::assertSame(ResultadoExclusaoPasta::Removida, $this->excluir($p1239, $tenant));
        self::assertSame('1239', $this->proximoNumero($tenant));

        // Já a do meio vira lápide e SEGURA o número: sem isso o 1238 morreria em silêncio, que é
        // exatamente o que aconteceu em produção em 28/08/2026.
        PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant]);
        self::assertSame(ResultadoExclusaoPasta::Lapide, $this->excluir($p1238, $tenant));
        self::assertSame('1240', $this->proximoNumero($tenant));
    }

    #[TestDox('A lápide continua ocupando o número: excluir a de baixo não libera o dela')]
    public function testLapideContinuaOcupandoONumero(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $p10    = PastaFactory::createOne(['nup' => '10', 'tenant' => $tenant])->_real();
        $p11    = PastaFactory::createOne(['nup' => '11', 'tenant' => $tenant])->_real();
        PastaFactory::createOne(['nup' => '12', 'tenant' => $tenant]);

        $this->excluir($p11, $tenant);                                   // 11 vira lápide
        // A 10 continua tendo posterior — a lápide da 11 conta como número ocupado.
        self::assertSame(ResultadoExclusaoPasta::Lapide, $this->excluir($p10, $tenant));
    }

    #[TestDox('ISOLAMENTO: pasta de número maior do vizinho não conta na minha decisão')]
    public function testDecisaoIgnoraPastaDeOutroEscritorio(): void
    {
        self::bootKernel();
        $meu   = TenantFactory::createOne()->_real();
        $outro = TenantFactory::createOne()->_real();

        PastaFactory::createOne(['nup' => '9000', 'tenant' => $outro]);
        $minha = PastaFactory::createOne(['nup' => '5', 'tenant' => $meu])->_real();

        // Se o tenant_id caísse da SQL crua, a 9000 do vizinho faria esta virar lápide.
        self::assertSame(ResultadoExclusaoPasta::Removida, $this->excluir($minha, $meu));
    }

    #[TestDox('Número repetido não conta como posterior: a gêmea não libera nada')]
    public function testNumeroRepetidoNaoContaComoPosterior(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $uma    = PastaFactory::createOne(['nup' => '77', 'tenant' => $tenant])->_real();
        PastaFactory::createOne(['nup' => '77', 'tenant' => $tenant]);

        // O NUP não é único (Version20260701144054). "Maior" é estritamente maior.
        self::assertSame(ResultadoExclusaoPasta::Removida, $this->excluir($uma, $tenant));
    }

    #[TestDox('Sufixo de letra do legado conta pelo prefixo: 10B é posterior à 9')]
    public function testSufixoDeLetraContaPeloPrefixo(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $p9     = PastaFactory::createOne(['nup' => '9', 'tenant' => $tenant])->_real();
        PastaFactory::createOne(['nup' => '10B', 'tenant' => $tenant]);

        self::assertSame(ResultadoExclusaoPasta::Lapide, $this->excluir($p9, $tenant));
    }

    #[TestDox('NUP fora da sequência vira lápide: apagá-lo não devolveria número nenhum')]
    public function testNupNaoNumericoViraLapide(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $fora   = PastaFactory::createOne(['nup' => 'PROC-ABC', 'tenant' => $tenant])->_real();

        self::assertTrue($this->numeracao()->existeNumeroMaiorQue($tenant, 'PROC-ABC'));
        self::assertSame(ResultadoExclusaoPasta::Lapide, $this->excluir($fora, $tenant));
    }

    #[TestDox('Excluir exige transação: a decisão sem trava é recusada em vez de sair errada')]
    public function testDecisaoSemTravaEhRecusada(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();

        // A DAMA segura a transação no nível do DRIVER, que o DBAL não enxerga — então aqui não há
        // transação para o `travar()`, e ele precisa falhar alto. Se um dia alguém tirar o
        // wrapInTransaction do UseCase, é este teste que cai.
        self::assertFalse($this->em()->getConnection()->isTransactionActive());

        $this->expectException(\LogicException::class);
        $this->numeracao()->travar($tenant);
    }
}
