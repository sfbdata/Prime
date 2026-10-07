<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote L2 da Trilha B (itens 3 e 4 de `pendencias-pos-documentos.md`), desenho 1.2.3:
 *  - botão "Cadastro" na faixa das abas, antes da Situação, com o ponto VERDE
 *    (cadastro completo) ou ÂMBAR (falta algo) — a regra é a do
 *    `PendenciasDoCadastro`, sobre o cliente PRINCIPAL da pasta;
 *  - abas de atalho (Dados da pasta · Cliente · Processo · Histórico) e o botão
 *    "Cadastro do cliente" no modal "Editar dados", SEM tirar campo nenhum do form.
 *
 * Arranjo com combinador de FILHO DIRETO. Cor do ponto, tamanhos e o que o clique
 * abre (JS) são invisíveis ao PHPUnit — smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaCadastroCabecalhoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    /**
     * Os `name` do form do Editar dados ANTES do lote L2 (contrato do
     * `PastaController::edit`). Atalho que entrasse no form com `name` — ou campo
     * que saísse — mudaria esta lista.
     */
    private const NAMES_DO_FORM = ['_token', 'nup', 'nome_cliente', 'nome_acao', 'situacao'];

    /**
     * Cliente PF com tudo o que o `PendenciasDoCadastro` cobra, menos o que vier em
     * `$faltando` ('estadoCivil', 'profissao', 'anexos').
     *
     * @param list<string> $faltando
     */
    private function criarCliente(Tenant $tenant, Pasta $pasta, string $nome, string $cpf, array $faltando = []): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setEmail('l2' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf($cpf);
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        if (!\in_array('estadoCivil', $faltando, true)) {
            $cliente->setEstadoCivil('CASADO');
        }
        if (!\in_array('profissao', $faltando, true)) {
            $cliente->setProfissao('Professora');
        }
        $this->em()->persist($cliente);

        if (!\in_array('anexos', $faltando, true)) {
            foreach ([ClienteDocumento::CATEGORIA_IDENTIFICACAO, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA] as $categoria) {
                $doc = new ClienteDocumento();
                $doc->setTenant($tenant);
                $doc->setTitulo('Anexo ' . $categoria);
                $doc->setCategoria($categoria);
                $doc->setCaminhoArquivo('arquivo_' . uniqid() . '.pdf');
                $doc->setNomeOriginal('anexo.pdf');
                $doc->setMimeType('application/pdf');
                $doc->setTamanhoBytes(1024);
                $cliente->addDocumento($doc);
                $this->em()->persist($doc);
            }
        }

        $pasta->addCliente($cliente);

        return $cliente;
    }

    #[TestDox('Cadastro completo: botão na faixa das abas, entre as abas e a Situação, com o ponto verde e a dica do desenho')]
    public function testBotaoComCadastroCompleto(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->criarCliente($tenant, $pasta, 'Ana Completa', '11122233344');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $botao = $crawler->filter('.ps-cabecalho > .ps-abas-faixa > button#psCadastroAbrir.ps-cad-btn');
        self::assertCount(1, $botao, 'o botão é filho direto da faixa das abas');
        self::assertCount(1, $crawler->filter('.ps-abas-faixa > #pastaTabs + #psCadastroAbrir'), 'logo depois das abas');
        self::assertCount(1, $crawler->filter('.ps-abas-faixa > #psCadastroAbrir + .ps-situacao-wrap'), 'logo antes da Situação');

        self::assertSame('button', $botao->attr('type'));
        self::assertSame('Cadastro', trim($botao->text()));
        self::assertCount(1, $botao->filter('#psCadastroAbrir > .ps-cad-btn-icone > i.bi-person-vcard'));
        self::assertCount(1, $botao->filter('#psCadastroAbrir > .ps-cad-btn-icone > .ps-cad-ponto.ps-cad-ponto--completo'));
        self::assertCount(0, $botao->filter('.ps-cad-ponto--pendente'));
        self::assertSame('0', $botao->attr('data-pendencias'));
        self::assertSame('Cadastro completo · abrir qualificação do cliente', $botao->attr('title'));
        self::assertSame($botao->attr('title'), $botao->attr('aria-label'));

        // O clique abre a janela "Detalhes do cliente" (pasta-clientes.js): o gancho é a
        // classe + o id do cliente no próprio botão, e a URL do resumo vem do trilho.
        self::assertStringContainsString('js-ps-cli-detalhes', (string) $botao->attr('class'));
        self::assertSame((string) $cliente->getId(), $botao->attr('data-cliente-id'));
        self::assertCount(1, $crawler->filter('[data-trilho="clientes"][data-resumo-url]'));
    }

    #[TestDox('Cadastro incompleto: ponto âmbar e a contagem REAL de pendências na dica (plural e singular)')]
    public function testBotaoComCadastroIncompleto(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();

        // Faltam estado civil, profissão e os dois anexos = 4 pendências.
        $pastaQuatro = $this->criarPasta($tenant);
        $this->criarCliente($tenant, $pastaQuatro, 'Bruno Incompleto', '55566677788', ['estadoCivil', 'profissao', 'anexos']);
        // Falta só a profissão = 1 pendência.
        $pastaUma = $this->criarPasta($tenant);
        $this->criarCliente($tenant, $pastaUma, 'Carla Quase', '99988877766', ['profissao']);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pastaQuatro->getId());
        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('.ps-abas-faixa > #psCadastroAbrir');
        self::assertCount(1, $botao->filter('#psCadastroAbrir > .ps-cad-btn-icone > .ps-cad-ponto.ps-cad-ponto--pendente'));
        self::assertCount(0, $botao->filter('.ps-cad-ponto--completo'));
        self::assertSame('4', $botao->attr('data-pendencias'));
        self::assertSame('Cadastro incompleto: 4 pendências · clique para completar', $botao->attr('title'));

        $crawler = $client->request('GET', '/pasta/' . $pastaUma->getId());
        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('.ps-abas-faixa > #psCadastroAbrir');
        self::assertCount(1, $botao->filter('.ps-cad-ponto--pendente'));
        self::assertSame('1', $botao->attr('data-pendencias'));
        self::assertSame('Cadastro incompleto: 1 pendência · clique para completar', $botao->attr('title'));
    }

    #[TestDox('O botão fala do cliente PRINCIPAL: outro cliente completo na mesma pasta não pinta o ponto de verde')]
    public function testBotaoSegueOClientePrincipal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarCliente($tenant, $pasta, 'Daniel Completo', '12312312312');
        $principal = $this->criarCliente($tenant, $pasta, 'Elisa Principal', '32132132132', ['anexos']);
        $pasta->definirClientePrincipal($principal);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $botao = $crawler->filter('.ps-abas-faixa > #psCadastroAbrir');
        self::assertCount(1, $botao);
        self::assertSame((string) $principal->getId(), $botao->attr('data-cliente-id'));
        self::assertCount(1, $botao->filter('.ps-cad-ponto--pendente'));
        self::assertSame('2', $botao->attr('data-pendencias'));
    }

    #[TestDox('Pasta sem cliente cadastrado: nada de botão Cadastro (não há cadastro para mostrar); a Situação continua')]
    public function testSemClienteNaoDesenhaOBotao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#psCadastroAbrir'));
        self::assertCount(0, $crawler->filter('.ps-cad-btn'));
        self::assertCount(1, $crawler->filter('.ps-abas-faixa > .ps-situacao-wrap'));
    }

    #[TestDox('Modal Editar dados: 4 abas de atalho entre o cabeçalho e o form, "Dados da pasta" ativa, e "Cadastro do cliente" no rodapé')]
    public function testAtalhosDoModal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarCliente($tenant, $pasta, 'Fabio Atalho', '45645645645');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $conteudo = $crawler->filter('#modalEditarPasta > .modal-dialog.ps-modal-pasta > .modal-content');
        self::assertCount(1, $conteudo);
        self::assertCount(1, $conteudo->filter('.modal-content > .ps-modal-pasta-cab + nav.ps-modal-pasta-abas + form'), 'cabeçalho · atalhos · form, nessa ordem');

        $abas = $conteudo->filter('.modal-content > nav.ps-modal-pasta-abas > button.ps-modal-pasta-aba');
        self::assertCount(4, $abas);
        $esperado = [
            ['Dados da pasta', 'bi-folder2',       null],
            ['Cliente',        'bi-person',        'cliente'],
            ['Processo',       'bi-bank',          'processo'],
            ['Histórico',      'bi-clock-history', 'historico'],
        ];
        foreach ($esperado as $i => [$rotulo, $icone, $destino]) {
            $aba = $abas->eq($i);
            self::assertSame($rotulo, trim($aba->text()));
            self::assertSame('button', $aba->attr('type'));
            self::assertCount(1, $aba->filter('button > i.bi.' . $icone));
            self::assertSame($destino, $aba->attr('data-pe-ir'));
        }
        self::assertStringContainsString('is-ativa', (string) $abas->eq(0)->attr('class'));
        self::assertSame('true', $abas->eq(0)->attr('aria-current'));
        self::assertStringNotContainsString('js-pe-ir', (string) $abas->eq(0)->attr('class'), 'a aba ativa não tem ação');
        self::assertCount(1, $conteudo->filter('nav.ps-modal-pasta-abas > .is-ativa'), 'só uma aba ativa');

        $cadastro = $conteudo->filter('form > .ps-modal-pasta-rodape > button.ps-modal-pasta-btn--cadastro.js-pe-ir');
        self::assertCount(1, $cadastro);
        self::assertSame('Cadastro do cliente', trim($cadastro->text()));
        self::assertSame('button', $cadastro->attr('type'), 'não submete o form');
        self::assertSame('cliente', $cadastro->attr('data-pe-ir'));
        self::assertCount(1, $cadastro->filter('button > i.bi-person-vcard'));
        self::assertCount(1, $conteudo->filter('.ps-modal-pasta-btn--fechar + .ps-modal-pasta-btn--cadastro + .ps-modal-pasta-btn--salvar'), 'Fechar · Cadastro do cliente · Salvar');
    }

    #[TestDox('Modal Editar dados: nenhum campo a menos nem a mais — a lista de name= é a de antes do lote, e os atalhos não têm name')]
    public function testNenhumCampoDoFormMuda(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarCliente($tenant, $pasta, 'Gabriela Campos', '78978978978');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $nomesNoModal = $crawler->filter('#modalEditarPasta [name]')->each(fn ($n) => (string) $n->attr('name'));
        self::assertSame(self::NAMES_DO_FORM, $nomesNoModal, 'mesmos campos, mesma ordem');

        $nomesNoForm = $crawler->filter('#modalEditarPasta form [name]')->each(fn ($n) => (string) $n->attr('name'));
        self::assertSame(self::NAMES_DO_FORM, $nomesNoForm, 'todos os campos continuam dentro do form');

        self::assertCount(0, $crawler->filter('#modalEditarPasta nav.ps-modal-pasta-abas [name]'));
        self::assertCount(0, $crawler->filter('#modalEditarPasta form nav'), 'os atalhos ficam fora do form');
        // Nenhum campo escondido pelo lote (a aba "Dados da pasta" é a única e está à mostra).
        self::assertCount(0, $crawler->filter('#modalEditarPasta form .d-none [name], #modalEditarPasta form [name][hidden]'));
    }

    #[TestDox('Sem cliente cadastrado os atalhos do modal continuam todos (Cliente leva à seção Clientes da aba Dados) e o form é o mesmo')]
    public function testAtalhosDoModalSemCliente(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(4, $crawler->filter('#modalEditarPasta .modal-content > nav.ps-modal-pasta-abas > button.ps-modal-pasta-aba'));
        self::assertCount(1, $crawler->filter('#modalEditarPasta form > .ps-modal-pasta-rodape > .ps-modal-pasta-btn--cadastro[data-pe-ir="cliente"]'));
        // O destino de reserva existe na página.
        self::assertCount(1, $crawler->filter('#dados-tab'));
        self::assertCount(1, $crawler->filter('[data-trilho="clientes"]'));
        self::assertSame(
            self::NAMES_DO_FORM,
            $crawler->filter('#modalEditarPasta form [name]')->each(fn ($n) => (string) $n->attr('name'))
        );
    }

    #[TestDox('Isolamento: usuário de outro escritório não abre a pasta alheia nem vê o botão Cadastro ou o cliente dela')]
    public function testOutroEscritorioNaoVe(): void
    {
        $client            = static::createClient();
        [, $tenantA]       = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaA            = $this->criarPasta($tenantA);
        $clienteA          = $this->criarCliente($tenantA, $pastaA, 'Helena Sigilosa', '14714714714');
        $this->em()->flush();

        $idPasta = (int) $pastaA->getId();
        $nome    = (string) $clienteA->getNomeExibicao();

        $this->logarComTenant($client, $userB, $tenantB);
        // Sem o clear() a pasta fica no cache do Doctrine e o TenantFilter não tem o que
        // filtrar (o teste provaria outra barreira).
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $idPasta);

        self::assertResponseStatusCodeSame(404);
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('psCadastroAbrir', $corpo);
        self::assertStringNotContainsString($nome, $corpo);
    }
}
