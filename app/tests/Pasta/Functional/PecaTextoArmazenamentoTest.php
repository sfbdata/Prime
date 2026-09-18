<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMInvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * As três rotas de peça escrita no editor, depois da E2.4B: criar, editar e exportar passam pelo
 * armazenamento por chave.
 *
 * Dois backends, cada um para o que só ele prova:
 *  - o dublê em memória (`ArmazenamentoEmMemoriaNoContainer`) guarda o escopo na chave — é ele
 *    que mostra que gravação e leitura usam a MESMA chave (R1) e que falha de gravação não deixa
 *    linha;
 *  - o disco de verdade (`uploads_dir` de teste) é o único em que "diretório ilegível" e "arquivo
 *    ilegível" existem — é ele que prova que pane não vira 404 (D12, D13).
 *
 * Os testes de `chmod` mexem no diretório compartilhado de uploads de teste e restauram em
 * `finally`; supõem uma suíte por worktree (mesma restrição da E2.3).
 */
#[CoversClass(PeticionarController::class)]
final class PecaTextoArmazenamentoTest extends JusPrimeWebTestCase
{
    private const HTML_ORIGINAL = '<p>Peça original com acentuação: ç, ã.</p>';

    /** @var list<string> */
    private array $arquivosParaLimpar = [];

    private int $posicaoDoLog = 0;

    protected function tearDown(): void
    {
        foreach ($this->arquivosParaLimpar as $arquivo) {
            if (is_file($arquivo)) {
                @chmod($arquivo, 0o644);
                @unlink($arquivo);
            }
        }

        parent::tearDown();
    }

    // ── criar ─────────────────────────────────────────────────────────────────

