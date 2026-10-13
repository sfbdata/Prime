<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use App\Twig\PermissionExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * PAS-050 — `can_access_resource` consulta o banco (vínculo do usuário + acesso específico ao
 * recurso). A pasta pergunta a mesma coisa em vários parciais; a resposta fica guardada por
 * requisição, pela chave (usuário, escritório, tipo, id, ação), e some no `reset()` do
 * `kernel.reset` — para não vazar entre requisições de um worker nem entre testes.
 *
 * As consultas são contadas no `doctrine.debug_data_holder` (precedente:
 * CorrigirValorDoPagamentoTravaTest). Usuário de papel NÃO-sistema: é o caminho que chega às
 * duas consultas (o papel de sistema e o super admin decidem antes).
 */
#[CoversClass(PermissionExtension::class)]
#[Group('pasta')]
final class PermissionExtensionMemoriaTest extends KernelTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    /** @return array{PermissionExtension, Pasta, Tenant} */
    private function cenario(): array
    {
        self::bootKernel();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $pasta  = $this->criarPasta($tenant);
        $this->autenticar($user, $tenant);

        return [static::getContainer()->get(PermissionExtension::class), $pasta, $tenant];
    }

    private function autenticar(User $user, Tenant $tenant): void
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $session = new Session(new MockArraySessionStorage());
        $session->set('current_tenant_id', $tenant->getId());
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
    }

    /** @return list<string> */
    private function consultasDurante(callable $acao): array
    {
        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();
        $acao();

        return array_column($coletor->getData()['default'] ?? [], 'sql');
    }

    /** @param list<string> $sqls */
    private function consultasDePermissao(array $sqls): int
    {
        return count(array_filter($sqls, static fn (string $sql): bool => (bool) preg_match('/\bFROM\s+(user_tenant|resource_access)\b/i', $sql)));
    }

    #[TestDox('N chamadas com a mesma chave: as consultas de permissão saem uma vez só (2)')]
    public function testMesmaChaveConsultaUmaVez(): void
    {
        [$ext, $pasta] = $this->cenario();
        $id = (int) $pasta->getId();

        $resultados = [];
        $sqls = $this->consultasDurante(static function () use ($ext, $id, &$resultados): void {
            for ($i = 0; $i < 5; ++$i) {
                $resultados[] = $ext->canAccessResource('pasta', $id, 'edit');
            }
        });

        self::assertSame([true, true, true, true, true], $resultados);
        self::assertSame(2, $this->consultasDePermissao($sqls), implode("\n", $sqls));
    }

    #[TestDox('Ação diferente é outra chave: consulta de novo e decide por ela')]
    public function testOutraAcaoEOutraChave(): void
    {
        [$ext, $pasta] = $this->cenario();
        $id = (int) $pasta->getId();

        $sqls = $this->consultasDurante(static function () use ($ext, $id): void {
            self::assertTrue($ext->canAccessResource('pasta', $id, 'edit'));
            self::assertFalse($ext->canAccessResource('pasta', $id, 'delete'));
            self::assertTrue($ext->canAccessResource('pasta', $id, 'edit'));
            self::assertFalse($ext->canAccessResource('pasta', $id, 'delete'));
        });

        self::assertSame(4, $this->consultasDePermissao($sqls));
    }

    #[TestDox('reset() esvazia a memória: a requisição seguinte consulta de novo')]
    public function testResetEsvazia(): void
    {
        [$ext, $pasta] = $this->cenario();
        $id = (int) $pasta->getId();
        $ext->canAccessResource('pasta', $id, 'edit');

        $sqls = $this->consultasDurante(static function () use ($ext, $id): void {
            $ext->reset();
            $ext->canAccessResource('pasta', $id, 'edit');
        });

        self::assertSame(2, $this->consultasDePermissao($sqls));
    }

    #[TestDox('Outro usuário no mesmo serviço não herda a decisão do anterior')]
    public function testOutroUsuarioNaoHerda(): void
    {
        [$ext, $pasta, $tenant] = $this->cenario();
        $id = (int) $pasta->getId();
        self::assertTrue($ext->canAccessResource('pasta', $id, 'edit'));

        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->autenticar($leitor, $tenant);

        self::assertFalse($ext->canAccessResource('pasta', $id, 'edit'));
    }
}
