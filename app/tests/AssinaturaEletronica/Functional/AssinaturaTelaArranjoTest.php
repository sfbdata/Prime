<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tela Assinatura de documentos, etapas 1, 2 e 4 (docs/specs/assinatura-documentos-etapa-1.md, seção 4.2,
 * assinatura-documentos-etapa-2.md, seção 5.1, e assinatura-documentos-etapa-4.md, seção 8): o esqueleto que o
 * servidor entrega e o arranjo do desenho por combinador de filho direto. Linhas, chips com contagem, aba Modelos,
 * assistente, detalhe e editor de modelo são desenhados pelo public/js/assinatura.js (o arranjo deles está em
 * {@see \App\Tests\AssinaturaEletronica\Unit\AssinaturaModelosJsTest}).
 */
#[CoversNothing]
final class AssinaturaTelaArranjoTest extends JusPrimeWebTestCase
{
    private const URLS_DA_ETAPA = ['documentos', 'documento', 'criar', 'salvar', 'duplicar', 'lixeira', 'restaurar', 'excluir', 'cor', 'arquivo', 'logo', 'logo-padrao', 'enviar', 'lembrete', 'cancelar', 'previa', 'rubrica', 'modelos', 'modelo-criar', 'modelo-salvar', 'modelo-excluir'];

    private KernelBrowser $clienteAtual;

