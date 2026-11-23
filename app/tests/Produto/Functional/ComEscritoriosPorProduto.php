<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Service\ChaveDoMultiproduto;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Apoio dos testes da guarda do produto (spec multiproduto, §12 "PR 6"): troca o modo da chave no container, monta
 * escritório com os produtos pedidos (sem produto nenhum = sem linha = completo) e pessoas nele.
 */
trait ComEscritoriosPorProduto
{
    /** Antes da primeira requisição: depois dela, a guarda já foi montada com a chave do container. */
    private function modo(string $modo): void
    {
        static::getContainer()->set(ChaveDoMultiproduto::class, new ChaveDoMultiproduto($modo));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Escritório com uma linha ativa por produto pedido; sem produto, fica sem linha (completo, como os de hoje). */
    private function escritorio(Produto ...$produtos): Tenant
    {
        $tenant = TenantFactory::createOne(['name' => 'Guarda do produto ' . uniqid()])->_real();
        foreach ($produtos as $produto) {
            $this->em()->persist(Contratacao::ativar($tenant, $produto, OrigemDaContratacao::Master, null, new \DateTimeImmutable('-1 hour')));
        }
        $this->em()->flush();

        return $tenant;
    }

    /** A chave global `voto` ligada e o escritório liberado pelo Master: o Voto abre por ele mesmo. */
    private function liberarOVoto(Tenant $tenant): void
    {
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        $estado?->reativar(null, new \DateTimeImmutable());
        $liberacao = new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $this->em()->persist($liberacao);
        $this->em()->flush();
    }

    /**
     * Pessoa com perfil em cada escritório: `sistema` é o primário (bypass do PermissionChecker), `comum` sem permissão.
     *
     * @param list<string> $papeis
     */
    private function pessoa(array $papeis, bool $sistema, Tenant ...$escritorios): User
    {
        $user = UserFactory::createOne(['fullName' => 'Pessoa da guarda', 'roles' => $papeis])->_real();
        foreach ($escritorios as $tenant) {
            $tenant = $this->em()->getReference(Tenant::class, $tenant->getId());
            $perfil = (new TenantRole())->setTenant($tenant)->setName('Perfil ' . uniqid())->setIsSystem($sistema);
            $this->em()->persist($perfil);
            $this->em()->persist((new UserTenant($user, $tenant))->setTenantRole($perfil));
        }
        $this->em()->flush();

        return $user;
    }

    private function primario(Tenant ...$escritorios): User
    {
        return $this->pessoa(['ROLE_USER'], true, ...$escritorios);
    }
}
