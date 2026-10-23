<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Support;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Entity\ConfiguracaoDeEdicao;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Para os testes que conferem a regra de SEMPRE (autor, dentro do prazo): desliga no escritório a opção "Usuário
 * primário pode editar e excluir qualquer registro sem limite de tempo" (spec `edicao-sem-prazo-master-primario.md`
 * §3), que nasce ligada. Sem ela, o primário seguiria pelo poder sem prazo.
 *
 * O Master não tem opção (§1.2): quando o teste entra com um usuário `ROLE_SUPER_ADMIN` só para passar pelas
 * permissões do módulo, `$semMaster` o rebaixa a usuário primário do escritório, que passa pelas mesmas permissões
 * (`isSystem`) e, com a opção desligada, volta à regra de sempre.
 */
final class DesligaEdicaoSemPrazo
{
    public static function no(EntityManagerInterface $em, Tenant $tenant, ?ContainerInterface $container = null, ?User $semMaster = null): void
    {
        $configuracao = new ConfiguracaoDeEdicao($tenant);
        $alterador = new \ReflectionProperty(ConfiguracaoDeEdicao::class, 'primarioSemPrazo');
        $alterador->setValue($configuracao, false);
        $em->persist($configuracao);
        if ($semMaster !== null) {
            self::rebaixarAPrimario($em, $semMaster, $tenant);
        }
        $em->flush();
        if ($container !== null && $container->has(PoderDeEdicaoSemPrazo::class)) {
            $container->get(PoderDeEdicaoSemPrazo::class)->esquecer();
        }
    }

    private static function rebaixarAPrimario(EntityManagerInterface $em, User $usuario, Tenant $tenant): void
    {
        $usuario->setRoles(array_values(array_diff($usuario->getRoles(), ['ROLE_SUPER_ADMIN'])));
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy(['user' => $usuario, 'tenant' => $tenant]);
        if ($vinculo === null) {
            $vinculo = new UserTenant($usuario, $tenant);
            $em->persist($vinculo);
        }
        if ($vinculo->getTenantRole()?->isSystem() !== true) {
            $perfil = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório ' . uniqid())->setIsSystem(true);
            $em->persist($perfil);
            $vinculo->setTenantRole($perfil);
        }
    }
}
