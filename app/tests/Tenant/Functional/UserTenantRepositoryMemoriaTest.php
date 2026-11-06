<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Memória do vínculo ativo no `UserTenantRepository` (spec `memoria-do-vinculo-ativo.md`): a resposta com a
 * memória é a mesma de sempre consultar, o que muda no vínculo vale na hora e N leituras do mesmo vínculo
 * custam uma consulta. Consultas contadas no `doctrine.debug_data_holder`; o DAMA desfaz o que foi gravado.
 */
#[CoversClass(UserTenantRepository::class)]
final class UserTenantRepositoryMemoriaTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo()->reset();
    }

    #[TestDox('equivalência: com a memória, as duas leituras respondem igual a consultar sempre, em todos os casos')]
    public function testEquivalenciaComESemMemoria(): void
    {
        $tenant = $this->novoEscritorio();
        $outro = $this->novoEscritorio();
        $ativo = $this->novoUsuario();
        $this->vincular($ativo, $tenant);
        $suspenso = $this->novoUsuario();
        $this->vincular($suspenso, $tenant)->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $deOutro = $this->novoUsuario();
        $this->vincular($deOutro, $outro);
        $semVinculo = $this->novoUsuario();
        $this->em()->flush();

        $casos = [];
        foreach ([$ativo, $suspenso, $deOutro, $semVinculo] as $pessoa) {
            foreach ([$tenant, $outro] as $escritorio) {
                $casos[] = [$pessoa, $escritorio];
            }
        }

        $semMemoria = [];
        foreach ($casos as $i => [$pessoa, $escritorio]) {
            $this->repo()->reset();
            $semMemoria[$i] = $this->resposta($pessoa, $escritorio);
        }

        $this->repo()->reset();
        // Duas voltas: a segunda sai toda da memória.
        foreach ([1, 2] as $volta) {
            foreach ($casos as $i => [$pessoa, $escritorio]) {
                self::assertSame($semMemoria[$i], $this->resposta($pessoa, $escritorio), "caso $i, volta $volta");
            }
        }
        self::assertSame([$ativo->getId() . ':' . $tenant->getId(), $deOutro->getId() . ':' . $outro->getId()], array_keys($this->memoria()));
    }

    #[TestDox('N leituras do mesmo vínculo (find e existe): uma consulta só')]
    public function testUmaConsultaParaNLeituras(): void
    {
        [$quem, $tenant, $vinculo] = $this->ativoNoEscritorio();

        $consultas = $this->consultasDurante(function () use ($quem, $tenant, $vinculo): void {
            for ($i = 0; $i < 5; ++$i) {
                self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));
                self::assertTrue($this->repo()->existeVinculoAtivo($quem, $tenant));
            }
        });

        self::assertSame(1, $consultas);
    }

    #[TestDox('"não achou" não fica guardado: o vínculo criado depois aparece')]
    public function testVinculoCriadoDepoisAparece(): void
    {
        $tenant = $this->novoEscritorio();
        $quem = $this->novoUsuario();
        $this->em()->flush();
        self::assertNull($this->repo()->findAtivoPorUserETenant($quem, $tenant));
        self::assertFalse($this->repo()->existeVinculoAtivo($quem, $tenant));

        $vinculo = $this->vincular($quem, $tenant);
        $this->em()->flush();

        self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));
        self::assertTrue($this->repo()->existeVinculoAtivo($quem, $tenant));
    }

    #[TestDox('desativado sem flush: igual à consulta, que olha o banco (ainda ativo lá)')]
    public function testDesativarSemFlushSegueOBanco(): void
    {
        [$quem, $tenant, $vinculo] = $this->ativoNoEscritorio();
        self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));

        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $comMemoria = [$this->repo()->findAtivoPorUserETenant($quem, $tenant), $this->repo()->existeVinculoAtivo($quem, $tenant)];
        $this->repo()->reset();
        $semMemoria = [$this->repo()->findAtivoPorUserETenant($quem, $tenant), $this->repo()->existeVinculoAtivo($quem, $tenant)];

        self::assertSame($semMemoria, $comMemoria);
    }

    #[TestDox('desativado com flush: some na hora')]
    public function testDesativarComFlushSome(): void
    {
        [$quem, $tenant, $vinculo] = $this->ativoNoEscritorio();
        self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));

        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();

        self::assertNull($this->repo()->findAtivoPorUserETenant($quem, $tenant));
        self::assertFalse($this->repo()->existeVinculoAtivo($quem, $tenant));
        self::assertSame([], $this->memoria());
    }

    #[TestDox('reativado com flush depois de sumir: volta a aparecer')]
    public function testReativarDepoisDeSumir(): void
    {
        [$quem, $tenant, $vinculo] = $this->ativoNoEscritorio();
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
        self::assertNull($this->repo()->findAtivoPorUserETenant($quem, $tenant));

        $vinculo->alterarSituacao(SituacaoNaEquipe::Aprovado);
        $this->em()->flush();

        self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));
    }

    #[TestDox('removido, ou EntityManager limpo: a memória não devolve a entidade velha')]
    public function testRemoverEClearConsultamDeNovo(): void
    {
        [$quem, $tenant, $vinculo] = $this->ativoNoEscritorio();
        self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));
        $this->em()->remove($vinculo);
        self::assertSame($vinculo, $this->semMemoria(fn (): ?UserTenant => $this->repo()->findAtivoPorUserETenant($quem, $tenant)), 'sem flush, o banco ainda tem o vínculo');
        self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));
        $this->em()->flush();
        self::assertNull($this->repo()->findAtivoPorUserETenant($quem, $tenant));

        [$quem, $tenant, $vinculo] = $this->ativoNoEscritorio();
        $id = $vinculo->getId();
        self::assertSame($vinculo, $this->repo()->findAtivoPorUserETenant($quem, $tenant));
        $quemId = $quem->getId();
        $tenantId = $tenant->getId();
        $this->em()->clear();
        $quem = $this->em()->find(User::class, $quemId);
        $tenant = $this->em()->find(Tenant::class, $tenantId);
        self::assertNotNull($quem);
        self::assertNotNull($tenant);

        $relido = $this->repo()->findAtivoPorUserETenant($quem, $tenant);
        self::assertNotNull($relido);
        self::assertNotSame($vinculo, $relido);
        self::assertSame($id, $relido->getId());
    }

    #[TestDox('o reset do kernel (fim do request, mensagem do worker) zera a memória')]
    public function testResetDoKernelZeraAMemoria(): void
    {
        [$quem, $tenant] = $this->ativoNoEscritorio();
        $this->repo()->findAtivoPorUserETenant($quem, $tenant);
        self::assertCount(1, $this->memoria());

        static::getContainer()->get('services_resetter')->reset();

        self::assertSame([], $this->memoria());
    }

    /** @return array{0: ?int, 1: bool} */
    private function resposta(User $pessoa, Tenant $escritorio): array
    {
        // O existe vem antes do find: sem memória, ele roda o COUNT de verdade (o find guardaria o vínculo).
        $existe = $this->repo()->existeVinculoAtivo($pessoa, $escritorio);

        return [$this->repo()->findAtivoPorUserETenant($pessoa, $escritorio)?->getId(), $existe];
    }

    private function semMemoria(callable $leitura): mixed
    {
        $guardado = $this->memoria();
        $this->repo()->reset();
        $resposta = $leitura();
        (new \ReflectionProperty(UserTenantRepository::class, 'ativos'))->setValue($this->repo(), $guardado);

        return $resposta;
    }

    /** @return array<string, UserTenant> */
    private function memoria(): array
    {
        return (new \ReflectionProperty(UserTenantRepository::class, 'ativos'))->getValue($this->repo());
    }

    private function consultasDurante(callable $acao): int
    {
        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();
        $acao();
        $sqls = array_column($coletor->getData()['default'] ?? [], 'sql');

        return count(array_filter($sqls, static fn (string $sql): bool => (bool) preg_match('/^\s*SELECT\b.*\bFROM\s+user_tenant\b/is', $sql)));
    }

    /** @return array{0: User, 1: Tenant, 2: UserTenant} */
    private function ativoNoEscritorio(): array
    {
        $tenant = $this->novoEscritorio();
        $quem = $this->novoUsuario();
        $vinculo = $this->vincular($quem, $tenant);
        $this->em()->flush();

        return [$quem, $tenant, $vinculo];
    }

    private function repo(): UserTenantRepository
    {
        return static::getContainer()->get(UserTenantRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function novoEscritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório do vínculo ' . uniqid());
        $this->em()->persist($tenant);

        return $tenant;
    }

    private function novoUsuario(): User
    {
        $user = new User();
        $user->setEmail('vinculo_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa do vínculo');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('sem-senha');
        $this->em()->persist($user);

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): UserTenant
    {
        $vinculo = new UserTenant($user, $tenant);
        $this->em()->persist($vinculo);

        return $vinculo;
    }
}
