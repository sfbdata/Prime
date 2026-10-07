<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * D-DOC-RO: as cinco rotas que recebem só o id da FILHA (documento, seção) — e por isso escapavam
 * do portão — agora recebem a mesma recusa na pasta excluída (lápide), sem efeito no banco; na
 * pasta viva funcionam como sempre. E o .zip, que é leitura por POST, passa na pasta riscada.
 *
 * O efeito é conferido por SQL cru (DBAL): o EM compartilhado carrega o identity map e o
 * `LixeiraFilter`, e uma releitura por ele poderia mostrar memória, não o banco.
 */
#[CoversClass(PastaSomenteLeituraListener::class)]
final class PastaSomenteLeituraPelaFilhaTest extends JusPrimeWebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    /** @var list<ChaveDeArquivo> */
    private array $arquivosGravados = [];

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

        parent::tearDown();
    }

    // ── pasta_secao_renomear ─────────────────────────────────────────────────

    #[TestDox('renomear seção: pasta riscada recusa (403 JSON) e o nome não muda')]
    public function testRenomearSecaoNaPastaRiscadaRecusa(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $secao = $this->criarSecao($this->criarPasta($tenant, true, $user), $tenant, 'Original');
        $id    = (int) $secao->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/secao/{$id}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $id),
            'nome'   => 'Invadido',
        ], [], self::XHR);

        $this->assertRecusaDaLapide($client);
        self::assertSame('ORIGINAL', $this->coluna('pasta_secao', 'nome', $id));
    }

    #[TestDox('renomear seção: pasta viva continua renomeando')]
    public function testRenomearSecaoNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $secao = $this->criarSecao($this->criarPasta($tenant, false, $user), $tenant, 'Original');
        $id    = (int) $secao->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/secao/{$id}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $id),
            'nome'   => 'Novo nome',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame('NOVO NOME', $this->coluna('pasta_secao', 'nome', $id));
    }

    #[TestDox('renomear seção de OUTRO escritório numa pasta riscada: não devolve a recusa da lápide (não confirma a pasta alheia) e nada muda')]
    public function testRenomearSecaoDeOutroEscritorioNaoVazaALapide(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        [$donoB, $tenantB]        = $this->criarUsuarioAdmin();
        $secaoB = $this->criarSecao($this->criarPasta($tenantB, true, $donoB), $tenantB, 'Alheia');
        $id     = (int) $secaoB->getId();
        $intruso = $this->criarUsuarioComum($tenant);
        $this->logarComTenant($client, $intruso, $tenant);
        $this->em()->clear();

        $client->request('POST', "/pasta/secao/{$id}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $id),
            'nome'   => 'Invadido',
        ], [], self::XHR);

        self::assertContains($client->getResponse()->getStatusCode(), [403, 404]);
        self::assertStringNotContainsString('somente para leitura', (string) $client->getResponse()->getContent());
        self::assertSame('ALHEIA', $this->coluna('pasta_secao', 'nome', $id));
    }

    // ── pasta_secao_excluir ──────────────────────────────────────────────────

    #[TestDox('excluir seção: pasta riscada recusa e a seção não vai para a lixeira')]
    public function testExcluirSecaoNaPastaRiscadaRecusa(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $secao = $this->criarSecao($this->criarPasta($tenant, true, $user), $tenant, 'Provas');
        $id    = (int) $secao->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/secao/{$id}/excluir", [
            '_token' => $this->csrf('pasta_secao_excluir_' . $id),
        ], [], self::XHR);

        $this->assertRecusaDaLapide($client);
        self::assertNull($this->coluna('pasta_secao', 'excluido_em', $id));
    }

    #[TestDox('excluir seção: pasta viva continua mandando para a lixeira')]
    public function testExcluirSecaoNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $secao = $this->criarSecao($this->criarPasta($tenant, false, $user), $tenant, 'Provas');
        $id    = (int) $secao->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/secao/{$id}/excluir", [
            '_token' => $this->csrf('pasta_secao_excluir_' . $id),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->coluna('pasta_secao', 'excluido_em', $id));
    }

    // ── pasta_secao_mover ────────────────────────────────────────────────────

    #[TestDox('mover seção: pasta riscada recusa e o pai não muda')]
    public function testMoverSecaoNaPastaRiscadaRecusa(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta   = $this->criarPasta($tenant, true, $user);
        $secao   = $this->criarSecao($pasta, $tenant, 'Filha');
        $destino = $this->criarSecao($pasta, $tenant, 'Destino');
        $id      = (int) $secao->getId();
        $destId  = (int) $destino->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/secao/{$id}/mover", [
            '_token'    => $this->csrf('pasta_secao_mover_' . $id),
            'destinoId' => (string) $destId,
        ], [], self::XHR);

        $this->assertRecusaDaLapide($client);
        self::assertNull($this->coluna('pasta_secao', 'secao_pai_id', $id));
    }

    #[TestDox('mover seção: pasta viva continua movendo')]
    public function testMoverSecaoNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta   = $this->criarPasta($tenant, false, $user);
        $secao   = $this->criarSecao($pasta, $tenant, 'Filha');
        $destino = $this->criarSecao($pasta, $tenant, 'Destino');
        $id      = (int) $secao->getId();
        $destId  = (int) $destino->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/secao/{$id}/mover", [
            '_token'    => $this->csrf('pasta_secao_mover_' . $id),
            'destinoId' => (string) $destId,
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame($destId, (int) $this->coluna('pasta_secao', 'secao_pai_id', $id));
    }

    // ── pasta_documento_mover_secao ──────────────────────────────────────────

    #[TestDox('mover documento para seção: pasta riscada recusa e o documento fica onde estava')]
    public function testMoverDocumentoNaPastaRiscadaRecusa(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta = $this->criarPasta($tenant, true, $user);
        $secao = $this->criarSecao($pasta, $tenant, 'Destino');
        $doc   = $this->criarDocumento($pasta, $tenant, 'peca.pdf');
        $id    = (int) $doc->getId();
        $secId = (int) $secao->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/documento/{$id}/mover-secao", [
            '_token'   => $this->csrf('pasta_doc_mover_' . $id),
            'secao_id' => (string) $secId,
        ], [], self::XHR);

        $this->assertRecusaDaLapide($client);
        self::assertNull($this->coluna('pasta_documento', 'secao_id', $id));
    }

    #[TestDox('mover documento para seção: pasta viva continua movendo')]
    public function testMoverDocumentoNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta = $this->criarPasta($tenant, false, $user);
        $secao = $this->criarSecao($pasta, $tenant, 'Destino');
        $doc   = $this->criarDocumento($pasta, $tenant, 'peca.pdf');
        $id    = (int) $doc->getId();
        $secId = (int) $secao->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/documento/{$id}/mover-secao", [
            '_token'   => $this->csrf('pasta_doc_mover_' . $id),
            'secao_id' => (string) $secId,
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame($secId, (int) $this->coluna('pasta_documento', 'secao_id', $id));
    }

    // ── pasta_documento_edit ─────────────────────────────────────────────────

    #[TestDox('editar documento: pasta riscada recusa (403 JSON) e o documento não muda')]
    public function testEditarDocumentoNaPastaRiscadaRecusaPorXhr(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $doc = $this->criarDocumento($this->criarPasta($tenant, true, $user), $tenant, 'contrato.pdf');
        $id  = (int) $doc->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token'    => $this->csrf('edit_documento_' . $id),
            'nomeBase'  => 'invadido',
            'descricao' => 'invadido',
        ], [], self::XHR);

        $this->assertRecusaDaLapide($client);
        self::assertSame('contrato.pdf', $this->coluna('pasta_documento', 'nome_original', $id));
        self::assertNull($this->coluna('pasta_documento', 'descricao', $id));
    }

    #[TestDox('editar documento sem JS: pasta riscada devolve para a pasta com o aviso, sem gravar')]
    public function testEditarDocumentoNaPastaRiscadaRecusaSemXhr(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta = $this->criarPasta($tenant, true, $user);
        $doc   = $this->criarDocumento($pasta, $tenant, 'contrato.pdf');
        $id    = (int) $doc->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token'   => $this->csrf('edit_documento_' . $id),
            'nomeBase' => 'invadido',
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());
        self::assertSame('contrato.pdf', $this->coluna('pasta_documento', 'nome_original', $id));
    }

    #[TestDox('editar documento: pasta viva continua editando')]
    public function testEditarDocumentoNaPastaVivaFunciona(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $doc = $this->criarDocumento($this->criarPasta($tenant, false, $user), $tenant, 'contrato.pdf');
        $id  = (int) $doc->getId();
        $this->em()->clear();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token'    => $this->csrf('edit_documento_' . $id),
            'nomeBase'  => 'Contrato final',
            'categoria' => PastaDocumento::CATEGORIA_PROCURACAO,
            'descricao' => 'Assinado',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame('Contrato final.pdf', $this->coluna('pasta_documento', 'nome_original', $id));
    }

    // ── pasta_documentos_zip (leitura por POST) ──────────────────────────────

    #[TestDox('zip na pasta riscada é liberado: baixar é consultar')]
    public function testZipNaPastaRiscadaEPermitido(): void
    {
        [$client, $user, $tenant] = $this->preparar();
        $pasta = $this->criarPasta($tenant, true, $user);
        $doc   = $this->criarDocumento($pasta, $tenant, 'peca.pdf', $this->gravarArquivo($tenant, 'bytes da peça'));
        $this->em()->clear();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/zip", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $doc->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('PK', (string) $client->getInternalResponse()->getContent());
    }

    // ── apoio ────────────────────────────────────────────────────────────────

    private function assertRecusaDaLapide(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(403);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('erro', $dados['status'] ?? null);
        self::assertStringContainsString('somente para leitura', (string) ($dados['mensagem'] ?? ''));
    }

    /** @return array{KernelBrowser, User, Tenant} */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user, $tenant];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function coluna(string $tabela, string $coluna, int $id): mixed
    {
        $linha = $this->em()->getConnection()->fetchAssociative(
            sprintf('SELECT %s AS valor FROM %s WHERE id = :id', $coluna, $tabela),
            ['id' => $id],
        );
        self::assertIsArray($linha, "{$tabela}#{$id} sumiu do banco");

        return $linha['valor'];
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lapide Filha ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('lapide_filha_' . uniqid() . '@test.com');
        $user->setFullName('Admin Lapide');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    /** Papel comum (não-sistema) sem acesso a recurso nenhum. */
    private function criarUsuarioComum(Tenant $tenant): User
    {
        $em = $this->em();

        $user = new User();
        $user->setEmail('comum_lapide_' . uniqid() . '@test.com');
        $user->setFullName('Comum');
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

    private function criarPasta(Tenant $tenant, bool $excluida, User $autor): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup(($excluida ? 'LAPIDE-' : 'VIVA-') . uniqid());
        $pasta->setTenant($tenant);

        if ($excluida) {
            $pasta->marcarExcluida($autor, new \DateTimeImmutable());
        }

        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, string $nome, ?string $caminho = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($caminho ?? 'fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    /** Arquivo DE VERDADE pela chave: o .zip lê os bytes. */
    private function gravarArquivo(Tenant $tenant, string $conteudo): string
    {
        $chave = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePasta::novoDocumento((new PastaDocumento())->setTenant($tenant), 'pdf'),
            FonteDeConteudo::deTexto($conteudo),
        )->chave;
        $this->arquivosGravados[] = $chave;

        return $chave->nome;
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
