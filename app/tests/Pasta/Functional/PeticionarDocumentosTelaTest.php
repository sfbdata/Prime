<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\PosicaoDoDocumentoNovo;
use App\Pasta\Entity\TipoDeApenso;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A tela do Peticionar depois do desenho 07 (P1 e P2): "Voltar à pasta", menu da linha com os
 * data-* das rotas que já existem, tamanho e ícone por tipo, barra do selecionado, dica da lista
 * vazia. O comportamento (clique, menu, soltar arquivo) é do peticionar-documentos.js; aqui se
 * prova o que o servidor entrega para ele, e o arranjo por filho direto.
 */
#[CoversClass(PeticionarController::class)]
final class PeticionarDocumentosTelaTest extends JusPrimeWebTestCase
{
    use CriaUsuarioComPermissoesTrait;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('"Voltar à pasta" aponta para a pasta, e a tela inclui o JS e o CSS do desenho 07')]
    public function testVoltarAPastaEAssets(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        $voltar = $crawler->filter('nav.navtop .navtop-right > a.petic-voltar-pasta');
        self::assertCount(1, $voltar);
        self::assertSame("/pasta/{$pasta->getId()}", $voltar->attr('href'));
        self::assertSame('Voltar à pasta', trim($voltar->text()));
        self::assertCount(1, $voltar->filter('i.bi-arrow-left'));

        self::assertCount(1, $crawler->filter('script[src*="/js/peticionar-documentos.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="/css/peticionar-documentos.css"]'));
    }

    #[TestDox('o cabeçalho é o do desenho 07: Voltar à pasta e o nome do usuário, sem sino, tela cheia nem menu do avatar')]
    public function testCabecalhoEnxuto(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.navtop .navtop-right > .petic-navtop-usuario'));
        self::assertCount(2, $crawler->filter('nav.navtop .navtop-right > *'));
        self::assertCount(0, $crawler->filter('nav.navtop #notificacaoDropdownToggle, nav.navtop [data-lte-toggle="fullscreen"], nav.navtop .menu-usuario'));
        self::assertCount(1, $crawler->filter('nav.navtop .petic-navtop-info > .petic-navtop-nup'));
    }

    #[TestDox('a lista segue a ordem manual da pasta (a da aba Documentos), com o número da posição e linhas arrastáveis')]
    public function testListaSegueAOrdemManual(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = $this->documento($pasta, $tenant, 'a.pdf', 'application/pdf', 100);
        $b = $this->documento($pasta, $tenant, 'b.pdf', 'application/pdf', 100);
        $c = $this->documento($pasta, $tenant, 'c.pdf', 'application/pdf', 100);
        $c->setOrdem(1);
        $a->setOrdem(2);
        $b->setOrdem(3);
        $em->flush();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        $linhas = $crawler->filter('#tlInner > .petic-tl-row');
        self::assertSame(
            [(string) $c->getId(), (string) $a->getId(), (string) $b->getId()],
            $linhas->each(static fn (Crawler $l): string => (string) $l->attr('data-doc-id')),
        );
        self::assertSame(['1', '2', '3'], $crawler->filter('#tlInner > .petic-tl-row .petic-tl-card-top > .petic-tl-ord')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertSame('true', $linhas->first()->attr('draggable'));
        $lista = $crawler->filter('#timelineList');
        self::assertSame("/pasta/{$pasta->getId()}/documentos/reordenar", $lista->attr('data-url-reordenar'));
        self::assertNotEmpty($lista->attr('data-csrf-reordenar'));
    }

    #[TestDox('o documento novo entra no fim da ordem manual da pasta')]
    public function testDocumentoNovoEntraNoFim(): void
    {
        [, $pasta, $tenant] = $this->cenario();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a  = $this->documento($pasta, $tenant, 'a.pdf', 'application/pdf', 100);
        $a->setOrdem(7);
        $em->flush();

        $posicao = static::getContainer()->get(PosicaoDoDocumentoNovo::class);
        $novo    = $this->documento($pasta, $tenant, 'novo.pdf', 'application/pdf', 100);
        $posicao->aoFim($novo, $pasta, $tenant);

        self::assertSame(8, $novo->getOrdem());
    }

    #[TestDox('cada linha tem os três pontos e os data-* das rotas de renomear, classificar, baixar e excluir')]
    public function testLinhaTemMenuEDataAttributesDasRotas(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $pdf = $this->documento($pasta, $tenant, 'Petição Inicial.pdf', 'application/pdf', 1024, 'desc da peça', '001/2026');
        $png = $this->documento($pasta, $tenant, 'foto.png', 'image/png', 2621440);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $lista = $crawler->filter('.petic-timeline > #timelineList');
        self::assertCount(1, $lista);
        self::assertSame('1', $lista->attr('data-pode-editar'));
        self::assertSame("/pasta/{$pasta->getId()}/documentos/excluir-lote", $lista->attr('data-url-excluir'));
        self::assertTokenValido('pex_lote_' . $pasta->getId(), $lista->attr('data-csrf-lote'));
        self::assertSame("/pasta/{$pasta->getId()}/peticionar/upload", $lista->attr('data-url-upload'));
        self::assertTokenValido('peticionar_upload_' . $pasta->getId(), $lista->attr('data-csrf-upload'));
        self::assertSame('/pasta/documento/__ID__/editar', $lista->attr('data-url-editar-tpl'));
        self::assertSame(
            array_keys(ExploradorDeDocumentosOutput::CATEGORIAS),
            json_decode((string) $lista->attr('data-classificaveis'), true),
            'Classificar só oferece o que o pasta_documento_edit aceita',
        );

        foreach ([$pdf, $png] as $doc) {
            $id  = (int) $doc->getId();
            $row = $this->linha($crawler, $id);

            self::assertSame("/pasta/documento/{$id}/editar", $row->attr('data-editar-documento-url'));
            self::assertTokenValido('edit_documento_' . $id, $row->attr('data-csrf-documento'));
            self::assertSame("/pasta/documento/{$id}/download", $row->attr('data-download-url'));
            self::assertSame("/pasta/documento/{$id}/visualizar", $row->attr('data-view-url'));

            $mais = $row->filter('.petic-tl-row > .petic-tl-card > button.petic-doc-mais');
            self::assertCount(1, $mais, 'os três pontos ficam no cartão da linha');
            self::assertSame('Mais opções', $mais->attr('title'));
            self::assertCount(1, $mais->filter('i.bi-three-dots-vertical'));
        }

        $rowPdf = $this->linha($crawler, (int) $pdf->getId());
        self::assertSame('Petição Inicial.pdf', $rowPdf->attr('data-titulo'), 'o nome é o da aba Documentos (o que o Renomear grava)');
        self::assertSame('desc da peça', $rowPdf->attr('data-descricao'), 'o Renomear reenvia a descrição: sem ela, viraria NULL');
        self::assertSame('001/2026', $rowPdf->attr('data-numero'));
        self::assertSame(PastaDocumento::CATEGORIA_PECA, $rowPdf->attr('data-categoria'));
        self::assertMatchesRegularExpression('~^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$~', (string) $rowPdf->attr('data-carregado-em'));
    }

    #[TestDox('a linha mostra o tamanho do arquivo e o ícone do tipo (PDF, imagem, peça de texto)')]
    public function testTamanhoEIconePorTipo(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $pdf   = $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);
        $png   = $this->documento($pasta, $tenant, 'foto.png', 'image/png', 2621440);
        $texto = $this->documento($pasta, $tenant, 'Minha Petição.html', 'text/html', 300);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $casos = [
            [$pdf, 'bi-file-earmark-pdf-fill', '1 KB', '1 KB'],
            [$png, 'bi-file-earmark-image-fill', '2,5 MB', '2,5 MB'],
            [$texto, 'bi-file-earmark-text-fill', 'Texto', '1 KB'],
        ];
        foreach ($casos as [$doc, $icone, $rotulo, $tamanhoBarra]) {
            $row = $this->linha($crawler, (int) $doc->getId());

            $i = $row->filter('.petic-tl-card > .petic-tl-card-top > i.petic-doc-icone');
            self::assertCount(1, $i);
            self::assertStringContainsString($icone, (string) $i->attr('class'));

            $tamanho = $row->filter('.petic-tl-card > .petic-tl-card-bottom > .petic-doc-tamanho');
            self::assertCount(1, $tamanho);
            self::assertSame($rotulo, trim($tamanho->text()));
            self::assertSame($tamanhoBarra, $row->attr('data-tamanho-texto'));
        }

        // A peça de texto aparece sem o ".html" que o editor acrescenta ao nome.
        self::assertSame('Minha Petição', $this->linha($crawler, (int) $texto->getId())->attr('data-titulo'));
    }

    #[TestDox('a barra do documento selecionado fica no topo do preview, com Baixar e Excluir')]
    public function testBarraDoSelecionado(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $barra = $crawler->filter('.petic-layout > #previewPanel > #peticSelbar');
        self::assertCount(1, $barra);
        self::assertCount(1, $barra->filter('#peticSelbar > b.petic-selbar-nome[title="Clique duas vezes para renomear"]'));
        self::assertCount(1, $barra->filter('#peticSelbar > .petic-selbar-sub'));
        self::assertSame('Baixar', $barra->filter('#peticSelbar > button[data-acao="baixar"]')->attr('title'));
        self::assertSame('Excluir peça', $barra->filter('#peticSelbar > button[data-acao="excluir"]')->attr('title'));

        // "Editar" (L81) é da peça de texto: nasce escondido e o JS o mostra quando ela é a selecionada.
        $editar = $barra->filter('#peticSelbar > button.petic-selbar-editar[data-acao="editar"]');
        self::assertCount(1, $editar);
        self::assertSame('Editar', trim($editar->text()));
        self::assertNotNull($editar->attr('hidden'));
    }

    #[TestDox('quem só pode VER: data-pode-editar="0", sem Excluir, sem Editar e sem nenhum token de escrita')]
    public function testQuemSoVeNaoRecebeEscrita(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $doc    = $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);
        $texto  = $this->documento($pasta, $tenant, 'Minha Petição.html', 'text/html', 300);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.pastas.view']);
        $this->logarComTenant($client, $leitor, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        // O JS lê esta chave para não montar Renomear, Classificar e Excluir no menu e para não
        // oferecer a lista como alvo de arrastar (o drop é engolido sem envio).
        self::assertSame('0', $crawler->filter('.petic-timeline > #timelineList')->attr('data-pode-editar'));
        self::assertCount(0, $crawler->filter('#peticSelbar > button[data-acao="excluir"]'));
        self::assertCount(0, $crawler->filter('#peticSelbar > button[data-acao="editar"]'));
        // Os tokens de escrita não vão para quem só vê: nem os da lista (excluir, enviar), nem os
        // da linha (renomear/classificar, salvar texto).
        $this->assertSemTokensDeEscrita($crawler, [(int) $doc->getId(), (int) $texto->getId()]);
        $this->assertSemAdicionarPecaNemTokensDoScript($crawler, $client);
        // Ler continua: a linha, o menu (Abrir e Baixar) e o download.
        $row = $this->linha($crawler, (int) $doc->getId());
        self::assertCount(1, $row->filter('.petic-tl-row > .petic-tl-card > button.petic-doc-mais'));
        self::assertCount(1, $crawler->filter('#peticSelbar > button[data-acao="baixar"]'));
    }

    #[TestDox('quem edita recebe os tokens de escrita na lista e na linha (inclusive o de salvar texto)')]
    public function testQuemEditaRecebeTokensDeEscrita(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $texto = $this->documento($pasta, $tenant, 'Minha Petição.html', 'text/html', 300);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $lista = $crawler->filter('.petic-timeline > #timelineList');
        self::assertSame('1', $lista->attr('data-pode-editar'));
        self::assertTokenValido('pex_lote_' . $pasta->getId(), $lista->attr('data-csrf-lote'));
        self::assertTokenValido('peticionar_upload_' . $pasta->getId(), $lista->attr('data-csrf-upload'));

        $id  = (int) $texto->getId();
        $row = $this->linha($crawler, $id);
        self::assertTokenValido('edit_documento_' . $id, $row->attr('data-csrf-documento'));
        self::assertTokenValido('peticionar_editar_' . $id, $row->attr('data-csrf-editar'));

        // "Adicionar peça" (anexo e texto) e os três tokens do script da tela.
        self::assertCount(1, $crawler->filter('.petic-timeline > .petic-timeline-footer #btnAbrirUpload'));
        self::assertCount(1, $crawler->filter('#btnOpcaoAnexo'));
        self::assertCount(1, $crawler->filter('#btnOpcaoTexto'));
        $html = (string) $client->getResponse()->getContent();
        foreach (self::TOKENS_DO_SCRIPT as $prefixo => $padrao) {
            self::assertTokenValido($prefixo . $pasta->getId(), self::tokenDoScript($html, $padrao));
        }
    }

    #[TestDox('pasta excluída abre SÓ LEITURA, mesmo para quem pode editar: sem Excluir, sem Editar e sem tokens de escrita')]
    public function testPastaExcluidaAbreSoLeituraParaQuemEdita(): void
    {
        [$client, $pasta, $tenant, $gestor] = $this->cenario();
        $doc   = $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);
        $texto = $this->documento($pasta, $tenant, 'Minha Petição.html', 'text/html', 300);
        $pasta->marcarExcluida($gestor, new \DateTimeImmutable());
        $this->em()->flush();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        self::assertSame('0', $crawler->filter('.petic-timeline > #timelineList')->attr('data-pode-editar'));
        self::assertCount(0, $crawler->filter('#peticSelbar > button[data-acao="excluir"]'));
        self::assertCount(0, $crawler->filter('#peticSelbar > button[data-acao="editar"]'));
        $this->assertSemTokensDeEscrita($crawler, [(int) $doc->getId(), (int) $texto->getId()]);
        $this->assertSemAdicionarPecaNemTokensDoScript($crawler, $client);
        // Consultar continua: a linha e o Baixar.
        self::assertCount(1, $this->linha($crawler, (int) $doc->getId())->filter('.petic-tl-row > .petic-tl-card > button.petic-doc-mais'));
        self::assertCount(1, $crawler->filter('#peticSelbar > button[data-acao="baixar"]'));
    }

    #[TestDox('lista vazia mostra a dica do desenho')]
    public function testDicaDaListaVazia(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $vazio = $crawler->filter('#timelineList > #tlInner > .petic-tl-vazio');
        self::assertCount(1, $vazio);
        self::assertStringContainsString('Nenhum documento na pasta.', $vazio->text());
        self::assertCount(1, $vazio->filter('.petic-tl-vazio > i.bi-folder2-open'));
        self::assertSame(
            'Use "Adicionar peça" ou arraste arquivos para cá.',
            trim($vazio->filter('.petic-tl-vazio > .petic-tl-vazio-dica')->text()),
        );
    }

    #[TestDox('casca da lista (L46-75): cabeçalho, busca e rodapé são filhos diretos da coluna, na ordem do desenho')]
    public function testArranjoDaCascaDaLista(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $doc = $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $coluna = '.petic-layout > .petic-timeline';
        self::assertCount(1, $crawler->filter($coluna . ' > .petic-timeline-header'));
        self::assertCount(1, $crawler->filter($coluna . ' > .petic-timeline-header > .petic-timeline-header-title > b.petic-timeline-titulo'));
        self::assertCount(1, $crawler->filter($coluna . ' > .petic-timeline-header > .petic-timeline-header-title > span.petic-contador#badgeCount'));
        self::assertSame('1', trim($crawler->filter('#badgeCount')->text()));
        self::assertSame(
            'Pesquisar documentos...',
            $crawler->filter($coluna . ' > .petic-timeline-header > .petic-search > input#timelinePesquisa')->attr('placeholder'),
        );
        self::assertCount(1, $crawler->filter($coluna . ' > #timelineList > #tlInner'));
        self::assertCount(1, $crawler->filter($coluna . ' > .petic-timeline-footer'));

        // O cartão é filho direto da linha, e a linha, filha direta da lista (sem o trilho nem a bolinha).
        $id = (int) $doc->getId();
        self::assertCount(1, $crawler->filter(sprintf('#tlInner > .petic-tl-row[data-doc-id="%d"] > .petic-tl-card', $id)));
        self::assertCount(0, $crawler->filter('.petic-tl-datesep'), 'o desenho não tem separador de dia');
        self::assertSame('1', trim($crawler->filter(sprintf('#tlInner > .petic-tl-row[data-doc-id="%d"] .petic-tl-card-top > .petic-tl-ord', $id))->text()));
    }

    #[TestDox('rodapé (L65-74): botão e popover do desenho são filhos diretos do rodapé, com Anexo e Texto e seus subtítulos')]
    public function testRodapeAdicionarPecaPopover(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $rodape = '.petic-layout > .petic-timeline > .petic-timeline-footer';
        $botao  = $crawler->filter($rodape . ' > button#btnAbrirUpload.petic-add-btn');
        self::assertCount(1, $botao);
        self::assertSame('Adicionar Peça', trim($botao->text()));
        self::assertCount(1, $botao->filter('button > i.bi-file-earmark-plus'));
        self::assertCount(1, $botao->filter('button > i.bi-caret-up-fill'), 'a seta começa para cima (menu fechado)');

        $menu = $crawler->filter($rodape . ' > #peticAddMenu.petic-add-menu');
        self::assertCount(1, $menu);
        self::assertNotNull($menu->attr('hidden'), 'o menu nasce fechado');
        self::assertCount(1, $crawler->filter($rodape . ' > #peticAddFundo[hidden]'));

        $anexo = $menu->filter('#peticAddMenu > button#btnOpcaoAnexo.petic-add-item');
        self::assertCount(1, $anexo);
        self::assertCount(1, $anexo->filter('button > i.bi-paperclip'));
        self::assertStringContainsString('Anexo', $anexo->text());
        self::assertStringContainsString('PDF, Word, Excel, imagens, XML e KML', $anexo->text());

        $texto = $menu->filter('#peticAddMenu > button#btnOpcaoTexto.petic-add-item');
        self::assertCount(1, $texto);
        self::assertCount(1, $texto->filter('button > i.bi-file-earmark-text'));
        self::assertStringContainsString('Escrever a peça no editor', $texto->text());
    }

    #[TestDox('o que o desenho não tem continua fora: trilho, bolinha, badge e dropdown do Bootstrap, player de áudio e vídeo')]
    public function testOQueSaiuContinuaFora(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.petic-tl-dot'), 'sem bolinha por categoria');
        self::assertNull($crawler->filter('#timelineList')->attr('data-ponto-categoria'));
        self::assertCount(0, $crawler->filter('.petic-timeline .dropdown-menu, .petic-timeline .dropup, .petic-timeline [data-bs-toggle]'), 'o menu do rodapé é o do desenho');
        self::assertCount(0, $crawler->filter('.petic-timeline-header .badge, .petic-timeline-header .rounded-pill'), 'o contador não é badge do Bootstrap');
        self::assertCount(0, $crawler->filter('.petic-timeline-footer .btn'), 'o botão não é o btn do Bootstrap');
        self::assertCount(0, $crawler->filter('audio, video, #previewAudio, #previewVideo'));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('petic-tl-dot', $html);
        self::assertStringNotContainsString('.petic-tl-inner::before', $html, 'sem o trilho vertical');
    }

    #[TestDox('painel direito (L76-100): corpo com a moldura do PDF, imagem e o aviso de formato sem visualização')]
    public function testPainelDireito(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $corpo = '#previewPanel > .petic-preview-corpo';
        self::assertCount(1, $crawler->filter($corpo . ' > #previewPlaceholder > i.bi-file-earmark-text'));
        self::assertStringContainsString('Selecione um documento na lista para visualizar', $crawler->filter('#previewPlaceholder')->text());
        self::assertCount(1, $crawler->filter($corpo . ' > iframe#previewIframe'));
        self::assertCount(1, $crawler->filter($corpo . ' > img#previewImagem'));

        $painel = $crawler->filter($corpo . ' > #downloadPanel');
        self::assertCount(1, $painel);
        self::assertCount(1, $painel->filter('#downloadPanel > i.bi-file-earmark'));
        self::assertSame('Pré-visualização indisponível para este formato.', trim($painel->filter('#downloadPanel > .petic-download-texto')->text()));
        $baixar = $painel->filter('#downloadPanel > a#downloadLink.petic-download-btn');
        self::assertCount(1, $baixar);
        self::assertSame('Baixar arquivo', trim($baixar->text()));
        self::assertCount(0, $painel->filter('.btn'), 'o botão é o do desenho, não o outline do Bootstrap');
    }

    #[TestDox('o modal "Adicionar peça" continua com os mesmos formatos aceitos')]
    public function testFormatosDoModalNaoMudaram(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        self::assertSame(
            '.pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx,.xml,.kml',
            $crawler->filter('#uploadArquivo')->attr('accept'),
        );
        $icones = json_decode((string) $crawler->filter('#timelineList')->attr('data-icones'), true);
        self::assertSame('bi-file-earmark-word-fill', $icones['docx']['icone'] ?? null, 'a linha nova usa o ícone do arquivo_icone()');
    }

    // ----------------------------------------------------------------- helpers

    private function linha(Crawler $crawler, int $id): Crawler
    {
        $row = $crawler->filter(sprintf('#tlInner > .petic-tl-row[data-doc-id="%d"]', $id));
        self::assertCount(1, $row, "linha do documento {$id}");

        return $row;
    }

    /**
     * Os tokens que o script embutido da tela usa para escrever: id do token => onde ele está.
     *
     * @var array<string, string>
     */
    private const TOKENS_DO_SCRIPT = [
        'peticionar_upload_'     => '~const csrfToken\s*=\s*("(?:[^"\\\\]|\\\\.)*");~',
        'peticionar_texto_'      => '~const csrfTexto\s*=\s*("(?:[^"\\\\]|\\\\.)*");~',
        'upload_imagem_editor_'  => '~formData\.append\(\'_token\',\s*("(?:[^"\\\\]|\\\\.)*")\);~',
    ];

    /** O valor (literal JSON) que o script embutido recebeu para aquele token. */
    private static function tokenDoScript(string $html, string $padrao): string
    {
        self::assertSame(1, preg_match($padrao, $html, $m), "script sem o ponto do token ({$padrao})");
        $valor = json_decode($m[1], true);
        self::assertIsString($valor);

        return $valor;
    }

    /** Sem o "Adicionar peça" e com os três tokens do script vazios. */
    private function assertSemAdicionarPecaNemTokensDoScript(Crawler $crawler, KernelBrowser $client): void
    {
        self::assertCount(0, $crawler->filter('#btnAbrirUpload'), 'sem o "Adicionar peça"');
        self::assertCount(0, $crawler->filter('#btnOpcaoAnexo'));
        self::assertCount(0, $crawler->filter('#btnOpcaoTexto'));

        $html = (string) $client->getResponse()->getContent();
        foreach (self::TOKENS_DO_SCRIPT as $prefixo => $padrao) {
            self::assertSame('', self::tokenDoScript($html, $padrao), "o script não recebe o token {$prefixo}");
        }
    }

    /**
     * Nenhum data-csrf-* de escrita: o da lista (excluir em lote, enviar) e o das linhas
     * (renomear/classificar, salvar a peça de texto).
     *
     * @param list<int> $idsDasLinhas
     */
    private function assertSemTokensDeEscrita(Crawler $crawler, array $idsDasLinhas): void
    {
        $lista = $crawler->filter('.petic-timeline > #timelineList');
        self::assertNull($lista->attr('data-csrf-lote'), 'sem token de excluir em lote');
        self::assertNull($lista->attr('data-csrf-upload'), 'sem token de envio');

        foreach ($idsDasLinhas as $id) {
            $row = $this->linha($crawler, $id);
            self::assertNull($row->attr('data-csrf-documento'), "linha {$id}: sem token de renomear/classificar");
            self::assertNull($row->attr('data-csrf-editar'), "linha {$id}: sem token de salvar texto");
        }
    }

    /** @return array{KernelBrowser, Pasta, Tenant, User} */
    #[TestDox('o seletor do processo lista o principal primeiro (marcado) e os apensados, como no desenho 07')]
    public function testSeletorDoProcessoListaPrincipalEApensados(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $apenso    = $this->processo($tenant, '0000002-22.2022.4.01.3400');
        $principal = $this->processo($tenant, '0000001-11.2022.4.01.3400');
        $pasta->vincularApensado($apenso, TipoDeApenso::Embargos);
        $pasta->vincularProcesso($principal);
        $em->flush();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        $itens = $crawler->filter('nav.navtop #peticProcMenu > .petic-proc-item');
        self::assertCount(2, $itens);
        self::assertSame('0000001-11.2022.4.01.3400', $itens->eq(0)->attr('data-numero'));
        self::assertStringContainsString('petic-proc-item--atual', (string) $itens->eq(0)->attr('class'));
        self::assertStringNotContainsString('petic-proc-item--atual', (string) $itens->eq(1)->attr('class'));
        self::assertStringContainsString('Embargos', $itens->eq(1)->text());
        self::assertStringContainsString('00000011120224013400', $crawler->filter('#peticProcTxt')->text());
        self::assertCount(1, $crawler->filter('script[src*="/js/peticionar-processo.js"]'));
    }

    #[TestDox('sem processo vinculado o seletor mostra "Sem processo" e o aviso do desenho')]
    public function testSeletorSemProcesso(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        self::assertSame('Sem processo', trim($crawler->filter('#peticProcTxt')->text()));
        self::assertCount(0, $crawler->filter('#peticProcMenu > .petic-proc-item'));
        self::assertStringContainsString('Nenhum processo vinculado', $crawler->filter('#peticProcMenu > .petic-proc-vazio')->text());
    }

    private function processo(Tenant $tenant, string $numero): Processo
    {
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        $processo->setTenant($tenant);
        $em->persist($processo);
        $em->flush();

        return $processo;
    }

    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant PET07 ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('pet07_' . uniqid() . '@test.com');
        $user->setFullName('Gestor Peticionar');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $pasta = new Pasta();
        $pasta->setNup('PET07-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $pasta, $tenant, $user];
    }

    private function documento(Pasta $pasta, Tenant $tenant, string $nome, string $mime, int $bytes, ?string $descricao = null, ?string $numero = null): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo($mime === 'text/html' ? substr($nome, 0, -5) : $nome);
        $doc->setNomeOriginal($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
        $doc->setCaminhoArquivo('pet07_' . uniqid() . '.bin');
        $doc->setMimeType($mime);
        $doc->setTamanhoBytes($bytes);
        $doc->setDescricao($descricao);
        $doc->setNumero($numero);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
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

    /** O gerenciador real mascara o valor (BREACH); a prova é ele aceitar o token para aquele id. */
    private static function assertTokenValido(string $idDoToken, mixed $valor): void
    {
        self::assertIsString($valor);
        self::assertNotSame('', $valor);
        self::assertTrue(
            static::getContainer()->get('security.csrf.token_manager')->isTokenValid(new CsrfToken($idDoToken, $valor)),
            "token da tela não vale para '{$idDoToken}'",
        );
    }
}
