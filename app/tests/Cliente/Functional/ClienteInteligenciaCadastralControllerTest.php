<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteCadastroController;
use App\Cliente\Controller\ClienteController;
use App\Cliente\Controller\ClienteInteligenciaCadastralController;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Repository\DocumentoVinculadoRepository;
use App\Cliente\Service\AlertasDaInteligenciaCadastral;
use App\Cliente\UseCase\MontarInteligenciaCadastralUseCase;
use App\Cliente\UseCase\VincularDocumentoDaPastaAoClienteUseCase;
use App\Cobranca\Entity\Pessoa;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Inteligência cadastral do cliente, F-A (spec docs/specs/cliente-inteligencia-cadastral.md, §4): o bloco só com
 * documento válido, nada de outro escritório, a origem "Cobranças" só com o módulo, documento de pasta que o usuário
 * não vê fica de fora, vincular sem cópia e com histórico, CSRF e 404.
 */
#[CoversClass(ClienteInteligenciaCadastralController::class)]
#[CoversClass(MontarInteligenciaCadastralUseCase::class)]
#[CoversClass(VincularDocumentoDaPastaAoClienteUseCase::class)]
#[CoversClass(AlertasDaInteligenciaCadastral::class)]
#[CoversClass(ClienteController::class)]
#[CoversClass(ClienteCadastroController::class)]
#[CoversClass(ClienteDocumento::class)]
final class ClienteInteligenciaCadastralControllerTest extends JusPrimeWebTestCase
{
    private const CPF = '529.982.247-25';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }

    #[TestDox('sem CPF válido o bloco não aparece; com CPF válido vem o cabeçalho, o subtítulo e a nota, sem "IA"')]
    public function testSoComDocumentoValido(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $invalido = $this->criarClientePF($tenant, 'Ana Invalida', '111.111.111-11');
        $valido = $this->criarClientePF($tenant, 'Ana Valida', self::CPF);
        $this->logarComTenant($this->client, $gestor, $tenant);

        self::assertFalse($this->retrato((int) $invalido->getId())['on']);
        $ii = $this->retrato((int) $valido->getId());

        self::assertTrue($ii['on']);
        self::assertSame('Inteligência cadastral', $ii['titulo']);
        self::assertSame('0 documento(s) deste cliente em 0 pasta(s)', $ii['subtitulo']);
        self::assertStringStartsWith('Nome indica uma possibilidade; CPF', $ii['nota']);
        self::assertDoesNotMatchRegularExpression('/\bIA\b/', (string) json_encode($ii, JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('PJ com CNPJ válido também tem o bloco')]
    public function testPj(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $pj = (new ClientePJ())->setRazaoSocial('ACME Ltda')->setCnpj('11222333000181')->setEnderecSede('SCS')->setRepresentanteLegal('Rep')
            ->setRepresentanteCpf(self::CPF)->setRepresentanteRg('1')->setRepresentanteCargo('Diretor');
        $pj->setEmail('pj_' . uniqid() . '@t.com')->setCep('70000-000')->setEndereco('SCS')->setCidade('Brasília')->setEstado('DF');
        $pj->setTenant($tenant);
        $this->em()->persist($pj);
        $this->em()->flush();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $ii = $this->retrato((int) $pj->getId());

        self::assertTrue($ii['on']);
        self::assertStringStartsWith('Nome indica uma possibilidade; CNPJ', $ii['nota']);
    }

    #[TestDox('homônimo e mesmo CPF: só do mesmo escritório; o CPF nunca aparece no texto; o nome do outro visível aparece, sem link')]
    public function testAlertasSoDoEscritorio(): void
    {
        $tenant = $this->criarTenant();
        $outro = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'José da Silva', self::CPF);
        $this->criarClientePF($tenant, 'JOSE DA SILVA.', '111.444.777-35');
        $this->criarClientePF($tenant, 'J. da Silva', '52998224725');
        $this->criarClientePF($outro, 'José da Silva', '390.533.447-05');
        $this->criarClientePF($outro, 'Outro Nome', self::CPF);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $alertas = $this->retrato((int) $cliente->getId())['alertas'];

        self::assertSame(['homonimo', 'mesmoDocumento'], array_column($alertas, 'tipo'));
        self::assertStringStartsWith('1 outro(s) cadastro(s) com o mesmo nome e CPF diferente.', $alertas[0]['texto']);
        self::assertSame('"J. DA SILVA" em outro cadastro de cliente. Pode ser abreviação ou cadastro duplicado; confira antes de consolidar.', $alertas[1]['texto']);
        self::assertArrayNotHasKey('abrirUrl', $alertas[1], 'só texto, sem link (decisão do coordenador)');
        $json = (string) json_encode($alertas, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('529', $json);
        self::assertStringNotContainsString('Outro Nome', $json);
    }

    #[TestDox('pessoa da Cobrança com o mesmo CPF e outro nome: aparece com o módulo, some sem ele; de outro escritório nunca')]
    public function testCobrancaSoComModulo(): void
    {
        $tenant = $this->criarTenant();
        $outro = $this->criarTenant();
        $cliente = $this->criarClientePF($tenant, 'José da Silva', self::CPF);
        $this->criarPessoa($tenant, 'Gleisson B. Gabriel', '52998224725');
        $this->criarPessoa($outro, 'Pessoa Alheia', self::CPF);
        $semModulo = $this->criarUsuario($tenant, false);
        $comModulo = $this->criarUsuario($tenant, false, ['modules.cobrancas.view']);
        foreach ([$semModulo, $comModulo] as $u) {
            $this->conceder($u, $tenant, 'cliente', (int) $cliente->getId(), false);
        }

        $this->logarComTenant($this->client, $comModulo, $tenant);
        $com = $this->retrato((int) $cliente->getId())['alertas'];
        $this->logarComTenant($this->client, $semModulo, $tenant);
        $sem = $this->retrato((int) $cliente->getId())['alertas'];

        self::assertCount(1, $com);
        self::assertSame('"Gleisson B. Gabriel" em Cobranças. Pode ser abreviação ou cadastro duplicado; confira antes de consolidar.', $com[0]['texto']);
        self::assertSame([], $sem);
        self::assertStringNotContainsString('Pessoa Alheia', (string) json_encode($com, JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('encontrados: identificação e comprovante das pastas do cliente que o usuário vê; sem a pasta escondida, a lixeira e outras categorias')]
    public function testEncontradosRespeitamAPasta(): void
    {
        $tenant = $this->criarTenant();
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $criador = $this->criarUsuario($tenant, true);
        $vista = $this->criarPasta($tenant, $criador, $cliente, 'II-VISTA');
        $escondida = $this->criarPasta($tenant, $criador, $cliente, 'II-OCULTA');
        $rg = $this->criarDocumento($vista, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG_Maria.pdf');
        $this->criarDocumento($vista, PastaDocumento::CATEGORIA_PECA, 'Peticao.pdf');
        $lixo = $this->criarDocumento($vista, PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA, 'Conta_velha.pdf');
        $lixo->marcarExcluido($criador, new \DateTimeImmutable());
        $this->criarDocumento($escondida, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'CNH_Oculta.pdf');
        $this->em()->flush();
        $colab = $this->criarUsuario($tenant, false);
        $this->conceder($colab, $tenant, 'cliente', (int) $cliente->getId(), true);
        $this->conceder($colab, $tenant, 'pasta', (int) $vista->getId(), false);
        $this->logarComTenant($this->client, $colab, $tenant);

        $ii = $this->retrato((int) $cliente->getId());

        self::assertSame([(int) $rg->getId()], array_column($ii['encontrados'], 'id'));
        self::assertSame('RG_Maria.pdf', $ii['encontrados'][0]['arquivo']);
        self::assertSame('Documento de identificação', $ii['encontrados'][0]['categoriaRotulo']);
        self::assertSame('Pasta II-VISTA', $ii['encontrados'][0]['pasta']);
        self::assertTrue($ii['encontrados'][0]['podeVincular']);
        self::assertSame('1 documento(s) deste cliente em 1 pasta(s)', $ii['subtitulo']);
        self::assertStringNotContainsString('CNH_Oculta', (string) json_encode($ii));

        $this->vincular((int) $cliente->getId(), (int) $escondida->getDocumentos()->first()->getId());
        self::assertResponseStatusCodeSame(404, 'documento da pasta que o usuário não vê');
    }

    #[TestDox('vincular: cria o vínculo sem cópia do arquivo, grava o histórico, some dos encontrados e abre pelo arquivo da pasta')]
    public function testVincular(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true, [], 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $pasta = $this->criarPasta($tenant, $gestor, $cliente, 'II-1180');
        $rg = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG_Maria.pdf');
        $duble->memoria->semear(ChavesDePasta::documento($rg), '%PDF-1.4');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->vincular((int) $cliente->getId(), (int) $rg->getId());

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['vinculado']);
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT * FROM cliente_documento WHERE cliente_id = ?', [$cliente->getId()]);
        self::assertIsArray($linha);
        self::assertSame((int) $rg->getId(), (int) $linha['pasta_documento_id']);
        self::assertSame('', $linha['caminho_arquivo']);
        self::assertSame(0, (int) $linha['tamanho_bytes']);
        self::assertSame((int) $tenant->getId(), (int) $linha['tenant_id']);
        self::assertSame([], $duble->memoria->chavesGravadas, 'nenhum arquivo novo');
        self::assertSame(['Ana Souza vinculou documento de identificação a partir do menu Documentos'], $this->em()->getConnection()
            ->fetchFirstColumn('SELECT texto FROM cliente_historico WHERE cliente_id = ?', [$cliente->getId()]));
        self::assertSame([], $this->retrato((int) $cliente->getId())['encontrados']);

        $this->vincular((int) $cliente->getId(), (int) $rg->getId());
        self::assertFalse($this->json()['vinculado'], 'de novo não duplica');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE cliente_id = ?', [$cliente->getId()]));

        $this->client->request('GET', '/clientes/documento/' . $linha['id'] . '/visualizar');
        self::assertResponseRedirects('/pasta/documento/' . $rg->getId() . '/visualizar');
        $this->client->request('GET', '/clientes/documento/' . $linha['id'] . '/download');
        self::assertResponseRedirects('/pasta/documento/' . $rg->getId() . '/download');
    }

    #[TestDox('remover o vínculo do cadastro não apaga o arquivo da pasta; documento da pasta na lixeira some da aba')]
    public function testRemoverELixeira(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $pasta = $this->criarPasta($tenant, $gestor, $cliente, 'II-2000');
        $rg = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG_Maria.pdf');
        $conta = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA, 'Conta_luz.pdf');
        $duble->memoria->semear(ChavesDePasta::documento($rg), '%PDF-1.4');
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->vincular((int) $cliente->getId(), (int) $rg->getId());
        $this->vincular((int) $cliente->getId(), (int) $conta->getId());
        $ids = $this->em()->getConnection()->fetchAllKeyValue('SELECT pasta_documento_id, id FROM cliente_documento WHERE cliente_id = ?', [$cliente->getId()]);

        $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro');
        self::assertSelectorTextContains('[data-cad-doc="IDENTIFICACAO"] .cad-doc-info', 'RG_Maria.pdf · anexado em');
        self::assertSelectorTextContains('[data-cad-doc="COMPROVANTE_RESIDENCIA"] .cad-doc-info', 'Conta_luz.pdf');

        $this->em()->clear();
        $this->client->request('POST', '/clientes/documento/' . $ids[$rg->getId()] . '/deletar', ['_token' => 'TOKEN_delete_doc_cliente_' . $ids[$rg->getId()]]);
        self::assertResponseRedirects();
        self::assertSame([], $duble->memoria->excluidas, 'o arquivo da pasta fica');
        self::assertTrue($duble->memoria->existe(ChavesDePasta::documento($rg)));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id = ? AND excluido_em IS NULL', [$rg->getId()]));

        $this->em()->getConnection()->executeStatement('UPDATE pasta_documento SET excluido_em = NOW() WHERE id = ?', [$conta->getId()]);
        $this->em()->clear();
        $crawler = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('não anexado', $crawler->filter('[data-cad-doc="COMPROVANTE_RESIDENCIA"] .cad-doc-info')->text());
        self::assertCount(0, $crawler->filter('[data-cad-doc="COMPROVANTE_RESIDENCIA"].is-anexado'));
    }

    #[TestDox('excluir o cliente com um documento vinculado não apaga o arquivo da pasta')]
    public function testExcluirClienteNaoApagaArquivoDaPasta(): void
    {
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $pasta = $this->criarPasta($tenant, $gestor, $cliente, 'II-5000');
        $rg = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG_Maria.pdf');
        $duble->memoria->semear(ChavesDePasta::documento($rg), '%PDF-1.4');
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->vincular((int) $cliente->getId(), (int) $rg->getId());
        $this->em()->getConnection()->executeStatement('DELETE FROM pasta_cliente WHERE cliente_id = ?', [$cliente->getId()]);

        $this->em()->clear();
        $this->client->request('POST', '/clientes/' . $cliente->getId() . '/deletar', ['_token' => 'TOKEN_delete' . $cliente->getId()]);

        self::assertResponseRedirects();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente WHERE id = ?', [$cliente->getId()]));
        self::assertSame([], $duble->memoria->excluidas);
        self::assertTrue($duble->memoria->existe(ChavesDePasta::documento($rg)));
    }

    #[TestDox('categoria que o cadastro já tem some dos encontrados; pasta na lápide fica fora do bloco e o vincular dela dá 404')]
    public function testCategoriaNoCadastroEPastaExcluida(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $pasta = $this->criarPasta($tenant, $gestor, $cliente, 'II-6000');
        $rg = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG.pdf');
        $cnh = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'CNH.pdf');
        $conta = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA, 'Conta.pdf');
        $lapide = $this->criarPasta($tenant, $gestor, $cliente, 'II-6001');
        $daLapide = $this->criarDocumento($lapide, PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA, 'Conta_lapide.pdf');
        $lapide->marcarExcluida($gestor, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $antes = $this->retrato((int) $cliente->getId());
        $ids = array_column($antes['encontrados'], 'id');
        sort($ids);
        self::assertSame([(int) $rg->getId(), (int) $cnh->getId(), (int) $conta->getId()], $ids, 'sem o da pasta na lápide');
        self::assertSame('3 documento(s) deste cliente em 1 pasta(s)', $antes['subtitulo']);

        $this->vincular((int) $cliente->getId(), (int) $daLapide->getId());
        self::assertResponseStatusCodeSame(404, 'pasta na lápide');
        $this->vincular((int) $cliente->getId(), (int) $rg->getId());
        self::assertResponseIsSuccessful();

        self::assertSame([(int) $conta->getId()], array_column($this->retrato((int) $cliente->getId())['encontrados'], 'id'), 'com identificação no cadastro, a CNH não aparece');
    }

    #[TestDox('vínculo de pasta que o usuário não vê não aparece na janela nem na página do cliente; a pendência continua resolvida')]
    public function testVinculoDePastaInvisivel(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $pasta = $this->criarPasta($tenant, $gestor, $cliente, 'II-7000');
        $rg = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG_Secreto.pdf');
        $colab = $this->criarUsuario($tenant, false);
        $this->conceder($colab, $tenant, 'cliente', (int) $cliente->getId(), true);
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->vincular((int) $cliente->getId(), (int) $rg->getId());

        $this->logarComTenant($this->client, $colab, $tenant);
        $this->em()->clear();
        $janela = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('não anexado', $janela->filter('[data-cad-doc="IDENTIFICACAO"] .cad-doc-info')->text());
        self::assertStringNotContainsString('RG_Secreto', (string) $this->client->getResponse()->getContent());
        $pendDocs = json_decode((string) $janela->filter('[data-cad-janela]')->attr('data-pend-docs'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Comprovante de residência não anexado'], array_column($pendDocs, 'texto'), 'a pendência conta o vínculo');
        $this->em()->clear();
        $this->client->request('GET', '/clientes/' . $cliente->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('RG_Secreto', (string) $this->client->getResponse()->getContent());

        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->em()->clear();
        $this->client->request('GET', '/clientes/' . $cliente->getId());
        $pagina = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('RG_Secreto', $pagina);
        self::assertStringContainsString('na Pasta II-7000', $pagina);
        self::assertStringNotContainsString('0 KB', $pagina);
    }

    #[TestDox('vinculado não é editável na página legada do cliente (403), só desvinculado')]
    public function testVinculadoNaoEditavel(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $rg = $this->criarDocumento($this->criarPasta($tenant, $gestor, $cliente, 'II-8000'), PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG.pdf');
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->vincular((int) $cliente->getId(), (int) $rg->getId());
        $id = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM cliente_documento WHERE cliente_id = ?', [$cliente->getId()]);

        $this->em()->clear();
        $crawler = $this->client->request('GET', '/clientes/' . $cliente->getId());
        self::assertCount(0, $crawler->filter('[data-bs-target="#editDocModal' . $id . '"]'));
        $this->client->request('POST', '/clientes/documento/' . $id . '/editar', ['_token' => 'TOKEN_edit_doc_cliente_' . $id, 'categoria' => 'DEMAIS']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('IDENTIFICACAO', $this->em()->getConnection()->fetchOne('SELECT categoria FROM cliente_documento WHERE id = ?', [$id]));
    }

    #[TestDox('vincular concorrente: o índice único recusa o segundo e a resposta é vinculado false (200), não 500')]
    public function testVincularConcorrente(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $rg = $this->criarDocumento($this->criarPasta($tenant, $gestor, $cliente, 'II-9000'), PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG.pdf');
        // O "outro pedido" já gravou o vínculo; esta conferência não o viu (chegou antes do COMMIT dele).
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO cliente_documento (titulo, categoria, caminho_arquivo, nome_original, mime_type, tamanho_bytes, uploaded_at, cliente_id, tenant_id, pasta_documento_id)
             VALUES ('RG.PDF', 'IDENTIFICACAO', '', 'RG.pdf', 'application/pdf', 0, NOW(), ?, ?, ?)",
            [$cliente->getId(), $tenant->getId(), $rg->getId()],
        );
        $semVer = $this->createStub(DocumentoVinculadoRepository::class);
        $semVer->method('idsDePastaDocumentoVinculados')->willReturn([]);
        static::getContainer()->set(DocumentoVinculadoRepository::class, $semVer);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->vincular((int) $cliente->getId(), (int) $rg->getId());

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['vinculado']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE cliente_id = ?', [$cliente->getId()]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_historico WHERE cliente_id = ?', [$cliente->getId()]), 'o histórico volta junto');
    }

    #[TestDox('o bloco está na aba Documentos do cadastro, com as rotas do contrato')]
    public function testBlocoNaAba(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $crawler = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro');

        $bloco = $crawler->filter('[data-cad-painel="docs"] > .cad-ii.js-cad-ii');
        self::assertCount(1, $bloco);
        self::assertSame('/cliente/' . $cliente->getId() . '/inteligencia-cadastral', $bloco->attr('data-url'));
        self::assertSame('/cliente/' . $cliente->getId() . '/inteligencia-cadastral/vincular/0', $bloco->attr('data-url-vincular'));
    }

    #[TestDox('CSRF: sem o header X-CSRF-Token não vincula (403); sem editar o cliente, 403')]
    public function testCsrfEPermissao(): void
    {
        $tenant = $this->criarTenant();
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $gestor = $this->criarUsuario($tenant, true);
        $pasta = $this->criarPasta($tenant, $gestor, $cliente, 'II-3000');
        $rg = $this->criarDocumento($pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG.pdf');
        $leitor = $this->criarUsuario($tenant, false);
        $this->conceder($leitor, $tenant, 'cliente', (int) $cliente->getId(), false);
        $this->conceder($leitor, $tenant, 'pasta', (int) $pasta->getId(), false);

        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->client->request('POST', '/cliente/' . $cliente->getId() . '/inteligencia-cadastral/vincular/' . $rg->getId(), [], [], ['HTTP_X_CSRF_TOKEN' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/cliente/' . $cliente->getId() . '/inteligencia-cadastral/vincular/' . $rg->getId(), ['_token' => 'TOKEN_cliente_cadastro_' . $cliente->getId()]);
        self::assertResponseStatusCodeSame(403, 'o token só vale no header');

        $this->logarComTenant($this->client, $leitor, $tenant);
        self::assertFalse($this->retrato((int) $cliente->getId())['encontrados'][0]['podeVincular']);
        $this->vincular((int) $cliente->getId(), (int) $rg->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE cliente_id = ?', [$cliente->getId()]));
    }

    #[TestDox('404: cliente de outro escritório (GET e POST), cliente invisível, documento de outra pasta ou de outro escritório')]
    public function test404(): void
    {
        $tenant = $this->criarTenant();
        $outro = $this->criarTenant();
        $gestor = $this->criarUsuario($tenant, true);
        $gestorOutro = $this->criarUsuario($outro, true);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores', self::CPF);
        $alheio = $this->criarClientePF($outro, 'Cliente Alheio', self::CPF);
        $outroCliente = $this->criarClientePF($tenant, 'Outra Pessoa', '111.444.777-35');
        $pastaDoOutroCliente = $this->criarPasta($tenant, $gestor, $outroCliente, 'II-4000');
        $docDoOutroCliente = $this->criarDocumento($pastaDoOutroCliente, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG_outra.pdf');
        $docAlheio = $this->criarDocumento($this->criarPasta($outro, $gestorOutro, $alheio, 'II-4001'), PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG_alheio.pdf');
        $invisivelPara = $this->criarUsuario($tenant, false);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->client->request('GET', '/cliente/' . $alheio->getId() . '/inteligencia-cadastral');
        self::assertResponseStatusCodeSame(404);
        $this->vincular((int) $alheio->getId(), (int) $docAlheio->getId());
        self::assertResponseStatusCodeSame(404);
        $this->vincular((int) $cliente->getId(), (int) $docDoOutroCliente->getId());
        self::assertResponseStatusCodeSame(404, 'documento de pasta que não é do cliente');
        $this->vincular((int) $cliente->getId(), (int) $docAlheio->getId());
        self::assertResponseStatusCodeSame(404, 'documento de outro escritório');

        $this->logarComTenant($this->client, $invisivelPara, $tenant);
        $this->client->request('GET', '/cliente/' . $cliente->getId() . '/inteligencia-cadastral');
        self::assertResponseStatusCodeSame(404, 'cliente que o usuário não vê');
    }

    /** @return array<string, mixed> */
    private function retrato(int $clienteId): array
    {
        $this->em()->clear();
        $this->client->xmlHttpRequest('GET', '/cliente/' . $clienteId . '/inteligencia-cadastral');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function vincular(int $clienteId, int $documentoId): void
    {
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/cliente/' . $clienteId . '/inteligencia-cadastral/vincular/' . $documentoId, [], [], [
            'HTTP_X_CSRF_TOKEN' => 'TOKEN_cliente_cadastro_' . $clienteId,
        ]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarClientePF(Tenant $tenant, string $nome, string $cpf): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto($nome)->setCpf($cpf)->setRg('1234567')->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('ii_' . uniqid() . '@test.com')->setCep('70000-000')->setEndereco('SQS 110')->setCidade('Brasília')->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function criarPessoa(Tenant $tenant, string $nome, string $cpf): void
    {
        $this->em()->persist((new Pessoa())->setNome($nome)->setCpf($cpf)->setTenant($tenant));
        $this->em()->flush();
    }

    private function criarPasta(Tenant $tenant, User $criador, ClientePF $cliente, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $pasta->setResponsavel($criador);
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarDocumento(Pasta $pasta, string $categoria, string $nome): PastaDocumento
    {
        $doc = (new PastaDocumento())->setPasta($pasta)->setTenant($pasta->getTenant())->setCategoria($categoria)->setTitulo($nome)
            ->setNomeOriginal($nome)->setCaminhoArquivo('ii_' . bin2hex(random_bytes(6)) . '.pdf')->setMimeType('application/pdf')->setTamanhoBytes(1234);
        $pasta->getDocumentos()->add($doc);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant II ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $codigos */
    private function criarUsuario(Tenant $tenant, bool $sistema, array $codigos = [], ?string $nome = null): User
    {
        $em = $this->em();
        $user = new User();
        $user->setEmail('ii_' . uniqid() . '@test.com');
        $user->setFullName($nome ?? 'Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($sistema);
        $em->persist($role);
        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = (new Permission())->setCode($codigo)->setDescription($codigo)->setGroup('modules');
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

    private function conceder(User $user, Tenant $tenant, string $tipo, int $recursoId, bool $editar): void
    {
        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setTenant($tenant);
        $ra->setResourceType($tipo);
        $ra->setResourceId($recursoId);
        $ra->setCanView(true);
        $ra->setCanEdit($editar);
        $this->em()->persist($ra);
        $this->em()->flush();
    }
}
