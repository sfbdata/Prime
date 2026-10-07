<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaSecaoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * SEC-001 — posse do escritório provada EXPLICITAMENTE nas rotas de seção/documento.
 *
 * Todo cenário roda com o TenantFilter DESLIGADO dentro da requisição
 * ({@see DesligaTenantFilterNaRequisicao}): é o que garante que o 404 vem do guarda novo (busca
 * escopada ao tenant / prólogo de posse da pasta) e não do filtro. O usuário é super-admin, que
 * passa direto pelo `PermissionChecker` — nenhuma outra barreira sobra no caminho.
 *
 * Sem o guarda, cada rota aqui respondia outra coisa: 200 (reordenar, criar), ou 403 do UseCase
 * que confere o tenant (renomear/mover-destino) — nunca o 404 que os testes exigem.
 */
#[CoversClass(PastaSecaoController::class)]
final class PastaSecaoPosseDoEscritorioTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    // ── renomear ─────────────────────────────────────────────────────────────

    #[TestDox('renomear seção de OUTRO escritório (filtro desligado) responde 404 e o nome não muda')]
    public function testRenomearSecaoDeOutroEscritorio(): void
    {
        [$client, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $id    = (int) $this->criarSecao($this->criarPasta($tenantB), $tenantB, 'Alheia')->getId();
        $antes = $this->conexao()->fetchOne('SELECT nome FROM pasta_secao WHERE id = ?', [$id]);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/secao/{$id}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $id),
            'nome'   => 'Invadido',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame($antes, $this->conexao()->fetchOne('SELECT nome FROM pasta_secao WHERE id = ?', [$id]));
    }

    #[TestDox('controle: renomear a PRÓPRIA seção com o filtro desligado continua funcionando (200)')]
    public function testRenomearPropriaSecaoComFiltroDesligado(): void
    {
        [$client, , $tenantA] = $this->prepararIntrusoDoEscritorioA();
        $id = (int) $this->criarSecao($this->criarPasta($tenantA), $tenantA, 'Minha')->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/secao/{$id}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $id),
            'nome'   => 'Renomeada',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseIsSuccessful();
        self::assertSame('RENOMEADA', $this->conexao()->fetchOne('SELECT nome FROM pasta_secao WHERE id = ?', [$id]));
    }

    // ── excluir ──────────────────────────────────────────────────────────────

    #[TestDox('excluir seção de OUTRO escritório (filtro desligado) responde 404 e ela não vai para a lixeira')]
    public function testExcluirSecaoDeOutroEscritorio(): void
    {
        [$client, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $id = (int) $this->criarSecao($this->criarPasta($tenantB), $tenantB, 'Alheia')->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/secao/{$id}/excluir", [
            '_token' => $this->csrf('pasta_secao_excluir_' . $id),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->conexao()->fetchOne('SELECT excluido_em FROM pasta_secao WHERE id = ?', [$id]));
    }

    // ── mover subpasta ───────────────────────────────────────────────────────

    #[TestDox('mover seção de OUTRO escritório para a raiz (filtro desligado) responde 404 e o pai não muda')]
    public function testMoverSecaoDeOutroEscritorio(): void
    {
        [$client, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB = $this->criarPasta($tenantB);
        $paiB   = $this->criarSecao($pastaB, $tenantB, 'Pai');
        $filhaB = $this->criarSecao($pastaB, $tenantB, 'Filha', $paiB);
        $idPai  = (int) $paiB->getId();
        $id     = (int) $filhaB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/secao/{$id}/mover", [
            '_token' => $this->csrf('pasta_secao_mover_' . $id),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame($idPai, (int) $this->conexao()->fetchOne('SELECT secao_pai_id FROM pasta_secao WHERE id = ?', [$id]));
    }

    // ── mover documento para seção ───────────────────────────────────────────

    #[TestDox('mover documento de OUTRO escritório (filtro desligado) responde 404 e a seção dele não muda')]
    public function testMoverDocumentoDeOutroEscritorio(): void
    {
        [$client, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB = $this->criarPasta($tenantB);
        $secaoB = $this->criarSecao($pastaB, $tenantB, 'Origem');
        $docB   = $this->criarDocumento($pastaB, $tenantB, $secaoB);
        $idSecao = (int) $secaoB->getId();
        $id      = (int) $docB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        // secao_id vazio = "mover para a raiz": sem o guarda, tiraria o documento da seção.
        $client->request('POST', "/pasta/documento/{$id}/mover-secao", [
            '_token'   => $this->csrf('pasta_doc_mover_' . $id),
            'secao_id' => '',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame($idSecao, (int) $this->conexao()->fetchOne('SELECT secao_id FROM pasta_documento WHERE id = ?', [$id]));
    }

    #[TestDox('mover o PRÓPRIO documento para seção de OUTRO escritório (filtro desligado) responde 404 e nada muda')]
    public function testMoverDocumentoParaSecaoDeOutroEscritorio(): void
    {
        [$client, $tenantB, $tenantA] = $this->prepararIntrusoDoEscritorioA();
        $docA    = $this->criarDocumento($this->criarPasta($tenantA), $tenantA);
        $secaoB  = $this->criarSecao($this->criarPasta($tenantB), $tenantB, 'Destino alheio');
        $id      = (int) $docA->getId();
        $destino = (int) $secaoB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/documento/{$id}/mover-secao", [
            '_token'   => $this->csrf('pasta_doc_mover_' . $id),
            'secao_id' => (string) $destino,
        ]);

        $this->assertTenantFilterFoiDesligado();
        // Sem o guarda: o UseCase recusava com AccessDenied (403) — confirmava que a seção existe.
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->conexao()->fetchOne('SELECT secao_id FROM pasta_documento WHERE id = ?', [$id]));
    }

    // ── criar ────────────────────────────────────────────────────────────────

    #[TestDox('criar seção na pasta de OUTRO escritório (filtro desligado) responde 404 e nenhuma seção nasce')]
    public function testCriarSecaoNaPastaDeOutroEscritorio(): void
    {
        [$client, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $idPasta = (int) $this->criarPasta($tenantB)->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/pasta/{$idPasta}/secao", [
            '_token' => $this->csrf('pasta_secao_criar_' . $idPasta),
            'nome'   => 'Invasora',
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE pasta_id = ?', [$idPasta]));
    }

    // ── reordenar ────────────────────────────────────────────────────────────

    #[TestDox('reordenar documentos da pasta de OUTRO escritório (filtro desligado) responde 404 e a ordem não muda')]
    public function testReordenarDocumentosDeOutroEscritorio(): void
    {
        [$client, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB = $this->criarPasta($tenantB);
        $id1    = (int) $this->criarDocumento($pastaB, $tenantB, null, 1)->getId();
        $id2    = (int) $this->criarDocumento($pastaB, $tenantB, null, 2)->getId();
        $idPasta = (int) $pastaB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $this->postJson($client, "/pasta/{$idPasta}/documentos/reordenar", [
            '_token' => $this->csrf('reordenar_docs_pasta_' . $idPasta),
            'ids'    => [$id2, $id1],
        ]);

        $this->assertTenantFilterFoiDesligado();
        // Sem o guarda: 200 (o UseCase filtra por tenant e não toca nada, mas a rota "dava certo").
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT ordem FROM pasta_documento WHERE id = ?', [$id1]));
        self::assertSame(2, (int) $this->conexao()->fetchOne('SELECT ordem FROM pasta_documento WHERE id = ?', [$id2]));
    }

    #[TestDox('reordenar seções da pasta de OUTRO escritório (filtro desligado) responde 404 e a ordem não muda')]
    public function testReordenarSecoesDeOutroEscritorio(): void
    {
        [$client, $tenantB] = $this->prepararIntrusoDoEscritorioA();
        $pastaB  = $this->criarPasta($tenantB);
        $id1     = (int) $this->criarSecao($pastaB, $tenantB, 'Um', null, 1)->getId();
        $id2     = (int) $this->criarSecao($pastaB, $tenantB, 'Dois', null, 2)->getId();
        $idPasta = (int) $pastaB->getId();

        $this->desligarTenantFilterNaProximaRequisicao();
        $this->postJson($client, "/pasta/{$idPasta}/secoes/reordenar", [
            '_token' => $this->csrf('reordenar_secoes_pasta_' . $idPasta),
            'ids'    => [$id2, $id1],
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT ordem FROM pasta_secao WHERE id = ?', [$id1]));
        self::assertSame(2, (int) $this->conexao()->fetchOne('SELECT ordem FROM pasta_secao WHERE id = ?', [$id2]));
    }

    // ── apoio ────────────────────────────────────────────────────────────────

    /**
     * Cliente logado como super-admin do escritório A (sessão em A) e um escritório B alheio.
     *
     * @return array{KernelBrowser, Tenant, Tenant} [cliente, tenantB, tenantA]
     */
    private function prepararIntrusoDoEscritorioA(): array
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant('A');
        $tenantB = $this->criarTenant('B');
        $userA   = $this->criarSuperAdmin($tenantA);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        return [$client, $tenantB, $tenantA];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conexao(): Connection
    {
        return $this->em()->getConnection();
    }

    private function criarTenant(string $rotulo): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Posse Secao ' . $rotulo . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarSuperAdmin(Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail('posse_secao_' . uniqid() . '@test.com');
        $user->setFullName('Admin Posse Secao');
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
        $pasta->setNup('TEST-POSSE-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome, ?PastaSecao $pai = null, int $ordem = 1): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem($ordem);
        $secao->setPai($pai);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao = null, int $ordem = 1): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo('doc' . $ordem);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/posse' . $ordem . '.pdf');
        $doc->setNomeOriginal('posse' . $ordem . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setOrdem($ordem);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setSecao($secao);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    /** @param array<string, mixed> $corpo */
    private function postJson(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($corpo, JSON_THROW_ON_ERROR));
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
