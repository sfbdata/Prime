<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Enum\StatusCaso;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Factory\Cliente\ClientePJFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Atalho da pasta judicializada para a UNIDADE cobrada, no cabeçalho da `pasta_show`.
 *
 * É o espelho do atalho que já existe no sentido contrário (`cobranca/objeto/show.html.twig`, o link
 * "Pasta judicial"), e serve à conferência: com o identificador da pasta derivado do caso, quem
 * confere precisa chegar ao caso em um clique para checar unidade e devedor.
 *
 * ⚠️ O link leva a uma tela com gate de MÓDULO (`cobrancas`). Oferecê-lo a quem não tem o módulo
 * seria um beco sem saída — o teste do operador sem módulo é o mais importante deste arquivo.
 */
final class AtalhoDaPastaParaUnidadeTest extends CobrancaWebTestCase
{
    #[TestDox('Pasta judicializada: o cabeçalho traz o atalho para a unidade cobrada')]
    public function testCabecalhoTrazOAtalhoParaAUnidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        [, $caso] = $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );
        $objeto = $caso->getObjeto();

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $link = $crawler->filter('[data-campo="cobranca"] a');

        self::assertCount(1, $link, 'o cabeçalho tem o atalho para a unidade');
        self::assertSame(
            '/cobrancas/objetos/' . $objeto->getId(),
            $link->attr('href'),
            'o atalho aponta para a unidade DESTE caso',
        );
        self::assertSame(
            $objeto->getIdentificacao(),
            trim($link->text()),
            'o texto do atalho é a identificação da unidade, que é por onde se confere',
        );
    }

    #[TestDox('Pasta comum, sem cobrança: o cabeçalho não ganha campo nenhum')]
    public function testPastaSemCobrancaNaoGanhaOAtalho(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'CLIENTE QUALQUER'])->_real();

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter('[data-campo="cobranca"]'),
            'as 1.093 pastas sem cobrança não mudam de aparência',
        );
    }

    #[TestDox('Sem o módulo Cobrança: o atalho não é oferecido (não vira beco sem saída)')]
    public function testSemOModuloCobrancaNaoOfereceOAtalho(): void
    {
        $client = static::createClient();
        // ⚠️ `criarOperadorComCapacidades` SEMPRE concede `modules.cobrancas.view`, então não serve
        // aqui: com ele o teste passaria por engano. Este operador tem só o módulo `pastas`.
        [, $tenant] = $this->criarOperadorSoComModuloPastas($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertCount(
            0,
            $crawler->filter('[data-campo="cobranca"]'),
            'sem o módulo, oferecer o atalho levaria a uma negação',
        );
    }

    /**
     * Operador que enxerga o módulo `pastas` e NÃO enxerga `cobrancas` — o cenário que o helper
     * compartilhado não sabe montar, porque ele concede `modules.cobrancas.view` a todo mundo.
     *
     * @return array{0: User, 1: Tenant}
     */
    private function criarOperadorSoComModuloPastas(KernelBrowser $client): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant SemCobranca ' . uniqid());
        $em->persist($tenant);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Operador SemCobranca ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach (['modules.pastas.view', 'resources.pasta.view'] as $code) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $code]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($code);
                $perm->setDescription($code);
                $perm->setGroup(str_contains($code, 'modules.') ? 'modules' : 'resources');
                $em->persist($perm);
                $em->flush();
            }

            $trp = new TenantRolePermission();
            $trp->setTenantRole($role);
            $trp->setPermission($perm);
            $em->persist($trp);
            $role->getTenantRolePermissions()->add($trp);
        }

        $user = new User();
        $user->setEmail('sem_cobranca_' . uniqid() . '@test.com');
        $user->setFullName('Operador Sem Cobranca');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }
}
