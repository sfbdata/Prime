<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * ARRANJO do cabeçalho da pasta no desenho "02 - EXPEDIENTES 1.2.3" (padrão PJe):
 * o cliente é o título, a ação virou célula da faixa, a situação é uma pílula à
 * direita das abas, as ações vivem no menu ⋮ e as setas dizem o identificador da
 * pasta vizinha.
 *
 * Tudo por combinador de FILHO DIRETO a partir do bloco certo — é a única coisa
 * que o PHPUnit prova sobre layout. Cor, fonte, a tarja azul e as animações
 * seguem invisíveis para o teste: é smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaCabecalhoPjeTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_A = '07011345720258070007';
    private const NUMERO_B = '07022222220258070007';

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function criarPastaNumerada(Tenant $tenant, string $nup, ?string $identificador): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        if ($identificador !== null) {
            $pasta->setNomeCliente($identificador);
        }
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    // =========================================================================
    // Título = cliente
    // =========================================================================

    #[TestDox('o título é o identificador da pasta, com o ícone de pessoa (não clicável) e o aviso de que não é cadastro')]
    public function testTituloEhOIdentificadorSemCadastro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3002', 'MARIA DAS GRACAS');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $bloco = $crawler->filter('.ps-cabecalho > .ps-cab-cliente');
        self::assertCount(1, $bloco, 'o bloco do cliente é filho direto do cabeçalho');
        self::assertCount(1, $bloco->filter('.ps-cab-cliente > span.ps-cab-cliente-icone'), 'o ícone é um <span>, não um botão: a foto do cliente é função nova');
        self::assertSame(
            'MARIA DAS GRACAS',
            trim($bloco->filter('.ps-cab-cliente > .ps-cab-cliente-corpo > .ps-cab-cliente-linha > h1.ps-cab-titulo')->text())
        );
        self::assertStringContainsString('não vinculado', $bloco->filter('.ps-cab-doc--aviso')->text());
        self::assertCount(0, $bloco->filter('.ps-cab-doc.ps-num'), 'sem cliente cadastrado não há documento');
    }

    #[TestDox('com cliente principal cadastrado o título é o nome dele e o documento aparece embaixo')]
    public function testClienteVinculadoMostraDocumento(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3003', 'TEXTO LEGADO');

        $cliente = new ClientePF();
        $cliente->setEmail('cab' . uniqid() . '@test.com');
        $cliente->setCep('80000-000');
        $cliente->setEndereco('Rua Um, 1');
        $cliente->setCidade('Curitiba');
        $cliente->setEstado('PR');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('Joao Batista Moreira');
        $cliente->setCpf('12345678901');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $pasta->definirClientePrincipal($cliente);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame(
            $cliente->getNomeExibicao(),
            trim($crawler->filter('.ps-cab-cliente h1.ps-cab-titulo')->text()),
            'o cadastro vence o texto legado'
        );
        $doc = $crawler->filter('.ps-cab-cliente .ps-cab-doc.ps-num');
        self::assertCount(1, $doc);
        self::assertMatchesRegularExpression('/CPF.*123\.?456\.?789-?01/', $doc->text(), 'rótulo e número do documento do cadastro');
        self::assertCount(0, $crawler->filter('.ps-cab-cliente .ps-cab-doc--aviso'));
    }

    #[TestDox('"Movimentado…" fica ao lado do nome e vem da última alteração da pasta (o mesmo dado de antes)')]
    public function testMovimentadoAoLadoDoNome(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3004', 'MARIA DAS GRACAS');
        // PreUpdate preenche `modificadoEm`: uma alteração depois do primeiro flush.
        $pasta->setNomeAcao('Cobrança');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $mov = $crawler->filter('.ps-cab-cliente-linha > .ps-cab-mov');
        self::assertCount(1, $mov, 'o indicador é irmão direto do título, na mesma linha');
        self::assertStringContainsString('Movimentado hoje', $mov->text());
        self::assertCount(0, $crawler->filter('.ps-cab-dados [data-campo="movimentacao"]'), 'saiu da faixa de dados');
    }

    // =========================================================================
    // Faixa de dados e processos vinculados
    // =========================================================================

    #[TestDox('sem processo, a célula oferece "vincular" (abre a modal) e não há chip de vinculados')]
    public function testSemProcessoOfereceVincular(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3005', 'MARIA');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $celula = $crawler->filter('.ps-cab-dados > [data-campo="processo"]');
        self::assertCount(1, $celula->filter('button.ps-link-btn[data-bs-target="#modalVincularProcesso"]'));
        self::assertCount(0, $celula->filter('.ps-chip-vinc'));
        self::assertCount(0, $crawler->filter('.ps-cab-dados a[href="#"]'), 'nenhum link morto na faixa');
    }

    #[TestDox('com dois processos, a célula mostra o principal, "trocar" e o chip "1 vinculado" que abre o outro')]
    public function testChipDeVinculadosComDoisProcessos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3006', 'MARIA');
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_A));
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_B));

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $celula = $crawler->filter('.ps-cab-dados > [data-campo="processo"]');
        self::assertCount(1, $celula->filter('.ps-cab-proc-acoes > button.ps-link-btn[data-ps-ir-aba="processo-tab"]'), '"trocar" leva à aba Processo');

        $chip = $celula->filter('.ps-cab-proc-acoes > button.ps-chip-vinc');
        self::assertCount(1, $chip);
        self::assertStringContainsString('1 vinculado', $chip->text());
        self::assertSame('psProcessosVinculados', $chip->attr('data-ps-toggle'));

        $lista = $celula->filter('#psProcessosVinculados > .ps-cab-vinculado');
        self::assertCount(1, $lista, 'a lista traz só o OUTRO processo, não o principal de novo');
        self::assertStringContainsString('vinculado', strtolower($lista->filter('.ps-rotulo--mini')->text()));
    }

    // =========================================================================
    // Situação (pílula) e menu ⋮
    // =========================================================================

    #[TestDox('a situação é uma pílula à direita das abas, com o menu Ativo/Arquivado ligado ao #btn-alternar-status')]
    public function testSituacaoNaLinhaDasAbas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3007', 'MARIA');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $pill = $crawler->filter('.ps-abas-faixa > .ps-situacao-wrap > button#btn-alternar-status.ps-situacao-pill--ativo');
        self::assertCount(1, $pill, 'a pílula é filha direta do wrap, que é filho direto da faixa das abas');
        self::assertSame('ativo', $pill->attr('data-situacao-atual'));
        self::assertNotEmpty($pill->attr('data-url'));
        self::assertNotEmpty($pill->attr('data-csrf'));
        self::assertSame('psSituacao', $pill->attr('data-ps-pop'));

        $opcoes = $crawler->filter('#psSituacao > button.js-situacao-opt[role="menuitemradio"]');
        self::assertCount(2, $opcoes, 'só as duas situações que o sistema tem: Suspenso e Cancelado são função nova');
        self::assertSame(['ativo', 'arquivado'], $opcoes->each(fn ($n) => $n->attr('data-situacao')));
        self::assertSame('ativo', $crawler->filter('#psSituacao > button[aria-checked="true"]')->attr('data-situacao'));

        self::assertCount(0, $crawler->filter('.ps-cab-dados [data-campo="situacao"]'), 'a situação saiu da faixa de dados');

        // O item do menu ⋮ oferece o caminho inverso ao estado atual.
        $alternar = $crawler->filter('#psMenuAcoes .js-situacao-alternar');
        self::assertSame('arquivado', $alternar->attr('data-situacao'));
        self::assertSame('Arquivar pasta', trim($alternar->text()));
    }

    #[TestDox('pasta arquivada: a pílula diz Arquivado e o menu ⋮ oferece Desarquivar')]
    public function testPastaArquivada(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3008', 'MARIA');
        $pasta->setSituacao('arquivado');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $pill = $crawler->filter('.ps-abas-faixa > .ps-situacao-wrap > #btn-alternar-status.ps-situacao-pill--arquivado');
        self::assertCount(1, $pill);
        self::assertSame('Arquivado', trim($pill->filter('.ps-situacao-label')->text()));
        self::assertSame('arquivado', $crawler->filter('#psSituacao > button[aria-checked="true"]')->attr('data-situacao'));

        $alternar = $crawler->filter('#psMenuAcoes .js-situacao-alternar');
        self::assertSame('ativo', $alternar->attr('data-situacao'));
        self::assertSame('Desarquivar pasta', trim($alternar->text()));
    }

    #[TestDox('o menu ⋮ tem a tarja com o número da pasta, as ações na ordem do desenho e o "Copiar link" apontando para esta pasta')]
    public function testMenuDeAcoes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3009', 'MARIA');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $menu = $crawler->filter('#psMenuAcoes');
        self::assertStringContainsString('Pasta 3009', $menu->filter('#psMenuAcoes > .ps-pop-tarja')->text());

        $itens = $menu->filter('.ps-pop-item')->each(fn ($n) => trim($n->filter('span')->first()->text()));
        self::assertSame(
            ['Editar dados', 'Histórico', 'Timeline inteligente', 'Duplicar pasta', 'Vincular processo', 'Trocar responsável', 'Copiar link da pasta', 'Imprimir resumo', 'Fixar nos favoritos', 'Arquivar pasta', 'Excluir pasta'],
            $itens
        );
        // L18: a Timeline inteligente ganhou back-end (`pasta_timeline`) e entrou no menu, com o T do desenho.
        self::assertSame('t', $menu->filter('#psTimelineAbrir')->attr('data-ps-atalho'));

        self::assertSame('e', $menu->filter('.ps-pop-item[data-bs-target="#modalEditarPasta"]')->attr('data-ps-atalho'));
        self::assertSame('h', $menu->filter('#psHistoricoAbrir')->attr('data-ps-atalho'));

        $copiar = $menu->filter('.js-copiar-link');
        self::assertStringEndsWith('/pasta/' . $pasta->getId(), (string) $copiar->attr('data-link'));
    }

    // =========================================================================
    // Setas
    // =========================================================================

    #[TestDox('o rótulo das setas diz o identificador da pasta vizinha e, entre parênteses, o número')]
    public function testSetasDizemOIdentificadorDaVizinha(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $this->criarPastaNumerada($tenant, '4001', 'CONDOMINIO SOL');
        $meio = $this->criarPastaNumerada($tenant, '4002', 'MARIA');
        $this->criarPastaNumerada($tenant, '4003', null);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $meio);

        self::assertSame(
            'Pasta anterior na lista: 4003',
            $crawler->filter('.ps-cab-linha1 > .ps-cab-nav > [data-nav="anterior"]')->attr('title'),
            'sem identificador, o rótulo continua pelo número'
        );
        self::assertSame(
            'Próxima pasta: CONDOMINIO SOL (pasta 4001)',
            $crawler->filter('.ps-cab-linha1 > .ps-cab-nav > [data-nav="proxima"]')->attr('title')
        );
    }

    // =========================================================================
    // Linha 1
    // =========================================================================

    #[TestDox('a linha de cima é Voltar · PASTA nnnn · prioridade · etiquetas, sem trilha de migalhas')]
    public function testLinhaDeCima(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3010', 'MARIA');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $identidade = $crawler->filter('.ps-cab-linha1 > .ps-cab-identidade');
        self::assertCount(1, $identidade);
        self::assertCount(1, $identidade->filter('.ps-cab-identidade > a.ps-cab-voltar'));
        self::assertSame('PASTA 3010', trim($identidade->filter('.ps-cab-identidade > .ps-cab-nup')->text()));
        // Divisória · prioridade · divisória · etiquetas · "+" formam o grupo do desenho.
        $etiquetas = $identidade->filter('.ps-cab-identidade > .ps-cab-etiquetas');
        self::assertCount(1, $etiquetas);
        self::assertCount(1, $etiquetas->filter('.ps-cab-etiquetas > .ps-pop-wrap > .pasta-prioridade-btn'));
        self::assertCount(1, $etiquetas->filter('.ps-cab-etiquetas > button.ps-etiqueta-add.js-mover-para'), 'o "+" das etiquetas mantém o contrato do JS');
        self::assertCount(0, $crawler->filter('.ps-cabecalho .ps-breadcrumb'), 'o desenho não tem trilha de migalhas');
    }

    #[TestDox('pasta sem identificador e sem cliente: o título diz que o cliente não foi informado, sem inventar nome')]
    public function testSemIdentificadorNenhum(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3011', null);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame('Cliente não informado', trim($crawler->filter('.ps-cab-cliente h1.ps-cab-titulo')->text()));
        self::assertCount(0, $crawler->filter('.ps-cab-cliente .ps-cab-doc'), 'nem documento, nem aviso de "não vinculado": não há nome nenhum');
    }

    #[TestDox('sem processo, a célula Ação convida a vincular um processo (o fallback do desenho)')]
    public function testAcaoSemProcessoConvidaAVincular(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3012', 'MARIA');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame(
            'Vincule um processo para definir',
            trim($crawler->filter('.ps-cab-dados > [data-campo="acao"] .ps-dado')->text())
        );
    }

    #[TestDox('pasta excluída: a situação vira só um selo — sem menu, sem #btn-alternar-status e sem ⋮')]
    public function testPastaExcluidaTemSeloInerte(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPastaNumerada($tenant, '3013', 'MARIA');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('.ps-abas-faixa > .ps-situacao-wrap > span.ps-situacao-pill'), 'o selo é um <span>, não um botão');
        self::assertCount(0, $crawler->filter('#btn-alternar-status'));
        self::assertCount(0, $crawler->filter('#psSituacao'));
        self::assertCount(0, $crawler->filter('[data-ps-pop="psSituacao"]'));
        self::assertCount(0, $crawler->filter('#psMenuAcoes'), 'pasta riscada não tem o menu ⋮');
        self::assertCount(1, $crawler->filter('.ps-cab-linha1 > .ps-cab-acoes > #psHistoricoAbrir'), 'o Histórico continua, como botão visível');
    }
}
