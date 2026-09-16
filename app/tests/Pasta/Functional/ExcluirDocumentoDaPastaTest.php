<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * As duas rotas do `PastaController` que excluem documento (E2.5), contra o banco e o disco reais.
 *
 * `deleteDocumento` não tinha teste nenhum e apagava o arquivo ANTES do `flush`: um banco que
 * recusasse deixava o documento de pé apontando para o vazio. `financeiroExcluirDocumento` já
 * apagava depois, mas sem proteção: uma falha de disco virava 500 com a exclusão já confirmada.
 */
#[CoversClass(PastaController::class)]
final class ExcluirDocumentoDaPastaTest extends JusPrimeWebTestCase
{
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

    /** @return iterable<string, array{string}> */
    public static function rotas(): iterable
    {
        yield 'documento'          => ['documento'];
        yield 'contrato financeiro' => ['financeiro'];
    }

    #[TestDox('excluir ($rota): a linha sai e, depois dela, o arquivo')]
    #[DataProvider('rotas')]
    public function testExcluirApagaDepoisDoCommit(string $rota): void
    {
        [$client, $pasta, $doc, $arquivo] = $this->cenario($rota);

        $this->excluir($client, $rota, $pasta, $doc);

        self::assertTrue($client->getResponse()->isSuccessful() || $client->getResponse()->isRedirect());
        self::assertSame(0, $this->linhas($doc));
        self::assertFileDoesNotExist($arquivo);
    }

    #[TestDox('excluir ($rota) com o banco recusando: a linha fica e o arquivo fica')]
    #[DataProvider('rotas')]
    public function testBancoQueRecusaNaoApagaOArquivo(string $rota): void
    {
        [$client, $pasta, $doc, $arquivo] = $this->cenario($rota);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $recusa = new class {
            public int $recusas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        ++$this->recusas;

                        throw new \LogicException('banco recusou a exclusão');
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::onFlush], $recusa);

        try {
            $this->excluir($client, $rota, $pasta, $doc);
        } finally {
            $em->getEventManager()->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertSame(1, $recusa->recusas);
        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $this->linhas($doc));
        self::assertFileExists($arquivo, 'ordem: nada sai do disco antes de o banco confirmar (a recusa é no onFlush, antes do BEGIN)');
    }

    /** COMMIT confirmado seguido de falha na exclusão física. */
    #[TestDox('excluir ($rota) com o disco recusando: a exclusão vale, o arquivo fica, e não há 500')]
    #[DataProvider('rotas')]
    public function testDiscoQueFalhaDepoisDoCommit(string $rota): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório');
        }

        [$client, $pasta, $doc, $arquivo] = $this->cenario($rota);

        $this->modoOriginal = fileperms($this->diretorio()) & 0o7777;
        chmod($this->diretorio(), 0o555);
        try {
            $this->excluir($client, $rota, $pasta, $doc);
        } finally {
            chmod($this->diretorio(), $this->modoOriginal);
        }

        self::assertTrue($client->getResponse()->isSuccessful() || $client->getResponse()->isRedirect());
        self::assertSame(0, $this->linhas($doc), 'o banco é autoritativo');
        self::assertFileExists($arquivo, 'órfão recuperável');
    }

    // ----------------------------------------------------------------- helpers

    private function excluir(KernelBrowser $client, string $rota, Pasta $pasta, PastaDocumento $doc): void
    {
        $id = (int) $doc->getId();

        if ($rota === 'documento') {
            $client->request('POST', "/pasta/documento/{$id}/deletar", ['_token' => 'TOKEN_delete_documento_' . $id]);

            return;
        }

        $client->request(
            'POST',
            sprintf('/pasta/%d/financeiro/documento/%d/excluir', (int) $pasta->getId(), $id),
            ['_token' => 'TOKEN_pasta_financeiro_excluir_' . $id],
        );
    }

    /** @return array{KernelBrowser, Pasta, PastaDocumento, string} */
    private function cenario(string $rota): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant E25 Pasta ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('e25_pasta_' . uniqid() . '@test.com');
        $user->setFullName('Admin E25');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('E25-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (!is_dir($this->diretorio())) {
            mkdir($this->diretorio(), 0o775, true);
        }
        $arquivo = $this->diretorio() . '/' . $nome;
        file_put_contents($arquivo, '%PDF-1.4 pasta');
        $this->criados[] = $arquivo;

        $doc = new PastaDocumento();
        $doc->setTitulo('Contrato E25');
        $doc->setCategoria($rota === 'financeiro' ? PastaDocumento::CATEGORIA_CONTRATO : PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($nome);
        $doc->setNomeOriginal('contrato.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(14);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $em->persist($doc);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $pasta, $doc, $arquivo];
    }

    private function linhas(PastaDocumento $doc): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE id = ?',
            [$doc->getId()],
        );
    }

    private function diretorio(): string
    {
        return (string) static::getContainer()->getParameter('uploads_dir');
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
