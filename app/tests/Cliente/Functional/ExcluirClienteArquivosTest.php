<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteController;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Exclusão de cliente e de documento de cliente, contra o banco e o disco reais (E2.5).
 *
 * **O defeito da FK.** Antes da E2.5, `ClienteController::delete` apagava os arquivos e só então
 * chamava o `flush`. Quando uma FK recusava — `cobranca_carteira → cliente` é NO ACTION, e o
 * `saas_ux` tinha dois clientes com carteira e documentos —, o cliente ficava, sem os arquivos. Agora
 * os arquivos só saem depois de o banco aceitar a exclusão.
 *
 * Nada aqui mexe no texto do aviso (que ainda fala em "pré-cadastro"): a correção se limita à
 * atomicidade banco × arquivo.
 */
#[CoversClass(ClienteController::class)]
final class ExcluirClienteArquivosTest extends JusPrimeWebTestCase
{
    use Factories;

    /** @var list<string> */
    private array $criados = [];

    private ?int $modoOriginal = null;

    protected function tearDown(): void
    {
        if ($this->modoOriginal !== null) {
            @chmod($this->diretorio(), $this->modoOriginal);
        }

        foreach ($this->criados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        parent::tearDown();
    }

    #[TestDox('FK recusa a exclusão do cliente: o cliente fica e os documentos continuam no disco')]
    public function testFkRecusaSemApagarArquivos(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente  = $this->criarCliente($tenant);
        $arquivos = [$this->criarDocumento($cliente), $this->criarDocumento($cliente)];
        CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente]);
        $id = (int) $cliente->getId();
        $this->em()->clear();

        $client->request('POST', "/clientes/{$id}/deletar", ['_token' => 'TOKEN_delete' . $id]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM cliente WHERE id = ?', $id), 'o banco recusou: o cliente fica');
        self::assertSame(2, $this->contar('SELECT COUNT(*) FROM cliente_documento WHERE cliente_id = ?', $id));
        foreach ($arquivos as $caminho) {
            self::assertFileExists($caminho, 'o arquivo não pode sair se o banco não aceitou a exclusão');
        }

        $erros = $client->getRequest()->getSession()->getFlashBag()->peek('error');
        self::assertStringContainsString('Não é possível excluir este cliente', implode(' ', $erros));
    }

    #[TestDox('sem FK no caminho: o cliente sai e os arquivos saem depois')]
    public function testExclusaoApagaOsArquivos(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente  = $this->criarCliente($tenant);
        $arquivos = [$this->criarDocumento($cliente), $this->criarDocumento($cliente)];
        $id       = (int) $cliente->getId();
        $this->em()->clear();

        $client->request('POST', "/clientes/{$id}/deletar", ['_token' => 'TOKEN_delete' . $id]);

        self::assertResponseRedirects();
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM cliente WHERE id = ?', $id));
        foreach ($arquivos as $caminho) {
            self::assertFileDoesNotExist($caminho);
        }
    }

    #[TestDox('documento excluído: a linha sai e, depois dela, o arquivo')]
    public function testDocumentoExcluidoApagaOArquivo(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarCliente($tenant);
        $arquivo = $this->criarDocumento($cliente);
        $docId   = (int) $this->contar('SELECT MAX(id) FROM cliente_documento WHERE cliente_id = ?', (int) $cliente->getId());
        $this->em()->clear();

        $client->request('POST', "/clientes/documento/{$docId}/deletar", ['_token' => 'TOKEN_delete_doc_cliente_' . $docId]);

        self::assertResponseRedirects();
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM cliente_documento WHERE id = ?', $docId));
        self::assertFileDoesNotExist($arquivo);
    }

    #[TestDox('cliente excluído com o disco recusando: o cliente sai, os arquivos ficam e não há 500')]
    public function testClienteExcluidoComDiscoQueFalha(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório');
        }

        [$client, $tenant] = $this->gestorLogado();
        $cliente  = $this->criarCliente($tenant);
        $arquivos = [$this->criarDocumento($cliente), $this->criarDocumento($cliente)];
        $id       = (int) $cliente->getId();
        $this->em()->clear();

        $this->modoOriginal = fileperms($this->diretorio()) & 0o7777;
        chmod($this->diretorio(), 0o555);
        try {
            $client->request('POST', "/clientes/{$id}/deletar", ['_token' => 'TOKEN_delete' . $id]);
        } finally {
            chmod($this->diretorio(), $this->modoOriginal);
        }

