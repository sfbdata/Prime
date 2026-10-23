<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Editar dados" da pasta (desenho 02, VS 18.10.26): a Ação e o CPF/CNPJ passam a ser SÓ LEITURA
 * (decisão do dono, 23/10/2026; PZ-MOD-N05 e PZ-MOD-16). A Ação "vem do menu Processo" (L.659) com o
 * "Abrir"; o documento é do cliente principal, formatado, com atalho ao cadastro dele. E o POST do
 * `pasta_edit` deixa de gravar a Ação, mesmo que alguém mande `nome_acao`.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaDadosSomenteLeituraTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function abrir(object $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function clientePF(Tenant $tenant, Pasta $pasta, string $cpf): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('MARIA DOCUMENTO');
        $cliente->setCpf($cpf);
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');
        $cliente->setEmail('maria_' . uniqid() . '@test.com');
        $cliente->setCep('');
        $cliente->setEndereco('');
        $cliente->setCidade('');
        $cliente->setEstado('');

        return $this->vincular($pasta, $cliente);
    }

    private function clientePJ(Tenant $tenant, Pasta $pasta, string $cnpj): ClientePJ
    {
        $empresa = new ClientePJ();
        $empresa->setTenant($tenant);
        $empresa->setRazaoSocial('Construtora Documento Ltda');
        $empresa->setNomeFantasia('Construtora Documento');
        $empresa->setCnpj($cnpj);
        $empresa->setEnderecSede('Av. Teste, 1000');
        $empresa->setRepresentanteLegal('Joana Representante');
        $empresa->setRepresentanteCpf('111.222.333-44');
        $empresa->setRepresentanteRg('12.345.678-9');
        $empresa->setRepresentanteCargo('Sócia-administradora');
        $empresa->setEmail('contato_' . uniqid() . '@exemplo.com');
        $empresa->setCep('80000-000');
        $empresa->setEndereco('Rua Teste, 1');
        $empresa->setCidade('Curitiba');
        $empresa->setEstado('PR');

        return $this->vincular($pasta, $empresa);
    }

    /**
     * @template T of Cliente
     *
     * @param T $cliente
     *
     * @return T
     */
    private function vincular(Pasta $pasta, Cliente $cliente): Cliente
    {
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function campoDoDocumento(Crawler $crawler): Crawler
    {
        $campo = $crawler->filter('#modalEditarPasta > .ps-modal-pasta > form > .ps-modal-pasta-corpo > .ps-modal-pasta-linha > .ps-modal-pasta-campo--2[data-pe-documento]');
        self::assertCount(1, $campo, 'o documento tem a própria linha no corpo do form');

        return $campo;
    }

    #[TestDox('Ação só leitura (dc L.659): rótulo "Ação · vem do menu Processo", o valor e o "Abrir" que leva à aba Processo; sem input nome_acao')]
    public function testAcaoSoLeituraComAbrir(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Ação de cobrança');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $campo = $crawler->filter('#modalEditarPasta > .ps-modal-pasta > form > .ps-modal-pasta-corpo > .ps-modal-pasta-linha--larga > .ps-modal-pasta-campo[data-pe-acao]');
        self::assertCount(1, $campo);
        self::assertSame('Ação · vem do menu Processo', trim($campo->filter('.ps-modal-pasta-campo > span.ps-modal-pasta-rotulo#editAcaoRotulo')->text()));

        $linha = $campo->filter('.ps-modal-pasta-campo > .ps-pe-resp[role="group"][aria-labelledby="editAcaoRotulo"]');
        self::assertCount(1, $linha);
        self::assertSame('AÇÃO DE COBRANÇA', trim($linha->filter('.ps-pe-resp > .ps-pe-resp-nome')->text()));
        $abrir = $linha->filter('.ps-pe-resp > button.ps-pe-resp-trocar.js-pe-ir[data-pe-ir="acao"][type="button"]');
        self::assertCount(1, $abrir);
        self::assertSame('Abrir', trim($abrir->text()));

        self::assertCount(0, $crawler->filter('#modalEditarPasta [name="nome_acao"], #edit_nome_acao'), 'a Ação saiu do formulário');
        self::assertCount(0, $campo->filter('[name]'), 'nada da Ação vai no POST do pasta_edit');
    }

    #[TestDox('Ação vazia e sem processo: o texto do desenho "Sem processo vinculado", em cinza')]
    public function testAcaoVaziaSemProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $nome = $crawler->filter('#modalEditarPasta [data-pe-acao] > .ps-pe-resp > .ps-pe-resp-nome.is-vazio');
        self::assertCount(1, $nome);
        self::assertSame('Sem processo vinculado', trim($nome->text()));
    }

    #[TestDox('CPF do cliente principal, só leitura e formatado 000.000.000-00, com o atalho "Cadastro" para a edição do cliente')]
    public function testDocumentoCpfFormatadoComAtalho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $cliente         = $this->clientePF($tenant, $pasta, '12345678901');

        $this->logarComTenant($client, $user, $tenant);
        $campo = $this->campoDoDocumento($this->abrir($client, $pasta));

        self::assertSame('CPF', trim($campo->filter('.ps-modal-pasta-campo > span.ps-modal-pasta-rotulo#editDocRotulo')->text()));
        $linha = $campo->filter('.ps-modal-pasta-campo > .ps-pe-resp[aria-labelledby="editDocRotulo"]');
        self::assertSame('123.456.789-01', trim($linha->filter('.ps-pe-resp > .ps-pe-resp-nome[data-pe-doc]')->text()));
        $atalho = $linha->filter('.ps-pe-resp > a.ps-pe-resp-trocar');
        self::assertCount(1, $atalho);
        self::assertSame('/clientes/' . $cliente->getId() . '/editar', $atalho->attr('href'));
        self::assertSame('Cadastro', trim($atalho->text()));
        self::assertCount(0, $campo->filter('[name], input'), 'o documento não se edita aqui');
    }

    #[TestDox('CNPJ do cliente principal formatado 00.000.000/0000-00, com o atalho para o cadastro')]
    public function testDocumentoCnpjFormatado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $empresa         = $this->clientePJ($tenant, $pasta, '12345678000190');

        $this->logarComTenant($client, $user, $tenant);
        $campo = $this->campoDoDocumento($this->abrir($client, $pasta));

        self::assertSame('CNPJ', trim($campo->filter('.ps-modal-pasta-campo > .ps-modal-pasta-rotulo')->text()));
        self::assertSame('12.345.678/0001-90', trim($campo->filter('.ps-pe-resp > [data-pe-doc]')->text()));
        self::assertSame('/clientes/' . $empresa->getId() . '/editar', $campo->filter('.ps-pe-resp > a.ps-pe-resp-trocar')->attr('href'));
    }

    #[TestDox('Sem cliente cadastrado (só o nome livre): "Sem cliente cadastrado" e nenhum atalho')]
    public function testSemClienteCadastrado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeCliente('Nome Livre');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $campo = $this->campoDoDocumento($this->abrir($client, $pasta));

        self::assertSame('CPF/CNPJ', trim($campo->filter('.ps-modal-pasta-campo > .ps-modal-pasta-rotulo')->text()));
        self::assertSame('Sem cliente cadastrado', trim($campo->filter('.ps-pe-resp > .ps-pe-resp-nome.is-vazio')->text()));
        self::assertCount(0, $campo->filter('a, button'), 'sem cliente, sem atalho');
    }

    #[TestDox('Cliente cadastrado sem documento: o vazio neutro e nenhum atalho')]
    public function testClienteSemDocumento(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        // PJ e não PF: a lista de clientes da janela (fora deste lote) cai em `razaoSocial` num PF sem CPF.
        $this->clientePJ($tenant, $pasta, '');

        $this->logarComTenant($client, $user, $tenant);
        $campo = $this->campoDoDocumento($this->abrir($client, $pasta));

        self::assertSame('Cliente sem documento cadastrado', trim($campo->filter('.ps-pe-resp > .ps-pe-resp-nome.is-vazio')->text()));
        self::assertCount(0, $campo->filter('a, button'));
    }

    #[TestDox('POST do pasta_edit com nome_acao NÃO muda a Ação no banco (só o menu Processo muda)')]
    public function testPostComNomeAcaoNaoMudaAAcao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Ação original');
        $this->em()->flush();
        $pastaId = (int) $pasta->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        // O form como o navegador o envia, mais um `nome_acao` forjado: o servidor ignora a chave.
        $form   = $crawler->filter('#modalEditarPasta > .ps-modal-pasta > form')->form();
        $campos = $form->getPhpValues();
        $campos['nome_cliente'] = 'Cliente Novo';
        $campos['nome_acao']    = 'Ação forjada';
        $client->request('POST', $form->getUri(), $campos);

        self::assertResponseRedirects('/pasta/' . $pastaId);
        $this->em()->clear();
        $gravada = $this->em()->find(Pasta::class, $pastaId);
        self::assertNotNull($gravada);
        self::assertSame('AÇÃO ORIGINAL', $gravada->getNomeAcao(), 'a Ação não muda pelo Editar dados');
        self::assertSame('CLIENTE NOVO', $gravada->getNomeCliente(), 'o resto do form continua gravando');
    }
}
