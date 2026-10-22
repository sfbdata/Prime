<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A esteira da pasta no repositório (desenho 04 de 22/10): a contagem por caixa (`contarEsteira`) e o clique
 * numa caixa (`findParaMinhasMetas` com o filtro `etapa`), sempre do escritório inteiro e só dele.
 */
#[CoversClass(TarefaRepository::class)]
#[Group('tarefa')]
final class TarefaRepositoryEsteiraTest extends KernelTestCase
{
    use Factories;

    private TarefaRepository $repo;
    private EntityManagerInterface $em;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(TarefaRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->hoje = new \DateTimeImmutable('today');
    }

    #[TestDox('contarEsteira agrupa por etapa e não conta concluída no total')]
    public function testAgrupaPorEtapaSemConcluidas(): void
    {
        $pasta = $this->pasta(TenantFactory::createOne()->_real());
        $this->meta($pasta, EtapaDaPasta::Juridico);
        $this->meta($pasta, EtapaDaPasta::Juridico, Tarefa::STATUS_EM_REVISAO);
        $this->meta($pasta, EtapaDaPasta::Juridico, Tarefa::STATUS_CONCLUIDA);
        $this->meta($pasta, EtapaDaPasta::Protocolo);
        $this->meta($pasta, EtapaDaPasta::Encerramento, Tarefa::STATUS_CONCLUIDA);

        $contagem = $this->repo->contarEsteira($pasta->getTenant(), $this->hoje);

        self::assertSame(['total' => 2, 'atrasadas' => 0], $contagem['juridico']);
        self::assertSame(['total' => 1, 'atrasadas' => 0], $contagem['protocolo']);
        self::assertArrayNotHasKey('encerramento', $contagem, 'caixa só com concluída não vem');
    }

    #[TestDox('Atrasada = prazo antes de hoje e status fora de concluída/em revisão')]
    public function testAtrasadas(): void
    {
        $pasta = $this->pasta(TenantFactory::createOne()->_real());
        $ontem = $this->hoje->modify('-1 day');
        $this->meta($pasta, EtapaDaPasta::Protocolo, Tarefa::STATUS_PENDENTE, $ontem);
        $this->meta($pasta, EtapaDaPasta::Protocolo, Tarefa::STATUS_EM_REVISAO, $ontem);
        $this->meta($pasta, EtapaDaPasta::Protocolo, Tarefa::STATUS_CONCLUIDA, $ontem);
        $this->meta($pasta, EtapaDaPasta::Protocolo, Tarefa::STATUS_PENDENTE, $this->hoje);
        $this->meta($pasta, EtapaDaPasta::Protocolo, Tarefa::STATUS_PENDENTE, null);

        $contagem = $this->repo->contarEsteira($pasta->getTenant(), $this->hoje);

        self::assertSame(['total' => 4, 'atrasadas' => 1], $contagem['protocolo']);
    }

    #[TestDox('contarEsteira só conta o escritório pedido')]
    public function testSoDoTenant(): void
    {
        $meu   = $this->pasta(TenantFactory::createOne()->_real());
        $outro = $this->pasta(TenantFactory::createOne()->_real());
        $this->meta($meu, EtapaDaPasta::Comercial);
        $this->meta($outro, EtapaDaPasta::Comercial, Tarefa::STATUS_PENDENTE, $this->hoje->modify('-3 days'));
        $this->meta($outro, EtapaDaPasta::Abertura);

        $contagem = $this->repo->contarEsteira($meu->getTenant(), $this->hoje);

        self::assertSame(['comercial' => ['total' => 1, 'atrasadas' => 0]], $contagem);
    }

    #[TestDox('contarEsteira não conta meta de pasta oculta pelo cadeado')]
    public function testSemPastasOcultas(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $visivel = $this->pasta($tenant);
        $oculta  = $this->pasta($tenant);
        $this->meta($visivel, EtapaDaPasta::Juridico);
        $this->meta($oculta, EtapaDaPasta::Juridico);

        $contagem = $this->repo->contarEsteira($tenant, $this->hoje, [(int) $oculta->getId()]);

        self::assertSame(1, $contagem['juridico']['total']);
    }

    #[TestDox('Filtro etapa lista as metas abertas da caixa de toda a equipe, sem concluídas e sem outro escritório')]
    public function testFiltroEtapaListaAEquipeToda(): void
    {
        $tenant  = TenantFactory::createOne()->_real();
        $pasta   = $this->pasta($tenant);
        $eu      = UserFactory::createOne()->_real();
        $colega  = UserFactory::createOne()->_real();
        $minha   = $this->meta($pasta, EtapaDaPasta::Protocolo);
        $minha->addResponsavel($eu);
        $doColega = $this->meta($pasta, EtapaDaPasta::Protocolo, Tarefa::STATUS_EM_REVISAO);
        $doColega->addResponsavel($colega);
        $this->meta($pasta, EtapaDaPasta::Protocolo, Tarefa::STATUS_CONCLUIDA)->addResponsavel($colega);
        $this->meta($pasta, EtapaDaPasta::Juridico)->addResponsavel($eu);
        $this->meta($this->pasta(TenantFactory::createOne()->_real()), EtapaDaPasta::Protocolo)->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['etapa' => 'protocolo'], [], $tenant);

        $ids = array_map(static fn (Tarefa $t): int => (int) $t->getId(), $resultado);
        sort($ids);
        $esperados = [(int) $minha->getId(), (int) $doColega->getId()];
        sort($esperados);
        self::assertSame($esperados, $ids);
    }

    #[TestDox('Filtro etapa sem o escritório explícito cai no escopo da aba (só as do usuário)')]
    public function testFiltroEtapaSemEscritorioUsaAAba(): void
    {
        $pasta  = $this->pasta(TenantFactory::createOne()->_real());
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();
        $minha  = $this->meta($pasta, EtapaDaPasta::Protocolo);
        $minha->addResponsavel($eu);
        $this->meta($pasta, EtapaDaPasta::Protocolo)->addResponsavel($colega);
        $this->em->flush();

        $resultado = $this->repo->findParaMinhasMetas($eu, AbaMetas::RESPONSAVEL, ['etapa' => 'protocolo']);

        self::assertSame([(int) $minha->getId()], array_map(static fn (Tarefa $t): int => (int) $t->getId(), $resultado));
    }

    private function pasta(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne(['tenant' => $tenant])->_real();
    }

    private function meta(Pasta $pasta, EtapaDaPasta $etapa, string $status = Tarefa::STATUS_PENDENTE, ?\DateTimeImmutable $prazo = null): Tarefa
    {
        return TarefaFactory::createOne([
            'pasta'  => $pasta,
            'etapa'  => $etapa,
            'status' => $status,
            'prazo'  => $prazo,
        ])->_real();
    }
}
