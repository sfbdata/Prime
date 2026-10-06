<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Controller\PastaDocumentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\UseCase\MontarZipDeDocumentosUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoGeradoParaEntrega;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * `POST /pasta/{id}/documentos/zip` (D5): o .zip de verdade é lido de volta (estrutura, bytes,
 * LEIA-ME), a permissão é a de VER, a lixeira nunca entra, e os guardas são os do lote — pasta
 * irmã e outro escritório 404, CSRF 400, sem ver 403, teto 422 antes de abrir arquivo.
 */
#[CoversClass(PastaDocumentoController::class)]
final class PastaDocumentoZipControllerTest extends JusPrimeWebTestCase
{
    /** @var list<ChaveDeArquivo> arquivos reais gravados no disco de teste — o rollback do banco não os limpa */
    private array $arquivosGravados = [];

    /** @var list<string> .zips copiados da resposta para leitura */
    private array $zipsLidos = [];

    protected function tearDown(): void
    {
        if ($this->arquivosGravados !== []) {
            $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
            foreach ($this->arquivosGravados as $chave) {
                if ($armazenamento->existe($chave)) {
                    $armazenamento->excluir($chave);
                }
            }
            $this->arquivosGravados = [];
        }
        foreach ($this->zipsLidos as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        parent::tearDown();
    }

    #[TestDox('zip: documentos da raiz e a subárvore da subpasta, com estrutura; lixeira e não selecionados ficam de fora; LEIA-ME; auditoria; o temporário some depois de enviado')]
    public function testZipComEstrutura(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'Provas');
        $b               = $this->criarSecao($pasta, $tenant, 'Fotos', $a);
        $raiz            = $this->criarDocumento($pasta, $tenant, null, 'raiz.pdf', $this->gravarArquivo($tenant, 'bytes raiz'));
        $emA             = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf', $this->gravarArquivo($tenant, 'bytes a'));
        $this->criarDocumento($pasta, $tenant, $b, 'em-b.jpg', $this->gravarArquivo($tenant, 'bytes b'));
        $naLixeira       = $this->criarDocumento($pasta, $tenant, $a, 'apagado.pdf', $this->gravarArquivo($tenant, 'bytes lixeira'));
        $this->criarDocumento($pasta, $tenant, null, 'fica.pdf', $this->gravarArquivo($tenant, 'não selecionado'));
        $this->marcarNaLixeira($naLixeira, $user);
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();
        ArquivoGeradoParaEntrega::limparSobras('zip'); // a montagem limpa sobras velhas: a foto de "antes" tem de ser tirada já limpa
        $temporariosAntes = $this->temporariosDoZip();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $raiz->getId()],
            'secoes'     => [(string) $a->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            'attachment; filename=documentos-' . $pasta->getNup() . '.zip',
            $client->getResponse()->headers->get('Content-Disposition'),
        );

        $entradas = $this->entradasDoZip($client);
        self::assertSame(['LEIA-ME.txt', 'Provas/Fotos/em-b.jpg', 'Provas/em-a.pdf', 'raiz.pdf'], array_keys($entradas));
        self::assertSame('bytes raiz', $entradas['raiz.pdf']);
        self::assertSame('bytes a', $entradas['Provas/em-a.pdf']);
        self::assertSame('bytes b', $entradas['Provas/Fotos/em-b.jpg']);
        self::assertStringContainsString('Documentos da pasta ' . $pasta->getNup(), $entradas['LEIA-ME.txt']);
        self::assertStringContainsString('por Admin Documentos', $entradas['LEIA-ME.txt']);
        self::assertStringContainsString('Arquivos incluídos: 3', $entradas['LEIA-ME.txt']);
        self::assertStringNotContainsString('apagado.pdf', $entradas['LEIA-ME.txt'], 'o que está na lixeira não existe para o .zip');
        self::assertStringNotContainsString('fica.pdf', $entradas['LEIA-ME.txt']);

        self::assertSame($temporariosAntes, $this->temporariosDoZip(), 'deleteFileAfterSend: nada sobra no diretório privado do processo');

