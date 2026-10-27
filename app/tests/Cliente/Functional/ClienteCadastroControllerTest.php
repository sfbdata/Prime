<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Controller\ClienteCadastroController;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\UseCase\SalvarCadastroDoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Janela "Cadastro e qualificação do cliente" do botão Cadastro da pasta (desenho 1.2.3; E12f fatia 3a): fragmento,
 * salvar com cartão no Registro, validações, CPF/CNPJ de outro cadastro, permissão e escritório.
 */
#[CoversClass(ClienteCadastroController::class)]
#[CoversClass(SalvarCadastroDoClienteUseCase::class)]
final class ClienteCadastroControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    #[TestDox('janela de PF: abas Cliente, Endereço, Contato e Qualificação, campos preenchidos, pendências e Salvar')]
    public function testJanelaPessoaFisica(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Gleisson Bruno Gabriel');
        $pasta = $this->criarPasta($tenant, $gestor, $cliente);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?pasta=' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('Cadastro e qualificação do cliente', $c->filter('.cad-janela')->attr('aria-label'));
        self::assertSame(['pessoais', 'endereco', 'contato', 'docs', 'reu', 'qual', 'hist'], $c->filter('[data-cad-aba]')->each(fn ($b) => $b->attr('data-cad-aba')));
        self::assertSame('GLEISSON BRUNO GABRIEL', trim($c->filter('.js-cad-nome')->text()));
        self::assertSame('CPF ' . $cliente->getCpf(), trim($c->filter('.js-cad-doc')->text()), 'subtítulo com o número, não só o rótulo');
        self::assertSame('Número não informado', $c->filter('input[name="numero"]')->attr('data-obrig'));
        self::assertSame('brasileiro(a)', $c->filter('input[name="nacionalidade"]')->attr('value'));
        self::assertCount(1, $c->filter('input[name="rg"]'));
        self::assertCount(1, $c->filter('input[name="rg_orgao"]'));
        self::assertCount(1, $c->filter('.js-cad-salvar'));
        self::assertSame([['texto' => 'Documento de identificação não anexado', 'aba' => 'docs'], ['texto' => 'Comprovante de residência não anexado', 'aba' => 'docs']], json_decode((string) $c->filter('[data-cad-janela]')->attr('data-pend-docs'), true), 'lista, não objeto: o JS soma às recalculadas');
        self::assertStringContainsString('GLEISSON BRUNO GABRIEL', $c->filter('.js-cad-qual')->text());
        self::assertCount(1, $c->filter('.js-cad-existente-usar'), 'quem edita a pasta pode usar outro cadastro');
    }

    #[TestDox('salvar muda só o enviado, lista os alterados e escreve "Cadastro do cliente: …" no Registro da pasta')]
    public function testSalvarComCartao(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Maria das Dores');
        $pasta = $this->criarPasta($tenant, $gestor, $cliente);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar($cliente->getId(), ['estado_civil' => 'CASADO', 'numero' => '101', 'bairro' => 'Asa Sul', 'genero' => 'FEMININO'], $pasta->getId());

        self::assertResponseIsSuccessful();
        $j = $this->json();
        self::assertSame(['estado civil', 'gênero', 'número', 'bairro'], $j['alterados']);
        self::assertSame('Cadastro salvo. Ainda há pendências.', $j['mensagem']);
        self::assertStringContainsString('casada', $j['qualificacao']);
        $conn = $this->em()->getConnection();
        $linha = $conn->fetchAssociative('SELECT c.numero, c.bairro, p.estado_civil, p.genero, p.nome_completo FROM cliente c JOIN cliente_pf p ON p.id = c.id WHERE c.id = ?', [$cliente->getId()]);
        self::assertSame(['101', 'ASA SUL', 'CASADO', 'FEMININO', 'MARIA DAS DORES'], array_values($linha));
        $cartao = (string) $conn->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro'", [$pasta->getId()]);
        self::assertStringContainsString('Cadastro do cliente:', $cartao);
        self::assertStringContainsString('MARIA DAS DORES: estado civil, gênero, número, bairro alterado(s).', $cartao);
    }

    #[TestDox('salvar sem mudança não grava cartão')]
    public function testSemMudanca(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $pasta = $this->criarPasta($tenant, $gestor, $cliente);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar($cliente->getId(), ['nome' => 'Ana'], $pasta->getId());

        self::assertFalse($this->json()['mudou']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro'", [$pasta->getId()]));
    }

    #[TestDox('nada é obrigatório (nome e CPF vazios salvam); CPF inválido: 422 com a aba Cliente')]
    public function testValidacoes(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar($cliente->getId(), ['nome' => '  ', 'cpf' => '']);
        self::assertResponseIsSuccessful();

        $this->salvar($cliente->getId(), ['cpf' => '529.982.247-26']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'CPF inválido: confira os dígitos.', 'aba' => 'pessoais'], $this->json());
    }

    #[TestDox('CPF de outro cadastro do escritório: 409 com o cadastro existente; a conferência ao sair do campo também acha')]
    public function testCpfDeOutroCadastro(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $outro = $this->criarClientePF($tenant, 'Beatriz Souza');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar($cliente->getId(), ['cpf' => $outro->getCpf()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Este CPF já possui cadastro no sistema.', $this->json()['erro']);
        self::assertSame($outro->getId(), $this->json()['existente']['id']);
        self::assertSame('CPF ' . $outro->getCpf(), $this->json()['existente']['documento']);

        $this->client->xmlHttpRequest('GET', '/clientes/' . $cliente->getId() . '/cadastro/documento?doc=' . preg_replace('/\D/', '', $outro->getCpf()));
        self::assertSame('BEATRIZ SOUZA', $this->json()['existente']['nome']);
    }

    #[TestDox('cliente de outro escritório: 404 na janela e no salvar')]
    public function testOutroEscritorio(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $alheio = $this->criarClientePF($this->criarTenant(), 'Alheio');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->client->request('GET', '/clientes/' . $alheio->getId() . '/cadastro');
        self::assertResponseStatusCodeSame(404);
        $this->salvar($alheio->getId(), ['nome' => 'Invasor']);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('quem só VÊ o cliente: campos travados, sem Salvar; o servidor recusa salvar (403)')]
    public function testSoVe(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $leitor = $this->criarColaborador($tenant, ['resources.cliente.view']);
        $this->conceder($leitor, $tenant, 'cliente', (int) $cliente->getId());
        $this->logarComTenant($this->client, $leitor, $tenant);

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $c->filter('.js-cad-salvar'));
        self::assertSame('disabled', $c->filter('input[name="nome"]')->attr('disabled'));

        $this->salvar($cliente->getId(), ['nome' => 'Outra']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ANA', $this->em()->getConnection()->fetchOne('SELECT nome_completo FROM cliente_pf WHERE id = ?', [$cliente->getId()]));
    }

    #[TestDox('empresa: aba "Empresa" com razão social e representante; CNPJ inválido é recusado')]
    public function testPessoaJuridica(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pj = new ClientePJ();
        $pj->setRazaoSocial('Condomínio Sol')->setCnpj('11222333000181')->setEnderecSede('')->setRepresentanteLegal('')
            ->setRepresentanteCpf('')->setRepresentanteRg('')->setRepresentanteCargo('');
        $pj->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');
        $pj->setTenant($tenant);
        $this->em()->persist($pj);
        $this->em()->flush();
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/clientes/' . $pj->getId() . '/cadastro');
        self::assertStringContainsString('Empresa', $c->filter('[data-cad-aba="pessoais"]')->text());
        self::assertSame('Representante legal não informado', $c->filter('input[name="representante_legal"]')->attr('data-obrig'));

        $this->salvar($pj->getId(), ['cnpj' => '11.222.333/0001-82']);
        self::assertResponseStatusCodeSame(422);

        $this->salvar($pj->getId(), ['representante_legal' => 'João Síndico', 'representante_cpf' => '529.982.247-25']);
        self::assertResponseIsSuccessful();
        self::assertSame(['representante legal', 'CPF do representante'], $this->json()['alterados']);
    }

    #[TestDox('o botão Cadastro do cabeçalho da pasta abre a janela nova')]
    public function testBotaoDoCabecalho(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $pasta = $this->criarPasta($tenant, $gestor, $cliente);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $botao = $c->filter('#psCadastroAbrir');
        self::assertStringContainsString('js-cad-abrir', (string) $botao->attr('class'));
        self::assertSame('/clientes/' . $cliente->getId() . '/cadastro?pasta=' . $pasta->getId(), $botao->attr('data-url'));
    }

    #[TestDox('o formulário inteiro, como a janela devolve (CEP só com dígitos, e-mail com maiúsculas), salva sem mudar nada')]
    public function testFormularioInteiroSemMudanca(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana Lima');
        $cliente->setCep('70000000')->setEmail('Ana@Exemplo.com');
        $cliente->setNacionalidade('brasileiro(a)');
        $cliente->setEstadoCivil('SEPARADA');
        $this->em()->flush();
        $pasta = $this->criarPasta($tenant, $gestor, $cliente);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?pasta=' . $pasta->getId());
        $campos = [];
        $c->filter('form.cad-corpo input[name], form.cad-corpo select[name]')->each(function ($n) use (&$campos): void {
            $campos[$n->attr('name')] = $n->nodeName() === 'select'
                ? (string) ($n->filter('option[selected]')->count() ? $n->filter('option[selected]')->attr('value') : '')
                : (string) $n->attr('value');
        });
        self::assertSame('70000-000', $campos['cep'], 'a janela mostra o CEP com máscara');

        $this->salvar($cliente->getId(), $campos, $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['mudou'], 'nada mudou: ' . implode(', ', $this->json()['alterados']));
        $conn = $this->em()->getConnection();
        self::assertSame('70000000', $conn->fetchOne('SELECT cep FROM cliente WHERE id = ?', [$cliente->getId()]), 'formato antigo não é regravado');
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro'", [$pasta->getId()]));
    }

    #[TestDox('campo maior que a coluna: 422 com a aba do campo, não erro do banco')]
    public function testCampoLongoDemais(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar($cliente->getId(), ['numero' => str_repeat('9', 21)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Número passa de 20 caracteres.', 'aba' => 'endereco'], $this->json());
    }

    #[TestDox('pasta de outro escritório no envio: salva o cliente, mas não escreve cartão nela')]
    public function testPastaDeOutroEscritorioNaoGanhaCartao(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $outroTenant = $this->criarTenant();
        $alheia = $this->criarPasta($outroTenant, $this->criarGestor($outroTenant), $this->criarClientePF($outroTenant, 'Zé'));
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->salvar($cliente->getId(), ['profissao' => 'Professora'], $alheia->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ?', [$alheia->getId()]));
    }

    #[TestDox('CPF de cadastro que o usuário não pode ver: 409 sem dizer de quem é')]
    public function testCpfDeCadastroInvisivel(): void
    {
        $tenant = $this->criarTenant();
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $oculto = $this->criarClientePF($tenant, 'Oculto da Silva');
        $editor = $this->criarColaborador($tenant, []);
        $ra = new ResourceAccess();
        $ra->setUser($editor);
        $ra->setTenant($tenant);
        $ra->setResourceType('cliente');
        $ra->setResourceId((int) $cliente->getId());
        $ra->setCanView(true);
        $ra->setCanEdit(true);
        $this->em()->persist($ra);
        $this->em()->flush();
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->salvar($cliente->getId(), ['cpf' => $oculto->getCpf()]);

        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->json()['existente']);
        self::assertStringNotContainsString('OCULTO', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('aba Documentos: sem anexo, os dois cartões "não anexado" com Anexar; com anexo, Visualizar, Baixar, Substituir e Excluir')]
    public function testAbaDocumentos(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?aba=docs');
        self::assertSame('true', $c->filter('[data-cad-aba="docs"]')->attr('aria-selected'));
        self::assertNull($c->filter('[data-cad-painel="docs"]')->attr('hidden'));
        self::assertNotNull($c->filter('[data-cad-painel="pessoais"]')->attr('hidden'));
        $ident = $c->filter('[data-cad-doc="IDENTIFICACAO"]');
        self::assertStringContainsString('CNH ou RG · não anexado', $ident->text());
        self::assertCount(1, $ident->filter('.js-cad-doc-anexar'));
        self::assertStringContainsString('Conta de consumo, contrato de locação ou similar · não anexado', $c->filter('[data-cad-doc="COMPROVANTE_RESIDENCIA"]')->text());
        self::assertCount(0, $c->filter('.cad-ocr'));

        $doc = $this->anexar($cliente, 'IDENTIFICACAO', 'rg.pdf');
        $this->em()->clear();
        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?aba=docs&ocr=1');

        $ident = $c->filter('[data-cad-doc="IDENTIFICACAO"]');
        self::assertStringContainsString('is-anexado', (string) $ident->attr('class'));
        self::assertSame((string) $doc->getId(), $ident->attr('data-doc-id'));
        self::assertStringContainsString('rg.pdf · anexado em', $ident->text());
        self::assertSame(['Visualizar', 'Baixar', 'Substituir', 'Excluir'], $ident->filter('.cad-doc-acoes .cad-btn')->each(fn ($b) => trim($b->text())));
        self::assertSame('/clientes/documento/' . $doc->getId() . '/deletar', $ident->filter('.js-cad-doc-excluir')->attr('data-url'));
        self::assertStringContainsString('O serviço de leitura (OCR) não está configurado neste ambiente.', $c->filter('.cad-ocr')->text());
        self::assertStringNotContainsString('Documento de identificação não anexado', (string) $c->filter('[data-cad-janela]')->attr('data-pend-docs'));
    }

    #[TestDox('quem só VÊ o cliente vê os anexos mas não Anexar, Substituir nem Excluir')]
    public function testAbaDocumentosSoVe(): void
    {
        $tenant = $this->criarTenant();
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $this->anexar($cliente, 'COMPROVANTE_RESIDENCIA', 'luz.pdf');
        $leitor = $this->criarColaborador($tenant, ['resources.cliente.view']);
        $this->conceder($leitor, $tenant, 'cliente', (int) $cliente->getId());
        $this->logarComTenant($this->client, $leitor, $tenant);
        $this->em()->clear();

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?aba=docs');

        self::assertCount(0, $c->filter('.js-cad-doc-anexar, .js-cad-doc-excluir, .js-cad-doc-arquivo'));
        self::assertSame(['Visualizar', 'Baixar'], $c->filter('[data-cad-doc="COMPROVANTE_RESIDENCIA"] .cad-doc-acoes .cad-btn')->each(fn ($b) => trim($b->text())));
    }

    #[TestDox('quem vê o cliente mas não a pasta: a janela abre sem a aba Réu e sem usar outro cadastro naquela pasta')]
    public function testPastaQueNaoVe(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Ana');
        $pasta = $this->criarPasta($tenant, $gestor, $cliente);
        $leitor = $this->criarColaborador($tenant, []);
        $this->conceder($leitor, $tenant, 'cliente', (int) $cliente->getId());
        $this->logarComTenant($this->client, $leitor, $tenant);

        $c = $this->client->request('GET', '/clientes/' . $cliente->getId() . '/cadastro?pasta=' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $c->filter('[data-cad-aba="reu"]'));
        self::assertSame('', (string) $c->filter('[data-cad-janela]')->attr('data-pasta'));
    }

    #[TestDox('pasta sem cliente: o botão Cadastro aparece e abre o "Novo cliente" com o nome da pasta')]
    public function testNovoClienteJanela(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Welton Alves da Silva');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame('/pasta/' . $pasta->getId() . '/cadastro/novo', $c->filter('#psCadastroAbrir')->attr('data-url'));

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId() . '/cadastro/novo');
        self::assertResponseIsSuccessful();
        self::assertSame('1', $c->filter('[data-cad-janela]')->attr('data-novo'));
        self::assertSame('WELTON ALVES DA SILVA', trim($c->filter('.js-cad-nome')->text()));
        self::assertSame('WELTON ALVES DA SILVA', $c->filter('input[name="nome"]')->attr('value'));
        self::assertSame(['pessoais', 'endereco', 'contato', 'reu', 'qual', 'hist'], $c->filter('[data-cad-aba]')->each(fn ($b) => $b->attr('data-cad-aba')));
        self::assertSame(['Pessoa física', 'Empresa'], $c->filter('.js-cad-tipo')->each(fn ($b) => trim($b->text())));
    }

    #[TestDox('salvar o "Novo cliente" cria o cliente, liga à pasta como principal e escreve "cadastro de … criado."')]
    public function testNovoClienteCria(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Welton Alves da Silva');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->criar($pasta, ['nome' => 'Welton Alves da Silva', 'cpf' => '529.982.247-25', 'numero' => '10']);

        self::assertResponseStatusCodeSame(201);
        $conn = $this->em()->getConnection();
        $id = (int) $conn->fetchOne("SELECT c.id FROM cliente c JOIN cliente_pf p ON p.id = c.id WHERE c.tenant_id = ? AND p.cpf = '529.982.247-25'", [$tenant->getId()]);
        self::assertGreaterThan(0, $id);
        self::assertSame('/clientes/' . $id . '/cadastro?pasta=' . $pasta->getId(), $this->json()['urlJanela']);
        self::assertSame($id, (int) $conn->fetchOne('SELECT cliente_principal_id FROM pasta WHERE id = ?', [$pasta->getId()]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = ? AND cliente_id = ?', [$pasta->getId(), $id]));
        self::assertStringContainsString('cadastro de WELTON ALVES DA SILVA criado.', (string) $conn->fetchOne("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadastro'", [$pasta->getId()]));
    }

    #[TestDox('"Novo cliente" com CPF inválido: 422; com CPF de outro cadastro: 409 e nada criado')]
    public function testNovoClienteRecusas(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $outro = $this->criarClientePF($tenant, 'Já Existe');
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Fulano');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->criar($pasta, ['nome' => 'Fulano', 'cpf' => '529.982.247-26']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('CPF inválido: confira os dígitos.', $this->json()['erro']);

        $this->criar($pasta, ['nome' => 'Fulano', 'cpf' => $outro->getCpf()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($outro->getId(), $this->json()['existente']['id']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = ?', [$pasta->getId()]));
    }

    #[TestDox('quem só vê a pasta abre o "Novo cliente" sem Salvar, e o servidor recusa criar (403)')]
    public function testNovoClienteSoVe(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Fulano');
        $leitor = $this->criarColaborador($tenant, []);
        $this->conceder($leitor, $tenant, 'pasta', (int) $pasta->getId());
        $this->logarComTenant($this->client, $leitor, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId() . '/cadastro/novo');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $c->filter('.js-cad-salvar'));

        $this->criar($pasta, ['nome' => 'Fulano', 'cpf' => '529.982.247-25']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('seletor de autores: os clientes da pasta com o atual marcado; "Novo autor" cria outro sem trocar o principal')]
    public function testAutores(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $ana = $this->criarClientePF($tenant, 'Ana Autora');
        $pasta = $this->criarPasta($tenant, $gestor, $ana);
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId() . '/cadastro/novo?autor=1');
        self::assertSame('Novo cliente', trim($c->filter('.js-cad-nome')->text()));
        $this->criar($pasta, ['nome' => 'Bruno Autor', 'cpf' => '529.982.247-25']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($ana->getId(), (int) $this->em()->getConnection()->fetchOne('SELECT cliente_principal_id FROM pasta WHERE id = ?', [$pasta->getId()]));

        $this->em()->clear();
        $c = $this->client->request('GET', '/clientes/' . $ana->getId() . '/cadastro?pasta=' . $pasta->getId());
        $chips = $c->filter('.cad-autores .js-cad-autor');
        self::assertSame(['ANA AUTORAPrincipal', 'BRUNO AUTOR', 'Novo autor'], $chips->each(fn ($b) => trim(preg_replace('/\s+/', ' ', $b->text()))));
        self::assertSame('true', $c->filter('.js-cad-autor.is-ativo')->attr('aria-current'));
    }

    #[TestDox('autores numa ordem fixa: o principal primeiro mesmo fora da ordem alfabética, depois o nome')]
    public function testAutoresPrincipalPrimeiro(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $zelia = $this->criarClientePF($tenant, 'Zélia Autora');
        $pasta = $this->criarPasta($tenant, $gestor, $zelia);
        $this->logarComTenant($this->client, $gestor, $tenant);
        $this->criar($pasta, ['nome' => 'Bruno Autor', 'cpf' => '529.982.247-25']);
        $this->criar($pasta, ['nome' => 'Amanda Autora', 'cpf' => '111.444.777-35']);

        $this->em()->clear();
        $c = $this->client->request('GET', '/clientes/' . $zelia->getId() . '/cadastro?pasta=' . $pasta->getId());

        self::assertSame(['ZÉLIA AUTORAPrincipal', 'AMANDA AUTORA', 'BRUNO AUTOR', 'Novo autor'], $c->filter('.cad-autores .js-cad-autor')->each(fn ($b) => trim(preg_replace('/\s+/', ' ', $b->text()))));
    }

    #[TestDox('"Novo cliente" como empresa: aba Empresa e cria pessoa jurídica')]
    public function testNovoClienteEmpresa(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta = $this->criarPastaSemCliente($tenant, $gestor, 'Condomínio Sol');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId() . '/cadastro/novo?tipo=pj');
        self::assertStringContainsString('Empresa', $c->filter('[data-cad-aba="pessoais"]')->text());
        self::assertSame('CONDOMÍNIO SOL', $c->filter('input[name="razao_social"]')->attr('value'));

        $this->criar($pasta, ['tipo' => 'pj', 'razao_social' => 'Condomínio Sol', 'cnpj' => '11.222.333/0001-81']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM cliente_pj WHERE cnpj = '11222333000181'"));
    }

    #[TestDox('"Novo cliente" de pasta de outro escritório: 404 na janela e no salvar, nada criado')]
    public function testNovoClienteOutroEscritorio(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $outro = $this->criarTenant();
        $alheia = $this->criarPastaSemCliente($outro, $this->criarGestor($outro), 'Alheio');
        $this->logarComTenant($this->client, $gestor, $tenant);

        $this->client->request('GET', '/pasta/' . $alheia->getId() . '/cadastro/novo');
        self::assertResponseStatusCodeSame(404);
        $this->criar($alheia, ['nome' => 'Invasor', 'cpf' => '529.982.247-25']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = ?', [$alheia->getId()]));
    }

    /** @param array<string, string> $campos */
    private function criar(Pasta $pasta, array $campos): void
    {
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/cadastro/novo', $campos + ['_token' => 'TOKEN_pasta_cadastro_novo_' . $pasta->getId()]);
    }

    private function criarPastaSemCliente(Tenant $tenant, User $criador, string $nomeCliente): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('NOVO-' . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $pasta->setResponsavel($criador);
        $pasta->setNomeCliente($nomeCliente);
        $pasta->setNomeAcao('Ação de teste');
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    // ----------------------------------------------------------------- helpers

    private function anexar(ClientePF $cliente, string $categoria, string $nome): \App\Cliente\Entity\ClienteDocumento
    {
        $doc = new \App\Cliente\Entity\ClienteDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo('fake/' . uniqid() . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(2048);
        $doc->setCliente($cliente);
        $doc->setTenant($cliente->getTenant());
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    /** @param array<string, string> $campos */
    private function salvar(?int $clienteId, array $campos, ?int $pastaId = null): void
    {
        $this->em()->clear();
        $dados = $campos + ['_token' => 'TOKEN_cliente_cadastro_' . $clienteId];
        if ($pastaId !== null) {
            $dados['pasta'] = (string) $pastaId;
        }
        $this->client->xmlHttpRequest('POST', '/clientes/' . $clienteId . '/cadastro', $dados);
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
        $cliente->setEmail('cad_' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function criarPasta(Tenant $tenant, User $criador, ClientePF $cliente): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('CAD-' . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $pasta->setResponsavel($criador);
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Resumo ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function novoUsuario(string $prefixo): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName(ucfirst($prefixo) . ' ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('gestor');

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

    /** @param list<string> $codigos permissões do papel (ex.: resources.cliente.view) */
    private function criarColaborador(Tenant $tenant, array $codigos): User
    {
        $em   = $this->em();
        $user = $this->novoUsuario('colab');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup('resources');
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

    private function conceder(User $user, Tenant $tenant, string $tipo, int $recursoId): void
    {
        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setTenant($tenant);
        $ra->setResourceType($tipo);
        $ra->setResourceId($recursoId);
        $ra->setCanView(true);
        $this->em()->persist($ra);
        $this->em()->flush();
    }
}
