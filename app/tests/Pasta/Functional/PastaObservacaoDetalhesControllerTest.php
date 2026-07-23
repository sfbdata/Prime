<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class PastaObservacaoDetalhesControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuario(Tenant $tenant, string $prefixo = 'obs'): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . $prefixo);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Obs Detalhes ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarObservacao(
        Pasta $pasta,
        User $autor,
        Tenant $tenant,
        ?\DateTimeImmutable $criadaEm = null,
    ): PastaObservacaoDetalhes {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $obs = (new PastaObservacaoDetalhes())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('Observação original');

        if ($criadaEm !== null) {
            $ref = new \ReflectionProperty(PastaObservacaoDetalhes::class, 'criadaEm');
            $ref->setValue($obs, $criadaEm);
        }

        $em->persist($obs);
        $em->flush();

        return $obs;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    // ── Sem autenticação ─────────────────────────────────────────────────────

    #[TestDox('POST detalhes/observacao/{obsId}/editar sem autenticação redireciona para login')]
    public function testEditarSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/detalhes/observacao/1/editar');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    // ── Editar ───────────────────────────────────────────────────────────────

    #[TestDox('Autor edita a própria observação dentro de 24h e recebe 200')]
    public function testAutorEditaObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_editar_' . $obs->getId()),
            'conteudo' => 'Observação corrigida',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Observação corrigida', $data['conteudo']);
        self::assertNotNull($data['editadaEm']);
    }

    #[TestDox('Não-autor recebe 403 ao tentar editar observação de outro')]
    public function testNaoAutorNaoEditaObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $outro  = $this->criarUsuario($tenant, 'outro');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_editar_' . $obs->getId()),
            'conteudo' => 'Tentando editar',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Autor recebe 403 ao editar observação fora da janela de 24h')]
    public function testEditarForaDaJanelaRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant, new \DateTimeImmutable('-25 hours'));

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_editar_' . $obs->getId()),
            'conteudo' => 'Tarde demais',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Editar com CSRF inválido retorna 403')]
    public function testEditarCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obs->getId()}/editar", [
            '_token'   => 'token_invalido',
            'conteudo' => 'Qualquer coisa',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Editar observação de outra pasta retorna 404')]
    public function testEditarObservacaoDeOutraPastaRetorna404(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pastaA = $this->criarPasta($tenant);
        $pastaB = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pastaA, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pastaB->getId()}/detalhes/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_editar_' . $obs->getId()),
            'conteudo' => 'Pasta errada',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Observação de outro tenant na própria pasta retorna 404 (IDOR bloqueado)')]
    public function testEditarObservacaoCrossTenantViaIdRetorna404(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->criarUsuario($tenantA, 'usera');
        $userB   = $this->criarUsuario($tenantB, 'userb');
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $obsB    = $this->criarObservacao($pastaB, $userB, $tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('POST', "/pasta/{$pastaA->getId()}/detalhes/observacao/{$obsB->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_editar_' . $obsB->getId()),
            'conteudo' => 'Invasão',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Excluir ──────────────────────────────────────────────────────────────

    #[TestDox('Autor exclui a própria observação dentro de 24h e recebe sucesso')]
    public function testAutorExcluiObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);
        $obsId  = $obs->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obsId}/excluir", [
            '_token' => $this->gerarCsrf('pasta_detalhes_obs_excluir_' . $obsId),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(PastaObservacaoDetalhes::class, $obsId));
    }

    #[TestDox('Não-autor recebe 403 ao tentar excluir observação de outro')]
    public function testNaoAutorNaoExcluiObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $outro  = $this->criarUsuario($tenant, 'outro');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obs->getId()}/excluir", [
            '_token' => $this->gerarCsrf('pasta_detalhes_obs_excluir_' . $obs->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Autor recebe 403 ao excluir observação fora da janela de 24h')]
    public function testExcluirForaDaJanelaRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant, new \DateTimeImmutable('-25 hours'));

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obs->getId()}/excluir", [
            '_token' => $this->gerarCsrf('pasta_detalhes_obs_excluir_' . $obs->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Editor rico: a marcação perigosa nunca chega ao banco ────────────────

    #[TestDox('POST com script no conteúdo grava LIMPO — a barreira é o servidor, não o editor')]
    public function testEnviarConteudoMaliciosoGravaLimpo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        // Requisição forjada: ninguém digitaria isto no editor — vem direto no POST.
        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_' . $pasta->getId()),
            'conteudo' => '<p onclick="roubar()">Combinado</p><script>alert(document.cookie)</script>',
        ]);

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringNotContainsStringIgnoringCase('<script', (string) $data['conteudo']);
        self::assertStringNotContainsStringIgnoringCase('onclick', (string) $data['conteudo']);
        self::assertStringContainsString('Combinado', (string) $data['conteudo']);

        // E o que foi PERSISTIDO precisa estar limpo — não só o que voltou no JSON.
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $obs = $em->getRepository(PastaObservacaoDetalhes::class)->find($data['id']);
        self::assertNotNull($obs);
        self::assertStringNotContainsStringIgnoringCase('<script', $obs->getConteudo());
        self::assertStringNotContainsStringIgnoringCase('onclick', $obs->getConteudo());
    }

    #[TestDox('POST preserva a formatação legítima da barra')]
    public function testEnviarFormatacaoLegitimaEPreservada(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_' . $pasta->getId()),
            'conteudo' => '<p><strong>Urgente:</strong> ligar hoje</p><ul><li>conferir prazo</li></ul>',
        ]);

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('<strong>Urgente:</strong>', (string) $data['conteudo']);
        self::assertStringContainsString('conferir prazo', (string) $data['conteudo']);
        // `conteudoHtml` é o que o JS insere na tela: precisa vir e vir seguro.
        self::assertArrayHasKey('conteudoHtml', $data);
        self::assertStringContainsString('<strong>Urgente:</strong>', (string) $data['conteudoHtml']);
    }

    #[TestDox('editar também sanitiza — a segunda porta de entrada do mesmo campo')]
    public function testEditarConteudoMaliciosoGravaLimpo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_editar_' . $obs->getId()),
            'conteudo' => '<p>Corrigido</p><img src=x onerror="alert(1)">',
        ]);

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringNotContainsStringIgnoringCase('onerror', (string) $data['conteudo']);
        self::assertStringContainsString('Corrigido', (string) $data['conteudo']);
    }

    #[TestDox('parágrafo vazio do editor é recusado com 422')]
    public function testEnviarParagrafoVazioDoEditorRecusado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/detalhes/observacao", [
            '_token'   => $this->gerarCsrf('pasta_detalhes_obs_' . $pasta->getId()),
            'conteudo' => '<p><br></p>',
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
