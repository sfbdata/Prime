<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Shared\Contract\TenantAware;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fatia 4 de docs/specs/isolamento-multitenant-cobertura.md: Sede, Cargo e Lotacao no TenantFilter.
 *
 * As três já tinham tenant_id NOT NULL; faltava a etiqueta, e sem ela uma consulta devolvia os
 * cadastros de todos os escritórios. Aqui se prova a negativa cross-tenant por entidade e, como a
 * spec manda (§5.2), que as telas de administração continuam mostrando os cadastros do escritório
 * da URL, mesmo quando a sessão está em outro escritório (as frases positivas; as negativas da tela
 * são só guarda, porque a tela lê as coleções do escritório e nunca mostrou os do vizinho).
 */
#[CoversClass(TenantController::class)]
final class CadastrosDoEscritorioIsolamentoTest extends JusPrimeWebTestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function cadastros(): iterable
    {
        yield 'sede'    => [Sede::class];
        yield 'cargo'   => [Cargo::class];
        yield 'lotacao' => [Lotacao::class];
    }

    #[DataProvider('cadastros')]
    #[TestDox('O escritório A não enxerga a sede, o cargo nem a lotação do escritório B')]
    public function testCadaCadastroIsolaEntreEscritorios(string $classe): void
    {
        static::bootKernel();
        $em = $this->em();
        $a = $this->criarCadastros('A');
        $b = $this->criarCadastros('B');
        $em->clear();

        $repo = $em->getRepository($classe);
        $idsDosDois = [$a[$classe]->getId(), $b[$classe]->getId()];

        // Sem filtro (console, worker): enxerga os dois, de propósito.
        $semFiltro = array_map(static fn (object $e): ?int => $e->getId(), $repo->findBy(['id' => $idsDosDois]));
        self::assertEqualsCanonicalizing($idsDosDois, $semFiltro, 'Cenário mal montado: era para haver 1 de cada.');

        $this->ligarFiltro($a['tenant']);
        $doA = $repo->findBy(['id' => $idsDosDois]);
        self::assertCount(1, $doA, sprintf('%s vazou entre escritórios.', $classe));
        self::assertSame($a[$classe]->getId(), $doA[0]->getId(), sprintf('%s devolveu o registro do escritório errado.', $classe));

        $em->clear();
        self::assertNull($em->find($classe, $b[$classe]->getId()), sprintf('%s do escritório B foi alcançada por id a partir do A.', $classe));
    }

    #[DataProvider('cadastros')]
    #[TestDox('Sede, Cargo e Lotacao declaram isolamento de escritório')]
    public function testCadastroEhTenantAware(string $classe): void
    {
        self::assertTrue(is_a($classe, TenantAware::class, true), sprintf('%s ficaria fora do TenantFilter, sem erro nenhum.', $classe));
    }

    #[TestDox('Admin dos dois escritórios, com a sessão em A, vê na tela do escritório B os cadastros de B e não os de A')]
    public function testTelasDeAdministracaoSeguemOEscritorioDaUrl(): void
    {
        $client = static::createClient();
        $a = $this->criarCadastros('A');
        $b = $this->criarCadastros('B');
        $admin = $this->criarAdminDosDois($a['tenant'], $b['tenant']);
        $this->logarComTenant($client, $admin, $a['tenant']);
        // O escritório criado aqui está na memória com as coleções vazias; a tela tem de ler do banco.
        $this->em()->clear();

        $idB = $b['tenant']->getId();
        $client->request('GET', "/tenant/{$idB}/edit");
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($b['nomes']['cargo'], $html, 'o cargo de B sumiu da edição de B');
        self::assertStringContainsString($b['nomes']['lotacao'], $html, 'a lotação de B sumiu da edição de B');
        self::assertStringNotContainsString($a['nomes']['cargo'], $html, 'o cargo de A apareceu na edição de B');
        self::assertStringNotContainsString($a['nomes']['lotacao'], $html, 'a lotação de A apareceu na edição de B');

        $client->request('GET', "/tenant/{$idB}/sedes");
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($b['nomes']['sede'], $html, 'a sede de B sumiu da tela de sedes de B');
        self::assertStringNotContainsString($a['nomes']['sede'], $html, 'a sede de A apareceu na tela de sedes de B');

        $idA = $a['tenant']->getId();
        $client->request('GET', "/tenant/{$idA}/edit");
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($a['nomes']['cargo'], $html, 'o cargo de A sumiu da edição de A');
        self::assertStringNotContainsString($b['nomes']['cargo'], $html, 'o cargo de B apareceu na edição de A');
    }

    // ----------------------------------------------------------------- helpers

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function ligarFiltro(Tenant $tenant): void
    {
        $this->em()->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    /**
     * Um escritório novo com uma sede, um cargo e uma lotação de nomes únicos.
     *
     * @return array<string, mixed> indexado pelo FQCN de cada cadastro, mais 'tenant' e 'nomes'
     */
    private function criarCadastros(string $rotulo): array
    {
        $em = $this->em();
        $sufixo = $rotulo . ' ' . bin2hex(random_bytes(4));

        $tenant = new Tenant();
        $tenant->setName('Escritório isolamento ' . $sufixo);
        $em->persist($tenant);

        $nomes = ['sede' => 'Sede ' . $sufixo, 'cargo' => 'Cargo ' . $sufixo, 'lotacao' => 'Lotação ' . $sufixo];

        $sede = (new Sede())->setNome($nomes['sede'])->setLatitude('-15.7939')->setLongitude('-47.8828');
        $sede->setTenant($tenant);
        $cargo = (new Cargo())->setNome($nomes['cargo']);
        $cargo->setTenant($tenant);
        $lotacao = (new Lotacao())->setNome($nomes['lotacao']);
        $lotacao->setTenant($tenant);
        foreach ([$sede, $cargo, $lotacao] as $cadastro) {
            $em->persist($cadastro);
        }
        $em->flush();

        return ['tenant' => $tenant, 'nomes' => $nomes, Sede::class => $sede, Cargo::class => $cargo, Lotacao::class => $lotacao];
    }

    /** Admin (TenantRole isSystem) com vínculo ativo nos dois escritórios. */
    private function criarAdminDosDois(Tenant $a, Tenant $b): User
    {
        $container = static::getContainer();
        $em = $this->em();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_cadastros_' . uniqid() . '@test.com');
        $user->setFullName('Admin Cadastros');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        foreach ([$a, $b] as $tenant) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Admin ' . uniqid());
            $role->setIsSystem(true);
            $em->persist($role);

            $vinculo = new UserTenant($user, $tenant);
            $vinculo->setTenantRole($role);
            $em->persist($vinculo);
        }
        $em->flush();

        return $user;
    }
}
