<?php

declare(strict_types=1);

namespace App\Tests\Profile\Integration;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\Twig\PreferenciasDoPerfilExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * `perfil_sons()`: "Sons de alerta" do Meu Perfil (desenho "07 - Meu Perfil", VS 18.10.26), lido do
 * `preferencia_usuario` do usuário logado NO escritório da sessão, com o padrão do catálogo (ligado).
 * A memória é por requisição: a página consulta uma vez e o `reset()` do `kernel.reset` a esvazia.
 */
#[CoversClass(PreferenciasDoPerfilExtension::class)]
final class PreferenciasDoPerfilExtensionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function extensao(): PreferenciasDoPerfilExtension
    {
        return static::getContainer()->get(PreferenciasDoPerfilExtension::class);
    }

    private function preferencias(): PreferenciaDoUsuarioRepository
    {
        return static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    #[TestDox('Sem usuário logado nem escritório na sessão: ligado (padrão)')]
    public function testSemSessaoLigado(): void
    {
        self::assertTrue($this->extensao()->perfilSons());
    }

    #[TestDox('Usuário sem preferência gravada: ligado (padrão do catálogo)')]
    public function testPadraoLigado(): void
    {
        [$usuario, $tenant] = $this->cenario();
        $this->autenticar($usuario, $tenant);

        self::assertTrue($this->extensao()->perfilSons());
    }

    #[TestDox('Preferência "perfil.sons" gravada como false: desligado')]
    public function testDesligado(): void
    {
        [$usuario, $tenant] = $this->cenario();
        $this->preferencias()->gravar($tenant, $usuario, CatalogoDePreferenciasDoPerfil::SONS, false);
        $this->autenticar($usuario, $tenant);

        self::assertFalse($this->extensao()->perfilSons());
    }

    #[TestDox('Usuário logado sem escritório na sessão: ligado, mesmo com a preferência desligada em algum escritório')]
    public function testSemEscritorioNaSessao(): void
    {
        [$usuario, $tenant] = $this->cenario();
        $this->preferencias()->gravar($tenant, $usuario, CatalogoDePreferenciasDoPerfil::SONS, false);
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($usuario, 'main', $usuario->getRoles()));

        self::assertTrue($this->extensao()->perfilSons());
    }

    #[TestDox('Desligado num escritório não vale no outro, nem para o colega do mesmo escritório')]
    public function testNaoVazaEntreEscritoriosNemColegas(): void
    {
        [$usuario, $tenantA] = $this->cenario();
        $tenantB = $this->criarTenant();
        $this->em->persist(new UserTenant($usuario, $tenantB));
        $colega = $this->criarUsuario($tenantA);
        $this->em->flush();
        $this->preferencias()->gravar($tenantA, $usuario, CatalogoDePreferenciasDoPerfil::SONS, false);

        $this->autenticar($usuario, $tenantB);
        self::assertTrue($this->extensao()->perfilSons(), 'outro escritório');

        $this->autenticar($colega, $tenantA);
        self::assertTrue($this->extensao()->perfilSons(), 'colega do mesmo escritório');

        $this->autenticar($usuario, $tenantA);
        self::assertFalse($this->extensao()->perfilSons(), 'o próprio escritório');
    }

    #[TestDox('Memória por requisição: a mudança só aparece depois do reset() (kernel.reset)')]
    public function testMemoriaPorRequisicao(): void
    {
        [$usuario, $tenant] = $this->cenario();
        $this->autenticar($usuario, $tenant);
        $extensao = $this->extensao();
        self::assertTrue($extensao->perfilSons());

        $this->preferencias()->gravar($tenant, $usuario, CatalogoDePreferenciasDoPerfil::SONS, false);
        self::assertTrue($extensao->perfilSons(), 'a mesma requisição reaproveita a leitura');

        $extensao->reset();
        self::assertFalse($extensao->perfilSons());
    }

    /** @return array{User, Tenant} */
    private function cenario(): array
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuario($tenant);
        $this->em->flush();

        return [$usuario, $tenant];
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório Sons ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $usuario = new User();
        $usuario->setEmail(uniqid('perfil_sons_') . '@test.com');
        $usuario->setFullName('Pessoa dos Sons');
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setIsActive(true);
        $usuario->setPassword('x');
        $this->em->persist($usuario);
        $this->em->persist(new UserTenant($usuario, $tenant));

        return $usuario;
    }

    private function autenticar(User $usuario, Tenant $tenant): void
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($usuario, 'main', $usuario->getRoles()));

        $sessao = new Session(new MockArraySessionStorage());
        $sessao->set('current_tenant_id', $tenant->getId());
        $requisicao = new Request();
        $requisicao->setSession($sessao);
        $container->get('request_stack')->push($requisicao);
    }
}
