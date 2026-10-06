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
 * As duas rotas do `PastaController` que excluem documento — `pasta_documento_delete` (aba
 * Documentos) e `pasta_financeiro_excluir_documento` (contrato, aba Financeiro) —, contra o banco
 * e o disco reais.
 *
 * Desde o L7 (D7) as duas são LIXEIRA: a linha fica com a lápide (`excluido_em`/`excluido_por`),
 * o arquivo físico fica, o documento some da pasta (explorador, `view`/`download` → 404). Nada sai
 * do disco aqui — quem remove é `app:documentos:purgar-lixeira`, e os três casos INV-6 que esta
 * classe provava para a exclusão física (arquivo depois do COMMIT; banco recusa → nada sai; disco
 * recusa → o banco é autoritativo) vivem agora em `PurgarLixeiraCommandTest`, sem enfraquecer: a
 * lápide prova que nada sai, a purga prova a remoção física.
 */
#[CoversClass(PastaController::class)]
final class ExcluirDocumentoDaPastaTest extends JusPrimeWebTestCase
{
    /** @var list<string> */
    private array $criados = [];

    protected function tearDown(): void
    {
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
        yield 'documento'           => ['documento'];
        yield 'contrato financeiro' => ['financeiro'];
    }

    #[TestDox('excluir ($rota): vira lápide — a linha fica, o arquivo fica, e o documento some da pasta (view/download 404, fora do explorador)')]
    #[DataProvider('rotas')]
    public function testExcluirViraLapide(string $rota): void
    {
        [$client, $pasta, $doc, $arquivo, $user] = $this->cenario($rota);
        $id = (int) $doc->getId();

        $this->excluir($client, $rota, $pasta, $doc);

        $this->assertRespostaDeSucesso($client, $rota, $pasta);
        self::assertSame(1, $this->linhas($doc), 'lixeira é lápide: a linha fica');
        self::assertFileExists($arquivo, 'o arquivo físico fica até a purga');

        $lapide = $this->lapide($doc);
        self::assertNotNull($lapide['excluido_em']);
        self::assertSame($user->getId(), (int) $lapide['excluido_por_id']);

        // Com `disableReboot()` o EntityManager sobrevive entre requests, e o filtro é SQL: o que
        // já está no identity map não é relido. Em produção cada request nasce com o EM vazio —
        // o `limpar()` reproduz isso antes de cada leitura.
        $this->limpar();
        $client->request('GET', "/pasta/documento/{$id}/visualizar");
        self::assertResponseStatusCodeSame(404, 'documento na lixeira não se vê');
        $this->limpar();
        $client->request('GET', "/pasta/documento/{$id}/download");
        self::assertResponseStatusCodeSame(404, 'nem se baixa');

        $this->limpar();
        $client->request('GET', '/pasta/' . (int) $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertNotContains($id, $this->idsDosArquivosDoExplorador((string) $client->getResponse()->getContent()), 'o explorador não lista a lixeira');

        // Excluir de novo o que já está na lixeira: não é achado (404), sem recarimbar.
        $this->limpar();
        $this->excluir($client, $rota, $pasta, $doc);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($lapide, $this->lapide($doc));
    }

    #[TestDox('excluir ($rota) com o banco recusando: nada é marcado e o arquivo fica')]
    #[DataProvider('rotas')]
    public function testBancoQueRecusaNaoMarcaALapide(string $rota): void
    {
        [$client, $pasta, $doc, $arquivo] = $this->cenario($rota);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $recusa = new class {
            public int $recusas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        ++$this->recusas;

                        throw new \LogicException('banco recusou a lápide');
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
        self::assertNull($this->lapide($doc)['excluido_em'], 'a lápide não foi gravada');
        self::assertFileExists($arquivo);
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

    /** A aba Documentos redireciona para `#documentos`; a Financeiro responde JSON `{sucesso, lixeira}`. */
    private function assertRespostaDeSucesso(KernelBrowser $client, string $rota, Pasta $pasta): void
    {
        if ($rota === 'documento') {
            self::assertResponseRedirects(sprintf('/pasta/%d#documentos', (int) $pasta->getId()));

            return;
        }

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($json['sucesso']);
        self::assertTrue($json['lixeira']);
    }

    /** @return array{KernelBrowser, Pasta, PastaDocumento, string, User} */
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

        // O request lê o banco, não a memória do teste (o resolver passa pelo LixeiraFilter).
        $em->clear();

        return [$client, $pasta, $doc, $arquivo, $user];
    }

    private function linhas(PastaDocumento $doc): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE id = ?',
            [$doc->getId()],
        );
    }

    /** @return array{excluido_em: ?string, excluido_por_id: ?string} */
    private function lapide(PastaDocumento $doc): array
    {
        $linha = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT excluido_em, excluido_por_id FROM pasta_documento WHERE id = ?',
            [$doc->getId()],
        );
        self::assertIsArray($linha);

        return $linha;
    }

    /** @return list<int> */
    private function idsDosArquivosDoExplorador(string $html): array
    {
        self::assertSame(1, preg_match('~<script type="application/json" id="pexDados">(.*?)</script>~s', $html, $m), 'o explorador emite #pexDados');
        $dados = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        return array_map(static fn (array $a): int => (int) $a['id'], $dados['arquivos']);
    }

    /** O request lê o banco, não a memória do teste (ver `testExcluirViraLapide`). */
    private function limpar(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
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
