<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Pasta\UseCase\GerarNumeroDePasta;
use App\Pasta\UseCase\RestaurarPastaUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * Funcional de propósito: toda exclusão vira lápide (lixeira restaurável), inclusive a da última
 * pasta da sequência, e o que precisa ser provado contra o Postgres é o efeito disso na numeração.
 * Desde 18/11/2026 (pedido do Samuel, no lugar da decisão de 28/08) a lápide NÃO ocupa número: se
 * ela era a última, o número volta a ser o próximo; buraco do meio continua sem uso (maior + 1).
 * Restaurar uma lápide cujo número já foi reusado é recusado.
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

    private function excluir(Pasta $pasta, Tenant $tenant): void
    {
        static::getContainer()->get(ExcluirPastaUseCase::class)
            ->executar($pasta, UserFactory::createOne()->_real(), $tenant);
    }

    private function recarregar(Pasta $pasta): ?Pasta
    {
        $id = $pasta->getId();
        $this->em()->clear();

        return $this->em()->find(Pasta::class, $id);
    }

    private function proximoNumero(Tenant $tenant): string
    {
        $gerador = static::getContainer()->get(GerarNumeroDePasta::class);

        return $this->em()->wrapInTransaction(fn (): string => $gerador->executar($tenant));
    }

    #[TestDox('Pasta do MEIO vai para a lixeira: a linha continua no banco, riscada e arquivada')]
    public function testPastaComPosteriorViraLapide(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $doMeio = PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant])->_real();
        PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant]);

        $this->excluir($doMeio, $tenant);

        $recarregada = $this->recarregar($doMeio);
        self::assertNotNull($recarregada, 'A linha da lápide não pode sumir do banco.');
        self::assertTrue($recarregada->estaExcluida());
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $recarregada->getSituacao());
        self::assertNotNull($recarregada->getExcluidaEm());
        self::assertNotNull($recarregada->getExcluidaPor());
    }

    #[TestDox('Pasta ÚLTIMA também vai para a lixeira: a linha fica e a pasta é restaurável')]
    public function testUltimaPastaViraLapideRestauravel(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant]);
        $ultima = PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant])->_real();

        $this->excluir($ultima, $tenant);

        $recarregada = $this->recarregar($ultima);
        self::assertNotNull($recarregada, 'A última da sequência não pode mais ser apagada de verdade.');
        self::assertTrue($recarregada->estaExcluida());

        static::getContainer()->get(RestaurarPastaUseCase::class)->executar($recarregada, $recarregada->getTenant());

        $restaurada = $this->recarregar($ultima);
        self::assertNotNull($restaurada);
        self::assertFalse($restaurada->estaExcluida(), 'A pasta excluída tem de voltar ao ser restaurada.');
    }

    #[TestDox('Excluir a ÚLTIMA devolve o número dela: a próxima pasta nasce com ele')]
    public function testExcluirAUltimaDevolveONumero(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant]);
        $p1239 = PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant])->_real();
        self::assertSame('1240', $this->proximoNumero($tenant));

        $this->excluir($p1239, $tenant);

        self::assertSame('1239', $this->proximoNumero($tenant));
    }

    #[TestDox('Excluir a do MEIO não muda o próximo: buraco não é preenchido (maior + 1)')]
    public function testExcluirADoMeioNaoPreencheBuraco(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $p1238  = PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant])->_real();
        PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant]);

        $this->excluir($p1238, $tenant);

        self::assertSame('1240', $this->proximoNumero($tenant));
    }

    #[TestDox('Restaurar lápide cujo número já foi reusado é recusado com a mensagem, e ela fica na lixeira')]
    public function testRestaurarComNumeroReusadoEhRecusado(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        PastaFactory::createOne(['nup' => '1238', 'tenant' => $tenant]);
        $p1239 = PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant])->_real();
        $this->excluir($p1239, $tenant);
        PastaFactory::createOne(['nup' => $this->proximoNumero($tenant), 'nomeCliente' => 'MARIA NOVA', 'tenant' => $tenant]);

        $lapide = $this->recarregar($p1239);
        self::assertNotNull($lapide);

        try {
            static::getContainer()->get(RestaurarPastaUseCase::class)->executar($lapide, $lapide->getTenant());
            self::fail('Restaurar com o número já reusado devia ser recusado.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'O número 1239 já é usado por outra pasta (MARIA NOVA). Mude o número dela antes de restaurar esta.',
                $e->getMessage(),
            );
        }

        $depois = $this->recarregar($p1239);
        self::assertNotNull($depois);
        self::assertTrue($depois->estaExcluida(), 'A lápide recusada continua na lixeira.');
    }

    #[TestDox('Número reusado em OUTRO escritório não impede restaurar')]
    public function testNumeroDeOutroEscritorioNaoImpedeRestaurar(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $p1239  = PastaFactory::createOne(['nup' => '1239', 'tenant' => $tenant])->_real();
        PastaFactory::createOne(['nup' => '1239', 'tenant' => TenantFactory::createOne()]);
        $this->excluir($p1239, $tenant);

        $lapide = $this->recarregar($p1239);
        self::assertNotNull($lapide);
        static::getContainer()->get(RestaurarPastaUseCase::class)->executar($lapide, $lapide->getTenant());

        $depois = $this->recarregar($p1239);
        self::assertNotNull($depois);
        self::assertFalse($depois->estaExcluida());
    }

    #[TestDox('ISOLAMENTO: excluir pasta de outro escritório é recusado e ela fica intacta')]
    public function testPastaDeOutroEscritorioEhRecusada(): void
    {
        self::bootKernel();
        $meu    = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $alheia = PastaFactory::createOne(['nup' => '9000', 'tenant' => $outro])->_real();

        try {
            $this->excluir($alheia, $meu);
            self::fail('Pasta de outro escritório não pode ser excluída.');
        } catch (AccessDeniedException) {
        }

        self::assertFalse($this->recarregar($alheia)?->estaExcluida());
    }

    #[TestDox('A numeração exige transação: a trava sem transação é recusada em vez de valer nada')]
    public function testTravaSemTransacaoEhRecusada(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();

        // A DAMA segura a transação no nível do DRIVER, que o DBAL não enxerga: então aqui não há
        // transação para o `travar()`, e ele precisa falhar alto. Se um dia alguém tirar o
        // wrapInTransaction de quem gera o número, é este teste que cai.
        self::assertFalse($this->em()->getConnection()->isTransactionActive());

        $this->expectException(\LogicException::class);
        $this->numeracao()->travar($tenant);
    }
}
