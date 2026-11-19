<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * As buscas das telas do conector (PR 8, `docs/specs/mcp-remoto-oauth.md` 13.1 e 14.a): o Meu Perfil lista as conexões
 * da pessoa em todos os escritórios, mesmo com o `TenantFilter` ligado no escritório da sessão, e só as dela; o Master
 * acha a linha de qualquer escritório. O filtro volta ligado, no mesmo escritório, depois de cada busca.
 */
#[CoversClass(ConsentimentoDoConectorRepository::class)]
#[CoversClass(EscritorioDoConectorRepository::class)]
final class RepositoriosDasTelasDoConectorTest extends KernelTestCase
{
    use Factories;
    use SemeiaConector;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function consentimentos(): ConsentimentoDoConectorRepository
    {
        return static::getContainer()->get(ConsentimentoDoConectorRepository::class);
    }

    private function escritorios(): EscritorioDoConectorRepository
    {
        return static::getContainer()->get(EscritorioDoConectorRepository::class);
    }

    /** Liga o `TenantFilter` no escritório da sessão, como o listener faz numa requisição. */
    private function sessaoNoEscritorio(Tenant $tenant): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    private function assertFiltroDeVoltaEm(Tenant $tenant): void
    {
        $filtros = $this->em->getFilters();
        self::assertTrue($filtros->isEnabled('tenant'), 'o filtro volta ligado depois da busca');
        self::assertSame((string) $tenant->getId(), trim($filtros->getFilter('tenant')->getParameter('tenant'), "'"), 'no mesmo escritório');
    }

    #[TestDox('Meu Perfil: lista as conexões da pessoa nos dois escritórios com a sessão num só, a mais recente primeiro, com app e escritório carregados')]
    public function testListaConexoesDeTodosOsEscritoriosDaPessoa(): void
    {
        $user = UserFactory::createOne()->_real();
        $outraPessoa = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $a = $this->tenantNovo();
        $b = $this->tenantNovo();
        $deA = $this->semearConector($a, $user, $cliente);
        $deB = $this->semearConector($b, $user, $cliente);
        $this->em->persist(new ConsentimentoDoConector($outraPessoa, $a, $cliente, ['mcp:leitura'], new \DateTimeImmutable()));
        $this->em->flush();
        $idDeA = $deA['consentimento']->getId();
        $idDeB = $deB['consentimento']->getId();
        $this->em->clear();
        $this->sessaoNoEscritorio($a);

        $lista = $this->consentimentos()->listarAtivosDaPessoaEmTodos($user);

        self::assertSame([$idDeB, $idDeA], array_map(static fn (ConsentimentoDoConector $c): ?int => $c->getId(), $lista), 'só as da pessoa, a do escritório fora da sessão inclusive');
        $uow = $this->em->getUnitOfWork();
        foreach ($lista as $conexao) {
            self::assertFalse($uow->isUninitializedObject($conexao->getCliente()), 'app no mesmo SELECT');
            self::assertFalse($uow->isUninitializedObject($conexao->getTenant()), 'escritório no mesmo SELECT');
        }
        self::assertSame($b->getId(), $lista[0]->getTenant()?->getId());
        $this->assertFiltroDeVoltaEm($a);
    }

