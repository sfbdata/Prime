<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Cliente\Controller\ClienteController;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Http\EntregaDeArquivo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * As rotas de entrega que não tinham NENHUM teste de sucesso antes da E2.3: as quatro de documento
 * da pasta, as duas de documento do cliente e o atestado do colaborador no ponto.
 *
 * Cada teste passa pelo HTTP de verdade, com arquivo de verdade no diretório configurado, e afirma
 * o que quem baixa enxerga: status, tipo, disposição, nome e corpo. Foram escritos ANTES da
 * migração e passaram com o `servir()` antigo — é isso que os torna prova de que a migração não
 * mudou nada. As outras oito rotas já tinham teste de sucesso, e ganharam a asserção de
 * disposição nos arquivos delas.
 */
#[CoversClass(EntregaDeArquivo::class)]
#[CoversClass(PastaController::class)]
#[CoversClass(ClienteController::class)]
#[CoversClass(PontoController::class)]
final class EntregaDeArquivoRotasTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<ChaveDeArquivo> */
    private array $gravados = [];

    protected function tearDown(): void
    {
        if ($this->gravados !== []) {
            $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
            foreach ($this->gravados as $chave) {
                $armazenamento->excluir($chave);
            }
            $this->gravados = [];
        }

        parent::tearDown();
    }

    // ============================================================ pasta

    #[TestDox('visualizar documento da pasta: 200 inline, PDF, com o nome original e o corpo do arquivo')]
    public function testPastaVisualizar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Contrato.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');

        $this->assertEntregue('inline; filename=Contrato.pdf', 'application/pdf');
        self::assertSame(self::PDF, $client->getInternalResponse()->getContent());
    }

    #[TestDox('Range continua funcionando pela rota: bytes=0-9 → 206 com Content-Range e 10 bytes')]
    public function testPastaVisualizarComRange(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Contrato.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar', server: ['HTTP_RANGE' => 'bytes=0-9']);

        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Range', 'bytes 0-9/' . \strlen(self::PDF));
        self::assertSame(substr(self::PDF, 0, 10), $client->getInternalResponse()->getContent());
    }

    #[TestDox('baixar documento da pasta: 200 attachment, com o nome original')]
    public function testPastaBaixar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Contrato.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/pasta/documento/' . $doc->getId() . '/download');

        $this->assertEntregue('attachment; filename=Contrato.pdf', 'application/pdf');
    }

    #[TestDox('baixar documento sem arquivo em disco continua avisando e voltando para a pasta (não 404)')]
    public function testPastaBaixarAusenteAvisaERedireciona(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Sumido.pdf', gravarArquivo: false);
        $pastaId         = (int) $doc->getPasta()?->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/pasta/documento/' . $doc->getId() . '/download');

        self::assertResponseRedirects('/pasta/' . $pastaId);
        self::assertSame(
            ['Arquivo não encontrado no servidor.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('error'),
        );
    }

    #[TestDox('visualizar documento sem arquivo em disco → 404 (D10: chave válida, arquivo ausente) — pela checagem da E2.2')]
    public function testPastaVisualizarAusenteDevolve404(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Sumido.pdf', gravarArquivo: false);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Com o DIRETÓRIO ilegível, quem lança é a checagem de presença que o controller faz antes de
     * entregar (E2.2) — a entrega nem é alcançada. O teste seguinte é o que prova a entrega.
     *
     * ⚠️ Faz chmod no diretório COMPARTILHADO de uploads de teste; restaura em `finally`. Uma
     * segunda suíte rodando na mesma worktree ao mesmo tempo pode falhar nessa janela.
     */
    #[TestDox('diretório ilegível NÃO vira 404: pane do storage aparece como erro (D10) — pela checagem da E2.2')]
    public function testDiscoIlegivelNaoViraNotFound(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Contrato.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $dir          = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/');
        $modoOriginal = fileperms($dir) & 0o777;
        self::assertTrue(chmod($dir, 0o000));

        try {
            self::assertFalse(is_readable($dir), 'pré-condição: o processo não pode estar rodando como root');
            $client->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');
        } finally {
            chmod($dir, $modoOriginal);
        }

        self::assertResponseStatusCodeSame(500, '"não consegui ler o disco" não pode se passar por "o arquivo não existe"');
    }

    /**
     * Arquivo PRESENTE e ilegível: a checagem de presença responde "existe" (o stat não precisa ler
     * o arquivo) e quem decide é a entrega — o materializador lança `FalhaDeArmazenamento`, e a
     * entrega não pode capturá-la como 404. É a única prova de D10(3) que passa pela entrega via HTTP.
     */
    #[TestDox('arquivo presente mas ilegível NÃO vira 404 — decidido pela EntregaDeArquivo (D10)')]
    public function testArquivoIlegivelNaoViraNotFound(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Contrato.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $caminho = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/' . $doc->getCaminhoArquivo();
        self::assertFileExists($caminho, 'pré-condição: o arquivo foi gravado onde a rota procura');
        self::assertTrue(chmod($caminho, 0o000));

        try {
            self::assertFalse(is_readable($caminho), 'pré-condição: o processo não pode estar rodando como root');
            $client->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');
        } finally {
            chmod($caminho, 0o644);
        }

        self::assertResponseStatusCodeSame(500, 'arquivo que existe e não pode ser lido é pane, não ausência');
    }

    #[TestDox('sem permissão na pasta → 403 mesmo com o arquivo em disco: a autorização vem antes da entrega')]
    public function testSemPermissaoNaoRecebeOArquivo(): void
    {
        $client  = static::createClient();
        [, $tenant] = $this->criarSuperAdmin();
        $doc     = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_DEMAIS, 'Contrato.pdf');
        $comum   = $this->criarUsuario($tenant, sistema: false);
        $this->logarComTenant($client, $comum, $tenant);

        $client->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');

        self::assertResponseStatusCodeSame(403);
        self::assertNotInstanceOf(BinaryFileResponse::class, $client->getResponse());
    }

    #[TestDox('financeiro da pasta: visualizar inline e baixar attachment, com o nome original')]
    public function testPastaFinanceiro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->criarDocumentoDaPasta($tenant, PastaDocumento::CATEGORIA_CONTRATO, 'Honorarios.pdf');
        $pastaId         = (int) $doc->getPasta()?->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pastaId}/financeiro/documento/{$doc->getId()}/visualizar");
        $this->assertEntregue('inline; filename=Honorarios.pdf', 'application/pdf');

        $client->request('GET', "/pasta/{$pastaId}/financeiro/documento/{$doc->getId()}/download");
        $this->assertEntregue('attachment; filename=Honorarios.pdf', 'application/pdf');
    }

    // ============================================================ cliente

    #[TestDox('documento do cliente: visualizar inline e baixar attachment, com o nome original')]
    public function testCliente(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarUsuario($tenant, sistema: true);
        $doc     = $this->criarDocumentoDoCliente($tenant, 'RG.pdf');
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->logarComTenant($client, $gestor, $tenant);

        $client->request('GET', '/clientes/documento/' . $doc->getId() . '/visualizar');
        $this->assertEntregue('inline; filename=RG.pdf', 'application/pdf');

        $client->request('GET', '/clientes/documento/' . $doc->getId() . '/download');
        $this->assertEntregue('attachment; filename=RG.pdf', 'application/pdf');
    }

    // ============================================================ ponto

    #[TestDox('atestado do colaborador: 200 inline, com o nome guardado na justificativa')]
    public function testPontoAtestado(): void
    {
        $client        = static::createClient();
        $tenant        = $this->criarTenant();
        $colaborador   = $this->criarUsuario($tenant, sistema: true);
        $justificativa = $this->criarJustificativa($tenant, $colaborador);
        $this->logarComTenant($client, $colaborador, $tenant);

        $client->request('GET', '/ponto/justificativa/' . $justificativa->getId() . '/anexo');

        $this->assertEntregue('inline; filename=' . $justificativa->getAnexoPath(), 'application/pdf');
    }

    #[TestDox('atestado de outro colaborador → 403 antes de qualquer entrega')]
    public function testPontoAtestadoDeOutro(): void
    {
        $client        = static::createClient();
        $tenant        = $this->criarTenant();
        $dono          = $this->criarUsuario($tenant, sistema: true);
        $outro         = $this->criarUsuario($tenant, sistema: true);
        $justificativa = $this->criarJustificativa($tenant, $dono);
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('GET', '/ponto/justificativa/' . $justificativa->getId() . '/anexo');

        self::assertResponseStatusCodeSame(403);
    }

    // ============================================================ apoio

    private function assertEntregue(string $disposicao, string $tipo): void
    {
        self::assertResponseIsSuccessful();
        self::assertInstanceOf(BinaryFileResponse::class, $this->getClient()->getResponse());
        self::assertResponseHeaderSame('Content-Disposition', $disposicao);
        self::assertResponseHeaderSame('Content-Type', $tipo);
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
    }

    private function gravar(Tenant $tenant, CategoriaDeArquivo $categoria): string
    {
        $armazenado = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            new NovoArquivo(EscopoDeArquivo::deTenant((int) $tenant->getId()), $categoria, 'pdf'),
            FonteDeConteudo::deTexto(self::PDF),
        );
        $this->gravados[] = $armazenado->chave;

        return $armazenado->chave->nome;
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Entrega ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarSuperAdmin(): array
    {
        $tenant = $this->criarTenant();
        $user   = $this->novoUsuario(['ROLE_SUPER_ADMIN']);
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    /** `sistema: true` é o papel de gestor que o PermissionChecker libera; `false`, um papel vazio. */
    private function criarUsuario(Tenant $tenant, bool $sistema): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->novoUsuario(['ROLE_USER']);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem($sistema);
        $em->persist($papel);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    /** @param list<string> $papeis */
    private function novoUsuario(array $papeis): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('entrega_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Entrega');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        return $user;
    }

    private function criarDocumentoDaPasta(Tenant $tenant, string $categoria, string $nomeOriginal, bool $gravarArquivo = true): PastaDocumento
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-ENTREGA-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $doc = new PastaDocumento();
        $doc->setTitulo($nomeOriginal);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo($gravarArquivo ? $this->gravar($tenant, CategoriaDeArquivo::PASTA_DOCUMENTO) : 'nunca_gravado_' . uniqid() . '.pdf');
        $doc->setNomeOriginal($nomeOriginal);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(\strlen(self::PDF));
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function criarDocumentoDoCliente(Tenant $tenant, string $nomeOriginal): ClienteDocumento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Cliente Entrega Z' . substr(uniqid(), -6));
        $cliente->setCpf(str_pad((string) random_int(1, 99999999), 11, '0', STR_PAD_LEFT));
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/SP');
        $cliente->setEmail('cliente_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);
        $em->persist($cliente);

        $doc = new ClienteDocumento();
        $doc->setCliente($cliente);
        $doc->setTenant($tenant);
        $doc->setTitulo('Documento');
        $doc->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO);
        $doc->setCaminhoArquivo($this->gravar($tenant, CategoriaDeArquivo::CLIENTE_DOCUMENTO));
        $doc->setNomeOriginal($nomeOriginal);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(\strlen(self::PDF));
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function criarJustificativa(Tenant $tenant, User $colaborador): JustificativaPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($colaborador);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime('2026-09-01'));
        $justificativa->setAnexoPath($this->gravar($tenant, CategoriaDeArquivo::JUSTIFICATIVA_ANEXO));
        $em->persist($justificativa);
        $em->flush();

        return $justificativa;
    }
}
