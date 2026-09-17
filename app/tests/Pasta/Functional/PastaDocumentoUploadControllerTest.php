<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Upload de documentos na pasta (`POST /pasta/{id}/documento/upload`), contra o storage e o
 * diretório REAIS (`var/uploads-test/pastas` em teste). Não tinha teste até a E2.4A.
 *
 * O DAMA reverte o banco, não o disco: todo arquivo que surgir no diretório durante a requisição
 * é removido no tearDown. Os dois últimos casos trocam o storage pelo dublê em memória: é o único
 * jeito de ver o escopo da chave (R1) e de provocar falha de gravação.
 */
#[CoversClass(PastaController::class)]
final class PastaDocumentoUploadControllerTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $arquivosCriados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        $this->arquivosCriados = [];

        parent::tearDown();
    }

    #[TestDox('dois arquivos: cada um vira um documento com arquivo próprio em %uploads_dir%, nome e MIME')]
    public function testUploadGravaCadaArquivoERegistraODocumento(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta  = $this->criarPasta($tenant);
        $id     = (int) $pasta->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestor, $tenant);

        $novos = $this->enviar($client, $id, [
            ['conteudo' => self::PDF, 'nome' => 'procuracao.pdf'],
            ['conteudo' => self::PDF . '% segundo', 'nome' => 'contrato.pdf'],
        ]);

        self::assertResponseRedirects();
        self::assertCount(2, $novos, 'cada upload tem de ter o próprio arquivo');

        $documentos = $this->documentosDaPasta($id);
        self::assertCount(2, $documentos);

        $porNome = [];
        foreach ($documentos as $doc) {
            $porNome[$doc->getNomeOriginal()] = $doc;
        }

        foreach (['procuracao.pdf' => self::PDF, 'contrato.pdf' => self::PDF . '% segundo'] as $nome => $conteudo) {
            $doc     = $porNome[$nome] ?? self::fail('documento não registrado: ' . $nome);
            $caminho = $this->diretorio() . '/' . $doc->getCaminhoArquivo();

            self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $doc->getCaminhoArquivo());
            self::assertContains($doc->getCaminhoArquivo(), $novos);
            self::assertStringEqualsFile($caminho, $conteudo);
            self::assertSame('application/pdf', $doc->getMimeType());
            self::assertSame(\strlen($conteudo), $doc->getTamanhoBytes());
            self::assertSame($tenant->getId(), $doc->getTenant()?->getId());
        }
    }

    /**
     * E2.6B: compressão pela CHAVE, em upload MÚLTIPLO — o que o unit não cobre é cada arquivo
     * terminar com a própria versão comprimida e o próprio tamanho medido, sem um contaminar o outro.
     */
    #[TestDox('reduzir_tamanho: cada arquivo fica comprimido, íntegro e com o tamanho do disco')]
    public function testReduzirTamanhoComprimeCadaArquivo(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }

        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta  = $this->criarPasta($tenant);
        $id     = (int) $pasta->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestor, $tenant);

        $gordo    = $this->pdfGordo();
        $pequeno  = self::PDF;

        $this->enviar($client, $id, [
            ['conteudo' => $gordo, 'nome' => 'peticao.pdf'],
            ['conteudo' => $pequeno, 'nome' => 'nota.pdf'],
        ], reduzirTamanho: true);

        self::assertResponseRedirects();
        $documentos = $this->documentosDaPasta($id);
        self::assertCount(2, $documentos);

        foreach ($documentos as $doc) {
            $caminho = $this->diretorio() . '/' . $doc->getCaminhoArquivo();
            self::assertFileExists($caminho);
            $gravado = (string) file_get_contents($caminho);

            self::assertStringStartsWith('%PDF-', $gravado);
            self::assertSame(\strlen($gravado), $doc->getTamanhoBytes(), 'D30: a coluna não tem o tamanho do arquivo real');

            if ($doc->getNomeOriginal() === 'peticao.pdf') {
                self::assertLessThan(\strlen($gordo), \strlen($gravado), 'o arquivo gordo não foi comprimido');
                continue;
            }

            // O pequeno não encolhe: o original fica byte a byte (INV-7).
            self::assertSame($pequeno, $gravado);
        }

        self::assertSame([], glob($this->diretorio() . '/.compress_*') ?: [], 'sobrou temporário de compressão no volume');
    }

    #[TestDox('D30: a coluna guarda o tamanho que o STORAGE mediu, não o que o upload declarou')]
    public function testTamanhoPersistidoVemDoStorage(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $duble  = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $duble->memoria->tamanhoRelatado = 4242;
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta  = $this->criarPasta($tenant);
        $id     = (int) $pasta->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestor, $tenant);

        $this->enviar($client, $id, [['conteudo' => self::PDF, 'nome' => 'procuracao.pdf']]);

        $documentos = $this->documentosDaPasta($id);
        self::assertCount(1, $documentos);
        self::assertSame(4242, $documentos[0]->getTamanhoBytes());
    }

    private function pdfGordo(): string
    {
        $temporario = sys_get_temp_dir() . '/gordo_' . bin2hex(random_bytes(6)) . '.pdf';
        $this->arquivosCriados[] = $temporario;

        return GhostscriptDeTeste::pdfGordo($temporario);
    }

    #[TestDox('tipo fora da lista é recusado sem gravar arquivo nem registrar documento')]
    public function testTipoNaoPermitidoNaoGrava(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta  = $this->criarPasta($tenant);
        $id     = (int) $pasta->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestor, $tenant);

        $novos = $this->enviar($client, $id, [
            ['conteudo' => "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 200), 'nome' => 'setup.exe'],
        ]);

        self::assertResponseRedirects();
        self::assertSame([], $novos, 'a recusa tem de acontecer antes de qualquer gravação');
        self::assertCount(0, $this->documentosDaPasta($id));
    }

    #[TestDox('upload na pasta de outro escritório responde 404 sem gravar arquivo nem registrar documento')]
    public function testPastaDeOutroTenantRetorna404SemGravar(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $idB     = (int) $pastaB->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $novos = $this->enviar($client, $idB, [['conteudo' => self::PDF, 'nome' => 'intruso.pdf']]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $novos);
        self::assertCount(0, $this->documentosDaPasta($idB));
    }

    /**
     * R1: no disco o escopo da chave não aparece (a categoria é plana). Contra o dublê, a chave
     * gravada tem de ser exatamente a que a leitura monta a partir do documento persistido.
     */
    #[TestDox('R1: nas duas rotas, a chave gravada é a mesma que a leitura monta a partir do documento')]
    public function testChaveGravadaEhAMesmaDaLeitura(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $duble  = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta  = $this->criarPasta($tenant);
        $id     = (int) $pasta->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestor, $tenant);

        $this->enviar($client, $id, [['conteudo' => self::PDF, 'nome' => 'procuracao.pdf']]);
        self::assertResponseRedirects();
        $pelaTela = $duble->memoria->ultimaGravada();

        $client->request(
            'POST',
            "/pasta/{$id}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $id],
            ['arquivo' => $this->uploadDe(self::PDF, 'contrato.pdf')],
        );
        self::assertResponseStatusCodeSame(201);
        $peloFinanceiro = $duble->memoria->ultimaGravada();

        $porNome = [];
        foreach ($this->documentosDaPasta($id) as $doc) {
            $porNome[$doc->getNomeOriginal()] = $doc;
        }

        foreach (['procuracao.pdf' => $pelaTela, 'contrato.pdf' => $peloFinanceiro] as $nome => $gravada) {
            $doc = $porNome[$nome] ?? self::fail('documento não registrado: ' . $nome);

            self::assertTrue($gravada->ehIgualA(ChavesDePasta::documento($doc)), $nome . ': gravação e leitura divergem');
            self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
            self::assertSame($tenant->getId(), $gravada->escopo->tenantIdOuNull());
            self::assertTrue($duble->memoria->existe(ChavesDePasta::documento($doc)));
        }
    }

    #[TestDox('falha do storage: nas duas rotas, nenhum documento é registrado')]
    public function testFalhaDoStorageNaoRegistraDocumento(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $duble                        = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $duble->memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');
        $tenant                       = $this->criarTenant();
        $gestor                       = $this->criarGestor($tenant);
        $pasta                        = $this->criarPasta($tenant);
        $id                           = (int) $pasta->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestor, $tenant);

        $this->enviar($client, $id, [['conteudo' => self::PDF, 'nome' => 'procuracao.pdf']]);
        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent(), 'o 500 tem de ser a falha de gravação, não outra');

        $client->request(
            'POST',
            "/pasta/{$id}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $id],
            ['arquivo' => $this->uploadDe(self::PDF, 'contrato.pdf')],
        );
        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent(), 'o 500 tem de ser a falha de gravação, não outra');

        self::assertCount(0, $this->documentosDaPasta($id));
    }

    // ----------------------------------------------------------------- helpers

    private function uploadDe(string $conteudo, string $nome): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/pasta_doc_' . bin2hex(random_bytes(6));
        file_put_contents($origem, $conteudo);
        $this->arquivosCriados[] = $origem;

        return new UploadedFile($origem, $nome, null, null, true);
    }

    /**
     * @param list<array{conteudo: string, nome: string}> $arquivos
     *
     * @return list<string> nomes que surgiram no diretório durante a requisição
     */
    private function enviar(KernelBrowser $client, int $pastaId, array $arquivos, bool $reduzirTamanho = false): array
    {
        $uploads = [];
        foreach ($arquivos as $i => $arquivo) {
            $uploads[$i] = $this->uploadDe($arquivo['conteudo'], $arquivo['nome']);
        }

        $antes = $this->arquivosNoDiretorio();

        $client->request(
            'POST',
            "/pasta/{$pastaId}/documento/upload",
            [
                '_token'     => 'TOKEN_upload_documento_pasta_' . $pastaId,
                'categorias' => array_fill(0, \count($arquivos), PastaDocumento::CATEGORIA_DEMAIS),
            ] + ($reduzirTamanho ? ['reduzir_tamanho' => '1'] : []),
            ['arquivos' => $uploads],
        );

        $novos = array_values(array_diff($this->arquivosNoDiretorio(), $antes));
        foreach ($novos as $novo) {
            $this->arquivosCriados[] = $this->diretorio() . '/' . $novo;
        }

        return $novos;
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

    private function diretorio(): string
    {
        return (string) static::getContainer()->getParameter('uploads_dir');
    }

    /** @return list<string> só arquivos: as subpastas de tenant (imagens do editor) não contam */
    private function arquivosNoDiretorio(): array
    {
        $diretorio = $this->diretorio();

        if (!is_dir($diretorio)) {
            return [];
        }

        return array_values(array_filter(
            array_diff(scandir($diretorio) ?: [], ['.', '..']),
            static fn (string $nome): bool => is_file($diretorio . '/' . $nome),
        ));
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

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PASTA UPLOAD ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('gestor_pasta_upload_' . uniqid() . '@test.com');
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel de sistema → gestor do tenant (passa em canAccessResource).
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('UPLOAD-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }
}
