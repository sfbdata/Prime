<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\PastaDocumentoAssinaturaController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Enum\OrigemDaAssinatura;
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
 * Selo "Assinado" por arquivo na aba Financeiro (desenho 02 VS 18.10.26 L1974-1976).
 *
 * - tela: o selo é filho direto da linha, entre o corpo (nome) e as ações (⋮); o ⋮ ganha "Marcar como
 *   assinado" ou, na marcação manual, "Desmarcar assinatura"; a assinatura digital não ganha item;
 * - rotas: marcar e desmarcar com CSRF, tenant (404), permissão de editar (403) e lápide (403);
 * - upload: PDF com assinatura digital embutida nasce com o selo.
 */
#[CoversClass(PastaDocumentoAssinaturaController::class)]
#[CoversClass(PastaController::class)]
final class PastaFinanceiroAssinaturaTest extends JusPrimeWebTestCase
{
    private const PDF_ASSINADO = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\n"
        . "5 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 10 20 30] /Contents <3082> >>\nendobj\n"
        . "trailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $arquivosCriados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            @unlink($caminho);
        }
        $this->arquivosCriados = [];

        parent::tearDown();
    }

    // ── Tela ────────────────────────────────────────────────────────────────────

    #[TestDox('arquivo assinado à mão: selo filho direto da linha, entre o nome e o ⋮, e "Desmarcar assinatura" no ⋮')]
    public function testSeloNaLinhaEDesmarcarNoMenu(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);
        $doc->marcarAssinadoManualmente($user, new \DateTimeImmutable());
        $this->em()->flush();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $linha = "#financeiro-docs-lista > #financeiro-doc-{$doc->getId()}";
        $selo  = $crawler->filter("{$linha} > .ps-fin-doc-corpo + span.ps-fin-doc-selo");
        self::assertCount(1, $selo, 'o selo tem de vir logo depois do nome, na própria linha');
        self::assertSame('Assinado', trim($selo->text()));
        self::assertCount(1, $selo->filter(':scope > i.bi.bi-patch-check-fill'));
        self::assertCount(1, $crawler->filter("{$linha} > span.ps-fin-doc-selo + .ps-fin-doc-acoes"), 'o ⋮ vem logo depois do selo');

        $item = $crawler->filter("#financeiro-doc-menu-{$doc->getId()} > .btn-assinatura-doc");
        self::assertCount(1, $item);
        self::assertSame('Desmarcar assinatura', trim($item->text()));
        self::assertSame('desmarcar', $item->attr('data-acao'));
        self::assertSame("/pasta/{$pasta->getId()}/financeiro/documento/{$doc->getId()}/assinatura/desmarcar", $item->attr('data-url'));
        self::assertNotEmpty($item->attr('data-csrf'));
    }

    #[TestDox('arquivo sem assinatura: sem selo, e "Marcar como assinado" no ⋮')]
    public function testSemSeloEMarcarNoMenu(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertCount(0, $crawler->filter("#financeiro-doc-{$doc->getId()} .ps-fin-doc-selo"));
        $item = $crawler->filter("#financeiro-doc-menu-{$doc->getId()} > .btn-assinatura-doc");
        self::assertCount(1, $item);
        self::assertSame('Marcar como assinado', trim($item->text()));
        self::assertSame("/pasta/{$pasta->getId()}/financeiro/documento/{$doc->getId()}/assinatura/marcar", $item->attr('data-url'));
    }

    #[TestDox('assinatura digital: selo na linha e nenhum item de assinatura no ⋮')]
    public function testDigitalSemItemNoMenu(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);
        $doc->marcarAssinadoDigitalmente(new \DateTimeImmutable(), null);
        $this->em()->flush();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertCount(1, $crawler->filter("#financeiro-docs-lista > #financeiro-doc-{$doc->getId()} > span.ps-fin-doc-selo"));
        self::assertCount(0, $crawler->filter("#financeiro-doc-menu-{$doc->getId()} .btn-assinatura-doc"));
    }

    #[TestDox('quem só vê a pasta: vê o selo, mas não recebe marcar nem desmarcar')]
    public function testLeitorVeSeloSemAcao(): void
    {
        [$client, $admin, $tenant, $pasta] = $this->cenario();
        $marcado = $this->criarDocumento($pasta, $tenant);
        $marcado->marcarAssinadoManualmente($admin, new \DateTimeImmutable());
        $this->criarDocumento($pasta, $tenant);
        $this->em()->flush();

        $leitor = $this->criarLeitor($tenant);
        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter("#financeiro-docs-lista > #financeiro-doc-{$marcado->getId()} > span.ps-fin-doc-selo"));
        self::assertCount(0, $crawler->filter('#financeiro-docs-lista .btn-assinatura-doc'));
    }

    // ── Rotas ───────────────────────────────────────────────────────────────────

    #[TestDox('marcar grava o selo manual com quem marcou; desmarcar apaga')]
    public function testMarcarEDesmarcar(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $doc   = $this->criarDocumento($pasta, $tenant);
        $docId = (int) $doc->getId();

        $this->post($client, $pasta, $docId, 'marcar');
        self::assertResponseIsSuccessful();
        self::assertSame(['assinado' => true, 'origem' => 'manual'], json_decode((string) $client->getResponse()->getContent(), true));

        $gravado = $this->recarregar($docId);
        self::assertSame(OrigemDaAssinatura::Manual, $gravado->getAssinaturaOrigem());
        self::assertSame($user->getId(), $gravado->getAssinadoPor()?->getId());
        self::assertNotNull($gravado->getAssinadoEm());

        $this->post($client, $pasta, $docId, 'desmarcar');
        self::assertResponseIsSuccessful();
        self::assertSame(['assinado' => false, 'origem' => null], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertFalse($this->recarregar($docId)->estaAssinado());
    }

    #[TestDox('assinatura digital não se desmarca pela rota (422)')]
    public function testDesmarcarDigitalRecusado(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);
        $doc->marcarAssinadoDigitalmente(new \DateTimeImmutable(), null);
        $this->em()->flush();

        $this->post($client, $pasta, (int) $doc->getId(), 'desmarcar');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(OrigemDaAssinatura::Digital, $this->recarregar((int) $doc->getId())->getAssinaturaOrigem());
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrfInvalido(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);

        $this->post($client, $pasta, (int) $doc->getId(), 'marcar', 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->recarregar((int) $doc->getId())->estaAssinado());
    }

    #[TestDox('usuário de outro escritório: 404 e nada muda (IDOR)')]
    public function testOutroEscritorio(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);

        [$intruso, $outroTenant] = $this->criarAdmin();
        $this->logarComTenant($client, $intruso, $outroTenant);
        $this->post($client, $pasta, (int) $doc->getId(), 'marcar');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->recarregar((int) $doc->getId())->estaAssinado());
    }

    #[TestDox('documento de outra pasta do mesmo escritório pela rota desta pasta: 404')]
    public function testDocumentoDeOutraPasta(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $outraPasta = $this->criarPasta($tenant);
        $doc        = $this->criarDocumento($outraPasta, $tenant);

        $this->post($client, $pasta, (int) $doc->getId(), 'marcar');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->recarregar((int) $doc->getId())->estaAssinado());
    }

    #[TestDox('sem permissão de editar a pasta: 403 e nada muda')]
    public function testSemPermissaoDeEditar(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);

        $this->logarComTenant($client, $this->criarLeitor($tenant), $tenant);
        $this->post($client, $pasta, (int) $doc->getId(), 'marcar');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->recarregar((int) $doc->getId())->estaAssinado());
    }

    #[TestDox('pasta na lápide (somente leitura): 403 e nada muda')]
    public function testLapide(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $doc = $this->criarDocumento($pasta, $tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->post($client, $pasta, (int) $doc->getId(), 'marcar');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->recarregar((int) $doc->getId())->estaAssinado());
    }

    // ── Upload ──────────────────────────────────────────────────────────────────

    #[TestDox('upload no Financeiro de PDF com assinatura digital nasce com o selo, origem digital')]
    public function testUploadDePdfAssinado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $pasta->getId()],
            ['arquivo' => $this->uploadDe(self::PDF_ASSINADO, 'contrato_assinado.pdf')],
        );

        self::assertResponseStatusCodeSame(201);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('digital', $dados['assinatura']);
        self::assertNotEmpty($dados['csrfAssinatura'], 'a linha nascida no JS precisa do token do ⋮');

        $gravado = $this->recarregar((int) $dados['id']);
        self::assertSame(OrigemDaAssinatura::Digital, $gravado->getAssinaturaOrigem());
        self::assertSame($user->getId(), $gravado->getAssinadoPor()?->getId());
    }

    #[TestDox('upload no Financeiro de PDF comum nasce sem selo')]
    public function testUploadDePdfComum(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $pasta->getId()],
            ['arquivo' => $this->uploadDe("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n", 'contrato.pdf')],
        );

        self::assertResponseStatusCodeSame(201);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNull($dados['assinatura']);
        self::assertFalse($this->recarregar((int) $dados['id'])->estaAssinado());
    }

    #[TestDox('upload de contrato assinado digitalmente (nome com "contrato") marca o contrato da pasta como Assinado')]
    public function testUploadDeContratoAssinadoMarcaOContrato(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pastaId         = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pastaId}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $pastaId],
            ['arquivo' => $this->uploadDe(self::PDF_ASSINADO, 'contrato_assinado.pdf')],
        );

        self::assertResponseStatusCodeSame(201);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('REGULAR', $dados['situacaoContrato']);
        self::assertSame('REGULAR', $this->situacaoGravada($pastaId));
    }

    #[TestDox('upload de arquivo assinado que não é contrato (recibo) deixa o contrato Pendente')]
    public function testUploadDeReciboAssinadoNaoMarcaOContrato(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pastaId         = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pastaId}/financeiro/upload",
            ['_token' => 'TOKEN_pasta_financeiro_upload_' . $pastaId],
            ['arquivo' => $this->uploadDe(self::PDF_ASSINADO, 'recibo_assinado.pdf')],
        );

        self::assertResponseStatusCodeSame(201);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('PENDENTE', $dados['situacaoContrato']);
        self::assertSame('PENDENTE', $this->situacaoGravada($pastaId));
    }

    #[TestDox('o botão Adicionar de CONTRATOS é um label com input file múltiplo')]
    public function testAdicionarAceitaVariosArquivos(): void
    {
        [$client, , , $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $input = $crawler->filter('#financeiro [data-trilho="arquivos"] > .ps-card-cab > label.ps-fin-adicionar > input[type="file"]#financeiro-upload-input');
        self::assertCount(1, $input);
        self::assertNotNull($input->attr('multiple'));
    }

    private function situacaoGravada(int $pastaId): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT situacao_contrato FROM pasta WHERE id = :p', ['p' => $pastaId]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /** @return array{KernelBrowser, User, Tenant, Pasta} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user, $tenant, $pasta];
    }

    private function post(KernelBrowser $client, Pasta $pasta, int $docId, string $acao, ?string $token = null): void
    {
        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/financeiro/documento/{$docId}/assinatura/{$acao}",
            ['_token' => $token ?? 'TOKEN_pasta_financeiro_assinatura_' . $docId],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function recarregar(int $docId): PastaDocumento
    {
        $em = $this->em();
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em->find(PastaDocumento::class, $docId) ?? self::fail('documento sumiu');
    }

    /** @return array{User, Tenant} */
    private function criarAdmin(): array
    {
        $em = $this->em();

        $tenant = new Tenant();
        $tenant->setName('Tenant Selo Assinado ' . uniqid());
        $em->persist($tenant);

        $user = $this->novoUsuario('Admin Selo', ['ROLE_SUPER_ADMIN']);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    /** Vê pastas, mas não edita. */
    private function criarLeitor(Tenant $tenant): User
    {
        $em   = $this->em();
        $user = $this->novoUsuario('Leitor Selo', ['ROLE_USER']);
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Leitor ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);
        foreach (['modules.pastas.view', 'resources.pasta.view'] as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    /** @param list<string> $roles */
    private function novoUsuario(string $nome, array $roles): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail('selo_assinado_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('SELO-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setNomeCliente('Cliente do Selo');
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo('Contrato de honorários.pdf');
        $doc->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
        $doc->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf');
        $doc->setNomeOriginal('contrato.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(14);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    private function uploadDe(string $conteudo, string $nome): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/selo_assinado_' . bin2hex(random_bytes(6));
        file_put_contents($origem, $conteudo);
        $this->arquivosCriados[] = $origem;

        return new UploadedFile($origem, $nome, null, null, true);
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
