<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec memoria-do-layout: `valoresDoUsuario()` guarda o que já leu por escritório, pessoa e chave; `gravar()` e
 * `apagarDoUsuario()` esquecem a pessoa no escritório. Consultas contadas no `doctrine.debug_data_holder`.
 */
#[CoversClass(PreferenciaDoUsuarioRepository::class)]
final class PreferenciaDoUsuarioRepositoryMemoriaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PreferenciaDoUsuarioRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
        $this->repo->reset();
    }

    private function criarTenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant MEM ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = (new User())->setEmail('mem_' . uniqid() . '@test.com')->setFullName('Pessoa Memória')->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('dummy');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return int consultas à tabela durante a ação */
    private function consultasDurante(callable $acao): int
    {
        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();
        $acao();
        $sqls = array_column($coletor->getData()['default'] ?? [], 'sql');

        return count(array_filter($sqls, static fn (string $sql): bool => (bool) preg_match('/^\s*SELECT\b.*\bFROM\s+preferencia_usuario\b/is', $sql)));
    }

    #[TestDox('N leituras das mesmas chaves: uma consulta, mesma resposta')]
    public function testUmaConsultaEmNChamadas(): void
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUser();
        $this->repo->gravar($tenant, $usuario, 'dashboard.animacoes', 'reduzidas');

        $respostas = [];
        $consultas = $this->consultasDurante(function () use ($tenant, $usuario, &$respostas): void {
            for ($i = 0; $i < 5; ++$i) {
                $respostas[] = $this->repo->valoresDoUsuario($tenant, $usuario, ['dashboard.animacoes', 'perfil.som']);
            }
        });

        self::assertSame(1, $consultas);
        foreach ($respostas as $r) {
            self::assertSame(['dashboard.animacoes' => 'reduzidas'], $r);
        }
    }

    #[TestDox('Uma consulta traz todas as chaves da pessoa: chave nova, com ou sem linha, não consulta de novo')]
    public function testChaveNovaNaoConsultaDeNovo(): void
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUser();
        $this->repo->gravar($tenant, $usuario, 'a', 1);
        $this->repo->gravar($tenant, $usuario, 'b', 2);

        $consultas = $this->consultasDurante(function () use ($tenant, $usuario): void {
            self::assertSame(['a' => 1], $this->repo->valoresDoUsuario($tenant, $usuario, ['a', 'z']));
            self::assertSame(['a' => 1, 'b' => 2], $this->repo->valoresDoUsuario($tenant, $usuario, ['a', 'b', 'z']));
            self::assertSame(['b' => 2], $this->repo->valoresDoUsuario($tenant, $usuario, ['z', 'b']));
        });

        // A primeira pergunta traz todas as linhas da pessoa no escritório: chave nova não consulta de novo.
        self::assertSame(1, $consultas);
    }

    #[TestDox('gravar() e apagarDoUsuario() na mesma requisição: a leitura seguinte vê o banco')]
    public function testGravarEApagarEsquecem(): void
    {
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUser();

        self::assertSame([], $this->repo->valoresDoUsuario($tenant, $usuario, ['perfil.som']));
        $this->repo->gravar($tenant, $usuario, 'perfil.som', false);
        self::assertSame(['perfil.som' => false], $this->repo->valoresDoUsuario($tenant, $usuario, ['perfil.som']));
        $this->repo->apagarDoUsuario($tenant, $usuario, ['perfil.som']);
        self::assertSame([], $this->repo->valoresDoUsuario($tenant, $usuario, ['perfil.som']));
    }

    #[TestDox('Outra pessoa e outro escritório não herdam a memória; reset() esvazia')]
    public function testIsolamentoEReset(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $ana     = $this->criarUser();
        $bia     = $this->criarUser();
        $this->repo->gravar($tenantA, $ana, 'k', 'ana-a');
        $this->repo->gravar($tenantB, $ana, 'k', 'ana-b');
        $this->repo->gravar($tenantA, $bia, 'k', 'bia-a');

        self::assertSame(['k' => 'ana-a'], $this->repo->valoresDoUsuario($tenantA, $ana, ['k']));
        self::assertSame(['k' => 'ana-b'], $this->repo->valoresDoUsuario($tenantB, $ana, ['k']));
        self::assertSame(['k' => 'bia-a'], $this->repo->valoresDoUsuario($tenantA, $bia, ['k']));

        $consultas = $this->consultasDurante(function () use ($tenantA, $ana): void {
            $this->repo->reset();
            $this->repo->valoresDoUsuario($tenantA, $ana, ['k']);
        });
        self::assertSame(1, $consultas);
    }
}
