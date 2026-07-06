<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaSecaoController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaSecaoController::class)]
final class PastaSecaoControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Secao ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_secao_' . uniqid() . '@test.com');
        $user->setFullName('Admin Secao');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-SEC-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant): PastaSecao
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome('Seção de Teste');
        $secao->setOrdem(1);
        $em->persist($secao);
        $em->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, int $ordem): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setTitulo('doc' . $ordem);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/doc' . $ordem . '.pdf');
        $doc->setNomeOriginal('doc' . $ordem . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setOrdem($ordem);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $em->persist($doc);
        $em->flush();

        return $doc;
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

    // ── Criar ──────────────────────────────────────────────────────────────────

    #[TestDox('POST criar seção sem autenticação redireciona para login')]
    public function testCriarSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/secao');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST criar seção com CSRF inválido retorna 400')]
    public function testCriarCsrfInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => 'token_invalido',
            'nome'   => 'Petições',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[TestDox('POST criar seção com nome vazio retorna 422')]
    public function testCriarNomeVazioRetorna422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => '   ',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('POST criar seção com nome válido retorna 201 com id e nome')]
    public function testCriarComSucessoRetorna201(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => 'Petições',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('PETIÇÕES', $data['nome']);
        self::assertArrayHasKey('id', $data);
        self::assertGreaterThan(0, $data['id']);
    }

    // ── Renomear ───────────────────────────────────────────────────────────────

    #[TestDox('POST renomear seção com nome válido retorna 200')]
    public function testRenomearComSucessoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/secao/{$secao->getId()}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $secao->getId()),
            'nome'   => 'Contratos',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame('CONTRATOS', $data['nome']);
    }

    #[TestDox('POST renomear seção inexistente retorna 404')]
    public function testRenomearSecaoInexistenteRetorna404(): void
    {
        $client  = static::createClient();
        [$user]  = $this->criarUsuarioAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', '/pasta/secao/999999/renomear', [
            '_token' => $this->csrf('pasta_secao_renomear_999999'),
            'nome'   => 'Teste',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Excluir ────────────────────────────────────────────────────────────────

    #[TestDox('POST excluir seção com sucesso retorna 200')]
    public function testExcluirComSucessoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant);
        $secaoId         = $secao->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/secao/{$secaoId}/excluir", [
            '_token' => $this->csrf('pasta_secao_excluir_' . $secaoId),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(PastaSecao::class, $secaoId));
    }

    #[TestDox('POST excluir seção com CSRF inválido retorna 400')]
    public function testExcluirCsrfInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/secao/{$secao->getId()}/excluir", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    // ── Reordenar seções ─────────────────────────────────────────────────────────

    #[TestDox('POST reordenar seções atualiza a ordem e retorna 200')]
    public function testReordenarSecoesComSucessoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secaoA          = $this->criarSecao($pasta, $tenant);
        $secaoB          = $this->criarSecao($pasta, $tenant);
        $idA             = $secaoA->getId();
        $idB             = $secaoB->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        // Inverte a ordem: B primeiro, A depois.
        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/secoes/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token' => $this->csrf('reordenar_secoes_pasta_' . $pasta->getId()),
                'ids'    => [$idB, $idA],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(1, $em->find(PastaSecao::class, $idB)->getOrdem());
        self::assertSame(2, $em->find(PastaSecao::class, $idA)->getOrdem());
    }

    #[TestDox('POST reordenar seções com CSRF inválido retorna 400')]
    public function testReordenarSecoesCsrfInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/secoes/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['_token' => 'token_invalido', 'ids' => [1, 2]], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
    }

    // ── Isolamento multi-tenant ──────────────────────────────────────────────────

    #[TestDox('POST reordenar seções de OUTRO tenant não altera a ordem (isolamento)')]
    public function testReordenarSecoesCrossTenantNaoAltera(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarUsuarioAdmin();
        [, $tenantB]       = $this->criarUsuarioAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $secaoB1           = $this->criarSecao($pastaB, $tenantB);
        $secaoB2           = $this->criarSecao($pastaB, $tenantB);

        // Ordens distintas para provar que nada muda.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $secaoB2->setOrdem(2);
        $em->flush();

        $idB1 = $secaoB1->getId();
        $idB2 = $secaoB2->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        // Usuário do tenant A tenta reordenar seções da pasta do tenant B.
        $client->request(
            'POST',
            "/pasta/{$pastaB->getId()}/secoes/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token' => $this->csrf('reordenar_secoes_pasta_' . $pastaB->getId()),
                'ids'    => [$idB2, $idB1],
            ], JSON_THROW_ON_ERROR),
        );

        // O UseCase filtra por tenant → nenhuma seção do tenant B é tocada.
        // Lê direto do banco (o filtro de tenant esconderia a linha via ORM — o que já é prova de isolamento).
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT ordem FROM pasta_secao WHERE id = ?', [$idB1]), 'A ordem da seção de outro escritório não pode mudar.');
        self::assertSame(2, (int) $conn->fetchOne('SELECT ordem FROM pasta_secao WHERE id = ?', [$idB2]));
    }

    #[TestDox('POST reordenar documentos de OUTRO tenant não altera a ordem (isolamento)')]
    public function testReordenarDocumentosCrossTenantNaoAltera(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarUsuarioAdmin();
        [, $tenantB]       = $this->criarUsuarioAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $docB1             = $this->criarDocumento($pastaB, $tenantB, 1);
        $docB2             = $this->criarDocumento($pastaB, $tenantB, 2);
        $idB1              = $docB1->getId();
        $idB2              = $docB2->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        // Usuário do tenant A tenta reordenar documentos da pasta do tenant B.
        $client->request(
            'POST',
            "/pasta/{$pastaB->getId()}/documentos/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token' => $this->csrf('reordenar_docs_pasta_' . $pastaB->getId()),
                'ids'    => [$idB2, $idB1],
            ], JSON_THROW_ON_ERROR),
        );

        // O UseCase filtra por tenant → nenhum documento do tenant B é tocado.
        // Lê direto do banco (o filtro de tenant esconderia a linha via ORM — o que já é prova de isolamento).
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT ordem FROM pasta_documento WHERE id = ?', [$idB1]), 'A ordem do documento de outro escritório não pode mudar.');
        self::assertSame(2, (int) $conn->fetchOne('SELECT ordem FROM pasta_documento WHERE id = ?', [$idB2]));
    }
}
