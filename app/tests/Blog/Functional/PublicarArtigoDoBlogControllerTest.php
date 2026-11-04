<?php

declare(strict_types=1);

namespace App\Tests\Blog\Functional;

use App\Blog\Controller\ArtigosDoBlogMasterController;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\SituacaoDaPublicacao;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Message\MontarPacoteDosBlogs;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Publicar, Despublicar e o histórico das gerações pela HTTP (spec painel-artigos-blogs, seções 2.1, 2.2, 5 e 6.1):
 * só o Master, CSRF no header X-CSRF-Token, 422 com os erros por campo. A mensagem ao worker fica no transport em
 * memória dos testes (nada monta o pacote aqui).
 */
#[CoversClass(ArtigosDoBlogMasterController::class)]
final class PublicarArtigoDoBlogControllerTest extends JusPrimeWebTestCase
{
    private const CSRF = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_blog'];

    #[TestDox('Usuário que não é Master: 403 em publicar, despublicar e no histórico, mesmo com o token certo')]
    public function testUsuarioComum(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->trocarTokens();
        $tenant = (new Tenant())->setName('Escritório Blog ' . uniqid());
        $this->em()->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $this->em()->persist(new UserTenant($usuario, $tenant));
        $this->em()->flush();
        $this->logarComTenant($client, $usuario, $tenant);
        $artigo = $this->salvo(ArtigosDeTeste::rascunhoCompleto($this->slug()));

        foreach (['publicar', 'despublicar'] as $acao) {
            $client->request('POST', '/master/blog/artigos/' . $artigo->getId() . '/' . $acao, [], [], self::CSRF, '{}');
            self::assertResponseStatusCodeSame(403, $acao);
        }
        $client->request('GET', '/master/blog/publicacoes');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($artigo)->getSituacao());
    }

    #[TestDox('Sem o X-CSRF-Token: 403 e o artigo não vai ao ar')]
    public function testSemToken(): void
    {
        $client = $this->cliente(['ROLE_SUPER_ADMIN']);
        $artigo = $this->salvo(ArtigosDeTeste::rascunhoCompleto($this->slug()));

        $client->request('POST', '/master/blog/artigos/' . $artigo->getId() . '/publicar', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($artigo)->getSituacao());
        self::assertSame([], $this->fila()->getSent());
    }

    #[TestDox('Publicar artigo incompleto: 422 com os erros por campo, nada pedido ao builder')]
    public function testPublicarIncompleto(): void
    {
        $client = $this->cliente(['ROLE_SUPER_ADMIN']);
        $artigo = ArtigosDeTeste::rascunhoCompleto($this->slug());
        $artigo->editarTextos('Título', 'Curto', 'SEO', '', 'Linha.', 'Resumo.');
        $this->salvo($artigo);

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/publicar');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erros' => ['descricao' => ['Informe a meta description.']]], $this->json($client));
        self::assertSame([], $this->fila()->getSent());
    }

    #[TestDox('Publicar: 200, o artigo vai ao ar, um pedido na fila e a mensagem ao worker; o histórico o mostra')]
    public function testPublicarEHistorico(): void
    {
        $client = $this->cliente(['ROLE_SUPER_ADMIN']);
        $artigo = $this->salvo(ArtigosDeTeste::rascunhoCompleto($this->slug()));

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/publicar');

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['ok']);
        self::assertSame('publicado', $dados['situacao']);
        self::assertSame('Publicado', $dados['situacaoRotulo']);
        self::assertFalse($dados['alteracoesPendentes']);
        self::assertSame('na_fila', $dados['publicacao']['situacao']);
        self::assertSame('publicar', $dados['publicacao']['motivo']);
        self::assertSame('Pessoa Blog', $dados['publicacao']['pedidaPor']);
        self::assertSame(SituacaoDoArtigo::Publicado, $this->reler($artigo)->getSituacao());
        $publicacao = $this->publicacoes()->porId($dados['publicacao']['id']);
        self::assertNotNull($publicacao);
        self::assertSame(SituacaoDaPublicacao::NaFila, $publicacao->getSituacao());
        self::assertCount(1, $this->fila()->getSent());
        self::assertEquals(new MontarPacoteDosBlogs($dados['publicacao']['id']), $this->fila()->getSent()[0]->getMessage());

        // Publicar de novo sem alterações: 409, e o pedido na fila não se repete.
        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/publicar');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['erro' => 'O artigo já está publicado e não tem alterações a publicar.'], $this->json($client));

        $client->request('GET', '/master/blog/publicacoes');
        self::assertResponseIsSuccessful();
        $itens = $this->json($client)['itens'];
        self::assertSame($dados['publicacao']['id'], $itens[0]['id']);
        self::assertSame('Na fila', $itens[0]['situacaoRotulo']);
        self::assertSame(['id', 'pedidaEm', 'pedidaPor', 'motivo', 'motivoRotulo', 'situacao', 'situacaoRotulo', 'iniciadaEm', 'terminadaEm', 'commit', 'log', 'artigos'], array_keys($itens[0]));
    }

    #[TestDox('Despublicar: 200 e um pedido na fila (reaproveitado se já há um sem pacote); despublicar de novo: 409; aí excluir pode')]
    public function testDespublicar(): void
    {
        $client = $this->cliente(['ROLE_SUPER_ADMIN']);
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->trocarSlug($this->slug());
        $this->salvo($artigo);

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/excluir');
        self::assertResponseStatusCodeSame(409, 'publicado pede despublicar antes de excluir');

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/despublicar');
        self::assertResponseIsSuccessful();
        self::assertSame('despublicado', $this->json($client)['situacao']);
        self::assertSame(SituacaoDoArtigo::Despublicado, $this->reler($artigo)->getSituacao());

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/despublicar');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['erro' => 'Só um artigo publicado pode ser despublicado.'], $this->json($client));

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/excluir');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Id inexistente: 404 em publicar e despublicar')]
    public function testInexistente(): void
    {
        $client = $this->cliente(['ROLE_SUPER_ADMIN']);

        foreach (['publicar', 'despublicar'] as $acao) {
            $this->postar($client, '/master/blog/artigos/999999999/' . $acao);
            self::assertResponseStatusCodeSame(404, $acao);
        }
    }

    private function slug(): string
    {
        return 'pub-' . bin2hex(random_bytes(4));
    }

    private function salvo(ArtigoDoBlog $artigo): ArtigoDoBlog
    {
        static::getContainer()->get(ArtigoDoBlogRepository::class)->salvar($artigo, flush: true);

        return $artigo;
    }

    private function reler(ArtigoDoBlog $artigo): ArtigoDoBlog
    {
        $this->em()->clear();

        return static::getContainer()->get(ArtigoDoBlogRepository::class)->porId((int) $artigo->getId()) ?? throw new \LogicException('sumiu');
    }

    private function publicacoes(): PublicacaoDosBlogsRepository
    {
        return static::getContainer()->get(PublicacaoDosBlogsRepository::class);
    }

    private function fila(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function postar(KernelBrowser $client, string $url): void
    {
        $client->request('POST', $url, [], [], self::CSRF, '{}');
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @param list<string> $papeis */
    private function cliente(array $papeis): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->trocarTokens();
        $client->loginUser($this->usuario($papeis));
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $user = (new User())->setEmail('blog_pub_' . uniqid() . '@test.com')->setFullName('Pessoa Blog')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** O token CSRF de cada id vira "TOKEN_<id>" (mesmo recurso do ArtigosDoBlogMasterControllerTest). */
    private function trocarTokens(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
