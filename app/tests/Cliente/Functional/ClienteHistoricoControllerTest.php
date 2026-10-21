<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteCadastroController;
use App\Cliente\Controller\ClienteController;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClienteHistorico;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Service\HistoricoDoCliente;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase;
use App\Cliente\UseCase\CriarClienteDaPastaUseCase;
use App\Cliente\UseCase\SalvarCadastroDoClienteUseCase;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Aba "Histórico" da janela de Cadastro (desenho 1.2.3, `cq.hist`; E12 fatia 3d): o que grava (salvar, criar pela
 * pasta, pelo cadastro antigo da pasta, ficha antiga, contato, anexar, editar e remover documento), o que mostra (mais nova primeiro, vazio com o texto do desenho) e o isolamento
 * entre escritórios. O texto nunca leva o valor do campo nem o nome do arquivo.
 */
#[CoversClass(ClienteCadastroController::class)]
#[CoversClass(ClienteController::class)]
#[CoversClass(SalvarCadastroDoClienteUseCase::class)]
#[CoversClass(CriarClienteDaPastaUseCase::class)]
#[CoversClass(HistoricoDoCliente::class)]
#[CoversClass(AtualizarContatoDoClienteUseCase::class)]
#[CoversClass(PastaController::class)]
final class ClienteHistoricoControllerTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private KernelBrowser $client;
    private static int $seq = 0;

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
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

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        parent::tearDown();
    }

    #[TestDox('salvar o cadastro grava "<autor> alterou <rótulos>" com o autor, sem os valores')]
    public function testSalvarGrava(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $pasta = $this->criarPasta($tenant, $gestor, $cliente);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar((int) $cliente->getId(), ['estado_civil' => 'CASADO', 'numero' => '101', 'bairro' => 'Asa Sul'], (int) $pasta->getId());

        self::assertResponseIsSuccessful();
        $linhas = $this->linhas((int) $cliente->getId());
        self::assertCount(1, $linhas);
        self::assertSame('Ana Souza alterou estado civil, número, bairro', $linhas[0]['texto']);
        self::assertSame($gestor->getId(), (int) $linhas[0]['autor_id']);
        self::assertSame($tenant->getId(), (int) $linhas[0]['tenant_id']);
        foreach (['CASADO', '101', 'ASA SUL', 'Asa Sul'] as $valor) {
            self::assertStringNotContainsString($valor, $linhas[0]['texto']);
        }
        self::assertStringContainsString('estado civil, número, bairro alterado(s).', (string) $this->em()->getConnection()
            ->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro'", [$pasta->getId()]), 'o cartão no Registro da pasta continua');
    }

    #[TestDox('salvar sem mudança não grava linha; CPF alterado entra só como "CPF"')]
    public function testSemMudancaECpf(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar((int) $cliente->getId(), ['nome' => 'Ana']);
        self::assertSame([], $this->linhas((int) $cliente->getId()));

        $this->salvar((int) $cliente->getId(), ['cpf' => '529.982.247-25']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Ana Souza alterou CPF'], array_column($this->linhas((int) $cliente->getId()), 'texto'));
    }

    #[TestDox('criar o cliente pela pasta grava "<autor> criou o cadastro"')]
    public function testCriarPelaPasta(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Welton Alves da Silva');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/cadastro/novo', [
            'nome' => 'Welton Alves da Silva', 'cpf' => '529.982.247-25', '_token' => 'TOKEN_pasta_cadastro_novo_' . $pasta->getId(),
        ]);

        self::assertResponseStatusCodeSame(201);
        $id = (int) $this->em()->getConnection()->fetchOne("SELECT c.id FROM cliente c JOIN cliente_pf p ON p.id = c.id WHERE c.tenant_id = ? AND p.cpf = '529.982.247-25'", [$tenant->getId()]);
        $linhas = $this->linhas($id);
        self::assertSame(['Ana Souza criou o cadastro'], array_column($linhas, 'texto'));
        self::assertSame($gestor->getId(), (int) $linhas[0]['autor_id']);
    }

    #[TestDox('anexar e remover documento grava "alterou <tipo>" uma vez por ação, sem o nome do arquivo')]
    public function testAnexarERemoverDocumento(): void
    {
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $id = (int) $cliente->getId();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->enviar($id, 'rg-maria-529.982.247-25.pdf', ClienteDocumento::CATEGORIA_IDENTIFICACAO);
        self::assertResponseRedirects('/clientes/' . $id);
        $docId = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM cliente_documento WHERE cliente_id = ?', [$id]);
        self::assertGreaterThan(0, $docId);

        $this->em()->clear();
        $this->client->request('POST', '/clientes/documento/' . $docId . '/deletar', ['_token' => 'TOKEN_delete_doc_cliente_' . $docId]);
        self::assertResponseRedirects('/clientes/' . $id);

        $textos = array_column($this->linhas($id), 'texto');
        self::assertSame(['Ana Souza alterou documento de identificação', 'Ana Souza alterou documento de identificação'], $textos, 'uma linha por ação, com o verbo do desenho');
        foreach ($textos as $t) {
            self::assertStringNotContainsString('rg-maria', $t);
            self::assertStringNotContainsString('529', $t);
        }
    }

    #[TestDox('aba Histórico: última aba, linhas mais nova primeiro com "dd/mm/aaaa, hh:mm"; Pendências fica fora do painel')]
    public function testAbaComLinhas(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $this->gravar($tenant, $cliente, $gestor, 'Ana Souza criou o cadastro', '2026-10-01 09:00:00');
        $this->gravar($tenant, $cliente, $gestor, 'Ana Souza alterou bairro', '2026-10-18 14:32:00');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?aba=hist');

        self::assertResponseIsSuccessful();
        self::assertSame('hist', $c->filter('[data-cad-aba]')->last()->attr('data-cad-aba'));
        self::assertSame('Histórico', trim($c->filter('[data-cad-aba="hist"]')->text()));
        self::assertSame('true', $c->filter('[data-cad-aba="hist"]')->attr('aria-selected'));
        $painel = $c->filter('[data-cad-painel="hist"]');
        self::assertNull($painel->attr('hidden'));
        self::assertSame([['18/10/2026, 14:32', 'Ana Souza alterou bairro'], ['01/10/2026, 09:00', 'Ana Souza criou o cadastro']], $this->linhasDaTela($c));
        self::assertCount(0, $painel->filter('.cad-hist-vazio'));
        self::assertCount(0, $painel->filter('.js-cad-pend'), 'o bloco Pendências não mora no painel do Histórico');
    }

    #[TestDox('aba Histórico mostra só as 40 mais novas')]
    public function testAbaLimite(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        for ($i = 1; $i <= 42; ++$i) {
            $this->gravar($tenant, $cliente, $gestor, 'linha ' . $i, sprintf('2026-10-01 10:%02d:00', $i));
        }
        $this->logarComTenant($this->client, $gestor, $tenant);

        $linhas = $this->linhasDaTela($this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro'));

        self::assertCount(40, $linhas);
        self::assertSame('linha 42', $linhas[0][1]);
        self::assertSame('linha 3', $linhas[39][1]);
    }

    #[TestDox('sem linha, e no Novo cliente: "Nenhuma alteração registrada ainda."')]
    public function testVazio(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Welton');
        $this->logarComTenant($this->client, $gestor, $tenant);

        foreach (['/clientes/' . $cliente->getId() . '/cadastro', '/pasta/' . $pasta->getId() . '/cadastro/novo'] as $url) {
            $c = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $painel = $c->filter('[data-cad-painel="hist"]');
            self::assertSame('Nenhuma alteração registrada ainda.', trim($painel->filter('.cad-hist-vazio')->text()), $url);
            self::assertCount(0, $painel->filter('.cad-hist-linha'), $url);
            self::assertNotNull($painel->attr('hidden'), 'fora da aba inicial o painel vem escondido');
        }
    }

    #[TestDox('outro escritório: o cadastro é 404 e o histórico dele nunca aparece no meu')]
    public function testOutroEscritorio(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'Ana Souza');
        $gestorB = $this->criarGestor($tenantB, 'Bruno Lima');
        $clienteA = $this->criarClientePF($tenantA, 'Cliente A');
        $clienteB = $this->criarClientePF($tenantB, 'Cliente B');
        $this->gravar($tenantA, $clienteA, $gestorA, 'Ana Souza alterou nome', '2026-10-01 09:00:00');
        $this->gravar($tenantB, $clienteB, $gestorB, 'Bruno Lima alterou CPF', '2026-10-02 09:00:00');
        $this->logarComTenant($this->client, $gestorA, $tenantA);

        $this->client->request('GET', '/clientes/' . $clienteB->getId() . '/cadastro');
        self::assertResponseStatusCodeSame(404);

        $c = $this->client->request('GET', '/clientes/' . $clienteA->getId() . '/cadastro');
        self::assertSame([['01/10/2026, 09:00', 'Ana Souza alterou nome']], $this->linhasDaTela($c));
        self::assertStringNotContainsString('Bruno Lima', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('criar o cliente pelo "Cadastrar cliente" antigo da pasta (pasta_cliente_novo) grava "criou o cadastro"')]
    public function testCriarPeloCadastroAntigoDaPasta(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Welton');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/cliente/novo', [
            '_token' => 'TOKEN_pasta_cliente_novo_' . $pasta->getId(), 'tipo' => 'pf', 'nomeCompleto' => 'Welton Alves',
            'cpf' => '529.982.247-25', 'rg' => '123', 'rgOrgaoExpedidor' => 'SSP/DF', 'email' => 'w_' . uniqid() . '@t.com',
            'cep' => '70000-000', 'endereco' => 'SQS 110', 'cidade' => 'Brasília', 'estado' => 'DF',
        ]);

        self::assertResponseIsSuccessful();
        $id = (int) $this->em()->getConnection()->fetchOne("SELECT c.id FROM cliente c JOIN cliente_pf p ON p.id = c.id WHERE c.tenant_id = ? AND p.cpf = '529.982.247-25'", [$tenant->getId()]);
        self::assertSame(['Ana Souza criou o cadastro'], array_column($this->linhas($id), 'texto'));
    }

    #[TestDox('ficha antiga (cliente_edit): grava "alterou <rótulos>" dos campos que mudaram; reenviar igual não grava')]
    public function testFichaAntiga(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $id = (int) $cliente->getId();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->em()->clear();
        $form = $this->client->request('GET', '/clientes/' . $id . '/editar')->selectButton('Salvar Alterações')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/clientes/' . $id);
        self::assertSame([], $this->linhas($id), 'nada mudou');

        $this->em()->clear();
        $form = $this->client->request('GET', '/clientes/' . $id . '/editar')->selectButton('Salvar Alterações')->form();
        $form['cliente_pf[estadoCivil]'] = 'CASADO';
        $form['cliente_pf[complemento]'] = 'Apto 101';
        $form['cliente_pf[telefoneFixo]'] = '(61) 3425-8844';
        $this->client->submit($form);

        self::assertResponseRedirects('/clientes/' . $id);
        $textos = array_column($this->linhas($id), 'texto');
        self::assertSame(['Ana Souza alterou estado civil, complemento, telefone fixo'], $textos);
        self::assertStringNotContainsString('101', $textos[0]);
    }

    #[TestDox('salvar recusado (CPF inválido, 422) não grava linha nenhuma')]
    public function testSalvarRecusadoNaoGrava(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar((int) $cliente->getId(), ['bairro' => 'Asa Sul', 'cpf' => '529.982.247-26']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_historico WHERE cliente_id = ?', [$cliente->getId()]));
    }

    #[TestDox('ficha antiga: emissão do RG (PF) e endereço da sede (PJ) entram pelo rótulo')]
    public function testFichaAntigaCamposForaDaJanela(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $pf = $this->criarClientePF($tenant, 'Maria das Dores');
        $pj = $this->criarClientePJ($tenant);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->em()->clear();
        $form = $this->client->request('GET', '/clientes/' . $pf->getId() . '/editar')->selectButton('Salvar Alterações')->form();
        $form['cliente_pf[rgDataEmissao]'] = '2010-05-04';
        $this->client->submit($form);
        self::assertResponseRedirects('/clientes/' . $pf->getId());

        $this->em()->clear();
        $form = $this->client->request('GET', '/clientes/' . $pj->getId() . '/editar')->selectButton('Salvar Alterações')->form();
        $form['cliente_pj[enderecSede]'] = 'Setor Comercial Sul, Quadra 2';
        $this->client->submit($form);
        self::assertResponseRedirects('/clientes/' . $pj->getId());

        self::assertSame(['Ana Souza alterou emissão do RG'], array_column($this->linhas((int) $pf->getId()), 'texto'));
        self::assertSame(['Ana Souza alterou endereço da sede'], array_column($this->linhas((int) $pj->getId()), 'texto'));
    }

    #[TestDox('editar documento trocando o tipo: o texto usa o tipo de ANTES')]
    public function testEditarDocumentoTrocandoTipo(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $doc = (new ClienteDocumento())->setCliente($cliente)->setTenant($tenant)->setCategoria(ClienteDocumento::CATEGORIA_CONTRATO)
            ->setTitulo('c.pdf')->setNomeOriginal('c.pdf')->setCaminhoArquivo('fake/' . uniqid() . '.pdf')->setMimeType('application/pdf')->setTamanhoBytes(10);
        $this->em()->persist($doc);
        $this->em()->flush();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->editarDocumento((int) $doc->getId(), ['categoria' => 'PROCURACAO']);

        self::assertSame('PROCURACAO', $this->em()->getConnection()->fetchOne('SELECT categoria FROM cliente_documento WHERE id = ?', [$doc->getId()]));
        self::assertSame(['Ana Souza alterou contrato'], array_column($this->linhas((int) $cliente->getId()), 'texto'));
    }

    #[TestDox('editar os dados do documento (cliente_documento_edit) grava "alterou <tipo>"; reenviar igual não grava')]
    public function testEditarDocumento(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $doc = (new ClienteDocumento())->setCliente($cliente)->setTenant($tenant)->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO)
            ->setTitulo('rg.pdf')->setNomeOriginal('rg.pdf')->setCaminhoArquivo('fake/' . uniqid() . '.pdf')->setMimeType('application/pdf')->setTamanhoBytes(10);
        $this->em()->persist($doc);
        $this->em()->flush();
        $docId = (int) $doc->getId();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->editarDocumento($docId, ['categoria' => 'IDENTIFICACAO']);
        self::assertSame([], $this->linhas((int) $cliente->getId()), 'nada mudou');

        $this->editarDocumento($docId, ['categoria' => 'IDENTIFICACAO', 'numero' => '529.982.247-25', 'nomeBase' => 'rg-maria']);

        $textos = array_column($this->linhas((int) $cliente->getId()), 'texto');
        self::assertSame(['Ana Souza alterou documento de identificação'], $textos);
        self::assertStringNotContainsString('529', $textos[0]);
        self::assertStringNotContainsString('maria', $textos[0]);
    }

    #[TestDox('contato pela janela Detalhes do cliente (cliente_contatos) grava "alterou telefone fixo"')]
    public function testContato(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'Ana Souza');
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $id = (int) $cliente->getId();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->em()->clear();
        $this->client->request('POST', '/clientes/' . $id . '/contatos', ['campo' => 'fixo', 'valor' => '6134258844', '_token' => 'TOKEN_cliente_contatos_' . $id]);

        self::assertResponseIsSuccessful();
        self::assertSame(['Ana Souza alterou telefone fixo'], array_column($this->linhas($id), 'texto'));
    }

    // ----------------------------------------------------------------- helpers

    /** @return list<array{0: string, 1: string}> */
    private function linhasDaTela(Crawler $c): array
    {
        return $c->filter('[data-cad-painel="hist"] > .cad-hist > .cad-hist-linha')->each(
            static fn (Crawler $li): array => [trim($li->filter('.cad-hist-q')->text()), trim($li->filter('.cad-hist-t')->text())],
        );
    }

    /** @return list<array<string, mixed>> mais nova primeiro */
    private function linhas(int $clienteId): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT texto, autor_id, tenant_id FROM cliente_historico WHERE cliente_id = ? ORDER BY em DESC, id DESC', [$clienteId]);
    }

    private function gravar(Tenant $tenant, ClientePF $cliente, User $autor, string $texto, string $em): void
    {
        $h = new ClienteHistorico($tenant, $cliente, $autor, $texto);
        (new \ReflectionProperty($h, 'em'))->setValue($h, new \DateTimeImmutable($em));
        $this->em()->persist($h);
        $this->em()->flush();
    }

    private function enviar(int $clienteId, string $nomeOriginal, string $categoria): void
    {
        $origem = sys_get_temp_dir() . '/cliente_hist_' . bin2hex(random_bytes(6));
        file_put_contents($origem, self::PDF);
        $this->temporarios[] = $origem;
        $this->em()->clear();
        $this->client->request('POST', '/clientes/' . $clienteId . '/documento/upload', [
            '_token' => 'TOKEN_upload_documento_cliente_' . $clienteId, 'categorias' => [0 => $categoria],
        ], ['arquivos' => [0 => new UploadedFile($origem, $nomeOriginal, null, null, true)]]);
    }

    /** @param array<string, string> $campos */
    private function editarDocumento(int $docId, array $campos): void
    {
        $this->em()->clear();
        $this->client->request('POST', '/clientes/documento/' . $docId . '/editar', $campos + ['_token' => 'TOKEN_edit_doc_cliente_' . $docId]);
        self::assertResponseRedirects();
    }

    /** @param array<string, string> $campos */
    private function salvar(int $clienteId, array $campos, ?int $pastaId = null): void
    {
        $this->em()->clear();
        $dados = $campos + ['_token' => 'TOKEN_cliente_cadastro_' . $clienteId];
        if ($pastaId !== null) {
            $dados['pasta'] = (string) $pastaId;
        }
        $this->client->xmlHttpRequest('POST', '/clientes/' . $clienteId . '/cadastro', $dados);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private static function cpf(int $n): string
    {
        $base = str_pad((string) (100000000 + $n * 7919 % 800000000), 9, '0', STR_PAD_LEFT);
        foreach ([9, 10] as $t) {
            $s = 0;
            for ($i = 0; $i < $t; ++$i) {
                $s += (int) $base[$i] * ($t + 1 - $i);
            }
            $base .= (string) (($s * 10) % 11 % 10);
        }

        return substr($base, 0, 3) . '.' . substr($base, 3, 3) . '.' . substr($base, 6, 3) . '-' . substr($base, 9, 2);
    }

    private function criarClientePF(Tenant $tenant, string $nome): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf(self::cpf(++self::$seq + random_int(1, 90000)));
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('hist_' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function criarClientePJ(Tenant $tenant): ClientePJ
    {
        $cliente = new ClientePJ();
        $cliente->setRazaoSocial('Empresa Histórico ' . uniqid())->setCnpj('11222333000181')->setEnderecSede('SCS Quadra 1')
            ->setRepresentanteLegal('Rep')->setRepresentanteCpf('529.982.247-25')->setRepresentanteRg('123')->setRepresentanteCargo('Diretor');
        $cliente->setEmail('pj_' . uniqid() . '@test.com')->setCep('70000-000')->setEndereco('SCS')->setCidade('Brasília')->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function criarPasta(Tenant $tenant, User $criador, ClientePF $cliente): Pasta
    {
        $pasta = $this->novaPasta($tenant, $criador);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        return $pasta;
    }

    private function criarPastaSemCliente(Tenant $tenant, User $criador, string $nomeCliente): Pasta
    {
        $pasta = $this->novaPasta($tenant, $criador);
        $pasta->setNomeCliente($nomeCliente);
        $pasta->setNomeAcao('Ação de teste');
        $this->em()->flush();

        return $pasta;
    }

    private function novaPasta(Tenant $tenant, User $criador): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('HIST-' . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $pasta->setResponsavel($criador);
        $this->em()->persist($pasta);

        return $pasta;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Histórico ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('hist_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }
}
