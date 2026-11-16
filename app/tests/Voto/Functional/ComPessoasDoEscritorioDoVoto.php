<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Apoio dos testes funcionais das ocorrências e avaliações do conselho (W2-7b): um escritório com o Voto liberado, pessoas
 * com o módulo (colega), a Administração (primário), a equipe própria de uma assembleia e a assembleia com unidades e pauta.
 * Quem usa declara `$em`, `$escritorio` e o `entrar()` (cada teste faz o login do seu jeito).
 */
trait ComPessoasDoEscritorioDoVoto
{
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    private function prepararEscritorioDoVoto(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Avaliações do Voto ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    /** Publicada, com uma pauta (aguardando, aberta ou encerrada) e duas unidades: a 101 apta e a 102 inapta. */
    private function assembleiaComUnidades(string $estadoDaPauta = 'aguardando'): Assembleia
    {
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO ' . uniqid()])->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $a])->comOpcoesPadrao();
        $pauta = match ($estadoDaPauta) {
            'aberta' => $pauta->aberta(),
            'encerrada' => $pauta->encerrada(),
            default => $pauta,
        };
        $pauta->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '101', 'responsavel' => 'Ana Apta']);
        UnidadeDaAssembleiaFactory::new(['assembleia' => $a, 'identificacao' => '102', 'responsavel' => 'Beto Inapto'])->inapta()->create();
        $this->em->refresh($a);

        return $a;
    }

    private function unidadeDe(Assembleia $a, string $identificacao): UnidadeDaAssembleia
    {
        foreach ($a->getUnidades() as $unidade) {
            if ($unidade->getIdentificacao() === $identificacao) {
                return $unidade;
            }
        }
        self::fail('unidade ' . $identificacao . ' não encontrada');
    }

    private function membro(Assembleia $a, PapelNaEquipe $papel, ?User $user, string $nome = 'Membro'): MembroDaEquipe
    {
        $m = new MembroDaEquipe($a, $papel, $nome, null, '', $user);
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    private function primario(): User
    {
        return $this->usuarioNoEscritorio(true, true);
    }

    private function colega(bool $comModulo = true): User
    {
        return $this->usuarioNoEscritorio(false, $comModulo);
    }

    private function usuarioNoEscritorio(bool $sistema, bool $comModulo): User
    {
        $user = UserFactory::createOne(['fullName' => 'Pessoa ' . uniqid(), 'roles' => ['ROLE_USER']])->_real();
        // Os pedidos do cliente limpam o EntityManager: o escritório volta a ser uma entidade gerenciada.
        $escritorio = $this->em->getReference(Tenant::class, $this->escritorio->getId());
        $papel = (new TenantRole())->setTenant($escritorio)->setName('Perfil ' . uniqid())->setIsSystem($sistema);
        $this->em->persist($papel);
        if ($comModulo) {
            $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'modules.voto.view']);
            if (null === $permissao) {
                $permissao = (new Permission())->setCode('modules.voto.view')->setDescription('Acesso ao módulo Voto (eleições e votações)')->setGroup('modules');
                $this->em->persist($permissao);
            }
            $concessao = (new TenantRolePermission())->setTenantRole($papel)->setPermission($permissao);
            $this->em->persist($concessao);
            $papel->getTenantRolePermissions()->add($concessao);
        }
        $this->em->persist((new UserTenant($user, $escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