    #[TestDox('GET /assinatura com o módulo responde 200 e a raiz .bja traz eu, padrao, logos e uma URL por rota das etapas 1 e 2')]
    public function testRaizComOsAtributosDoContrato(): void
    {
        $crawler = $this->abrir();

        self::assertCount(1, $crawler->filter('div.bja#bjaPagina'));
        $raiz = $crawler->filter('div.bja#bjaPagina');

        $eu = json_decode((string) $raiz->attr('data-eu'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($eu);
        self::assertArrayHasKey('nome', $eu);
        self::assertArrayHasKey('email', $eu);

        $padrao = json_decode((string) $raiz->attr('data-padrao'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($padrao);
        foreach (['adv_trat', 'adv_nome', 'adv_seccional', 'adv_oab', 'adv_email', 'esc_tel', 'esc_end', 'esc_cep', 'esc_razao', 'esc_reg', 'esc_cnpj', 'cidade'] as $campo) {
            self::assertArrayHasKey($campo, $padrao, $campo);
        }

        $logos = json_decode((string) $raiz->attr('data-logos'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($logos);
        self::assertNotSame('', (string) ($logos['bluejus'] ?? ''));
        self::assertArrayHasKey('escritorio', $logos);
        self::assertIsArray($logos['padrao'] ?? null);
        self::assertArrayHasKey('tipo', $logos['padrao']);

        foreach (self::URLS_DA_ETAPA as $nome) {
            self::assertNotSame('', (string) $raiz->attr('data-url-' . $nome), 'data-url-' . $nome);
        }
        // Etapa 2: marcador 0 no lugar do {id} e, na prévia e na rubrica, também da {ordem}.
        self::assertStringEndsWith('/assinatura/documentos/0/enviar', (string) $raiz->attr('data-url-enviar'));
        self::assertStringEndsWith('/assinatura/documentos/0/lembrete', (string) $raiz->attr('data-url-lembrete'));
        self::assertStringEndsWith('/assinatura/documentos/0/cancelar', (string) $raiz->attr('data-url-cancelar'));
        self::assertStringEndsWith('/assinatura/documentos/0/previa/0', (string) $raiz->attr('data-url-previa'));
        self::assertStringEndsWith('/assinatura/documentos/0/rubrica/0', (string) $raiz->attr('data-url-rubrica'));
        // Uma vez cada: o mapa da etapa 2 entra no mesmo laço, sem atributo repetido.
        self::assertSame(1, substr_count((string) $this->clienteAtual->getResponse()->getContent(), 'data-url-enviar='));
        self::assertStringEndsWith('/assinatura/documentos', (string) $raiz->attr('data-url-documentos'));
        self::assertStringEndsWith('/assinatura/documentos/0', (string) $raiz->attr('data-url-documento'));
        self::assertStringEndsWith('/assinatura/documentos/0', (string) $raiz->attr('data-url-salvar'));
        self::assertStringEndsWith('/assinatura/documentos/0/duplicar', (string) $raiz->attr('data-url-duplicar'));
        self::assertStringEndsWith('/assinatura/documentos/0/arquivo', (string) $raiz->attr('data-url-arquivo'));
        self::assertStringEndsWith('/assinatura/documentos/0/logo', (string) $raiz->attr('data-url-logo'));
        // Etapa 4: modelos do escritório.
        self::assertStringEndsWith('/assinatura/modelos', (string) $raiz->attr('data-url-modelos'));
        self::assertStringEndsWith('/assinatura/modelos', (string) $raiz->attr('data-url-modelo-criar'));
        self::assertStringEndsWith('/assinatura/modelos/0', (string) $raiz->attr('data-url-modelo-salvar'));
        self::assertStringEndsWith('/assinatura/modelos/0/excluir', (string) $raiz->attr('data-url-modelo-excluir'));
    }

    #[TestDox('A raiz tem a lista visível e o assistente, o detalhe, o editor de modelo, a câmera e as camadas vazios, nessa ordem')]
    public function testFilhosDaRaiz(): void
    {
        $crawler = $this->abrir();

        self::assertSame(
            ['bjaTelaLista', 'bjaTelaNovo', 'bjaTelaDet', 'bjaTelaMod', 'bjaValCam', 'bjaCamCtx', 'bjaCamModal', 'bjaCamToast'],
            $crawler->filter('.bja > *')->each(static fn (Crawler $n): string => (string) $n->attr('id')),
        );
        self::assertCount(1, $crawler->filter('.bja > .bjaTela#bjaTelaLista:not([hidden])'));
        self::assertCount(1, $crawler->filter('.bja > .bjaTela#bjaTelaNovo[hidden]'));
        self::assertCount(1, $crawler->filter('.bja > .bjaTela#bjaTelaDet[hidden]'));
        self::assertCount(1, $crawler->filter('.bja > .bjaTela#bjaTelaDet + .bjaTela#bjaTelaMod[hidden]'));
        self::assertSame('', trim($crawler->filter('#bjaTelaNovo')->html()));
        self::assertSame('', trim($crawler->filter('#bjaTelaDet')->html()));
        self::assertSame('', trim($crawler->filter('#bjaTelaMod')->html()), 'o editor de modelo é desenhado pelo JS');
    }

    #[TestDox('Topo da lista: título Assinatura e o botão Novo documento; abas Documentos, Modelos e Validar')]
    public function testTopoEAbas(): void
    {
        $crawler = $this->abrir();

        self::assertSame('Assinatura', trim($crawler->filter('#bjaTelaLista > .bjaTopo > h1.bjaH1')->text()));
        self::assertSame('Novo documento', trim($crawler->filter('#bjaTelaLista > .bjaTopo > button.bjaP#bjaNovo')->text()));
        self::assertCount(1, $crawler->filter('#bjaTelaLista > .bjaTopo > button#bjaNovo > i.bi.bi-plus-lg'));
        self::assertCount(1, $crawler->filter('#bjaTelaLista > .bjaTopo + section.bjaCard#bjaLista'));

        self::assertSame(
            ['Documentos', 'Modelos', 'Validar'],
            $crawler->filter('#bjaLista > .bjaAbas[role="tablist"] > button.bjaAba[role="tab"]')->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertSame(
            ['docs', 'mods', 'val'],
            $crawler->filter('#bjaLista > .bjaAbas > button')->each(static fn (Crawler $b): string => (string) $b->attr('data-bja-aba')),
        );
        self::assertSame('true', $crawler->filter('#bjaLista > .bjaAbas > button[data-bja-aba="docs"]')->attr('aria-selected'));
        self::assertCount(1, $crawler->filter('#bjaLista > .bjaAbas + #bjaAbaDocs:not([hidden]) + #bjaAbaMods[hidden] + #bjaAbaVal[hidden]'));
        self::assertSame('', trim($crawler->filter('#bjaAbaMods')->html()), 'a aba Modelos é desenhada pelo JS');

        // Etapa 3: a aba Validar traz o componente do Twig (o arranjo dele está no ValidacaoPublicaControllerTest).
        self::assertCount(1, $crawler->filter('#bjaAbaVal > .bjaVal[data-bja-validar]'));
        // "Criar novo modelo" (etapa 4) é desenhado pelo JS a partir do catálogo da tela.
        self::assertCount(0, $crawler->filter('#bjaTelaLista [data-bja-acao="mod-criar"]'), 'o botão não vem no esqueleto');
    }

    #[TestDox('Aba Documentos na ordem do desenho: filtros (chips, busca, pastas), cores, barra de seleção, tabela e vazio')]
    public function testAbaDocumentos(): void
    {
        $crawler = $this->abrir();

        self::assertCount(1, $crawler->filter('#bjaAbaDocs > .bjaFiltros + .bjaCoresLin + .bjaSelBar#bjaSelBar[hidden] + .bjaTabWrap + .bjaVazio#bjaVazio[hidden]'));
        self::assertCount(5, $crawler->filter('#bjaAbaDocs > *'));

        self::assertCount(1, $crawler->filter('#bjaAbaDocs > .bjaFiltros > .bjaChips#bjaChips'));
        $busca = $crawler->filter('#bjaAbaDocs > .bjaFiltros > input.bjaF.bjaBusca#bjaBusca');
        self::assertCount(1, $busca);
        self::assertSame('Buscar por documento ou signatário', $busca->attr('placeholder'));
        self::assertSame('Buscar documento', $busca->attr('aria-label'));
        $pasta = $crawler->filter('#bjaAbaDocs > .bjaFiltros > select.bjaF.bjaPastaSel#bjaPasta');
        self::assertSame('Filtrar por pasta', $pasta->attr('aria-label'));
        self::assertSame(['Todas as pastas'], $pasta->filter('option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame('', $pasta->filter('option')->attr('value'));

        self::assertSame('Cor:', trim($crawler->filter('#bjaAbaDocs > .bjaCoresLin > span')->first()->text()));
        self::assertCount(1, $crawler->filter('#bjaAbaDocs > .bjaCoresLin > span + .bjaCoresIn#bjaCoresF + .bjaVao + .bjaDica'));
        self::assertSame('Clique com o botão direito na linha para mais opções', trim($crawler->filter('.bjaCoresLin > .bjaDica')->text()));

        self::assertCount(1, $crawler->filter('#bjaAbaDocs > .bjaTabWrap > table.bjaT.resp'));
        self::assertSame(
            ['', 'Documento', 'Signatários', 'Criado em', 'Prazo', 'Status', ''],
            $crawler->filter('.bjaTabWrap > table.bjaT > thead > tr > th')->each(static fn (Crawler $th): string => trim($th->text())),
        );
        self::assertSame('Selecionar tudo', $crawler->filter('.bjaTabWrap > table.bjaT > thead > tr > th > input[type="checkbox"]#bjaTodos')->attr('aria-label'));
        self::assertCount(1, $crawler->filter('.bjaTabWrap > table.bjaT > tbody#bjaLinhas'));
        self::assertSame('Nenhum documento neste filtro.', trim($crawler->filter('#bjaVazio')->text()));
    }

    #[TestDox('Só componentes do desenho dentro de .bja: nenhum .card, .modal ou .btn do sistema')]
    public function testSemComponentesDoSistema(): void
    {
        $crawler = $this->abrir();

        self::assertCount(0, $crawler->filter('.bja .card, .bja .modal, .bja .btn'));
        self::assertCount(0, $crawler->filter('.bja [class*="select2"]'));
        self::assertStringContainsString('bja-body', (string) $crawler->filter('body')->attr('class'));
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="css/assinatura.css"]'));
    }

    #[TestDox('Scripts: catálogo da tela, pdf.js 3.11.174 com SRI, mammoth, qrcode e jsQR do vendor, modelos, folha, validar e tela, nessa ordem')]
    public function testScripts(): void
    {
        $crawler = $this->abrir();

        $pdf = $crawler->filter('script[src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"]');
        self::assertCount(1, $pdf);
        self::assertMatchesRegularExpression('/^sha512-[A-Za-z0-9+\/=]{80,}$/', (string) $pdf->attr('integrity'));
        self::assertSame('anonymous', $pdf->attr('crossorigin'));

        $srcs = $crawler->filter('script[src]')->each(static fn (Crawler $s): string => (string) $s->attr('src'));
        $posicao = static function (string $trecho) use ($srcs): int {
            foreach ($srcs as $i => $src) {
                if (str_contains($src, $trecho)) {
                    return $i;
                }
            }

            return -1;
        };
        $ordem = array_map($posicao, ['pdf.js/3.11.174/pdf.min.js', 'vendor/mammoth/mammoth.browser.min.js', 'vendor/qrcode-generator/qrcode.js', 'js/assinatura-modelos.js', 'js/assinatura-folha.js', 'vendor/jsqr/jsQR.js', 'js/assinatura-validar.js', 'js/assinatura.js']);
        self::assertNotContains(-1, $ordem, implode(', ', $srcs));
        $ordenada = $ordem;
        sort($ordenada);
        self::assertSame($ordenada, $ordem);

        $catalogo = implode('', $crawler->filter('script[type="application/json"][data-bj-t]')->each(static fn (Crawler $s): string => $s->text()));
        self::assertStringContainsString('assinatura_eletronica_tela.js.chip', $catalogo);
        self::assertStringContainsString('assinatura_eletronica_tela.js.folha.previa', $catalogo);
        self::assertStringContainsString('assinatura_eletronica_tela.js.canc.texto', $catalogo);
        self::assertStringContainsString('assinatura_eletronica_tela.js.toast.link_copiado', $catalogo);
    }

    #[TestDox('Os arquivos da tela não levam travessão (regra do dono)')]
    public function testSemTravessao(): void
    {
        $app = \dirname(__DIR__, 3);
        foreach (['public/js/assinatura.js', 'public/js/assinatura-folha.js', 'public/js/assinatura-modelos.js', 'public/js/assinatura-publica.js', 'public/js/assinatura-validar.js', 'public/css/assinatura.css', 'public/css/assinatura-validar.css', 'public/css/assinatura-publica.css', 'templates/assinatura_eletronica/index.html.twig', 'templates/assinatura_eletronica/_validar.html.twig', 'templates/assinatura_eletronica/validar.html.twig', 'translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml', 'translations/assinatura_eletronica_publica/messages+intl-icu.pt_BR.yaml'] as $arquivo) {
            self::assertDoesNotMatchRegularExpression('/[\x{2014}\x{2013}]/u', (string) file_get_contents($app . '/' . $arquivo), $arquivo);
        }
    }

    private function abrir(): Crawler
    {
        $this->clienteAtual = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Escritório Assinatura ' . uniqid());
        $em->persist($tenant);
        $this->ligarATela($em);
        $usuario = $this->criarUsuarioComModulo($em, $tenant);
        $this->logarComTenant($this->clienteAtual, $usuario, $tenant);

        $crawler = $this->clienteAtual->request('GET', '/assinatura');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** A chave `assinatura` do Master nasce desligada até a etapa 3; o teste da tela liga para quem não é Master. */
    private function ligarATela(EntityManagerInterface $em): void
    {
        $estado = $em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'assinatura']);
        if ($estado === null) {
            $estado = new RecursoEstado('assinatura');
            $em->persist($estado);
        }
        $estado->reativar(null, new \DateTimeImmutable());
        $em->flush();
    }

    private function criarUsuarioComModulo(EntityManagerInterface $em, Tenant $tenant): User
    {
        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName('Perfil Assinatura ' . uniqid());
        $em->persist($perfil);

        $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.assinatura_eletronica.view']);
        if ($permissao === null) {
            $permissao = (new Permission())->setCode('modules.assinatura_eletronica.view')->setDescription('Acesso ao módulo Assinatura de documentos')->setGroup('modules');
            $em->persist($permissao);
        }
        $concessao = new TenantRolePermission();
        $concessao->setTenantRole($perfil);
        $concessao->setPermission($permissao);
        $em->persist($concessao);
        $perfil->getTenantRolePermissions()->add($concessao);

        $usuario = new User();
        $usuario->setEmail('assinatura_' . uniqid() . '@test.com');
        $usuario->setFullName('Advogada Assinatura');
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setIsActive(true);
        $em->persist($usuario);
        $vinculo = new UserTenant($usuario, $tenant);
        $vinculo->setTenantRole($perfil);
        $em->persist($vinculo);
        $em->flush();

        return $usuario;
    }
}
