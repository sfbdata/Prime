<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class PastaFinanceiroControllerTest extends WebTestCase
{
    private function criarUsuarioAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Teste Upload ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_upload_' . uniqid() . '@test.com');
        $user->setFullName('Admin Teste');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setTenant($tenant);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function criarPasta(): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-' . uniqid());
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /**
     * Instala um TokenStorage em memória que retorna tokens previsíveis
     * sem depender de sessão HTTP.
     */
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

    private function storageFake(): ArquivoStorageInterface
    {
        return new class implements ArquivoStorageInterface {
            public function salvar(UploadedFile $arquivo, string $diretorio): string
            {
                return 'fake_' . uniqid() . '.pdf';
            }

            public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse
            {
                throw new \LogicException('não utilizado neste teste');
            }

            public function excluir(string $caminhoCompleto): void {}

            public function existe(string $caminhoCompleto): bool
            {
                return false;
            }

            public function caminho(string $diretorio, string $nomeArquivo): string
            {
                return $diretorio . '/' . $nomeArquivo;
            }
        };
    }

    // ── Testes sem autenticação ──────────────────────────────────────────────

    #[TestDox('POST situacao-contrato sem autenticação redireciona para login')]
    public function testSituacaoContratoSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/situacao-contrato');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST pro-bono sem autenticação redireciona para login')]
    public function testProBonoSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/pro-bono');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST financeiro/upload sem autenticação redireciona para login')]
    public function testUploadSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/financeiro/upload');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST financeiro/documento/{docId}/renomear sem autenticação redireciona para login')]
    public function testRenomearSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/financeiro/documento/1/renomear');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST financeiro/documento/{docId}/excluir sem autenticação redireciona para login')]
    public function testExcluirDocSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/financeiro/documento/1/excluir');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST financeiro/observacao sem autenticação redireciona para login')]
    public function testEnviarObsSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/financeiro/observacao');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST financeiro/observacao/{obsId}/editar sem autenticação redireciona para login')]
    public function testEditarObsSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/financeiro/observacao/1/editar');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST financeiro/observacao/{obsId}/excluir sem autenticação redireciona para login')]
    public function testExcluirObsSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/financeiro/observacao/1/excluir');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET em rota POST retorna 405 Method Not Allowed')]
    public function testGetEmRotaPostRetorna405(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pasta/1/situacao-contrato');

        self::assertResponseStatusCodeSame(405);
    }

    // ── Upload de documento de contrato (pasta_financeiro_upload) ────────────

    #[TestDox('POST financeiro/upload com CSRF inválido retorna 403')]
    public function testUploadRetorna403ComCsrfInvalido(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/upload", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('POST financeiro/upload sem arquivo retorna 422')]
    public function testUploadRetorna422SemArquivo(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta();

        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/upload", [
            '_token' => $this->gerarCsrf('pasta_financeiro_upload_' . $pasta->getId()),
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Nenhum arquivo enviado.', $data['erro']);
    }

    #[TestDox('POST financeiro/upload com MIME type inválido retorna 422')]
    public function testUploadRetorna422ComMimeInvalido(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta();

        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $tmpPath = sys_get_temp_dir() . '/test_invalid_' . uniqid() . '.txt';
        file_put_contents($tmpPath, 'conteudo texto simples');
        $uploadedFile = new UploadedFile($tmpPath, 'arquivo.txt', 'text/plain', null, true);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/financeiro/upload",
            ['_token' => $this->gerarCsrf('pasta_financeiro_upload_' . $pasta->getId())],
            ['arquivo' => $uploadedFile],
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('não permitido', $data['erro']);

        @unlink($tmpPath);
    }

    #[TestDox('POST financeiro/upload com arquivo PDF válido retorna 201 com dados do documento')]
    public function testUploadComPdfValidoRetorna201(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuarioAdmin();
        $pasta = $this->criarPasta();

        $this->instalarCsrfStorage();
        static::getContainer()->set(ArquivoStorageInterface::class, $this->storageFake());
        $client->loginUser($user);

        $tmpPath = sys_get_temp_dir() . '/test_upload_' . uniqid() . '.pdf';
        file_put_contents($tmpPath, '%PDF-1.4 fake pdf content');
        $uploadedFile = new UploadedFile($tmpPath, 'contrato.pdf', 'application/pdf', null, true);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/financeiro/upload",
            ['_token' => $this->gerarCsrf('pasta_financeiro_upload_' . $pasta->getId())],
            ['arquivo' => $uploadedFile],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertSame('contrato.pdf', $data['titulo']);
        self::assertSame('application/pdf', $data['mimeType']);
        self::assertArrayHasKey('csrfRenomear', $data);
        self::assertArrayHasKey('csrfExcluir', $data);

        @unlink($tmpPath);
    }
}
