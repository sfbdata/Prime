<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaPagamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Aba Financeiro, desenho 1.2.3: o menu ⋮ dos arquivos, o ⋮ do card Pagamentos
 * e a folha do "Imprimir extrato".
 *
 * O que se trava aqui:
 * - o menu do arquivo tem SÓ as ações com função real, e cada uma aponta para a
 *   rota de verdade (nada de item do desenho sem função: "Encaminhar via Chat
 *   I.A", "Enviar por e-mail ao cliente");
 * - os ganchos do JS (`btn-renomear-doc`/`btn-excluir-doc` com `data-*`, o
 *   `data-bs-target="#previewDocModal"`) continuaram existindo dentro do menu;
 * - a folha do extrato mora no corpo RE-RENDERIZADO pelo servidor e lista os
 *   lançamentos DESTA pasta — nem de outra pasta, nem de outro escritório.
 *
 * Arranjo com combinador de filho direto, como pede a spec (§1.10).
 */
#[CoversClass(PastaController::class)]
final class PastaFinanceiroMenusTelaTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(string $sufixo = ''): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Menus Financeiro ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_menus_fin_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin Menus Financeiro');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nomeCliente = 'Cliente do Extrato'): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-MENU-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setNomeCliente($nomeCliente);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarDocumentoFinanceiro(Pasta $pasta, Tenant $tenant, string $titulo = 'Contrato de honorários.pdf'): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setTitulo($titulo);
        $doc->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
        $doc->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf');
        $doc->setNomeOriginal('contrato.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(14);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function criarPagamento(
        Pasta $pasta,
        Tenant $tenant,
        string $descricao,
        string $valor,
        ?string $pagoEm = null,
    ): PastaPagamento {
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable('2026-09-10'));

        if ($pagoEm !== null) {
            $pagamento->alternarQuitacao(new \DateTimeImmutable($pagoEm));
        }

        $em->persist($pagamento);
        $em->flush();

        return $pagamento;
    }

    /** @return list<string> */
    private function rotulos(Crawler $menu): array
    {
        return $menu->filter('.ps-fin-menu-item')->each(
            static fn (Crawler $item) => trim(preg_replace('/\s+/', ' ', $item->text()) ?? '')
        );
    }

    // =========================================================================
    // ⋮ dos arquivos
    // =========================================================================

    #[TestDox('cada arquivo tem o ⋮ do desenho, e o menu é filho direto das ações da linha')]
    public function testArquivoTemMenuNaPropriaLinha(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoFinanceiro($pasta, $tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $docId   = $doc->getId();
        $acoes   = "#financeiro-docs-lista > #financeiro-doc-{$docId} > .ps-fin-doc-acoes";
        $gatilho = $crawler->filter("{$acoes} > button.ps-fin-menu-gatilho");

        self::assertCount(1, $gatilho, 'o ⋮ do arquivo saiu da linha');
        self::assertSame('Mais ações do arquivo', $gatilho->attr('title'));
        self::assertSame('menu', $gatilho->attr('aria-haspopup'));
        self::assertSame("financeiro-doc-menu-{$docId}", $gatilho->attr('aria-controls'));

        $menu = $crawler->filter("{$acoes} > #financeiro-doc-menu-{$docId}.ps-fin-menu[role=\"menu\"]");
        self::assertCount(1, $menu, 'o menu tem de morar na linha do arquivo, ao lado do ⋮');
        self::assertNotNull($menu->attr('hidden'), 'o menu nasce fechado');
        // O título é o PERSISTIDO (o sistema normaliza para maiúsculas ao gravar).
        self::assertSame($doc->getTitulo(), trim($menu->filter('.ps-fin-menu-titulo')->text()));
    }

    /**
     * Auditoria 2, F2 (dc ~6549 `fi(ext,30)`): a linha do arquivo mostra o ícone
     * do TIPO, como o trilho de Dados, e não mais a caixinha com a sigla. O título
     * persistido vem em maiúsculas (".PDF"): o ícone tem de reconhecer assim mesmo.
     */
    #[TestDox('a linha do arquivo mostra o ícone do tipo, não a caixinha com a sigla')]
    public function testArquivoMostraIconeDoTipo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pdf             = $this->criarDocumentoFinanceiro($pasta, $tenant, 'Contrato de honorários.pdf');
        $docx            = $this->criarDocumentoFinanceiro($pasta, $tenant, 'Procuração.docx');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $iconePdf = $crawler->filter("#financeiro-docs-lista > #financeiro-doc-{$pdf->getId()} > span.ps-doc-icone > i.bi.bi-filetype-pdf");
        self::assertCount(1, $iconePdf, 'PDF ganha o ícone de PDF (título gravado: ' . $pdf->getTitulo() . ')');
        self::assertSame('PDF', $crawler->filter("#financeiro-doc-{$pdf->getId()} > .ps-doc-icone")->attr('title'));

        self::assertCount(1, $crawler->filter("#financeiro-docs-lista > #financeiro-doc-{$docx->getId()} > span.ps-doc-icone > i.bi.bi-filetype-docx"));

        self::assertCount(0, $crawler->filter('#financeiro-docs-lista .ps-doc-ext'), 'a caixinha com a sigla saiu');
    }

    #[TestDox('o menu do arquivo só tem ações com função real, na ordem do desenho')]
    public function testMenuDoArquivoSoTemAcoesReais(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoFinanceiro($pasta, $tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $menu    = $crawler->filter("#financeiro-doc-menu-{$doc->getId()}");

        self::assertSame(
            ['Abrir', 'Baixar', 'Copiar nome do arquivo', 'Renomear', 'Ver em Documentos', 'Excluir'],
            $this->rotulos($menu),
        );

        /* Itens do desenho que dependem de função nova não são renderizados
           (spec §1.3) — nem desabilitados, nem "em breve". */
        foreach (['Encaminhar', 'e-mail', 'Chat I.A'] as $semFuncao) {
            self::assertStringNotContainsString($semFuncao, $menu->text(), "\"{$semFuncao}\" não tem função no sistema");
        }

        // Excluir fica depois do separador, como no desenho.
        self::assertCount(1, $menu->filter('.ps-fin-menu-sep + .ps-fin-menu-item--perigo.btn-excluir-doc'));
    }

    #[TestDox('cada item do menu aponta para a rota real do arquivo e mantém os ganchos do JS')]
    public function testItensDoMenuApontamParaAsRotasReais(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumentoFinanceiro($pasta, $tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $pastaId = $pasta->getId();
        $docId   = $doc->getId();
        $menu    = $crawler->filter("#financeiro-doc-menu-{$docId}");

        $abrir = $menu->filter('.ps-fin-menu-item[data-bs-target="#previewDocModal"]');
        self::assertCount(1, $abrir, 'Abrir usa o visualizador que já existe');
        self::assertSame('modal', $abrir->attr('data-bs-toggle'));
        self::assertSame("/pasta/{$pastaId}/financeiro/documento/{$docId}/visualizar", $abrir->attr('data-url'));
        self::assertSame("/pasta/{$pastaId}/financeiro/documento/{$docId}/download", $abrir->attr('data-download'));
        self::assertSame('application/pdf', $abrir->attr('data-mime'));

        $baixar = $menu->filter('a.ps-fin-menu-item');
        self::assertCount(1, $baixar);
        self::assertSame("/pasta/{$pastaId}/financeiro/documento/{$docId}/download", $baixar->attr('href'));

        $renomear = $menu->filter('.btn-renomear-doc');
        self::assertCount(1, $renomear, 'o handler de renomear escuta .btn-renomear-doc');
        self::assertSame((string) $docId, $renomear->attr('data-doc-id'));
        self::assertSame($doc->getTitulo(), $renomear->attr('data-titulo'));
        self::assertSame("/pasta/{$pastaId}/financeiro/documento/{$docId}/renomear", $renomear->attr('data-url'));
        self::assertNotEmpty($renomear->attr('data-csrf'));

        $excluir = $menu->filter('.btn-excluir-doc');
        self::assertCount(1, $excluir, 'o handler de excluir escuta .btn-excluir-doc');
        self::assertSame((string) $docId, $excluir->attr('data-doc-id'));
        self::assertSame("/pasta/{$pastaId}/financeiro/documento/{$docId}/excluir", $excluir->attr('data-url'));
        self::assertNotEmpty($excluir->attr('data-csrf'));

        // "Ver em Documentos" leva a uma aba que existe.
        self::assertCount(1, $menu->filter('.js-fin-doc-ver-documentos'));
        self::assertCount(1, $crawler->filter('#documentos-tab'));
    }

    #[TestDox('arquivo de OUTRA pasta não ganha linha nem menu nesta')]
    public function testMenuSoDosArquivosDaPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outraPasta      = $this->criarPasta($tenant);
        $meu             = $this->criarDocumentoFinanceiro($pasta, $tenant, 'Meu contrato.pdf');
        $alheio          = $this->criarDocumentoFinanceiro($outraPasta, $tenant, 'Contrato alheio.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertCount(1, $crawler->filter("#financeiro-doc-menu-{$meu->getId()}"));
        self::assertCount(0, $crawler->filter("#financeiro-doc-menu-{$alheio->getId()}"));
        self::assertStringNotContainsString('Contrato alheio.pdf', $crawler->filter('#financeiro')->text());
    }

    // =========================================================================
    // ⋮ de Pagamentos e "Imprimir extrato"
    // =========================================================================

    #[TestDox('o card Pagamentos tem o ⋮ no cabeçalho, com as ações que existem')]
    public function testCardPagamentosTemMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarPagamento($pasta, $tenant, 'Entrada', '1000.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cab = '#financeiro .ps-trilho > [data-trilho="pagamentos"] > .ps-card-cab';
        self::assertCount(1, $crawler->filter("{$cab} > #psPagamentosMenuBtn.ps-fin-menu-gatilho"));
        self::assertSame('psPagamentosMenu', $crawler->filter('#psPagamentosMenuBtn')->attr('aria-controls'));

        $menu = $crawler->filter("{$cab} > #psPagamentosMenu.ps-fin-menu");
        self::assertCount(1, $menu);
        self::assertSame(
            ['Adicionar pagamento', 'Ver todos os lançamentos', 'Copiar resumo', 'Imprimir extrato'],
            $this->rotulos($menu),
        );

        // "Adicionar pagamento" abre o modal que já existe.
        self::assertSame('#modalNovoPagamento', $menu->filter('.ps-fin-menu-item')->first()->attr('data-bs-target'));
        self::assertCount(1, $crawler->filter('#modalNovoPagamento'));

        /* "Editar ou corrigir valores" depende de histórico de correção, que não
           existe: não é renderizado. */
        self::assertStringNotContainsString('corrigir', $menu->text());
        self::assertStringNotContainsString('Concluir edição', $menu->text());
    }

    #[TestDox('a folha do extrato vive no corpo re-renderizado e traz totais e TODOS os lançamentos')]
    public function testFolhaDoExtratoTrazTotaisELancamentos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Maria Extrato');
        $this->criarPagamento($pasta, $tenant, 'Entrada de honorários', '1000.00', '2026-09-01');
        $this->criarPagamento($pasta, $tenant, '2ª parcela', '300.50');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $folha = $crawler->filter('#psPagamentosCorpo > #psExtratoFolha.ps-fin-extrato');
        self::assertCount(1, $folha, 'a folha tem de estar no bloco que o servidor re-renderiza');
        self::assertNotNull($folha->attr('hidden'), 'na tela a folha não aparece; só na impressão');

        $total = trim(preg_replace('/\s+/', ' ', $folha->filter('.ps-fin-extrato-total')->text()) ?? '');
        self::assertSame('R$ 1.000,00 recebidos de R$ 1.300,50', $total);

        $linhas = $folha->filter('.ps-fin-extrato-lista > li');
        self::assertCount(2, $linhas, 'o extrato lista os pagos também, não só os próximos');
        $texto = $folha->text();
        self::assertStringContainsString('Entrada de honorários: R$ 1.000,00 · pago em 01/09/2026', $texto);
        // 10/09/2026 já passou: o extrato diz "venceu", como o selo Vencida (auditoria 2 F6/F18).
        self::assertStringContainsString('2ª parcela: R$ 300,50 · venceu 10/09/2026', $texto);

        $cabecalho = $crawler->filter('#financeiro [data-trilho="pagamentos"] > #psExtratoCabecalho');
        self::assertCount(1, $cabecalho);
        self::assertStringContainsString('Extrato de pagamentos', $cabecalho->text());
        self::assertStringContainsString('Pasta ' . $pasta->getNup() . ' · ' . $pasta->getNomeCliente(), preg_replace('/\s+/', ' ', $cabecalho->text()) ?? '');
    }

    #[TestDox('sem lançamento não há folha de extrato: o menu esconde Imprimir e Copiar')]
    public function testSemPagamentoNaoHaFolha(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertCount(0, $crawler->filter('#psExtratoFolha'));
        // O JS decide pela presença da folha; os itens carregam a marca para isso.
        self::assertCount(1, $crawler->filter('#psPagamentosMenu .js-pag-imprimir-extrato.js-pag-so-com-extrato'));
        self::assertCount(1, $crawler->filter('#psPagamentosMenu .js-pag-copiar-resumo.js-pag-so-com-extrato'));
    }

    #[TestDox('o extrato não leva pagamento de outra pasta nem de outro escritório')]
    public function testExtratoNaoVazaEntrePastasNemEscritorios(): void
    {
        $client             = static::createClient();
        [$user, $tenant]    = $this->criarUsuarioAdmin('a');
        [, $tenantVizinho]  = $this->criarUsuarioAdmin('b');

        $pasta          = $this->criarPasta($tenant);
        $outraPasta     = $this->criarPasta($tenant);
        $pastaDoVizinho = $this->criarPasta($tenantVizinho);

        $this->criarPagamento($pasta, $tenant, 'Meu lançamento', '1000.00');
        $this->criarPagamento($outraPasta, $tenant, 'Lançamento de outra pasta', '7777.00');
        $this->criarPagamento($pastaDoVizinho, $tenantVizinho, 'Lançamento do vizinho', '8888.00');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $folha = $crawler->filter('#psExtratoFolha')->text();
        self::assertStringContainsString('Meu lançamento', $folha);
        self::assertStringNotContainsString('Lançamento de outra pasta', $folha);
        self::assertStringNotContainsString('R$ 7.777,00', $folha);
        self::assertStringNotContainsString('Lançamento do vizinho', $folha);
        self::assertStringNotContainsString('R$ 8.888,00', $folha);
    }

    #[TestDox('a pasta de outro escritório não abre — e com ela não sai extrato nenhum')]
    public function testPastaDeOutroEscritorioNaoEntregaExtrato(): void
    {
        $client            = static::createClient();
        [$user, $tenant]   = $this->criarUsuarioAdmin('a');
        [, $tenantVizinho] = $this->criarUsuarioAdmin('b');

        $pastaDoVizinho = $this->criarPasta($tenantVizinho);
        $this->criarPagamento($pastaDoVizinho, $tenantVizinho, 'Lançamento do vizinho', '8888.00');
        $idDoVizinho = (int) $pastaDoVizinho->getId();

        /* Sem o clear a pasta continuaria no mapa de identidade do Doctrine, e a
           conversão do parâmetro a devolveria sem passar pelo filtro de tenant —
           o teste provaria outra coisa (mesma receita do PeticionarIsolamento). */
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$idDoVizinho}");

        self::assertFalse($client->getResponse()->isSuccessful(), 'pasta de outro escritório não pode abrir');
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('psExtratoFolha', $conteudo);
        self::assertStringNotContainsString('Lançamento do vizinho', $conteudo);
    }
}
