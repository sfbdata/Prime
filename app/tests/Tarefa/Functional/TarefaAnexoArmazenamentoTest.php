<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * E2.7 (spec P2 §10.1): o anexo de mensagem de meta passa pelo armazenamento por chave.
 *
 * O que não pode mudar, e é provado aqui pela rota de verdade:
 *  - a coluna continua guardando `/uploads/tarefas/chat/<nome>` (a timeline e o template a leem);
 *  - o arquivo mora em `<tarefas_uploads_dir>/chat/<nome>`, o mesmo lugar físico de sempre;
 *  - visualizar e baixar continuam servindo o arquivo com o nome de sempre;
 *  - ausência é 404, e valor que a fábrica recusa também — nunca 500.
 *
 * O que muda, e também é provado: o nome passa a ser o opaco de 128 bits do storage (D8), o download
 * ganha `nosniff`, e `admin/` deixa de ser servível (DP-9, zero referências em produção).
 */
#[CoversClass(TarefaController::class)]
final class TarefaAnexoArmazenamentoTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $arquivos = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivos as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        $this->arquivos = [];

        parent::tearDown();
    }

    #[TestDox('upload: grava em <tarefas>/chat com nome opaco do storage e a coluna guarda /uploads/tarefas/chat/<nome>')]
    public function testUploadGravaPelaChaveNoMesmoLugar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        $tarefaId        = $this->criarTarefa($tenant, $user);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/tarefas/' . $tarefaId . '/mensagem', [
            '_token'   => 'TOKEN_mensagem_tarefa_' . $tarefaId,
            'mensagem' => 'Segue o comprovante',
        ], ['arquivo_mensagem' => $this->upload(self::PDF, 'comprovante.pdf', 'application/pdf')]);

        self::assertResponseRedirects();
        $valor = $this->anexoDaUltimaMensagem($tarefaId);
        self::assertMatchesRegularExpression('#^/uploads/tarefas/chat/[0-9a-f]{32}\.pdf\z#', $valor);

        $caminho = $this->diretorioDeChat() . '/' . substr($valor, \strlen('/uploads/tarefas/chat/'));
        $this->arquivos[] = $caminho;
        self::assertFileExists($caminho);
        self::assertSame(self::PDF, file_get_contents($caminho));
        self::assertSame(0666 & ~umask(), fileperms($caminho) & 0777, 'INV-10: modo de upload, não 0600');
    }

    #[TestDox('upload sem texto: a mensagem continua sendo "[Arquivo anexado]"')]
    public function testUploadSemTextoMantemMensagemPadrao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        $tarefaId        = $this->criarTarefa($tenant, $user);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/tarefas/' . $tarefaId . '/mensagem', [
            '_token' => 'TOKEN_mensagem_tarefa_' . $tarefaId,
        ], ['arquivo_mensagem' => $this->upload('linha', 'nota.txt', 'text/plain')]);

        self::assertResponseRedirects();
        $mensagem = $this->ultimaMensagem($tarefaId);
        self::assertSame('[Arquivo anexado]', $mensagem->getMensagem());
        $this->arquivos[] = $this->diretorioDeChat() . '/' . basename((string) $mensagem->getArquivoAnexo());
        self::assertFileExists(end($this->arquivos));
    }

    #[TestDox('o anexo enviado pela rota abre de volta pela rota de visualização (ida e volta pela chave)')]
    public function testAnexoEnviadoAbreDeVolta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        $tarefaId        = $this->criarTarefa($tenant, $user);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/tarefas/' . $tarefaId . '/mensagem', [
            '_token' => 'TOKEN_mensagem_tarefa_' . $tarefaId,
        ], ['arquivo_mensagem' => $this->upload(self::PDF, 'peca.pdf', 'application/pdf')]);
        $mensagem         = $this->ultimaMensagem($tarefaId);
        $this->arquivos[] = $this->diretorioDeChat() . '/' . basename((string) $mensagem->getArquivoAnexo());

        $client->request('GET', '/tarefas/mensagem/' . $mensagem->getId() . '/arquivo/visualizar');

        self::assertResponseIsSuccessful();
        self::assertSame(self::PDF, $client->getInternalResponse()->getContent());
    }

    #[TestDox('download: attachment com o nome de sempre (último segmento do valor) e nosniff')]
    public function testDownloadServeComONomeDeSempre(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        $nome            = 'arquivo_6abab5669fac90.' . random_int(10000000, 99999999) . '.pdf';
        $mensagem        = $this->criarMensagem($tenant, $user, $this->gravarAnexo($nome, self::PDF));
        $this->limparIdentityMap();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/tarefas/mensagem/' . $mensagem . '/arquivo/download');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'attachment; filename=' . $nome,
            $client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertSame(self::PDF, $client->getInternalResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function rotas(): iterable
    {
        yield 'visualizar' => ['visualizar'];
        yield 'download'   => ['download'];
    }

    #[DataProvider('rotas')]
    #[TestDox('registro aponta para arquivo que não existe → 404')]
    public function testArquivoAusenteDa404(string $rota): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        $mensagem        = $this->criarMensagem($tenant, $user, '/uploads/tarefas/chat/arquivo_inexistente_' . bin2hex(random_bytes(6)) . '.pdf');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/tarefas/mensagem/' . $mensagem . '/arquivo/' . $rota);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string, string}> */
    public static function valoresRecusados(): iterable
    {
        foreach (['visualizar', 'download'] as $rota) {
            yield "admin legado ($rota)" => [$rota, '/uploads/tarefas/admin/arquivo_legado.pdf'];
            yield "travessia ($rota)"    => [$rota, '/uploads/tarefas/chat/../../../../.env'];
            yield "outro módulo ($rota)" => [$rota, '/uploads/pastas/x.pdf'];
        }
    }

    /**
     * O arquivo existe de verdade no caminho que o valor sugere — sem isso o 404 viria da ausência,
     * e o teste provaria outra barreira.
     */
    #[DataProvider('valoresRecusados')]
    #[TestDox('valor que a fábrica recusa → 404, mesmo com o arquivo presente em disco, nunca 500')]
    public function testValorRecusadoDa404(string $rota, string $valor): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        if (str_starts_with($valor, '/uploads/tarefas/admin/')) {
            $this->gravarEm(\dirname($this->diretorioDeChat()) . '/admin', 'arquivo_legado.pdf', self::PDF);
        }
        $mensagem = $this->criarMensagem($tenant, $user, $valor);
        $this->limparIdentityMap();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/tarefas/mensagem/' . $mensagem . '/arquivo/' . $rota);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('rotas')]
    #[TestDox('anexo de outro escritório → 404; o do próprio, com o mesmo conteúdo, sai')]
    public function testOutroEscritorioRecebe404(string $rota): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarGestor();
        [$userB, $tenantB] = $this->criarGestor();
        $mensagemA         = $this->criarMensagem($tenantA, $userA, $this->gravarAnexo('a_' . bin2hex(random_bytes(6)) . '.pdf', self::PDF));
        $mensagemB         = $this->criarMensagem($tenantB, $userB, $this->gravarAnexo('b_' . bin2hex(random_bytes(6)) . '.pdf', self::PDF));
        $this->limparIdentityMap();
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('GET', '/tarefas/mensagem/' . $mensagemA . '/arquivo/' . $rota);
        self::assertResponseIsSuccessful('O anexo do próprio escritório tem de sair.');

        $client->request('GET', '/tarefas/mensagem/' . $mensagemB . '/arquivo/' . $rota);
        self::assertResponseStatusCodeSame(404, 'o anexo de outro escritório não pode sair');
    }

    // ----------------------------------------------------------------- apoio

    private function diretorioDeChat(): string
    {
        return rtrim((string) static::getContainer()->getParameter('tarefas_uploads_dir'), '/') . '/chat';
    }

    /** Grava onde o armazenamento lê e devolve o valor da coluna, como o upload grava. */
    private function gravarAnexo(string $nome, string $conteudo): string
    {
        $this->gravarEm($this->diretorioDeChat(), $nome, $conteudo);

        return '/uploads/tarefas/chat/' . $nome;
    }

    private function gravarEm(string $diretorio, string $nome, string $conteudo): void
    {
        if (!is_dir($diretorio) && !mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
            self::fail('Não foi possível criar ' . $diretorio);
        }
        file_put_contents($diretorio . '/' . $nome, $conteudo);
        $this->arquivos[] = $diretorio . '/' . $nome;
    }

    private function upload(string $conteudo, string $nomeOriginal, string $mime): UploadedFile
    {
        $origem = tempnam(sys_get_temp_dir(), 'tarefa-upload-');
        file_put_contents($origem, $conteudo);
        $this->arquivos[] = $origem;

        return new UploadedFile($origem, $nomeOriginal, $mime, null, true);
    }

    private function instalarCsrfStorage(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        });
    }

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function ultimaMensagem(int $tarefaId): TarefaMensagem
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $mensagens = $em->getRepository(TarefaMensagem::class)->findBy(['tarefa' => $tarefaId], ['id' => 'DESC'], 1);
        self::assertCount(1, $mensagens, 'a mensagem com anexo tem de ter sido criada');

        return $mensagens[0];
    }

    private function anexoDaUltimaMensagem(int $tarefaId): string
    {
        return (string) $this->ultimaMensagem($tarefaId)->getArquivoAnexo();
    }

    /** @return array{User, Tenant} */
    private function criarGestor(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant E27 ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('e27_' . uniqid() . '@test.com');
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return [$user, $tenant];
    }

    private function criarTarefa(Tenant $tenant, User $autor): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('E27-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autor);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta ' . uniqid());
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($autor);
        $em->persist($tarefa);
        $em->flush();

        return (int) $tarefa->getId();
    }

    /** @return int o id da mensagem */
    private function criarMensagem(Tenant $tenant, User $autor, string $valorDaColuna): int
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tarefa = $em->find(Tarefa::class, $this->criarTarefa($tenant, $autor));

        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('Segue o anexo');
        $mensagem->setTenant($tenant);
        $mensagem->setArquivoAnexo($valorDaColuna);
        $em->persist($mensagem);
        $em->flush();

        return (int) $mensagem->getId();
    }
}