    #[TestDox('criar: a chave gravada é a mesma que a leitura monta a partir do documento persistido (R1)')]
    public function testCriarGravaNaChaveDaLeitura(): void
    {
        [$client, $duble, $tenant, $pasta] = $this->cenarioEmMemoria();

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/texto", [
            '_token'    => 'TOKEN_peticionar_texto_' . $pasta->getId(),
            'titulo'    => 'Petição Inicial',
            'categoria' => 'PECA',
            'conteudo'  => '<p>Conteúdo da peça</p>',
        ]);

        self::assertResponseIsSuccessful();
        $docs = $this->documentosDaPasta((int) $pasta->getId());
        self::assertCount(1, $docs);
        $doc = $docs[0];

        $gravada = $duble->memoria->ultimaGravada();
        self::assertTrue($gravada->ehIgualA(ChavesDePasta::documento($doc)), 'gravação e leitura divergem');
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
        self::assertSame($tenant->getId(), $gravada->escopo->tenantIdOuNull());
        self::assertSame('<p>Conteúdo da peça</p>', $duble->memoria->ler($gravada));
        self::assertSame('text/html', $doc->getMimeType());
        self::assertSame(strlen('<p>Conteúdo da peça</p>'), $doc->getTamanhoBytes());
    }

    #[TestDox('criar: falha do storage responde erro (não 404) e nenhum documento é registrado')]
    public function testCriarComFalhaDoStorageNaoRegistraDocumento(): void
    {
        [$client, $duble, , $pasta] = $this->cenarioEmMemoria();
        $duble->memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/texto", [
            '_token'    => 'TOKEN_peticionar_texto_' . $pasta->getId(),
            'titulo'    => 'Petição Inicial',
            'categoria' => 'PECA',
            'conteudo'  => '<p>Conteúdo da peça</p>',
        ]);

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent(), 'o 500 tem de ser a falha de gravação');
        self::assertCount(0, $this->documentosDaPasta((int) $pasta->getId()));
    }

    #[TestDox('criar: título que não cabe na coluna → 400 com a mensagem, sem gravar e sem registrar')]
    public function testCriarComTituloLongoDemaisNaoGrava(): void
    {
        [$client, $duble, , $pasta] = $this->cenarioEmMemoria();

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/texto", [
            '_token'    => 'TOKEN_peticionar_texto_' . $pasta->getId(),
            'titulo'    => str_repeat('a', 251),
            'categoria' => 'PECA',
            'conteudo'  => '<p>Conteúdo da peça</p>',
        ]);

        self::assertResponseStatusCodeSame(400);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('O título da peça é longo demais (máximo de 250 caracteres).', $dados['error']);
        self::assertSame([], $duble->memoria->gravadas);
        self::assertCount(0, $this->documentosDaPasta((int) $pasta->getId()));
    }

    // ── editar ────────────────────────────────────────────────────────────────

    #[TestDox('editar: regrava o HTML na chave do documento e atualiza título e tamanho')]
    public function testEditarRegravaNaMesmaChave(): void
    {
        [$client, $duble, $tenant, $pasta] = $this->cenarioEmMemoria();
        $doc   = $this->criarDocumentoHtml($pasta, $tenant, bin2hex(random_bytes(16)) . '.html');
        $chave = ChavesDePasta::documento($doc);
        $duble->memoria->gravar($chave, FonteDeConteudo::deTexto(self::HTML_ORIGINAL));

        $this->editar($client, $doc, '<p>Editado</p>', 'Novo Título');

        self::assertResponseIsSuccessful();
        self::assertSame('<p>Editado</p>', $duble->memoria->ler($chave));
        self::assertTrue($duble->memoria->ultimaGravada()->ehIgualA($chave));

        $recarregado = $this->documento((int) $doc->getId());
        self::assertSame('NOVO TÍTULO', $recarregado->getTitulo());
        self::assertSame(strlen('<p>Editado</p>'), $recarregado->getTamanhoBytes());
        self::assertSame($doc->getCaminhoArquivo(), $recarregado->getCaminhoArquivo());
    }

    /**
     * D14. Antes da E2.4B esta requisição recriava o arquivo em silêncio e respondia sucesso.
     */
    #[TestDox('D14 editar: arquivo sumido → 404 com mensagem fixa, arquivo NÃO recriado, banco intacto')]
    public function testEditarSemArquivoResponde404ENaoRecria(): void
    {
        [$client, $tenant, $pasta] = $this->cenarioEmDisco();
        $nome    = bin2hex(random_bytes(16)) . '.html';
        $doc     = $this->criarDocumentoHtml($pasta, $tenant, $nome);
        $caminho = $this->uploadsDir() . '/' . $nome;
        $this->arquivosParaLimpar[] = $caminho;

        $this->editar($client, $doc, '<p>Não pode ser gravado</p>', 'Outro Título');

        self::assertResponseStatusCodeSame(404);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($dados['success']);
        self::assertSame('O arquivo desta peça não foi encontrado. Nada foi salvo.', $dados['error']);
        self::assertStringNotContainsString($nome, (string) $client->getResponse()->getContent(), 'a resposta não expõe a chave');

        self::assertFileDoesNotExist($caminho, 'o arquivo sumido foi recriado');
        $recarregado = $this->documento((int) $doc->getId());
        self::assertSame('PEÇA DE TEXTO', $recarregado->getTitulo());
        self::assertSame(strlen(self::HTML_ORIGINAL), $recarregado->getTamanhoBytes());
        $this->assertLogouPecaSemArquivo((int) $doc->getId(), 'edição');
    }

    #[TestDox('editar: título que não cabe na coluna → 400 com a mensagem, peça e banco intactos')]
    public function testEditarComTituloLongoDemaisNaoRegrava(): void
    {
        [$client, $tenant, $pasta] = $this->cenarioEmDisco();
        $doc = $this->criarDocumentoHtmlEmDisco($pasta, $tenant);

        $this->editar($client, $doc, '<p>não pode ser gravado</p>', str_repeat('a', 251));

        self::assertResponseStatusCodeSame(400);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($dados['success']);
        self::assertSame('O título da peça é longo demais (máximo de 250 caracteres).', $dados['error']);
        self::assertSame(
            self::HTML_ORIGINAL,
            (string) file_get_contents($this->uploadsDir() . '/' . $doc->getCaminhoArquivo()),
        );
        $recarregado = $this->documento((int) $doc->getId());
        self::assertSame('PEÇA DE TEXTO', $recarregado->getTitulo());
        self::assertSame(strlen(self::HTML_ORIGINAL), $recarregado->getTamanhoBytes());
    }

    /** D12: diretório ilegível é pane, não "arquivo sumido". */
    #[TestDox('D12 editar: diretório ilegível → 500 (não 404), banco intacto')]
    public function testEditarComDiretorioIlegivelNaoEh404(): void
    {
        [$client, $tenant, $pasta] = $this->cenarioEmDisco();
        $doc = $this->criarDocumentoHtmlEmDisco($pasta, $tenant);

        $this->comDiretorioIlegivel(function () use ($client, $doc): void {
            $this->editar($client, $doc, '<p>novo</p>', 'Outro Título');
        });

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('FalhaDeArmazenamento', (string) $client->getResponse()->getContent());
        $recarregado = $this->documento((int) $doc->getId());
        self::assertSame('PEÇA DE TEXTO', $recarregado->getTitulo());
        self::assertSame(
            self::HTML_ORIGINAL,
            (string) file_get_contents($this->uploadsDir() . '/' . $doc->getCaminhoArquivo()),
        );
    }

    /**
     * O 400 da edição é só para o título longo demais. Um erro do Doctrine no `flush` também é
     * `InvalidArgumentException` (`ORMInvalidArgumentException`) — capturá-lo devolveria ao usuário
     * a mensagem interna como se o pedido fosse inválido, com a peça já regravada.
     */
    #[TestDox('editar: erro do Doctrine no flush não vira 400')]
    public function testEditarComErroDoDoctrineNoFlushNaoEh400(): void
    {
        [$client, $duble, $tenant, $pasta] = $this->cenarioEmMemoria();
        $doc = $this->criarDocumentoHtml($pasta, $tenant, bin2hex(random_bytes(16)) . '.html');
        $duble->memoria->gravar(ChavesDePasta::documento($doc), FonteDeConteudo::deTexto(self::HTML_ORIGINAL));

        $recusa = new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        throw new ORMInvalidArgumentException('erro interno do mapeamento');
                    }
                }
            }
        };
        $eventos = static::getContainer()->get(EntityManagerInterface::class)->getEventManager();
        $eventos->addEventListener([Events::onFlush], $recusa);

        try {
            $this->editar($client, $doc, '<p>Editado</p>', 'Novo Título');
        } finally {
            $eventos->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('erro interno do mapeamento', (string) $client->getResponse()->getContent());
    }

    #[TestDox('D12 editar: falha ao gravar → 500 com a falha, banco intacto')]
    public function testEditarComFalhaAoGravarNaoAlteraBanco(): void
    {
        [$client, $duble, $tenant, $pasta] = $this->cenarioEmMemoria();
        $doc   = $this->criarDocumentoHtml($pasta, $tenant, bin2hex(random_bytes(16)) . '.html');
        $chave = ChavesDePasta::documento($doc);
        $duble->memoria->gravar($chave, FonteDeConteudo::deTexto(self::HTML_ORIGINAL));
        $duble->memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $this->editar($client, $doc, '<p>Editado</p>', 'Novo Título');

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent());
        self::assertSame(self::HTML_ORIGINAL, $duble->memoria->ler($chave));
        $recarregado = $this->documento((int) $doc->getId());
        self::assertSame('PEÇA DE TEXTO', $recarregado->getTitulo());
        self::assertSame(strlen(self::HTML_ORIGINAL), $recarregado->getTamanhoBytes());
    }

    // ── exportar ──────────────────────────────────────────────────────────────

    #[TestDox('exportar: lê o HTML na chave do documento (R1)')]
    public function testExportarLeNaChaveDoDocumento(): void
    {
        [$client, $duble, $tenant, $pasta] = $this->cenarioEmMemoria();
        $doc = $this->criarDocumentoHtml($pasta, $tenant, bin2hex(random_bytes(16)) . '.html');
        $duble->memoria->gravar(ChavesDePasta::documento($doc), FonteDeConteudo::deTexto('<p>Só na chave certa</p>'));

        $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/txt");

        self::assertResponseIsSuccessful();
        self::assertSame('Só na chave certa', (string) $client->getResponse()->getContent());
    }

    /**
     * D13. Antes da E2.4B a mesma requisição respondia 200 com um arquivo VAZIO (em produção,
     * onde o warning do `file_get_contents` não vira exceção).
     */
    #[TestDox('D13 exportar: arquivo ausente → 404')]
    public function testExportarSemArquivoResponde404(): void
    {
        [$client, $tenant, $pasta] = $this->cenarioEmDisco();
        $doc = $this->criarDocumentoHtml($pasta, $tenant, bin2hex(random_bytes(16)) . '.html');

        $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/pdf");

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Arquivo da peça não encontrado.', (string) $client->getResponse()->getContent());
        $this->assertLogouPecaSemArquivo((int) $doc->getId(), 'exportação');
    }

    #[TestDox('D13 exportar: arquivo presente e ILEGÍVEL → 500, não 404')]
    public function testExportarComArquivoIlegivelNaoEh404(): void
    {
        $this->pularSeRoot();
        [$client, $tenant, $pasta] = $this->cenarioEmDisco();
        $doc     = $this->criarDocumentoHtmlEmDisco($pasta, $tenant);
        $caminho = $this->uploadsDir() . '/' . $doc->getCaminhoArquivo();
        chmod($caminho, 0o000);

        try {
            $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/pdf");
        } finally {
            chmod($caminho, 0o644);
        }

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('FalhaDeArmazenamento', (string) $client->getResponse()->getContent());
    }

    #[TestDox('D13 exportar: diretório ILEGÍVEL → 500, não 404')]
    public function testExportarComDiretorioIlegivelNaoEh404(): void
    {
        [$client, $tenant, $pasta] = $this->cenarioEmDisco();
        $doc = $this->criarDocumentoHtmlEmDisco($pasta, $tenant);

        $this->comDiretorioIlegivel(function () use ($client, $doc): void {
            $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/pdf");
        });

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('FalhaDeArmazenamento', (string) $client->getResponse()->getContent());
    }

    /**
     * Regra da E2.3: nome vindo do BANCO que a chave recusa é dado que o sistema nunca gravou —
     * erro do sistema, não pedido inválido. A exceção estende `InvalidArgumentException`, e o
     * `catch` do formato inválido a transformaria em 400 com a mensagem interna.
     */
    #[TestDox('exportar: nome no banco que a chave recusa → 500, não 400')]
    public function testExportarComChaveRecusadaNaoEh400(): void
    {
        [$client, $tenant, $pasta] = $this->cenarioEmDisco();
        $doc = $this->criarDocumentoHtml($pasta, $tenant, 'sub/peca.html');

        $client->request('GET', "/pasta/documento/{$doc->getId()}/exportar/pdf");

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('ChaveDeArquivoInvalida', (string) $client->getResponse()->getContent());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: KernelBrowser, 1: ArmazenamentoEmMemoriaNoContainer, 2: Tenant, 3: Pasta} */
    private function cenarioEmMemoria(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());

        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $duble, $tenant, $pasta];
    }

    /** @return array{0: KernelBrowser, 1: Tenant, 2: Pasta} */
    private function cenarioEmDisco(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        clearstatcache();
        $this->posicaoDoLog = is_file($this->arquivoDeLog()) ? (int) filesize($this->arquivoDeLog()) : 0;

        return [$client, $tenant, $pasta];
    }

    private function editar(KernelBrowser $client, PastaDocumento $doc, string $conteudo, string $titulo): void
    {
        $client->request(
            'PUT',
            "/pasta/documento/{$doc->getId()}/texto",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                '_token'   => 'TOKEN_peticionar_editar_' . $doc->getId(),
                'conteudo' => $conteudo,
                'titulo'   => $titulo,
            ]),
        );
    }

    private function pularSeRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão; o guarda não é observável');
        }
    }

    /**
     * D14: a inconsistência fica registrada para quem opera. O log de teste é do kernel e cresce
     * sem parar — lê-se só o que foi escrito depois de `$this->posicaoDoLog`.
     */
    private function assertLogouPecaSemArquivo(int $documentoId, string $operacao): void
    {
        clearstatcache();
        $log = (string) file_get_contents($this->arquivoDeLog(), false, null, $this->posicaoDoLog);

        self::assertMatchesRegularExpression(
            '/app\.ERROR: Peça sem arquivo no armazenamento na ' . preg_quote($operacao, '/')
                . '\.[^\n]*"documento_id":' . $documentoId . '\b/u',
            $log,
            'a peça sem arquivo não foi registrada no log',
        );
    }

    private function arquivoDeLog(): string
    {
        $container = static::getContainer();

        return (string) $container->getParameter('kernel.logs_dir') . '/' . $container->getParameter('kernel.environment') . '.log';
    }

    private function comDiretorioIlegivel(callable $requisicao): void
    {
        $this->pularSeRoot();

        $diretorio = $this->uploadsDir();
        $modo      = fileperms($diretorio) & 0o7777;
        chmod($diretorio, 0o000);

        try {
            $requisicao();
        } finally {
            chmod($diretorio, $modo);
        }
    }

    private function criarDocumentoHtmlEmDisco(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $nome    = bin2hex(random_bytes(16)) . '.html';
        $caminho = $this->uploadsDir() . '/' . $nome;
        file_put_contents($caminho, self::HTML_ORIGINAL);
        $this->arquivosParaLimpar[] = $caminho;

        return $this->criarDocumentoHtml($pasta, $tenant, $nome);
    }

    private function criarDocumentoHtml(Pasta $pasta, Tenant $tenant, string $caminhoArquivo): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo('Peça de Texto');
        $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
        $doc->setCaminhoArquivo($caminhoArquivo);
        $doc->setNomeOriginal('Peça de Texto.html');
        $doc->setMimeType('text/html');
        $doc->setTamanhoBytes(strlen(self::HTML_ORIGINAL));
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function documento(int $id): PastaDocumento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(PastaDocumento::class, $id) ?? self::fail('documento sumiu: ' . $id);
    }

    /** @return list<PastaDocumento> */
    private function documentosDaPasta(int $pastaId): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em->getRepository(PastaDocumento::class)->findBy(['pasta' => $pastaId]);
    }

    private function uploadsDir(): string
    {
        return (string) static::getContainer()->getParameter('uploads_dir');
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Peça E2.4B ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('peca_e24b_' . uniqid() . '@test.com');
        $user->setFullName('Admin Peça');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-E24B-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
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