        $auditoria = $this->em()->getConnection()->fetchAssociative(
            'SELECT actor_user_id, tenant_id, changes FROM audit_log WHERE action = :acao AND entity_class = :classe AND entity_id = :id',
            ['acao' => MontarZipDeDocumentosUseCase::ACAO_AUDITORIA, 'classe' => Pasta::class, 'id' => (string) $pasta->getId()],
        );
        self::assertIsArray($auditoria, 'o download em .zip deixa rastro no audit_log');
        self::assertSame($user->getId(), (int) $auditoria['actor_user_id']);
        self::assertSame($tenant->getId(), (int) $auditoria['tenant_id']);
        $mudancas = json_decode((string) $auditoria['changes'], true);
        self::assertSame([$raiz->getId()], $mudancas['documentos']);
        self::assertSame([$a->getId()], $mudancas['secoes']);
        self::assertSame(3, $mudancas['arquivos']);
        self::assertSame(0, $mudancas['nao_encontrados']);
        self::assertSame(strlen('bytes raiz') + strlen('bytes a') + strlen('bytes b'), $mudancas['bytes']);
    }

    #[TestDox('zip: basta VER a pasta — o leitor baixa; nomes repetidos ficam únicos')]
    public function testLeitorBaixaENomesUnicos(): void
    {
        $client          = $this->cliente();
        [, $tenant]      = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $um              = $this->criarDocumento($pasta, $tenant, null, 'rel.pdf', $this->gravarArquivo($tenant, 'um'));
        $dois            = $this->criarDocumento($pasta, $tenant, null, 'rel.pdf', $this->gravarArquivo($tenant, 'dois'));
        $leitor          = $this->criarUsuarioSoLeitura($tenant, (int) $pasta->getId());
        $this->logarComTenant($client, $leitor, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $um->getId(), (string) $dois->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $entradas = $this->entradasDoZip($client);
        self::assertSame(['LEIA-ME.txt', 'rel (2).pdf', 'rel.pdf'], array_keys($entradas));
        self::assertSame('um', $entradas['rel.pdf']);
        self::assertSame('dois', $entradas['rel (2).pdf']);
    }

    #[TestDox('zip: documento sem arquivo no armazenamento não aborta — fica fora e entra no LEIA-ME como não encontrado')]
    public function testArquivoAusenteNoStorage(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $ok              = $this->criarDocumento($pasta, $tenant, null, 'ok.pdf', $this->gravarArquivo($tenant, 'ok'));
        $sumido          = $this->criarDocumento($pasta, $tenant, null, 'sumido.pdf'); // caminho que não existe no disco
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $ok->getId(), (string) $sumido->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $entradas = $this->entradasDoZip($client);
        self::assertSame(['LEIA-ME.txt', 'ok.pdf'], array_keys($entradas));
        self::assertStringContainsString('Não encontrados no armazenamento: 1', $entradas['LEIA-ME.txt']);
        self::assertStringContainsString('  - sumido.pdf', $entradas['LEIA-ME.txt']);
    }

    #[TestDox('zip: acima de 1 GB pela soma de tamanho_bytes → 422 com a mensagem, sem auditoria')]
    public function testTetoDeBytes(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $ids             = [];
        foreach (['a', 'b', 'c'] as $nome) {
            $ids[] = (string) $this->criarDocumento($pasta, $tenant, null, "{$nome}.pdf", null, 400 * 1024 * 1024)->getId();
        }
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => $ids,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('limite', (string) $this->json($client)['erro']);
        self::assertSame(0, $this->auditoriasDeZip((int) $pasta->getId()));
    }

    #[TestDox('zip: item na lixeira na seleção (documento ou subpasta) → 404; só subpasta vazia → 422')]
    public function testLixeiraESelecaoSemArquivos(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $vazia           = $this->criarSecao($pasta, $tenant, 'Vazia');
        $apagada         = $this->criarSecao($pasta, $tenant, 'Apagada');
        $naLixeira       = $this->criarDocumento($pasta, $tenant, null, 'apagado.pdf', $this->gravarArquivo($tenant, 'x'));
        $this->marcarNaLixeira($naLixeira, $user);
        $this->em()->getConnection()->executeStatement(
            'UPDATE pasta_secao SET excluido_em = :em, excluido_por_id = :por WHERE id = :id',
            ['em' => '2026-10-07 10:00:00', 'por' => $user->getId(), 'id' => (int) $apagada->getId()],
        );
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();
        $token = $this->csrf('pex_lote_' . $pasta->getId());

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => $token, 'documentos' => [(string) $naLixeira->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => $token, 'secoes' => [(string) $apagada->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => $token, 'secoes' => [(string) $vazia->getId()]]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->auditoriasDeZip((int) $pasta->getId()));
    }

    #[TestDox('zip: documento de PASTA IRMÃ na seleção 404; pasta de OUTRO escritório 404; CSRF 400; sem permissão de VER 403; seleção vazia 422')]
    public function testRecusas(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf', $this->gravarArquivo($tenant, 'meu'));
        $daIrma          = $this->criarDocumento($irma, $tenant, null, 'da-irma.pdf', $this->gravarArquivo($tenant, 'irmã'));
        $alheio          = $this->criarDocumento($alheia, $outro, null, 'alheio.pdf', $this->gravarArquivo($outro, 'alheio'));
        $semAcesso       = $this->criarUsuarioSemAcesso($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();
        $token = $this->csrf('pex_lote_' . $pasta->getId());

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => $token, 'documentos' => [(string) $meu->getId(), (string) $daIrma->getId()]]);
        self::assertResponseStatusCodeSame(404, 'pasta irmã: sem efeito parcial — nem o meu sai');

        $client->request('POST', "/pasta/{$alheia->getId()}/documentos/zip", ['_token' => $this->csrf('pex_lote_' . $alheia->getId()), 'documentos' => [(string) $alheio->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => $token, 'documentos' => [(string) $alheio->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => 'errado', 'documentos' => [(string) $meu->getId()]]);
        self::assertResponseStatusCodeSame(400);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);

        $this->logarComTenant($client, $semAcesso, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", ['_token' => $token, 'documentos' => [(string) $meu->getId()]]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->auditoriasDeZip((int) $pasta->getId()));
        self::assertSame(0, $this->auditoriasDeZip((int) $alheia->getId()));
    }

    // ----------------------------------------------------------------- helpers

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function limpar(): void
    {
        $this->em()->clear();
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Zip ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('zip_' . uniqid() . '@test.com');
        $user->setFullName('Admin Documentos');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    /** Papel comum com acesso de VER a pasta, sem editar. */
    private function criarUsuarioSoLeitura(Tenant $tenant, int $pastaId): User
    {
        $user = $this->criarUsuarioSemAcesso($tenant);

        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId($pastaId);
        $acesso->setCanView(true);
        $acesso->setCanEdit(false);
        $this->em()->persist($acesso);
        $this->em()->flush();

        return $user;
    }

    /** Papel comum (não-sistema) sem acesso a recurso nenhum. */
    private function criarUsuarioSemAcesso(Tenant $tenant): User
    {
        $em = $this->em();

        $user = new User();
        $user->setEmail('semacesso_' . uniqid() . '@test.com');
        $user->setFullName('Sem Acesso');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('ZIP-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        if ($pai !== null) {
            $secao->setPai($pai);
        }
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nome, ?string $caminho = null, ?int $tamanho = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($caminho ?? 'fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes($tamanho ?? 10);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setSecao($secao);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    /** Grava um arquivo DE VERDADE pela chave e devolve o nome cunhado (vai em `caminho_arquivo`). */
    private function gravarArquivo(Tenant $tenant, string $conteudo): string
    {
        $chave = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePasta::novoDocumento((new PastaDocumento())->setTenant($tenant), 'pdf'),
            FonteDeConteudo::deTexto($conteudo),
        )->chave;
        $this->arquivosGravados[] = $chave;

        return $chave->nome;
    }

    /** Lápide gravada por SQL: o EM compartilhado ainda carrega o filtro do último request. */
    private function marcarNaLixeira(PastaDocumento $doc, User $por): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE pasta_documento SET excluido_em = :em, excluido_por_id = :por WHERE id = :id',
            ['em' => '2026-10-07 10:00:00', 'por' => $por->getId(), 'id' => (int) $doc->getId()],
        );
    }

    /**
     * O .zip que o navegador receberia: o `KernelBrowser` chama `sendContent()` ao filtrar a resposta
     * (é o que faz o `deleteFileAfterSend` disparar no teste), e os bytes ficam na resposta interna.
     *
     * @return array<string, string> nome da entrada => conteúdo, em ordem alfabética
     */
    private function entradasDoZip(KernelBrowser $client): array
    {
        $bytes = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith('PK', $bytes, 'a resposta é um .zip');

        $caminho = tempnam(sys_get_temp_dir(), 'zip-teste-');
        self::assertNotFalse($caminho);
        $this->zipsLidos[] = $caminho;
        self::assertNotFalse(file_put_contents($caminho, $bytes));

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($caminho));
        $entradas = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entradas[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        ksort($entradas);

        return $entradas;
    }

    /** @return list<string> */
    private function temporariosDoZip(): array
    {
        $nomes = array_values(array_diff(scandir(DiretorioTemporarioPrivado::doProcesso('zip')->caminho()) ?: [], ['.', '..']));
        sort($nomes);

        return $nomes;
    }

    private function auditoriasDeZip(int $pastaId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :acao AND entity_class = :classe AND entity_id = :id',
            ['acao' => MontarZipDeDocumentosUseCase::ACAO_AUDITORIA, 'classe' => Pasta::class, 'id' => (string) $pastaId],
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($json, (string) $client->getResponse()->getContent());

        return $json;
    }

    private function csrf(string $id): string
    {
        return 'TOKEN_' . $id;
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
