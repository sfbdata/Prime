<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ONDE cada bloco do explorador da aba Documentos fica — o arranjo que a suíte de
 * comportamento não vê (desenho 02 - EXPEDIENTES 1.2.3, dc L2020-2297).
 *
 * Combinador de FILHO DIRETO em tudo: é o que distingue "está no lugar certo" de "existe em
 * algum lugar da aba" — que continuaria verdade com o checklist de volta numa coluna lateral,
 * ou com um modal caído dentro do painel animado. E, porque roda sobre o DOM já PARSEADO, é
 * também o que pega `</div>` sobrando: tag desbalanceada faz o parser fechar os ancestrais
 * cedo e a relação pai→filho some. Estilo (cor, fonte, raio) segue invisível aqui e é do smoke.
 */
#[CoversClass(PastaController::class)]
final class PastaExploradorArranjoTelaTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Arranjo Explorador ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_pex_arr_' . uniqid() . '@test.com');
        $user->setFullName('Admin Arranjo Explorador');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-PEX-ARR-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, string $nomeOriginal): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setTitulo($nomeOriginal);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/' . $nomeOriginal);
        $doc->setNomeOriginal($nomeOriginal);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('a aba Documentos é: sugeridos, depois o explorador — e nada do gerenciador antigo')]
    public function testAbaTemSugeridosEDepoisOExplorador(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('#documentos > #documentosSugeridos + #pexExplorador'), 'sugeridos imediatamente antes e FORA do explorador (contrato até o L3)');
        self::assertCount(0, $crawler->filter('#pexExplorador #documentosSugeridos'));

        // O fm compartilhado com a Cobrança não existe mais nesta tela — nem markup, nem assets.
        self::assertCount(0, $crawler->filter('#fileManager'), 'dois gerenciadores brigariam pelos mesmos ids globais');
        self::assertCount(0, $crawler->filter('link[href*="pasta-arquivos"]'));
        self::assertCount(0, $crawler->filter('script[src*="pasta-arquivos"]'));
        self::assertCount(1, $crawler->filter('link[href*="pasta-explorador.css"]'));
        self::assertCount(1, $crawler->filter('script[src*="pasta-explorador.js"]'));
    }

    #[TestDox('ordem dos blocos (dc L2024/2079/2181/2295): faixa → checklist → corpo → rodapé, como filhos diretos')]
    public function testOrdemDosBlocosDoExplorador(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('#pexExplorador > .pex-cab:first-child'), 'a faixa de cabeçalho abre o cartão');
        self::assertCount(1, $crawler->filter('#pexExplorador > #pexChecklist'), 'o checklist é FAIXA no topo, filho direto do explorador — não coluna lateral');
        self::assertCount(1, $crawler->filter('#pexExplorador > #pexChecklist ~ .pex-corpo'), 'o checklist vem ANTES do corpo');
        self::assertCount(0, $crawler->filter('#pexExplorador > .pex-corpo ~ #pexChecklist'), 'nunca depois dele');
        self::assertCount(1, $crawler->filter('#pexExplorador > .pex-rodape:last-child'), 'o rodapé com totais fecha o cartão');

        // A barra de upload e o aviso de duplicado ficam entre o checklist e o corpo, nesta ordem.
        self::assertCount(1, $crawler->filter('#pexExplorador > #pexUploadBar + #uploadDuplicadosAviso + .pex-corpo'));

        // Dentro do corpo: a lista é filha direta (é nela que o JS despeja o DocumentFragment).
        self::assertCount(1, $crawler->filter('#pexExplorador > .pex-corpo > #pexLista'));
        self::assertCount(1, $crawler->filter('.pex-corpo > #pexTrilha'), 'trilha própria do explorador, no corpo');
        self::assertCount(1, $crawler->filter('.pex-corpo > #pexCabecalho'));
    }

    #[TestDox('a faixa tem busca, "Nova pasta" e "ANEXAR" com clipe; Organizar e Visualizar só com o que faz alguma coisa')]
    public function testFaixaDeCabecalho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('.pex-cab > .pex-busca > #pexBusca[placeholder="Buscar arquivos e pastas…"]'));
        self::assertSame('1', trim($crawler->filter('.pex-cab > .pex-titulo > #pexContagem')->text()), 'a contagem de arquivos vive na faixa');
        self::assertCount(1, $crawler->filter('.pex-cab > #pexNovaPasta > .bi-folder-plus'));
        self::assertSame('Nova pasta', trim($crawler->filter('#pexNovaPasta')->text()));
        self::assertCount(1, $crawler->filter('.pex-cab > #pexAnexar > .bi-paperclip'), 'Anexar com clipe (o 1.2.3), não "Enviar" com upload');
        self::assertSame('Anexar', trim($crawler->filter('#pexAnexar')->text()), 'maiúsculas são do CSS (text-transform), não do texto');

        // Organizar: Classificar por (Manual 1º, S-5) + coluna Categoria desligada (S-2) + Restaurar.
        self::assertSame(
            ['manual', 'nome', 'tipo', 'tamanho', 'data', 'categoria'],
            $crawler->filter('#pexOrganizarMenu #pexClassificar > [data-pex-classificar]')->each(fn (Crawler $n) => $n->attr('data-pex-classificar'))
        );
        self::assertSame('true', $crawler->filter('#pexClassificar > [data-pex-classificar="manual"]')->attr('aria-checked'), 'Manual é o padrão');
        self::assertNotNull($crawler->filter('#pexClassificar > [data-pex-classificar="categoria"]')->attr('hidden'), 'a pílula Categoria só aparece com a coluna ligada');
        self::assertSame('false', $crawler->filter('#pexOrganizarMenu [data-pex-coluna="categoria"]')->attr('aria-checked'), 'coluna Categoria nasce desligada');
        self::assertCount(1, $crawler->filter('#pexOrganizarMenu > #pexRestaurar'));

        // Visualizar: SÓ Detalhes até o L2 — os outros sete modos não são renderizados.
        $modos = $crawler->filter('#pexVisualizarMenu [role="menuitemradio"]');
        self::assertCount(1, $modos, 'menu sem ação real não entra na tela');
        self::assertSame('det', $modos->attr('data-pex-modo'));
        self::assertSame('true', $modos->attr('aria-checked'));
        self::assertCount(0, $crawler->filter('#pexVisualizarMenu [role="menuitemcheckbox"]'), 'o painel de detalhes é do L2');
    }

    #[TestDox('cabeçalho estático de Detalhes: Nome, Tipo, Tamanho, Modificado — botões com a chave de classificar e aria-sort=none')]
    public function testCabecalhoDasColunas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame(
            ['nome', 'tipo', 'tamanho', 'data'],
            $crawler->filter('#pexCabecalho > .pex-col > [data-pex-classificar]')->each(fn (Crawler $n) => $n->attr('data-pex-classificar')),
            'as quatro colunas do desenho, na ordem; Categoria entra por JS quando ligada'
        );
        self::assertSame(
            ['none', 'none', 'none', 'none'],
            $crawler->filter('#pexCabecalho > .pex-col[aria-sort]')->each(fn (Crawler $n) => $n->attr('aria-sort')),
            'nenhuma coluna manda até alguém clicar'
        );
        // A lista nasce VAZIA no HTML: quem a preenche é o JS, a partir de #pexDados.
        self::assertSame('', trim($crawler->filter('#pexLista')->html()), 'linha pronta no HTML é o que a pasta de 1.128 docs não pode ter');
        self::assertCount(1, $crawler->filter('.pex-corpo > script#pexDados[type="application/json"]'));
    }

    #[TestDox('os ids do checklist (contrato com o JS inline) continuam todos dentro de #pexChecklist')]
    public function testIdsDoChecklistDentroDaFaixa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        foreach (['checklistLista', 'checklistBadge', 'checklistBarra', 'btnChecklistEditar', 'btnChecklistAdicionar', 'btnChecklistModelos', 'checklistFormAdicionar', 'checklistModelosPainel', 'checklistModelosLista'] as $id) {
            self::assertCount(1, $crawler->filter('#pexChecklist #' . $id), "#{$id} é contrato com o JS e tem de continuar dentro do checklist");
        }
        self::assertCount(1, $crawler->filter('#pexChecklist > .pex-ck-cab'), 'cabeçalho da faixa');
        self::assertCount(1, $crawler->filter('#pexChecklist > .pex-ck-corpo > #checklistLista'), 'a lista mora no corpo da faixa');
    }

    #[TestDox('Cobrança ajuste 10 B2: o botão Documentos vive dentro de um [role=tablist] com irmãos')]
    public function testBotaoDocumentosViveDentroDeTablistComIrmaos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* `#documentos-tab` dentro de `[role="tablist"]` é contrato de três lados
           (`data-ps-ir-aba`, `pasta-show.js#abaDoFragmento` e os testes). O gancho é
           `[role="tablist"]` e não `.nav-tabs`: o redesenho de 26/08 trocou o <ul> pelo
           controle segmentado `.ps-abas`. */
        self::assertCount(1, $crawler->filter('[role="tablist"] #documentos-tab'));
        self::assertGreaterThan(1, $crawler->filter('#pastaTabs [data-bs-toggle="tab"]')->count(), 'o container precisa ter outras abas');
        self::assertCount(1, $crawler->filter('#documentos[role="tabpanel"][aria-labelledby="documentos-tab"]'));
    }

    #[TestDox('a barra de progresso do checklist reflete os itens concluídos, e não um número solto')]
    public function testBarraDeProgressoDoChecklist(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        // 4 itens, 1 concluído → 25%.
        foreach ([true, false, false, false] as $i => $concluido) {
            $item = new PastaChecklistItem();
            $item->setPasta($pasta);
            $item->setTenant($tenant);
            $item->setTitulo('Item ' . $i);
            $item->setOrdem($i);
            if ($concluido) {
                $item->toggle();
            }
            $em->persist($item);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame('1/4 itens', trim($crawler->filter('#checklistBadge')->text()));
        self::assertStringContainsString(
            'width: 25%',
            (string) $crawler->filter('#checklistBarra')->attr('style'),
            'a barra vem preenchida do servidor — não pode depender do JS para deixar de mentir no primeiro paint'
        );
        self::assertStringContainsString('incompleto', (string) $crawler->filter('#checklistBarra')->attr('class'));
        self::assertStringContainsString('incompleto', (string) $crawler->filter('#checklistBadge')->attr('class'));
    }

    #[TestDox('checklist sem item nenhum não divide por zero nem nasce dizendo 100%')]
    public function testChecklistVazioNaoDividePorZero(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame('0/0 itens', trim($crawler->filter('#checklistBadge')->text()));
        self::assertStringContainsString('width: 0%', (string) $crawler->filter('#checklistBarra')->attr('style'));

        /* Compara a LISTA de classes, não substring: "incompleto" contém "completo". */
        $classes = preg_split('/\s+/', trim((string) $crawler->filter('#checklistBadge')->attr('class')));
        self::assertContains('incompleto', $classes);
        self::assertNotContains('completo', $classes, '0/0 não é documentação conferida: é documentação não listada');
    }

    #[TestDox('nenhum modal mora dentro de um painel de aba; os três do explorador existem uma vez, fora de .ps-page')]
    public function testModaisForaDoPainelAnimado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, 'a.pdf');
        $this->criarDocumento($pasta, $tenant, 'b.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* Modal é `position: fixed`. Um ancestral com `transform` — inclusive o que vem da
           animação de entrada dos painéis — vira o bloco de contenção dele, e o modal cai
           ABAIXO do `.modal-backdrop`: aparece e não aceita clique nem digitação. */
        $dentro = $crawler->filter('.ps-paineis .modal')->each(fn (Crawler $n) => $n->attr('id') ?? '(sem id)');
        self::assertSame([], $dentro, 'estes modais estão dentro de um painel animado e vão ficar inertes: ' . implode(', ', $dentro));

        foreach (['pexInputModal', 'pexDestinoModal', 'pexEditarModal'] as $id) {
            self::assertCount(1, $crawler->filter('#' . $id), "#{$id} tem de existir UMA vez na página");
            self::assertCount(0, $crawler->filter('.ps-page #' . $id), "#{$id} não pode voltar para dentro de .ps-page");
        }

        // UM modal de edição para a pasta inteira (DOC-90): com 2 documentos, nenhum `editDocModal<id>`.
        self::assertCount(0, $crawler->filter('[id^="editDocModal"]'), 'um formulário por documento é o que a pasta de 1.128 docs não aguenta');
    }

    #[TestDox('o modal único de edição tem os campos que o POST de pasta_documento_edit lê, e nasce vazio')]
    public function testModalDeEdicaoUnico(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $form = $crawler->filter('#pexEditarModal form#pexEditarForm[method="post"]');
        self::assertCount(1, $form);
        self::assertSame('', (string) $form->attr('action'), 'o action é preenchido pelo JS com o id da linha');
        self::assertCount(1, $form->filter('input[type="hidden"][name="_token"]#pexEditarToken'));
        self::assertSame('', (string) $form->filter('#pexEditarToken')->attr('value'), 'o token é o da linha (edit_documento_<id>), posto pelo JS');
        self::assertCount(1, $form->filter('input[name="nomeBase"]'));
        self::assertCount(1, $form->filter('input[name="descricao"]'));
        self::assertCount(1, $form->filter('input[name="numero"]'));
        self::assertSame(
            ['PECA', 'PROCURACAO', 'IDENTIFICACAO', 'COMPROVANTE_RESIDENCIA', 'GRATUIDADE_JUSTICA', 'DEMAIS'],
            $form->filter('select[name="categoria"] > option')->each(fn (Crawler $n) => $n->attr('value')),
            'as mesmas categorias do controller, na mesma ordem'
        );

        // Mover para…: a árvore é preenchida pelo JS; o Mover nasce desabilitado até escolher.
        self::assertCount(1, $crawler->filter('#pexDestinoModal #pexDestinoArvore[role="listbox"]'));
        self::assertNotNull($crawler->filter('#pexDestinoModal #pexDestinoConfirmar')->attr('disabled'));
        // Nova pasta / renomear: os quatro ids que `pedirTexto()` procura.
        self::assertCount(1, $crawler->filter('#pexInputModal #pexInputTitulo'));
        self::assertCount(1, $crawler->filter('#pexInputModal #pexInputCampo'));
        self::assertCount(1, $crawler->filter('#pexInputModal #pexInputErro'));
        self::assertCount(1, $crawler->filter('#pexInputModal #pexInputConfirmar'));
    }

    #[TestDox('o pré-visualizador continua na página com o Baixar no rodapé, e o aviso de duplicado com seus ids')]
    public function testContratosQuePermanecem(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('#previewDocModal .modal-footer #previewDocDownload'), 'tirar o download do clique no nome deixa o usuário SEM caminho se este botão sumir');
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocConteudo'));
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocNome'));

        $aviso = $crawler->filter('#pexExplorador > #uploadDuplicadosAviso');
        self::assertCount(1, $aviso);
        self::assertSame('/pasta/__ID__', $aviso->attr('data-url-pasta-tpl'));
        self::assertCount(1, $aviso->filter('#uploadDuplicadosLista'));
        self::assertCount(1, $aviso->filter('#uploadDuplicadosFechar'));

        // Os endereços e tokens de nível de pasta vivem na raiz do explorador.
        $raiz = $crawler->filter('#pexExplorador');
        self::assertSame("/pasta/{$pasta->getId()}/peticionar/upload", $raiz->attr('data-url-upload'));
        self::assertNotEmpty($raiz->attr('data-csrf-upload'));
        self::assertSame("/pasta/{$pasta->getId()}/secao", $raiz->attr('data-url-criar-secao'));
        self::assertSame("/pasta/{$pasta->getId()}/documentos/reordenar", $raiz->attr('data-url-reordenar-docs'));
        self::assertSame("/pasta/{$pasta->getId()}/secoes/reordenar", $raiz->attr('data-url-reordenar-secoes'));
        self::assertSame('/pasta/documento/__ID__/editar', $raiz->attr('data-url-editar-doc-tpl'));
        self::assertSame('/pasta/documento/__ID__/deletar', $raiz->attr('data-url-excluir-doc-tpl'));
        self::assertSame('/pasta/secao/__ID__/mover', $raiz->attr('data-url-mover-tpl'));
    }
}
