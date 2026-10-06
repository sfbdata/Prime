<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
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
 * O que `POST /pasta/{id}/peticionar/upload` passou a gravar (D1: quem enviou, páginas) e a
 * responder (a forma do explorador, para a linha entrar sem recarregar — L5) — sem perder os
 * campos que já respondia.
 *
 * O storage é o dublê em memória; a contagem de páginas roda no temporário do upload, com o
 * Ghostscript real (o caso do PDF real é pulado onde o binário não existe).
 */
#[CoversClass(PeticionarController::class)]
final class PeticionarUploadMetadadosControllerTest extends JusPrimeWebTestCase
{
    private const PDF_MINIMO = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

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

    #[TestDox('D1: o documento nasce com enviado_por = quem fez o upload')]
    public function testUploadGravaQuemEnviou(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();

        $data = $this->enviar($client, (int) $pasta->getId(), self::PDF_MINIMO, 'procuracao.pdf', 'application/pdf');

        self::assertSame($user->getId(), (int) $this->coluna((int) $data['documento']['id'], 'enviado_por_id'));
        self::assertSame('Usuário Upload', $data['documento']['enviadoPor']);
        self::assertNull($this->coluna((int) $data['documento']['id'], 'modificado_em'), 'upload não é modificação');
    }

    #[TestDox('D1: PDF real de 3 páginas é gravado com paginas = 3 e a resposta diz 3')]
    public function testPdfRealContaAsPaginas(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }

        [$client, , , $pasta] = $this->cenario();
        $origem = sys_get_temp_dir() . '/petic_meta_' . bin2hex(random_bytes(6)) . '.pdf';
        GhostscriptDeTeste::pdfReal($origem, 3);
        $this->arquivosCriados[] = $origem;

        $data = $this->enviar($client, (int) $pasta->getId(), (string) file_get_contents($origem), 'tres-paginas.pdf', 'application/pdf');

        self::assertSame(3, $data['documento']['paginas']);
        self::assertSame(3, (int) $this->coluna((int) $data['documento']['id'], 'paginas'));
    }

    #[TestDox('D1: o que não é PDF fica com paginas NULL')]
    public function testNaoPdfFicaSemPaginas(): void
    {
        [$client, , , $pasta] = $this->cenario();

        $data = $this->enviar($client, (int) $pasta->getId(), "uma nota de texto\n", 'nota.txt', 'text/plain');

        self::assertNull($data['documento']['paginas']);
        self::assertNull($this->coluna((int) $data['documento']['id'], 'paginas'));
    }

    #[TestDox('a resposta traz a forma do explorador (nome, tamanho, seção, ordem, URLs, tokens) SEM perder os campos antigos')]
    public function testRespostaTrazAFormaDoExploradorEOsCamposAntigos(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $secao = $this->criarSecao($pasta, $tenant);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $data = $this->enviar($client, (int) $pasta->getId(), self::PDF_MINIMO, 'contrato assinado.pdf', 'application/pdf', (int) $secao->getId());
        $doc  = $data['documento'];
        $id   = (int) $doc['id'];

        // A forma do explorador — é o que o L5 usa para inserir a linha sem recarregar.
        self::assertSame('contrato assinado.pdf', $doc['nome']);
        self::assertSame(\strlen(self::PDF_MINIMO), $doc['tamanho']);
        self::assertSame('application/pdf', $doc['mime']);
        self::assertSame($secao->getId(), $doc['secaoId']);
        self::assertSame(0, $doc['ordem']);
        self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $doc['categoria']);
        self::assertSame('Demais documentos', $doc['categoriaRotulo']);
        self::assertSame("/pasta/documento/{$id}/visualizar", $doc['viewUrl']);
        self::assertSame("/pasta/documento/{$id}/download", $doc['downloadUrl']);
        self::assertSame("/pasta/documento/{$id}/mover-secao", $doc['urlMover']);
        self::assertSame('TOKEN_pasta_doc_mover_' . $id, $doc['csrfMover']);
        self::assertSame('TOKEN_edit_documento_' . $id, $doc['csrfEditar']);
        self::assertSame('TOKEN_delete_documento_' . $id, $doc['csrfExcluir']);
        self::assertSame(hash('sha256', self::PDF_MINIMO), $doc['sha256']);
        self::assertArrayHasKey('carregadoEm', $doc);
        self::assertArrayHasKey('modificadoEm', $doc);

        // Os campos de sempre, com os mesmos valores.
        self::assertSame('CONTRATO ASSINADO.PDF', $doc['titulo']);
        self::assertSame('application/pdf', $doc['mimeType']);
        self::assertMatchesRegularExpression('~^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$~', $doc['uploadedAt']);
        self::assertArrayHasKey('duplicadoDe', $data);
        self::assertArrayHasKey('compressao', $data);
        self::assertTrue($data['success']);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{KernelBrowser, User, Tenant, Pasta} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant META ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('meta_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Upload');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('META-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user, $tenant, $pasta];
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant): PastaSecao
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome('Procurações');
        $secao->setOrdem(1);
        $em->persist($secao);
        $em->flush();

        return $secao;
    }

    /** @return array<string, mixed> JSON decodificado da resposta 200 */
    private function enviar(KernelBrowser $client, int $pastaId, string $conteudo, string $nome, string $mime, ?int $secaoId = null): array
    {
        $origem = sys_get_temp_dir() . '/petic_meta_' . bin2hex(random_bytes(6));
        file_put_contents($origem, $conteudo);
        $this->arquivosCriados[] = $origem;

        $campos = ['_token' => 'TOKEN_peticionar_upload_' . $pastaId, 'categoria' => PastaDocumento::CATEGORIA_DEMAIS];
        if ($secaoId !== null) {
            $campos['secao_id'] = (string) $secaoId;
        }

        $client->request(
            'POST',
            "/pasta/{$pastaId}/peticionar/upload",
            $campos,
            ['arquivo' => new UploadedFile($origem, $nome, $mime, null, true)],
        );

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['success']);

        return $data;
    }

    /** Direto do banco: é a coluna que importa, não o objeto em memória. */
    private function coluna(int $documentoId, string $coluna): mixed
    {
        $valor = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            sprintf('SELECT %s FROM pasta_documento WHERE id = :id', $coluna),
            ['id' => $documentoId],
        );

        return $valor === false ? null : $valor;
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
