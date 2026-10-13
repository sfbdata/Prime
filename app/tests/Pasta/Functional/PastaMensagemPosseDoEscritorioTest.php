<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * SEC-002 — enviar, editar e excluir mensagem do Registro conferem EXPLICITAMENTE que a pasta (e
 * a mensagem) são do escritório da sessão.
 *
 * Todo cenário roda com o TenantFilter DESLIGADO dentro da requisição
 * ({@see DesligaTenantFilterNaRequisicao}) e com usuário super-admin (passa direto pelo
 * `PermissionChecker`): o 404 só pode vir do guarda novo. Sem ele, enviar gravava a mensagem na
 * pasta alheia (201) e editar/excluir paravam no 403 do UseCase — confirmando que o registro existe.
 */
#[CoversClass(PastaController::class)]
final class PastaMensagemPosseDoEscritorioTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    // ── enviar ───────────────────────────────────────────────────────────────

    #[TestDox('enviar mensagem (com @menção) na pasta de OUTRO escritório (filtro desligado) responde 404, sem mensagem nem notificação')]
    public function testEnviarNaPastaDeOutroEscritorio(): void
    {
        [$client, , $tenantA, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        // Um colega de A, mencionado: sem o guarda, a menção viraria notificação no sino dele.
        $colega   = $this->criarSuperAdmin($tenantA, 'colega');
        $idColega = (int) $colega->getId();
        $idPasta  = (int) $this->criarPasta($tenantB)->getId();
        $notificacoesAntes = $this->contarNotificacoes();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/mensagem", [
            '_token'   => $this->csrf('pasta_mensagem_' . $idPasta),
            'conteudo' => "Invasão para @[Colega](user:{$idColega})",
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ?', [$idPasta]));
        self::assertSame($notificacoesAntes, $this->contarNotificacoes());
    }

    #[TestDox('controle: enviar na PRÓPRIA pasta com o filtro desligado continua criando (201)')]
    public function testEnviarNaPropriaPastaComFiltroDesligado(): void
    {
        [$client, , $tenantA] = $this->prepararIntrusoDoEscritorioA();
        $idPasta = (int) $this->criarPasta($tenantA)->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/mensagem", [
            '_token'   => $this->csrf('pasta_mensagem_' . $idPasta),
            'conteudo' => 'Registro legítimo',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ?', [$idPasta]));
    }

    // ── editar ───────────────────────────────────────────────────────────────

    #[TestDox('editar mensagem na pasta de OUTRO escritório (filtro desligado) responde 404 e o conteúdo não muda')]
    public function testEditarNaPastaDeOutroEscritorio(): void
    {
        [$client, $userA, , $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB  = $this->criarPasta($tenantB);
        // Autor = o próprio intruso, dentro das 24h: a única barreira que sobra é o escritório.
        $idMsg   = (int) $this->criarMensagem($pastaB, $userA, $tenantB)->getId();
        $idPasta = (int) $pastaB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/mensagem/{$idMsg}/editar", [
            '_token'   => $this->csrf('pasta_mensagem_editar_' . $idMsg),
            'conteudo' => 'Invadido',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Mensagem original', $this->conexao()->fetchOne('SELECT conteudo FROM pasta_mensagem WHERE id = ?', [$idMsg]));
    }

    #[TestDox('editar pela PRÓPRIA pasta uma mensagem gravada com OUTRO escritório (filtro desligado) responde 404: a busca é escopada ao tenant')]
    public function testEditarMensagemDeOutroEscritorioPelaPropriaPasta(): void
    {
        [$client, $userA, $tenantA, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaA = $this->criarPasta($tenantA);
        // Linha inconsistente de propósito (pasta de A, tenant de B): o prólogo da pasta passa, e
        // só a busca da mensagem por pasta+tenant segura. Antes, o `find()` cru a achava e o
        // UseCase respondia 403.
        $idMsg   = (int) $this->criarMensagem($pastaA, $userA, $tenantB)->getId();
        $idPasta = (int) $pastaA->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/mensagem/{$idMsg}/editar", [
            '_token'   => $this->csrf('pasta_mensagem_editar_' . $idMsg),
            'conteudo' => 'Invadido',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Mensagem original', $this->conexao()->fetchOne('SELECT conteudo FROM pasta_mensagem WHERE id = ?', [$idMsg]));
    }

    // ── excluir ──────────────────────────────────────────────────────────────

    #[TestDox('excluir mensagem na pasta de OUTRO escritório (filtro desligado) responde 404 e a mensagem continua lá')]
    public function testExcluirNaPastaDeOutroEscritorio(): void
    {
        [$client, $userA, , $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB  = $this->criarPasta($tenantB);
        $idMsg   = (int) $this->criarMensagem($pastaB, $userA, $tenantB)->getId();
        $idPasta = (int) $pastaB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/mensagem/{$idMsg}/excluir", [
            '_token' => $this->csrf('pasta_mensagem_excluir_' . $idMsg),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM pasta_mensagem WHERE id = ?', [$idMsg]));
    }

    #[TestDox('excluir pela PRÓPRIA pasta uma mensagem gravada com OUTRO escritório (filtro desligado) responde 404: a busca é escopada ao tenant')]
    public function testExcluirMensagemDeOutroEscritorioPelaPropriaPasta(): void
    {
        [$client, $userA, $tenantA, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaA  = $this->criarPasta($tenantA);
        $idMsg   = (int) $this->criarMensagem($pastaA, $userA, $tenantB)->getId();
        $idPasta = (int) $pastaA->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/mensagem/{$idMsg}/excluir", [
            '_token' => $this->csrf('pasta_mensagem_excluir_' . $idMsg),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM pasta_mensagem WHERE id = ?', [$idMsg]));
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

    private function criarTenant(string $rotulo): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Posse Mensagem ' . $rotulo . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarSuperAdmin(Tenant $tenant, string $prefixo): User
    {
        $user = new User();
        $user->setEmail('posse_msg_' . $prefixo . '_' . uniqid() . '@test.com');
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
        $pasta->setNup('TEST-POSSE-MSG-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarMensagem(Pasta $pasta, User $autor, Tenant $tenant): PastaMensagem
    {
        $mensagem = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('Mensagem original');
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $mensagem;
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
