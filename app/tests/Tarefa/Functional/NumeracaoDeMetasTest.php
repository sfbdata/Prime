<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PrioridadePasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Service\NumeracaoDeMetas;
use App\Tarefa\UseCase\GravarMetaNovaUseCase;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Funcional (não unit) de propósito, como o GerarNumeroDePastaTest: o que precisa ser provado é o SQL do
 * contador, o recorte por tenant_id e a trava contra o Postgres de verdade. Com EntityManager dublado o
 * teste passaria sem provar nada disso.
 */
#[CoversClass(NumeracaoDeMetas::class)]
#[CoversClass(GravarMetaNovaUseCase::class)]
final class NumeracaoDeMetasTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function numeracao(): NumeracaoDeMetas
    {
        return static::getContainer()->get(NumeracaoDeMetas::class);
    }

    private function reservar(Tenant $tenant): int
    {
        return $this->em()->wrapInTransaction(fn (): int => $this->numeracao()->reservar((int) $tenant->getId()));
    }

    /** Cria a meta pelo caminho de produção (o UseCase), que é o que dá o número. */
    private function criarMeta(Tenant $tenant, string $titulo = 'Meta', PrioridadePasta $prioridadeDaPasta = PrioridadePasta::Normal): Tarefa
    {
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => $prioridadeDaPasta])->_real();

        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        static::getContainer()->get(GravarMetaNovaUseCase::class)->executar($meta);

        return $meta;
    }

    #[TestDox('Desenho 04: a meta nova herda a prioridade da pasta (urgente vira Urgente, prioridade vira Alta, normal fica Normal) e grava')]
    public function testMetaNovaHerdaAPrioridadeDaPasta(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $metas  = [
            'urgente'    => $this->criarMeta($tenant, 'Da urgente', PrioridadePasta::Urgente),
            'alta'       => $this->criarMeta($tenant, 'Da prioridade', PrioridadePasta::Prioridade),
            'normal'     => $this->criarMeta($tenant, 'Da normal', PrioridadePasta::Normal),
        ];
        $this->em()->clear();

        foreach ($metas as $esperado => $meta) {
            $gravada = $this->em()->find(Tarefa::class, $meta->getId());
            self::assertSame($esperado, $gravada?->getPrioridade()->value, 'gravada no banco: ' . $esperado);
        }
    }

    #[TestDox('Prioridade já escolhida antes de gravar não é sobrescrita pela da pasta')]
    public function testPrioridadeJaEscolhidaNaoEhSobrescrita(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Urgente])->_real();
        $meta   = new Tarefa();
        $meta->setTitulo('Escolhida');
        $meta->setDescricao('...');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setPrioridade(PrioridadeDaMeta::Alta);

        static::getContainer()->get(GravarMetaNovaUseCase::class)->executar($meta);

        self::assertSame(PrioridadeDaMeta::Alta, $meta->getPrioridade());
    }

    #[TestDox('Escritório sem nenhuma meta numerada começa no 1')]
    public function testEscritorioVazioComecaNoUm(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();

        self::assertSame(1, $this->criarMeta($tenant)->getNumero());
    }

    #[TestDox('Criar meta pelo UseCase grava o número no banco, em sequência')]
    public function testCriarMetaGravaONumeroEmSequencia(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();

        $primeira = $this->criarMeta($tenant, 'Primeira');
        $segunda  = $this->criarMeta($tenant, 'Segunda');

        $gravado = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT numero FROM tarefa WHERE id IN (?, ?) ORDER BY id',
            [$primeira->getId(), $segunda->getId()],
        );
        self::assertSame([1, 2], array_map('intval', $gravado));
    }

    #[TestDox('CONCORRÊNCIA: duas reservas seguidas na mesma transação dão números seguidos, nunca o mesmo')]
    public function testDuasReservasNaMesmaTransacaoDaoNumerosSeguidos(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $id     = (int) $tenant->getId();

        // Sem a gravação da meta entre uma e outra: é o contador (e não o MAX das metas) que avança.
        [$a, $b] = $this->em()->wrapInTransaction(fn (): array => [
            $this->numeracao()->reservar($id),
            $this->numeracao()->reservar($id),
        ]);

        self::assertSame([1, 2], [$a, $b]);
    }

    #[TestDox('ISOLAMENTO: a sequência de um escritório não interfere na do outro')]
    public function testEscritoriosNaoInterferem(): void
    {
        self::bootKernel();
        $meu   = TenantFactory::createOne()->_real();
        $outro = TenantFactory::createOne()->_real();

        $this->criarMeta($outro);
        $this->criarMeta($outro);
        $this->criarMeta($outro);

        // Se o tenant_id caísse do SQL cru, viria 4: o número do vizinho.
        self::assertSame(1, $this->criarMeta($meu)->getNumero());
        self::assertSame(4, $this->criarMeta($outro)->getNumero());
    }

    #[TestDox('NUNCA REAPROVEITA: excluir a última meta não devolve o número dela')]
    public function testExcluirAUltimaMetaNaoDevolveONumero(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $meta   = $this->criarMeta($tenant);
        self::assertSame(1, $meta->getNumero());

        $em = $this->em();
        $em->remove($meta);
        $em->flush();

        // Pelo MAX das metas viria 1 de novo (é o limite conhecido da numeração de pasta); o contador não volta.
        self::assertSame(2, $this->criarMeta($tenant)->getNumero());
    }

    #[TestDox('O contador nunca fica para trás de um número já gravado na meta')]
    public function testRespeitaONumeroJaGravado(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $antiga = PastaFactory::createOne(['tenant' => $tenant])->_real();

        $meta = new Tarefa();
        $meta->setTitulo('Numerada por fora');
        $meta->setDescricao('...');
        $meta->setPasta($antiga);
        $meta->setTenant($tenant);
        $meta->numerar(7);
        $this->em()->persist($meta);
        $this->em()->flush();

        self::assertSame(8, $this->reservar($tenant));
    }

    #[TestDox('Sem transação aberta a trava não seguraria: falha alto em vez de dar número inseguro')]
    public function testSemTransacaoLancaLogicException(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();

        // A DAMA segura a transação no nível do driver, que o DBAL não enxerga (ver GerarNumeroDePastaTest).
        self::assertFalse($this->em()->getConnection()->isTransactionActive());

        $this->expectException(\LogicException::class);
        $this->numeracao()->reservar((int) $tenant->getId());
    }

    #[TestDox('Meta de escritório não gravado é recusada antes de abrir a transação')]
    public function testMetaSemEscritorioGravadoEhRecusada(): void
    {
        self::bootKernel();
        $tenant = new Tenant();
        $tenant->setName('Nunca gravado');

        $meta = new Tarefa();
        $meta->setTitulo('Sem escritório');
        $meta->setDescricao('...');
        $meta->setTenant($tenant);

        $this->expectException(\InvalidArgumentException::class);
        static::getContainer()->get(GravarMetaNovaUseCase::class)->executar($meta);
    }
}
