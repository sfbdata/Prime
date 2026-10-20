<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PastaDocumentoController;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * As rotas que já existiam, chamadas do jeito que o peticionar-documentos.js chama (desenho 07):
 * renomear e classificar pelo pasta_documento_edit (form, XHR, token edit_documento_<id>, os quatro
 * campos), excluir pelo excluir-lote (JSON, token pex_lote_<pasta>, vai para a lixeira) e enviar
 * soltando arquivos pelo pasta_peticionar_upload (o mesmo corpo do modal "Adicionar peça").
 * E o outro escritório recusado em cada uma.
 */
#[CoversClass(PastaDocumentoController::class)]
#[CoversClass(PeticionarController::class)]
final class PeticionarDocumentosRotasTest extends JusPrimeWebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    private const PDF_MINIMO = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        $this->temporarios = [];

        parent::tearDown();
    }

    #[TestDox('Renomear: só a base do nome vai; a extensão, a descrição e o número ficam')]
    public function testRenomearComoOJsChama(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->documento($pasta, $tenant, 'peticao.pdf', 'texto da descrição', '12/2026');
        $id  = (int) $doc->getId();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token'    => 'TOKEN_edit_documento_' . $id,
            'nomeBase'  => 'Petição Inicial',
            'categoria' => PastaDocumento::CATEGORIA_PECA,
            'descricao' => 'texto da descrição',
            'numero'    => '12/2026',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $j = $this->json($client);
        self::assertTrue($j['ok']);
        self::assertSame('Petição Inicial.pdf', $j['documento']['nome']);

        $linha = $this->linhaDoBanco($id);
        self::assertSame('Petição Inicial.pdf', $linha['nome_original']);
        self::assertSame('texto da descrição', $linha['descricao']);
        self::assertSame('12/2026', $linha['numero']);
        self::assertSame(PastaDocumento::CATEGORIA_PECA, $linha['categoria']);
    }

    #[TestDox('Renomear a peça de texto mantém o ".html" e a tela volta a mostrar o nome sem ele')]
    public function testRenomearPecaDeTexto(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->documento($pasta, $tenant, 'Rascunho.html', null, null, 'text/html');
        $id  = (int) $doc->getId();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token' => 'TOKEN_edit_documento_' . $id, 'nomeBase' => 'Contestação', 'categoria' => PastaDocumento::CATEGORIA_PECA,
            'descricao' => '', 'numero' => '',
        ], [], self::XHR);
        self::assertResponseIsSuccessful();
        self::assertSame('Contestação.html', $this->json($client)['documento']['nome']);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();
        self::assertSame('Contestação', $crawler->filter(sprintf('#tlInner > .petic-tl-row[data-doc-id="%d"]', $id))->attr('data-titulo'));
    }

    #[TestDox('Classificar: troca só a categoria, o nome fica')]
    public function testClassificarComoOJsChama(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $id = (int) $this->documento($pasta, $tenant, 'procuracao.pdf')->getId();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token' => 'TOKEN_edit_documento_' . $id, 'nomeBase' => '', 'categoria' => PastaDocumento::CATEGORIA_PROCURACAO,
            'descricao' => '', 'numero' => '',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame(PastaDocumento::CATEGORIA_PROCURACAO, $this->json($client)['documento']['categoria']);
        $linha = $this->linhaDoBanco($id);
        self::assertSame(PastaDocumento::CATEGORIA_PROCURACAO, $linha['categoria']);
        self::assertSame('procuracao.pdf', $linha['nome_original']);
    }

    #[TestDox('Excluir: o documento vai para a lixeira e sai da lista do Peticionar')]
    public function testExcluirComoOJsChama(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $fica = $this->documento($pasta, $tenant, 'fica.pdf');
        $sai  = $this->documento($pasta, $tenant, 'sai.pdf');
        $idSai = (int) $sai->getId();

        $this->postJson($client, "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token' => 'TOKEN_pex_lote_' . $pasta->getId(), 'documentos' => [$idSai], 'secoes' => [],
        ]);

        self::assertResponseIsSuccessful();
        $j = $this->json($client);
        self::assertTrue($j['ok']);
        self::assertTrue($j['lixeira']);
        self::assertSame([$idSai], $j['ids']['documentos']);
        self::assertNotNull($this->linhaDoBanco($idSai)['excluido_em'], 'vai para a lixeira, não some do banco');

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('.petic-tl-row[data-doc-id="%d"]', $idSai)));
        self::assertCount(1, $crawler->filter(sprintf('.petic-tl-row[data-doc-id="%d"]', (int) $fica->getId())));
    }

    #[TestDox('Soltar arquivo: o mesmo corpo do modal, e a resposta traz o que a linha nova precisa')]
    public function testUploadPorArrastarComoOJsChama(): void
    {
        [$client, , , $pasta] = $this->cenario(armazenamentoEmMemoria: true);

        $client->request('POST', "/pasta/{$pasta->getId()}/peticionar/upload", [
            '_token'    => 'TOKEN_peticionar_upload_' . $pasta->getId(),
            'categoria' => PastaDocumento::CATEGORIA_PECA,
            'descricao' => '',
            'numero'    => '',
            'secao_id'  => '',
        ], ['arquivo' => $this->arquivo('solto na lista.pdf')]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $j = $this->json($client);
        self::assertTrue($j['success']);
        $doc = $j['documento'];
        foreach (['id', 'nome', 'tamanho', 'mime', 'categoria', 'viewUrl', 'downloadUrl', 'csrfEditar', 'uploadedAt'] as $chave) {
            self::assertArrayHasKey($chave, $doc, "a linha nova lê '{$chave}'");
        }
        self::assertSame('solto na lista.pdf', $doc['nome']);
        self::assertSame(PastaDocumento::CATEGORIA_PECA, $doc['categoria']);
        self::assertSame(\strlen(self::PDF_MINIMO), $doc['tamanho']);
    }

    #[TestDox('Outro escritório: renomear, excluir e soltar arquivo em pasta alheia são recusados e nada muda')]
    public function testOutroEscritorioRecusado(): void
    {
        [$client, , , ] = $this->cenario(armazenamentoEmMemoria: true);
        [$tenantAlheio, $pastaAlheia] = $this->pastaDeOutroEscritorio();
        $alheio = $this->documento($pastaAlheia, $tenantAlheio, 'alheio.pdf');
        $id     = (int) $alheio->getId();
        $pastaId = (int) $pastaAlheia->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token' => 'TOKEN_edit_documento_' . $id, 'nomeBase' => 'invadido', 'categoria' => PastaDocumento::CATEGORIA_PECA,
            'descricao' => '', 'numero' => '',
        ], [], self::XHR);
        self::assertResponseStatusCodeSame(404);

        $this->postJson($client, "/pasta/{$pastaId}/documentos/excluir-lote", [
            '_token' => 'TOKEN_pex_lote_' . $pastaId, 'documentos' => [$id], 'secoes' => [],
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pastaId}/peticionar/upload", [
            '_token' => 'TOKEN_peticionar_upload_' . $pastaId, 'categoria' => PastaDocumento::CATEGORIA_PECA,
            'descricao' => '', 'numero' => '', 'secao_id' => '',
        ], ['arquivo' => $this->arquivo('intruso.pdf')]);
        self::assertResponseStatusCodeSame(404);

        $linha = $this->linhaDoBanco($id);
        self::assertSame('alheio.pdf', $linha['nome_original']);
        self::assertNull($linha['excluido_em']);
        self::assertSame(1, (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :p',
            ['p' => $pastaId],
        ), 'nenhum documento entrou na pasta alheia');
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{KernelBrowser, User, Tenant, Pasta} */
    private function cenario(bool $armazenamentoEmMemoria = false): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        if ($armazenamentoEmMemoria) {
            ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        }

        $tenant = $this->tenant();
        $user   = $this->gestor($tenant);
        $pasta  = $this->pasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user, $tenant, $pasta];
    }

    /** @return array{Tenant, Pasta} */
    private function pastaDeOutroEscritorio(): array
    {
        $tenant = $this->tenant();

        return [$tenant, $this->pasta($tenant)];
    }

    private function tenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PET07R ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** Gestor de sistema: passa pelo PermissionChecker; quem fecha o outro escritório é a posse. */
    private function gestor(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('pet07r_' . uniqid() . '@test.com');
        $user->setFullName('Gestor PET07');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    private function pasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('PET07R-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function documento(Pasta $pasta, Tenant $tenant, string $nome, ?string $descricao = null, ?string $numero = null, string $mime = 'application/pdf'): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo($nome);
        $doc->setNomeOriginal($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
        $doc->setCaminhoArquivo('pet07r_' . uniqid() . '.bin');
        $doc->setMimeType($mime);
        $doc->setTamanhoBytes(2048);
        $doc->setDescricao($descricao);
        $doc->setNumero($numero);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function arquivo(string $nome): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/pet07r_' . bin2hex(random_bytes(6));
        file_put_contents($origem, self::PDF_MINIMO);
        $this->temporarios[] = $origem;

        return new UploadedFile($origem, $nome, 'application/pdf', null, true);
    }

    /** @param array<string, mixed> $corpo */
    private function postJson(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], self::XHR + ['CONTENT_TYPE' => 'application/json'], json_encode($corpo, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $j = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($j);

        return $j;
    }

    /** Direto do banco, sem filtro de lixeira: é a coluna que importa. @return array<string, mixed> */
    private function linhaDoBanco(int $id): array
    {
        $linha = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT nome_original, descricao, numero, categoria, excluido_em FROM pasta_documento WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($linha);

        return $linha;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
