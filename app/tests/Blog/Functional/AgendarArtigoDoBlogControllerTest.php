<?php

declare(strict_types=1);

namespace App\Tests\Blog\Functional;

use App\Blog\Controller\ArtigosDoBlogMasterController;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Agendar e Cancelar agendamento pela HTTP (spec painel-artigos-blogs, P5): só o Master, CSRF no header
 * X-CSRF-Token, data de Brasília e só no futuro, 422 com os erros por campo. A lista mostra o agendado e o filtro
 * "agendado" o encontra.
 */
#[CoversClass(ArtigosDoBlogMasterController::class)]
final class AgendarArtigoDoBlogControllerTest extends JusPrimeWebTestCase
{
    private const CSRF = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_blog'];

    #[TestDox('Usuário que não é Master: 403 em agendar e em cancelar, mesmo com o token certo')]
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

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/agendar', ['publicarEm' => $this->futuro()]);
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/cancelar-agendamento');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($artigo)->getSituacao());
    }

    #[TestDox('Sem o X-CSRF-Token: 403 e o artigo não é agendado')]
    public function testSemToken(): void
    {
        $client = $this->cliente();
        $artigo = $this->salvo(ArtigosDeTeste::rascunhoCompleto($this->slug()));

        $client->request('POST', '/master/blog/artigos/' . $artigo->getId() . '/agendar', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['publicarEm' => $this->futuro()]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($artigo)->getSituacao());
    }

    #[TestDox('Agendar: 200, fica agendado com a data de Brasília; a lista mostra "Agendado" e o filtro o encontra; cancelar volta a rascunho')]
    public function testAgendarListarECancelar(): void
    {
        $client = $this->cliente();
        $artigo = $this->salvo(ArtigosDeTeste::rascunhoCompleto($this->slug()));
        $quando = (new \DateTimeImmutable('+2 days', new \DateTimeZone('America/Sao_Paulo')))->setTime(9, 30);

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/agendar', ['publicarEm' => $quando->format('Y-m-d\TH:i')]);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['ok']);
        self::assertSame('agendado', $dados['situacao']);
        self::assertSame('Agendado', $dados['situacaoRotulo']);
        self::assertSame($quando->format('d/m/Y') . ' 09:30', $dados['publicarEmRotulo']);
        $relido = $this->reler($artigo);
        self::assertSame(SituacaoDoArtigo::Agendado, $relido->getSituacao());
        self::assertSame($quando->format('Y-m-d') . ' 09:30', $relido->getPublicarEm()?->format('Y-m-d H:i'));

        $client->request('GET', '/master/blog/artigos?situacao=agendado&busca=' . $artigo->getSlug());
        self::assertResponseIsSuccessful();
        $itens = $this->json($client)['itens'];
        self::assertCount(1, $itens);
        self::assertSame('agendado', $itens[0]['situacao']);
        self::assertSame($quando->format('Y-m-d') . 'T09:30', $itens[0]['data']);

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/cancelar-agendamento');
        self::assertResponseIsSuccessful();
        self::assertSame('rascunho', $this->json($client)['situacao']);
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($artigo)->getSituacao());
        self::assertNull($this->reler($artigo)->getPublicarEm());
        $client->request('GET', '/master/blog/artigos?busca=' . $artigo->getSlug());
        self::assertNull($this->json($client)['itens'][0]['data'], 'rascunho não mostra data');

        $this->postar($client, '/master/blog/artigos/' . $artigo->getId() . '/cancelar-agendamento');
        self::assertResponseStatusCodeSame(409, 'rascunho não tem agendamento a cancelar');
    }

    #[TestDox('Data no passado: 422 no campo publicarEm; data ausente, torta ou inexistente (31/02): 422; artigo incompleto: 422 com os erros do zod')]
    public function testRecusas(): void
    {
        $client = $this->cliente();
        $artigo = $this->salvo(ArtigosDeTeste::rascunhoCompleto($this->slug()));
        $url = '/master/blog/artigos/' . $artigo->getId() . '/agendar';

        $this->postar($client, $url, ['publicarEm' => '2020-01-01T09:00']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erros' => ['publicarEm' => ['Escolha uma data e uma hora no futuro.']]], $this->json($client));

        foreach ([[], ['publicarEm' => 'amanhã'], ['publicarEm' => '2099-02-31T09:00']] as $corpo) {
            $this->postar($client, $url, $corpo);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('publicarEm', $this->json($client)['erros']);
        }

        $incompleto = ArtigosDeTeste::rascunhoCompleto($this->slug());
        $incompleto->editarTextos('Título', 'Curto', 'SEO', '', 'Linha.', 'Resumo.');
        $this->salvo($incompleto);
        $this->postar($client, '/master/blog/artigos/' . $incompleto->getId() . '/agendar', ['publicarEm' => $this->futuro()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erros' => ['descricao' => ['Informe a meta description.']]], $this->json($client));
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($artigo)->getSituacao());
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->reler($incompleto)->getSituacao());
    }

    #[TestDox('Artigo publicado não agenda (409) e id inexistente dá 404')]
    public function testPublicadoEInexistente(): void
    {
        $client = $this->cliente();
        $publicado = ArtigosDeTeste::controleDePrazos();
        $publicado->trocarSlug($this->slug());
        $this->salvo($publicado);

        $this->postar($client, '/master/blog/artigos/' . $publicado->getId() . '/agendar', ['publicarEm' => $this->futuro()]);
        self::assertResponseStatusCodeSame(409);

        $this->postar($client, '/master/blog/artigos/999999999/agendar', ['publicarEm' => $this->futuro()]);
        self::assertResponseStatusCodeSame(404);
        $this->postar($client, '/master/blog/artigos/999999999/cancelar-agendamento');
        self::assertResponseStatusCodeSame(404);
    }

    private function futuro(): string
    {
        return (new \DateTimeImmutable('+3 days', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i');
    }

    private function slug(): string
    {
        return 'agd-' . bin2hex(random_bytes(4));
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

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, string $url, array $corpo = []): void
    {
        $client->request('POST', $url, [], [], self::CSRF, (string) json_encode($corpo === [] ? new \stdClass() : $corpo));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->trocarTokens();
        $client->loginUser($this->usuario(['ROLE_SUPER_ADMIN']));
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $user = (new User())->setEmail('blog_agd_' . uniqid() . '@test.com')->setFullName('Pessoa Blog')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** O token CSRF de cada id vira "TOKEN_<id>" (mesmo recurso do PublicarArtigoDoBlogControllerTest). */
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
