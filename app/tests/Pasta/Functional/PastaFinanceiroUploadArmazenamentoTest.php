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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Upload de documento pelo Financeiro da pasta (`POST /pasta/{id}/financeiro/upload`) contra o
 * storage: chave (R1), sha256, hash pós-compressão e falha de gravação. O dublê em memória é o
 * único jeito de ver o escopo da chave e de provocar falha de gravação.
 *
 * Até o DOC-124 estes casos corriam "nas duas rotas": esta e o upload legado
 * `pasta_documento_upload`, que só servia a um bloco `{% if false %}` da pasta_show e foi
 * removido com ele. A aba Documentos envia por `pasta_peticionar_upload` (Peticionar*Test).
 */
#[CoversClass(PastaController::class)]
final class PastaFinanceiroUploadArmazenamentoTest extends JusPrimeWebTestCase
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

    private function pdfGordo(): string
    {
        $temporario = sys_get_temp_dir() . '/gordo_' . bin2hex(random_bytes(6)) . '.pdf';
        $this->arquivosCriados[] = $temporario;

        return GhostscriptDeTeste::pdfGordo($temporario);
    }

    /**
     * R1: no disco o escopo da chave não aparece (a categoria é plana). Contra o dublê, a chave
     * gravada tem de ser exatamente a que a leitura monta a partir do documento persistido.
     */
    #[TestDox('R1: a chave gravada é a mesma que a leitura monta a partir do documento')]
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

        foreach (['contrato.pdf' => $peloFinanceiro] as $nome => $gravada) {
            $doc = $porNome[$nome] ?? self::fail('documento não registrado: ' . $nome);

            self::assertTrue($gravada->ehIgualA(ChavesDePasta::documento($doc)), $nome . ': gravação e leitura divergem');
            self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
            self::assertSame($tenant->getId(), $gravada->escopo->tenantIdOuNull());
            self::assertTrue($duble->memoria->existe(ChavesDePasta::documento($doc)));
        }
    }

    #[TestDox('sha256: o documento nasce com o hash do conteúdo que o storage guardou')]
    public function testSha256GravadoNasDuasRotas(): void
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

        $client->request(
            'POST',
            "/pasta/{$id}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $id],
            ['arquivo' => $this->uploadDe(self::PDF . '% contrato', 'contrato.pdf')],
        );
        self::assertResponseStatusCodeSame(201);

        $porNome = [];
        foreach ($this->documentosDaPasta($id) as $doc) {
            $porNome[$doc->getNomeOriginal()] = $doc;
        }

        foreach (['contrato.pdf' => self::PDF . '% contrato'] as $nome => $conteudo) {
            $doc = $porNome[$nome] ?? self::fail('documento não registrado: ' . $nome);

            self::assertSame(hash('sha256', $conteudo), $doc->getSha256(), $nome);
            self::assertSame(
                hash('sha256', $duble->memoria->ler(ChavesDePasta::documento($doc))),
                $doc->getSha256(),
                $nome . ': o hash tem de descrever o que está no storage',
            );
        }
    }

    #[TestDox('hash pós-compressão: se a releitura falhar, o upload segue com sha256 null')]
    public function testFalhaAoRelerOComprimidoNaoDerrubaOUpload(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }

        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        // A compressão usa cópia gravável + gravar(); só o hash pós-compressão passa por abrir().
        $duble->memoria->falhaAoAbrir = new FalhaDeArmazenamento('leitura indisponível');
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta  = $this->criarPasta($tenant);
        $id     = (int) $pasta->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestor, $tenant);

        $gordo = $this->pdfGordo();

        $client->request(
            'POST',
            "/pasta/{$id}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $id, 'reduzir_tamanho' => '1'],
            ['arquivo' => $this->uploadDe($gordo, 'contrato.pdf')],
        );
        self::assertResponseStatusCodeSame(201);

        $documentos = $this->documentosDaPasta($id);
        self::assertCount(1, $documentos);
        foreach ($documentos as $doc) {
            self::assertNull($doc->getSha256(), $doc->getNomeOriginal() . ': sem hash legível, fica null');
            self::assertLessThan(\strlen($gordo), $doc->getTamanhoBytes(), $doc->getNomeOriginal() . ': a compressão aconteceu');
        }
    }

    #[TestDox('falha do storage: nenhum documento é registrado')]
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
