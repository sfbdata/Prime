<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C6-SEC-01 — enviar, editar e excluir observação de Detalhes e do Relatório financeiro conferem
 * EXPLICITAMENTE que a pasta (e a observação) são do escritório da sessão. Mesmo padrão do SEC-002
 * ({@see PastaMensagemPosseDoEscritorioTest}).
 *
 * Todo cenário roda com o TenantFilter DESLIGADO dentro da requisição
 * ({@see DesligaTenantFilterNaRequisicao}) e com usuário super-admin (passa direto pelo
 * `PermissionChecker`): o 404 só pode vir do guarda novo. Sem ele, enviar parava no 422 do UseCase
 * e editar/excluir no 403 do UseCase — confirmando que o registro existe em algum lugar.
 *
 * Os dois tipos de observação têm as mesmas três rotas; o provedor roda cada cenário nos dois.
 *
 * A exceção ao super-admin é o bloco "a posse vem ANTES da permissão": lá o usuário só VÊ pastas,
 * para provar que a pasta alheia responde 404 (posse) e não o 403 do EDIT.
 */
#[CoversClass(PastaController::class)]
final class PastaObservacaoPosseDoEscritorioTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    /**
     * @return iterable<string, array{class-string, string, string, string}>
     *         [classe da observação, tabela, segmento da rota, prefixo do token CSRF]
     */
    public static function tiposDeObservacao(): iterable
    {
        yield 'financeiro' => [PastaObservacaoFinanceira::class, 'pasta_observacao_financeira', 'financeiro', 'pasta_financeiro_obs_'];
        yield 'detalhes'   => [PastaObservacaoDetalhes::class, 'pasta_observacao_detalhes', 'detalhes', 'pasta_detalhes_obs_'];
    }

    // ── enviar ───────────────────────────────────────────────────────────────

    /** @param class-string $classe */
    #[DataProvider('tiposDeObservacao')]
    #[TestDox('enviar observação (com @menção) na pasta de OUTRO escritório (filtro desligado) responde 404, sem observação nem notificação')]
    public function testEnviarNaPastaDeOutroEscritorio(string $classe, string $tabela, string $segmento, string $token): void
    {
        [$client, , $tenantA, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $colega   = $this->criarSuperAdmin($tenantA, 'colega');
        $idColega = (int) $colega->getId();
        $idPasta  = (int) $this->criarPasta($tenantB)->getId();
        $notificacoesAntes = $this->contarNotificacoes();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao", [
            '_token'   => $this->csrf($token . $idPasta),
            'conteudo' => "Invasão para @[Colega](user:{$idColega})",
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE pasta_id = ?", [$idPasta]));
        self::assertSame($notificacoesAntes, $this->contarNotificacoes());
    }

    /** @param class-string $classe */
    #[DataProvider('tiposDeObservacao')]
    #[TestDox('controle: enviar observação na PRÓPRIA pasta com o filtro desligado continua criando (201)')]
    public function testEnviarNaPropriaPastaComFiltroDesligado(string $classe, string $tabela, string $segmento, string $token): void
    {
        [$client, , $tenantA] = $this->prepararIntrusoDoEscritorioA();
        $idPasta = (int) $this->criarPasta($tenantA)->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao", [
            '_token'   => $this->csrf($token . $idPasta),
            'conteudo' => 'Observação legítima',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE pasta_id = ?", [$idPasta]));
    }

    // ── editar ───────────────────────────────────────────────────────────────

    /** @param class-string $classe */
    #[DataProvider('tiposDeObservacao')]
    #[TestDox('editar observação na pasta de OUTRO escritório (filtro desligado) responde 404 e o conteúdo não muda')]
    public function testEditarNaPastaDeOutroEscritorio(string $classe, string $tabela, string $segmento, string $token): void
    {
        [$client, $userA, , $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB = $this->criarPasta($tenantB);
        // Autor = o próprio intruso, dentro das 24h: a única barreira que sobra é o escritório.
        $idObs   = $this->criarObservacao($classe, $pastaB, $userA, $tenantB);
        $idPasta = (int) $pastaB->getId();
        $antes   = $this->conteudo($tabela, $idObs);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao/{$idObs}/editar", [
            '_token'   => $this->csrf($token . 'editar_' . $idObs),
            'conteudo' => 'Invadido',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame($antes, $this->conteudo($tabela, $idObs));
    }

    /** @param class-string $classe */
    #[DataProvider('tiposDeObservacao')]
    #[TestDox('editar pela PRÓPRIA pasta uma observação gravada com OUTRO escritório (filtro desligado) responde 404: a busca é escopada ao tenant')]
    public function testEditarObservacaoDeOutroEscritorioPelaPropriaPasta(string $classe, string $tabela, string $segmento, string $token): void
    {
        [$client, $userA, $tenantA, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaA = $this->criarPasta($tenantA);
        // Linha inconsistente de propósito (pasta de A, tenant de B): o prólogo da pasta passa, e
        // só a busca da observação por pasta+tenant segura. Antes, o `find()` cru a achava e o
        // UseCase respondia 403.
        $idObs   = $this->criarObservacao($classe, $pastaA, $userA, $tenantB);
        $idPasta = (int) $pastaA->getId();
        $antes   = $this->conteudo($tabela, $idObs);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao/{$idObs}/editar", [
            '_token'   => $this->csrf($token . 'editar_' . $idObs),
            'conteudo' => 'Invadido',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame($antes, $this->conteudo($tabela, $idObs));
    }

    // ── excluir ──────────────────────────────────────────────────────────────

    /** @param class-string $classe */
    #[DataProvider('tiposDeObservacao')]
    #[TestDox('excluir observação na pasta de OUTRO escritório (filtro desligado) responde 404 e a observação continua lá')]
    public function testExcluirNaPastaDeOutroEscritorio(string $classe, string $tabela, string $segmento, string $token): void
    {
        [$client, $userA, , $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB  = $this->criarPasta($tenantB);
        $idObs   = $this->criarObservacao($classe, $pastaB, $userA, $tenantB);
        $idPasta = (int) $pastaB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao/{$idObs}/excluir", [
            '_token' => $this->csrf($token . 'excluir_' . $idObs),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE id = ?", [$idObs]));
    }

    /** @param class-string $classe */
    #[DataProvider('tiposDeObservacao')]
    #[TestDox('excluir pela PRÓPRIA pasta uma observação gravada com OUTRO escritório (filtro desligado) responde 404: a busca é escopada ao tenant')]
    public function testExcluirObservacaoDeOutroEscritorioPelaPropriaPasta(string $classe, string $tabela, string $segmento, string $token): void
    {
        [$client, $userA, $tenantA, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaA  = $this->criarPasta($tenantA);
        $idObs   = $this->criarObservacao($classe, $pastaA, $userA, $tenantB);
        $idPasta = (int) $pastaA->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao/{$idObs}/excluir", [
            '_token' => $this->csrf($token . 'excluir_' . $idObs),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE id = ?", [$idObs]));
    }

    // ── a posse vem ANTES da permissão ───────────────────────────────────────
    //
    // Os cenários acima usam super-admin, que passa direto pelo `PermissionChecker`: não dizem se
    // a posse é conferida antes ou depois do EDIT. Aqui o usuário só VÊ (sem `resources.pasta.edit`):
    // se o guarda de posse viesse depois, a pasta alheia responderia 403 — e o 403 já confirma que
    // o id existe em algum escritório.

    /**
     * @return iterable<string, array{class-string, string, string, string, string}>
     *         [classe, tabela, segmento da rota, prefixo do token CSRF, ação]
     */
    public static function rotasDeObservacao(): iterable
    {
        foreach (self::tiposDeObservacao() as $tipo => $dados) {
            foreach (['enviar', 'editar', 'excluir'] as $acao) {
                yield $tipo . ' · ' . $acao => [...$dados, $acao];
            }
        }
    }

    /** @param class-string $classe */
    #[DataProvider('rotasDeObservacao')]
    #[TestDox('quem NÃO edita pastas, na pasta de OUTRO escritório (filtro desligado), recebe 404 e não 403: a posse vem antes da permissão')]
    public function testSemEditarNaPastaDeOutroEscritorioRecebe404(string $classe, string $tabela, string $segmento, string $token, string $acao): void
    {
        [$client, , $tenantB, $leitor] = $this->prepararLeitorDoEscritorioA();
        $pastaB  = $this->criarPasta($tenantB);
        $idObs   = $this->criarObservacao($classe, $pastaB, $leitor, $tenantB);
        $idPasta = (int) $pastaB->getId();
        $antes   = $this->conteudo($tabela, $idObs);

        $this->desligarTenantFilterNaProximaRequisicao();
        $this->requisitar($client, $acao, $idPasta, $idObs, $segmento, $token);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE pasta_id = ?", [$idPasta]), 'nada criado nem apagado');
        self::assertSame($antes, $this->conteudo($tabela, $idObs), 'nada editado');
    }

    /** @param class-string $classe */
    #[DataProvider('rotasDeObservacao')]
    #[TestDox('controle: quem NÃO edita pastas, na PRÓPRIA pasta (filtro desligado), recebe 403 — o 404 acima é da posse, não da permissão')]
    public function testSemEditarNaPropriaPastaRecebe403(string $classe, string $tabela, string $segmento, string $token, string $acao): void
    {
        [$client, $tenantA, , $leitor] = $this->prepararLeitorDoEscritorioA();
        $pastaA  = $this->criarPasta($tenantA);
        $idObs   = $this->criarObservacao($classe, $pastaA, $leitor, $tenantA);
        $idPasta = (int) $pastaA->getId();
        $antes   = $this->conteudo($tabela, $idObs);

        $this->desligarTenantFilterNaProximaRequisicao();
        $this->requisitar($client, $acao, $idPasta, $idObs, $segmento, $token);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE pasta_id = ?", [$idPasta]));
        self::assertSame($antes, $this->conteudo($tabela, $idObs));
    }

    // ── apoio ────────────────────────────────────────────────────────────────

    /**
     * Cliente logado no escritório A com um usuário que só VÊ pastas (papel com
     * `resources.pasta.view` e sem `resources.pasta.edit`), e um escritório B alheio.
     *
     * @return array{KernelBrowser, Tenant, Tenant, User} [cliente, tenantA, tenantB, leitor]
     */
    private function prepararLeitorDoEscritorioA(): array
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant('A');
        $tenantB = $this->criarTenant('B');
        $leitor  = $this->criarLeitor($tenantA);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $leitor, $tenantA);

        return [$client, $tenantA, $tenantB, $leitor];
    }

    private function criarLeitor(Tenant $tenant): User
    {
        $em   = $this->em();
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'resources.pasta.view']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode('resources.pasta.view');
            $perm->setDescription('Visualizar pasta específica');
            $perm->setGroup('resources');
            $em->persist($perm);
            $em->flush();
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Só leitura ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($role);
        $vinculo->setPermission($perm);
        $em->persist($vinculo);
        $role->getTenantRolePermissions()->add($vinculo);

        $user = new User();
        $user->setEmail('posse_obs_leitor_' . uniqid() . '@test.com');
        $user->setFullName('Usuário leitor');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function requisitar(KernelBrowser $client, string $acao, int $idPasta, int $idObs, string $segmento, string $token): void
    {
        match ($acao) {
            'enviar' => $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao", [
                '_token'   => $this->csrf($token . $idPasta),
                'conteudo' => 'Tentativa de quem só lê',
            ]),
            'editar' => $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao/{$idObs}/editar", [
                '_token'   => $this->csrf($token . 'editar_' . $idObs),
                'conteudo' => 'Invadido',
            ]),
            'excluir' => $client->request('POST', "/pasta/{$idPasta}/{$segmento}/observacao/{$idObs}/excluir", [
                '_token' => $this->csrf($token . 'excluir_' . $idObs),
            ]),
        };
    }

    /**
     * Cliente logado como super-admin do escritório A (sessão em A) e um escritório B alheio.
     *
     * @return array{KernelBrowser, User, Tenant, Tenant} [cliente, userA, tenantA, tenantB]
     */
    private function prepararIntrusoDoEscritorioA(): array
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant('A');
        $tenantB = $this->criarTenant('B');
        $userA   = $this->criarSuperAdmin($tenantA, 'intruso');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        return [$client, $userA, $tenantA, $tenantB];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conexao(): Connection
    {
        return $this->em()->getConnection();
    }

    private function contarNotificacoes(): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM notificacao');
    }

    private function conteudo(string $tabela, int $idObs): string
    {
        return (string) $this->conexao()->fetchOne("SELECT conteudo FROM {$tabela} WHERE id = ?", [$idObs]);
    }

    private function criarTenant(string $rotulo): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Posse Observacao ' . $rotulo . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarSuperAdmin(Tenant $tenant, string $prefixo): User
    {
        $user = new User();
        $user->setEmail('posse_obs_' . $prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . $prefixo);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-POSSE-OBS-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** @param class-string $classe */
    private function criarObservacao(string $classe, Pasta $pasta, User $autor, Tenant $tenant): int
    {
        /** @var PastaObservacaoFinanceira|PastaObservacaoDetalhes $obs */
        $obs = new $classe();
        $obs->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('Observação original');
        $this->em()->persist($obs);
        $this->em()->flush();

        return (int) $obs->getId();
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }
}
