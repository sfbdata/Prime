<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\Service\FiltroDoEscritorioDoctrine;
use App\Conector\Service\ModoDoConector;
use App\Conector\Service\PortaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\BarreirasDoEscritorio;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\Enum\OrigemDoAceite;
use App\Termo\TermoVigente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cenário dos testes do conector de I.A. com banco: escritório, pessoa, perfil (de sistema ou com permissões) e
 * vínculo, gravados de verdade. Quem usa precisa do kernel ligado (KernelTestCase ou WebTestCase).
 */
trait CenarioDoConectorTrait
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function escritorio(string $nome = 'Escritório Conector'): Tenant
    {
        $escritorio = (new Tenant())->setName($nome . ' ' . uniqid())->setIsActive(true);
        $this->em()->persist($escritorio);
        $this->em()->flush();

        return $escritorio;
    }

    /** @param list<string> $papeis */
    private function pessoa(array $papeis = ['ROLE_USER'], bool $aceitouOsTermos = true): User
    {
        $pessoa = (new User())
            ->setEmail('conector_' . uniqid() . '@teste.com')
            ->setFullName('Pessoa do Conector')
            ->setRoles($papeis)
            ->setIsActive(true);
        $pessoa->setPassword('sem-uso');
        $this->em()->persist($pessoa);
        if ($aceitouOsTermos) {
            $this->em()->persist(new AceiteTermo($pessoa, TermoVigente::VERSAO, '127.0.0.1', null, null, OrigemDoAceite::Reaceite));
        }
        $this->em()->flush();

        return $pessoa;
    }

    /**
     * @param list<string> $permissoes códigos ligados ao perfil (o de sistema também pode recebê-los)
     */
    private function vinculo(User $pessoa, Tenant $escritorio, bool $sistema = false, array $permissoes = []): UserTenant
    {
        $perfil = (new TenantRole())->setTenant($escritorio)->setName(($sistema ? 'Primário ' : 'Equipe ') . uniqid())->setIsSystem($sistema);
        $this->em()->persist($perfil);
        foreach ($permissoes as $codigo) {
            $ligacao = (new TenantRolePermission())->setTenantRole($perfil)->setPermission($this->permissao($codigo));
            $perfil->getTenantRolePermissions()->add($ligacao);
            $this->em()->persist($ligacao);
        }
        $vinculo = (new UserTenant($pessoa, $escritorio))->setTenantRole($perfil);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $vinculo;
    }

    /** O código do catálogo; criado se o banco de teste não o tiver. */
    private function permissao(string $codigo): Permission
    {
        $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = (new Permission())->setCode($codigo)->setDescription($codigo)->setGroup(explode('.', $codigo)[0]);
            $this->em()->persist($permissao);
            $this->em()->flush();
        }

        return $permissao;
    }

    /**
     * Montado com as peças do container: o portão e o filtro ainda não têm quem os use (o `/mcp` chega no PR 5), e o
     * Symfony tira do container compilado o serviço privado sem uso. O que importa é que o TenantContext, o modo e o
     * EntityManager sejam os MESMOS que o resto da aplicação usa.
     */
    private function portao(): PortaoDoConector
    {
        $c = static::getContainer();

        return new PortaoDoConector(
            $c->get(UserRepository::class),
            $c->get(TenantRepository::class),
            $c->get(UserTenantRepository::class),
            new BarreirasDoEscritorio($c->get(BloqueioPorPedidoDeExclusao::class), $c->get(RegraDosPrimarios::class)),
            $c->get(AceiteTermoRepository::class),
            new TermoVigente(),
            $this->filtro(),
            $c->get(ModoDoConector::class),
            $c->get(TenantContext::class),
        );
    }

    private function filtro(): FiltroDoEscritorioDoctrine
    {
        return new FiltroDoEscritorioDoctrine($this->em());
    }
}