    #[TestDox('Meu Perfil: conexão revogada não aparece na lista')]
    public function testRevogadaNaoLista(): void
    {
        $user = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $deA = $this->semearConector($this->tenantNovo(), $user, $cliente);
        $deB = $this->semearConector($this->tenantNovo(), $user, $cliente);
        $deA['consentimento']->revogar(new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([$deB['consentimento']], $this->consentimentos()->listarAtivosDaPessoaEmTodos($user));
    }

    #[TestDox('Revogar: acha a conexão da pessoa em outro escritório pelo id; id de outra pessoa ou já revogado dá nulo')]
    public function testBuscarAtivoDaPessoa(): void
    {
        $user = UserFactory::createOne()->_real();
        $outraPessoa = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $a = $this->tenantNovo();
        $b = $this->tenantNovo();
        $this->semearConector($a, $user, $cliente);
        $deB = $this->semearConector($b, $user, $cliente);
        $daOutra = new ConsentimentoDoConector($outraPessoa, $b, $cliente, ['mcp:leitura'], new \DateTimeImmutable());
        $revogada = new ConsentimentoDoConector($user, $b, $this->clienteGlobal(), ['mcp:leitura'], new \DateTimeImmutable());
        $revogada->revogar(new \DateTimeImmutable());
        $this->em->persist($daOutra);
        $this->em->persist($revogada);
        $this->em->flush();
        $this->sessaoNoEscritorio($a);

        self::assertSame($deB['consentimento'], $this->consentimentos()->buscarAtivoDaPessoa((int) $deB['consentimento']->getId(), $user), 'escritório fora da sessão');
        self::assertNull($this->consentimentos()->buscarAtivoDaPessoa((int) $daOutra->getId(), $user), 'conexão de outra pessoa não existe para quem pede');
        self::assertNull($this->consentimentos()->buscarAtivoDaPessoa((int) $deB['consentimento']->getId(), $outraPessoa));
        self::assertNull($this->consentimentos()->buscarAtivoDaPessoa((int) $revogada->getId(), $user), 'revogada não é ativa');
        $this->assertFiltroDeVoltaEm($a);
    }

    #[TestDox('Master: a tabela traz o escritório sem linha desligado, o ligado com o aceite, e não traz o excluído nem o suspenso')]
    public function testListarEscritoriosParaOMaster(): void
    {
        $user = UserFactory::createOne()->_real();
        $comLinha = $this->tenantNovo();
        $semLinha = $this->tenantNovo();
        $excluido = $this->tenantNovo(true, '2026-11-01 10:00:00');
        $suspenso = $this->tenantNovo(false);
        $linha = new EscritorioDoConector($comLinha);
        $linha->ligar($user, new \DateTimeImmutable('2026-11-10 09:30:00'));
        $this->em->persist($linha);
        $this->em->persist(new EscritorioDoConector($excluido));
        $this->em->flush();
        $this->sessaoNoEscritorio($semLinha);

        $porId = [];
        foreach ($this->escritorios()->listarEscritoriosParaOMaster() as $item) {
            $porId[$item['tenantId']] = $item;
        }

        self::assertSame(
            ['tenantId' => $comLinha->getId(), 'nome' => $comLinha->getName(), 'ligado' => true, 'aceiteEm' => '2026-11-10 09:30:00', 'aceiteVersao' => 1],
            $porId[$comLinha->getId()] ?? null,
        );
        self::assertSame(
            ['tenantId' => $semLinha->getId(), 'nome' => $semLinha->getName(), 'ligado' => false, 'aceiteEm' => null, 'aceiteVersao' => null],
            $porId[$semLinha->getId()] ?? null,
        );
        self::assertArrayNotHasKey((int) $excluido->getId(), $porId, 'escritório excluído fica fora');
        self::assertArrayNotHasKey((int) $suspenso->getId(), $porId, 'escritório suspenso fica fora, como na BlueJus I.A.');
    }

    #[TestDox('Master: conta as conexões ativas por escritório, de todos, sem a revogada; escritório sem conexão não aparece')]
    public function testContarAtivosPorEscritorio(): void
    {
        $user = UserFactory::createOne()->_real();
        $outraPessoa = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $a = $this->tenantNovo();
        $b = $this->tenantNovo();
        $semConexao = $this->tenantNovo();
        $this->semearConector($a, $user, $cliente);
        $deB = $this->semearConector($b, $user, $cliente);
        $this->em->persist(new ConsentimentoDoConector($outraPessoa, $a, $cliente, ['mcp:leitura'], new \DateTimeImmutable()));
        $revogadaEmA = new ConsentimentoDoConector($outraPessoa, $a, $this->clienteGlobal(), ['mcp:leitura'], new \DateTimeImmutable());
        $revogadaEmA->revogar(new \DateTimeImmutable());
        $this->em->persist($revogadaEmA);
        $this->em->persist(new ConsentimentoDoConector($outraPessoa, $b, $cliente, ['mcp:leitura'], new \DateTimeImmutable()));
        $deB['consentimento']->revogar(new \DateTimeImmutable());
        $this->em->flush();
        $this->sessaoNoEscritorio($a);

        $contagem = $this->consentimentos()->contarAtivosPorEscritorio();

        self::assertSame(2, $contagem[$a->getId()] ?? null);
        self::assertSame(1, $contagem[$b->getId()] ?? null, 'escritório fora da sessão também conta');
        self::assertArrayNotHasKey((int) $semConexao->getId(), $contagem);
        $this->assertFiltroDeVoltaEm($a);
    }
}
