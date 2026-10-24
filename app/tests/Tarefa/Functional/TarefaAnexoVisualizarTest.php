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
use App\Shared\Http\EntregaDeArquivo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-012: a visualização inline do anexo de mensagem de meta (`tarefa_mensagem_arquivo_view`).
 *
 * O upload do chat não tem lista de tipos — um `.html` entra. Antes, a rota o devolvia como
 * `text/html` na origem do BlueJus, sem CSP nem nosniff: XSS armazenado para quem abrisse o link.
 * Desde a E2.7 a rota entrega pela própria `EntregaDeArquivo` (SEC-003). Os testes passam pelo HTTP
 * de verdade, com arquivo de verdade em `<tarefas_uploads_dir>/chat` — em teste,
 * `var/uploads-test/tarefas/chat` —, com nome único por teste, apagado em `tearDown`.
 */
#[CoversClass(TarefaController::class)]
final class TarefaAnexoVisualizarTest extends JusPrimeWebTestCase
{
    private const PDF  = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private const HTML = "<!DOCTYPE html>\n<html><head><title>x</title></head><body><script>alert(document.cookie)</script></body></html>\n";

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

    #[TestDox('anexo .html abre inline com CSP sandbox e nosniff — o script não roda na origem')]
    public function testHtmlSaiEmSandbox(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        $mensagem        = $this->criarMensagemComAnexo($tenant, $user, self::HTML, 'html');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/tarefas/mensagem/' . $mensagem . '/arquivo/visualizar');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('inline;', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertResponseHeaderSame('Content-Security-Policy', EntregaDeArquivo::CSP_SANDBOX);
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertSame(self::HTML, $client->getInternalResponse()->getContent());
    }

    #[TestDox('anexo PDF abre inline SEM sandbox (o leitor do navegador recusaria) e com nosniff')]
    public function testPdfSemSandbox(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestor();
        $mensagem        = $this->criarMensagemComAnexo($tenant, $user, self::PDF, 'pdf');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/tarefas/mensagem/' . $mensagem . '/arquivo/visualizar');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('inline;', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertSame(self::PDF, $client->getInternalResponse()->getContent());
    }

    /**
     * O IRMÃO do mesmo tenant, com o mesmo arquivo, é servido — sem isso o 404 poderia vir de
     * arquivo ausente ou rota errada, e o teste provaria outra barreira.
     */
    #[TestDox('anexo de outro escritório → 404; o do próprio escritório, com o mesmo arquivo, abre')]
    public function testOutroEscritorioRecebe404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarGestor();
        [$userB, $tenantB] = $this->criarGestor();
        $mensagemA         = $this->criarMensagemComAnexo($tenantA, $userA, self::PDF, 'pdf');
        $mensagemB         = $this->criarMensagemComAnexo($tenantB, $userB, self::PDF, 'pdf');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('GET', '/tarefas/mensagem/' . $mensagemA . '/arquivo/visualizar');
        self::assertResponseIsSuccessful('O anexo do próprio escritório tem de abrir.');

        $client->request('GET', '/tarefas/mensagem/' . $mensagemB . '/arquivo/visualizar');
        self::assertResponseStatusCodeSame(404, 'o anexo de outro escritório não pode sair');
    }

    // ----------------------------------------------------------------- apoio

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** @return array{User, Tenant} */
    private function criarGestor(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant ANEXO ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('anexo_' . uniqid() . '@test.com');
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

    /** @return int o id da mensagem */
    private function criarMensagemComAnexo(Tenant $tenant, User $autor, string $conteudo, string $extensao): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('ANX-' . uniqid());
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

        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('Segue o anexo');
        $mensagem->setTenant($tenant);
        $mensagem->setArquivoAnexo($this->gravarAnexo($conteudo, $extensao));
        $em->persist($mensagem);
        $em->flush();

        return (int) $mensagem->getId();
    }

    /** Grava onde o armazenamento lê e devolve o valor da coluna, como o upload grava. */
    private function gravarAnexo(string $conteudo, string $extensao): string
    {
        $diretorio = rtrim((string) static::getContainer()->getParameter('tarefas_uploads_dir'), '/') . '/chat';
        if (!is_dir($diretorio) && !mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
            self::fail('Não foi possível criar ' . $diretorio);
        }

        $nome = 'arquivo_teste_sec012_' . bin2hex(random_bytes(6)) . '.' . $extensao;
        file_put_contents($diretorio . '/' . $nome, $conteudo);
        $this->arquivos[] = $diretorio . '/' . $nome;

        return '/uploads/tarefas/chat/' . $nome;
    }
}
