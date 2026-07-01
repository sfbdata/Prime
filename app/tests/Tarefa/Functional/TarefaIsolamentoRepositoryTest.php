<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Shared\Doctrine\Filter\TenantFilter;
use App\Tarefa\Repository\TarefaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida que o TenantFilter isola o domínio Tarefa após Tarefa + TarefaMensagem virarem
 * TenantAware. Cobre as duas queries que antes vazavam (findByResponsavel para usuário
 * multi-tenant, findByProcesso), a carga por id (Tarefa e a filha TarefaMensagem — que tem
 * coluna própria justamente porque há rotas que a carregam por id direto). em->clear() força
 * o find() a executar SQL real (em produção a identity map começa vazia por request).
 */
#[CoversClass(TenantFilter::class)]
#[CoversClass(TarefaRepository::class)]
final class TarefaIsolamentoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('findByResponsavel só retorna tarefas do tenant ativo (fecha o vazamento p/ usuário multi-tenant)')]
    public function testFindByResponsavelIsolaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUser();

        $tarefaA = $this->criarTarefa($this->criarPasta($tenantA), $usuario);
        $tarefaB = $this->criarTarefa($this->criarPasta($tenantB), $usuario);

        /** @var TarefaRepository $repo */
        $repo = $this->em->getRepository(Tarefa::class);

        // sem filtro: vê as duas (estado de vazamento)
        self::assertCount(2, $repo->findByResponsavel($usuario));

        $this->ligarFiltro((int) $tenantA->getId());
        $doA = $repo->findByResponsavel($usuario);
        self::assertCount(1, $doA);
        self::assertSame($tarefaA->getId(), $doA[0]->getId());

        $this->ligarFiltro((int) $tenantB->getId());
        $doB = $repo->findByResponsavel($usuario);
        self::assertCount(1, $doB);
        self::assertSame($tarefaB->getId(), $doB[0]->getId());
    }

    #[TestDox('findByProcesso não vaza tarefas de pasta de outro tenant ligada ao mesmo processo')]
    public function testFindByProcessoIsolaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $processo = $this->criarProcesso($tenantA);

        $pastaA = $this->criarPasta($tenantA, $processo);
        $tarefaA = $this->criarTarefa($pastaA, $this->criarUser());

        // pasta de outro tenant apontando para o mesmo processo (vetor de vazamento)
        $pastaCross = $this->criarPasta($tenantB, $processo);
        $this->criarTarefa($pastaCross, $this->criarUser());

        /** @var TarefaRepository $repo */
        $repo = $this->em->getRepository(Tarefa::class);

        $this->ligarFiltro((int) $tenantA->getId());
        $resultado = $repo->findByProcesso($processo);
        self::assertCount(1, $resultado, 'findByProcesso vazou tarefa de pasta de outro tenant');
        self::assertSame($tarefaA->getId(), $resultado[0]->getId());
    }

    #[TestDox('find() por id de Tarefa de outro tenant retorna null (fecha IDOR da raiz)')]
    public function testFindPorIdFechaIdorDaTarefa(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $tarefaB = $this->criarTarefa($this->criarPasta($tenantB), $this->criarUser());
        $idB = (int) $tarefaB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull($this->em->find(Tarefa::class, $idB), 'IDOR aberto na raiz Tarefa');
    }

    #[TestDox('find() por id de TarefaMensagem de outro tenant retorna null (coluna própria fecha o IDOR direto)')]
    public function testFindPorIdFechaIdorDaMensagem(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $tarefaB = $this->criarTarefa($this->criarPasta($tenantB), $this->criarUser());
        $mensagemB = $this->criarMensagem($tarefaB);
        $idMsgB = (int) $mensagemB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull(
            $this->em->find(TarefaMensagem::class, $idMsgB),
            'IDOR aberto em TarefaMensagem: rotas editar/visualizar-anexo a carregam por id direto',
        );
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant TAREFA ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('tarefa_' . uniqid() . '@test.com');
        $user->setFullName('User Tarefa');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant, ?Processo $processo = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TAR-' . (++$this->seq) . '-' . uniqid());
        $pasta->setTenant($tenant);
        if ($processo !== null) {
            $pasta->vincularProcesso($processo);
        }
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    private function criarTarefa(Pasta $pasta, User $criador): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta ' . uniqid());
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($pasta->getTenant());
        $tarefa->setCriadoPor($criador);
        $this->em->persist($tarefa);
        $this->em->flush();

        return $tarefa;
    }

    private function criarMensagem(Tarefa $tarefa): TarefaMensagem
    {
        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($this->criarUser());
        $mensagem->setMensagem('Mensagem de teste');
        $mensagem->setTenant($tarefa->getTenant());
        $this->em->persist($mensagem);
        $this->em->flush();

        return $mensagem;
    }

    private function criarProcesso(Tenant $tenant): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso('NUM-' . (++$this->seq) . '-' . uniqid());
        $processo->setSiglaTribunal('TRT2');
        $processo->setClasseProcessual('CLS');
        $processo->setAssuntoProcessual('ASS');
        $processo->setTenant($tenant);
        $this->em->persist($processo);
        $this->em->flush();

        return $processo;
    }
}
