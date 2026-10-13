<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
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

    // ── apoio ────────────────────────────────────────────────────────────────

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
