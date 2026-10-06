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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class PastaMensagemControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuario(Tenant $tenant, string $prefixo = 'msg'): User
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
        $tenant->setName('Tenant Mensagem ' . uniqid());
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

    private function criarMensagem(
        Pasta $pasta,
        User $autor,
        Tenant $tenant,
        ?\DateTimeImmutable $criadaEm = null,
    ): PastaMensagem {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $mensagem = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('Mensagem original');

        if ($criadaEm !== null) {
            $ref = new \ReflectionProperty(PastaMensagem::class, 'criadaEm');
            $ref->setValue($mensagem, $criadaEm);
        }

        $em->persist($mensagem);
        $em->flush();

        return $mensagem;
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

    #[TestDox('POST mensagem/{msgId}/editar sem autenticação redireciona para login')]
    public function testEditarSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/mensagem/1/editar');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST mensagem/{msgId}/excluir sem autenticação redireciona para login')]
    public function testExcluirSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/mensagem/1/excluir');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    // ── Editar ───────────────────────────────────────────────────────────────

    #[TestDox('Autor edita a própria mensagem dentro de 24h e recebe 200')]
    public function testAutorEditaMensagem(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pasta    = $this->criarPasta($tenant);
        $mensagem = $this->criarMensagem($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem/{$mensagem->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_mensagem_editar_' . $mensagem->getId()),
            'conteudo' => 'Mensagem corrigida',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Mensagem corrigida', $data['conteudo']);
        self::assertNotNull($data['editadaEm']);
    }

    #[TestDox('Não-autor recebe 403 ao tentar editar mensagem de outro')]
    public function testNaoAutorNaoEditaMensagem(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $outro    = $this->criarUsuario($tenant, 'outro');
        $pasta    = $this->criarPasta($tenant);
        $mensagem = $this->criarMensagem($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem/{$mensagem->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_mensagem_editar_' . $mensagem->getId()),
            'conteudo' => 'Tentando editar',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Autor recebe 403 ao editar mensagem fora da janela de 24h')]
    public function testEditarForaDaJanelaRetorna403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pasta    = $this->criarPasta($tenant);
        $mensagem = $this->criarMensagem(
            $pasta,
            $autor,
            $tenant,
            new \DateTimeImmutable('-20 minutes'),
        );

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem/{$mensagem->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_mensagem_editar_' . $mensagem->getId()),
            'conteudo' => 'Tarde demais',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Editar com CSRF inválido retorna 403')]
    public function testEditarCsrfInvalidoRetorna403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pasta    = $this->criarPasta($tenant);
        $mensagem = $this->criarMensagem($pasta, $autor, $tenant);

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem/{$mensagem->getId()}/editar", [
            '_token'   => 'token_invalido',
            'conteudo' => 'Qualquer coisa',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Editar mensagem de outra pasta retorna 404')]
    public function testEditarMensagemDeOutraPastaRetorna404(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pastaA   = $this->criarPasta($tenant);
        $pastaB   = $this->criarPasta($tenant);
        $mensagem = $this->criarMensagem($pastaA, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pastaB->getId()}/mensagem/{$mensagem->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_mensagem_editar_' . $mensagem->getId()),
            'conteudo' => 'Pasta errada',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Usar msgId de outro tenant na própria pasta retorna 404 (IDOR bloqueado)')]
    public function testEditarMensagemCrossTenantViaMsgIdRetorna404(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->criarUsuario($tenantA, 'usera');
        $userB   = $this->criarUsuario($tenantB, 'userb');
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $msgB    = $this->criarMensagem($pastaB, $userB, $tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        // IDOR: usa a própria pasta (acessível) mas o id de uma mensagem do tenant B.
        $client->request('POST', "/pasta/{$pastaA->getId()}/mensagem/{$msgB->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_mensagem_editar_' . $msgB->getId()),
            'conteudo' => 'Invasão',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Editar mensagem na pasta de outro tenant é barrado pela checagem de tenant (403)')]
    public function testEditarMensagemNaPastaDeOutroTenantRetorna403(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->criarUsuario($tenantA, 'usera');
        $userB   = $this->criarUsuario($tenantB, 'userb');
        $pastaB  = $this->criarPasta($tenantB);
        $msgB    = $this->criarMensagem($pastaB, $userB, $tenantB);

        $this->instalarCsrfStorage();
        // userA (super-admin) bypassa o ACESSO À pasta, mas o tenant da sessão é A:
        // a checagem de tenant no UseCase barra a edição da mensagem do tenant B.
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('POST', "/pasta/{$pastaB->getId()}/mensagem/{$msgB->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_mensagem_editar_' . $msgB->getId()),
            'conteudo' => 'Invasão',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Excluir ──────────────────────────────────────────────────────────────

    #[TestDox('Autor exclui a própria mensagem dentro de 24h e recebe sucesso')]
    public function testAutorExcluiMensagem(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pasta    = $this->criarPasta($tenant);
        $mensagem = $this->criarMensagem($pasta, $autor, $tenant);
        $msgId    = $mensagem->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem/{$msgId}/excluir", [
            '_token' => $this->gerarCsrf('pasta_mensagem_excluir_' . $msgId),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(PastaMensagem::class, $msgId));
    }

    #[TestDox('Não-autor recebe 403 ao tentar excluir mensagem de outro')]
    public function testNaoAutorNaoExcluiMensagem(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $outro    = $this->criarUsuario($tenant, 'outro');
        $pasta    = $this->criarPasta($tenant);
        $mensagem = $this->criarMensagem($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem/{$mensagem->getId()}/excluir", [
            '_token' => $this->gerarCsrf('pasta_mensagem_excluir_' . $mensagem->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Responder ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function responder(object $client, Pasta $pasta, int|string $respostaA, string $conteudo = 'Respondendo'): array
    {
        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem", [
            '_token'     => $this->gerarCsrf('pasta_mensagem_' . $pasta->getId()),
            'conteudo'   => $conteudo,
            'resposta_a' => (string) $respostaA,
        ]);

        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function contarMensagens(Pasta $pasta): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(m.id) FROM ' . PastaMensagem::class . ' m WHERE m.pasta = :p')
            ->setParameter('p', $pasta)
            ->getSingleScalarResult();
    }

    #[TestDox('POST com resposta_a de um registro da mesma pasta cria a resposta (201) ligada à raiz')]
    public function testResponderCriaRespostaLigadaARaiz(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $outro    = $this->criarUsuario($tenant, 'outro');
        $pasta    = $this->criarPasta($tenant);
        $original = $this->criarMensagem($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        // Qualquer um da equipe responde — não só o autor da original.
        $this->logarComTenant($client, $outro, $tenant);

        $data = $this->responder($client, $pasta, (int) $original->getId());

        self::assertResponseStatusCodeSame(201);
        self::assertSame($original->getId(), $data['respostaA']);
        self::assertSame($autor->getFullName(), $data['respostaANome']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $gravada = $em->find(PastaMensagem::class, $data['id']);
        self::assertNotNull($gravada);
        self::assertSame($original->getId(), $gravada->getRespostaA()?->getId());
        self::assertTrue($gravada->isResposta());
    }

    #[TestDox('POST sem resposta_a continua criando registro comum (respostaA null na resposta)')]
    public function testEnviarSemRespostaContinuaComum(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem", [
            '_token'   => $this->gerarCsrf('pasta_mensagem_' . $pasta->getId()),
            'conteudo' => 'Registro comum',
            // O compositor sempre manda o campo escondido — vazio quando não está respondendo.
            'resposta_a' => '',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNull($data['respostaA']);
        self::assertNull($data['respostaANome']);
    }

    #[TestDox('Responder a uma resposta grava a resposta ligada à RAIZ (um nível só)')]
    public function testResponderARespostaLigaARaiz(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pasta    = $this->criarPasta($tenant);
        $raiz     = $this->criarMensagem($pasta, $autor, $tenant);
        $resposta = $this->criarMensagem($pasta, $autor, $tenant);
        $resposta->setRespostaA($raiz);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $data = $this->responder($client, $pasta, (int) $resposta->getId(), 'Tréplica');

        self::assertResponseStatusCodeSame(201);
        self::assertSame($raiz->getId(), $data['respostaA']);
    }

    #[TestDox('resposta_a de um registro de OUTRA pasta do mesmo escritório é recusado (422) e nada é gravado')]
    public function testResponderMensagemDeOutraPastaRecusa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pastaA = $this->criarPasta($tenant);
        $pastaB = $this->criarPasta($tenant);
        $deB    = $this->criarMensagem($pastaB, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $this->responder($client, $pastaA, (int) $deB->getId());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->contarMensagens($pastaA));
    }

    #[TestDox('resposta_a de um registro de OUTRO escritório é recusado (422) e nada é gravado (IDOR)')]
    public function testResponderMensagemDeOutroTenantRecusa(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->criarUsuario($tenantA, 'usera');
        $userB   = $this->criarUsuario($tenantB, 'userb');
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $msgB    = $this->criarMensagem($pastaB, $userB, $tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        $this->responder($client, $pastaA, (int) $msgB->getId());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->contarMensagens($pastaA));
    }

    #[TestDox('resposta_a inexistente é recusado (422)')]
    public function testResponderMensagemInexistenteRecusa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $this->responder($client, $pasta, 999999999);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->contarMensagens($pasta));
    }

    #[TestDox('Excluir a original não apaga a resposta: o vínculo cai (SET NULL) e ela segue marcada como resposta')]
    public function testExcluirOriginalMantemRespostaOrfa(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pasta    = $this->criarPasta($tenant);
        $original = $this->criarMensagem($pasta, $autor, $tenant);
        $resposta = $this->criarMensagem($pasta, $autor, $tenant);
        $resposta->setRespostaA($original);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $originalId = $original->getId();
        $respostaId = $resposta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/mensagem/{$originalId}/excluir", [
            '_token' => $this->gerarCsrf('pasta_mensagem_excluir_' . $originalId),
        ]);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(PastaMensagem::class, $originalId));
        $orfa = $em->find(PastaMensagem::class, $respostaId);
        self::assertNotNull($orfa, 'a resposta continua');
        self::assertNull($orfa->getRespostaA());
        self::assertTrue($orfa->isRespostaOrfa());
    }
}