        self::assertResponseRedirects();
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM cliente WHERE id = ?', $id), 'o banco é autoritativo');
        foreach ($arquivos as $caminho) {
            self::assertFileExists($caminho, 'órfão recuperável');
        }
    }

    /** COMMIT confirmado seguido de falha na exclusão física: a exclusão vale, o arquivo fica. */
    #[TestDox('documento excluído com o disco recusando a remoção: a linha sai, o arquivo fica e não há 500')]
    public function testDocumentoExcluidoComDiscoQueFalha(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório');
        }

        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarCliente($tenant);
        $arquivo = $this->criarDocumento($cliente);
        $docId   = (int) $this->contar('SELECT MAX(id) FROM cliente_documento WHERE cliente_id = ?', (int) $cliente->getId());
        $this->em()->clear();

        $this->modoOriginal = fileperms($this->diretorio()) & 0o7777;
        chmod($this->diretorio(), 0o555);
        try {
            $client->request('POST', "/clientes/documento/{$docId}/deletar", ['_token' => 'TOKEN_delete_doc_cliente_' . $docId]);
        } finally {
            chmod($this->diretorio(), $this->modoOriginal);
        }

        self::assertResponseRedirects();
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM cliente_documento WHERE id = ?', $docId), 'o banco é autoritativo');
        self::assertFileExists($arquivo, 'o disco recusou: o arquivo fica, órfão recuperável');
    }

    #[TestDox('documento com o banco recusando a exclusão: a linha fica e o arquivo fica')]
    public function testDocumentoComBancoQueRecusaNaoApagaOArquivo(): void
    {
        [$client, $tenant] = $this->gestorLogado();
        $cliente = $this->criarCliente($tenant);
        $arquivo = $this->criarDocumento($cliente);
        $docId   = (int) $this->contar('SELECT MAX(id) FROM cliente_documento WHERE cliente_id = ?', (int) $cliente->getId());
        $this->em()->clear();

        $recusa = new class {
            public int $recusas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() !== []) {
                    ++$this->recusas;

                    throw new \LogicException('banco recusou a exclusão do documento');
                }
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::onFlush], $recusa);

        try {
            $client->request('POST', "/clientes/documento/{$docId}/deletar", ['_token' => 'TOKEN_delete_doc_cliente_' . $docId]);
        } finally {
            $this->em()->getEventManager()->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertSame(1, $recusa->recusas);
        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM cliente_documento WHERE id = ?', $docId));
        self::assertFileExists($arquivo, 'ordem: nada sai do disco antes de o banco confirmar (a recusa é no onFlush, antes do BEGIN)');
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{KernelBrowser, Tenant} */
    private function gestorLogado(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $tenant = new Tenant();
        $tenant->setName('Tenant E25 ' . uniqid());
        $this->em()->persist($tenant);

        $user = new User();
        $user->setEmail('gestor_e25_' . uniqid() . '@test.com');
        $user->setFullName('Gestor E25');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $tenant];
    }

    private function criarCliente(Tenant $tenant): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Cliente E25 ' . substr(uniqid(), -6));
        $cliente->setCpf(str_pad((string) random_int(1, 99_999_999), 11, '0', \STR_PAD_LEFT));
        $cliente->setRg('123456');
        $cliente->setRgOrgaoExpedidor('SSP/SP');
        $cliente->setEmail('cliente_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    /** @return string o caminho do arquivo real */
    private function criarDocumento(Cliente $cliente): string
    {
        $nome    = bin2hex(random_bytes(16)) . '.pdf';
        $caminho = $this->diretorio() . '/' . $nome;
        if (!is_dir($this->diretorio())) {
            mkdir($this->diretorio(), 0o775, true);
        }
        file_put_contents($caminho, '%PDF-1.4 cliente');
        $this->criados[] = $caminho;

        $doc = new ClienteDocumento();
        $doc->setCliente($cliente);
        $doc->setTenant($cliente->getTenant());
        $doc->setTitulo('Documento ' . uniqid());
        $doc->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO);
        $doc->setCaminhoArquivo($nome);
        $doc->setNomeOriginal('rg.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(16);
        $cliente->addDocumento($doc);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $caminho;
    }

    private function contar(string $sql, int $id): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql, [$id]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function diretorio(): string
    {
        return (string) static::getContainer()->getParameter('clientes_uploads_dir');
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
}
