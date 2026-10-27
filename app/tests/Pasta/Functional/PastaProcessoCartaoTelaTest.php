<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Processo\Service\ResumoDoProcesso;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Cartão do processo na aba Processo da pasta (L2, desenho "02 - EXPEDIENTES 1.2.3"):
 * resumo Órgão julgador · Valor da causa · Distribuição · Instância, "Ver todas as
 * informações" com os campos reais do cadastro e o ⋮ só com função real.
 *
 * Regra: no resumo, Órgão julgador · Valor da causa · Distribuição ficam SEMPRE e o vazio diz
 * "Não informado" (PMC-007, o desenho manda); a Instância e o painel "todas as informações"
 * só mostram o que o cadastro tem. A faixa de cima (Classe/Tribunal/Situação) continua como estava.
 *
 * Os seletores usam filho direto (`>`) a partir do `article.ps-processo`, para provar
 * que o bloco está NO cartão, e não em algum lugar da página.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaProcessoCartaoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';
    private const NUMERO_MASCARA = '0701134-57.2025.8.07.0007';
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    private function abrir(object $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function cartao(Crawler $crawler): Crawler
    {
        $cartao = $crawler->filter('#processoTabContent > .ps-processos > .ps-registro > article.ps-processo');
        self::assertCount(1, $cartao);

        return $cartao;
    }

    /** Processo com os dados que o DataJud preenche em prod (órgão, distribuição, instância). */
    private function processoCompleto(Pasta $pasta): Processo
    {
        $processo = $this->criarProcesso($pasta->getTenant(), self::NUMERO);
        $processo->setOrgaoJulgador('1ª Vara Cível de Brasília');
        $processo->setDataDistribuicao(new \DateTime('2025-03-14'));
        $processo->setInstancia('G1');
        $processo->setSiglaTribunal('TJDFT');
        $processo->setSituacaoProcesso('EM_ANDAMENTO');
        $pasta->setValorCausa('15234.50');
        $this->em()->flush();
        $this->vincular($pasta, $processo);

        return $processo;
    }

    /** @return list<string> */
    private function textos(Crawler $nos): array
    {
        return $nos->each(static fn (Crawler $n): string => trim(preg_replace('/\s+/', ' ', $n->text()) ?? ''));
    }

    // =========================================================================
    // Resumo
    // =========================================================================

    #[TestDox('Resumo do desenho: Órgão julgador, Valor da causa (da pasta), Última distribuição e a Instância quando existe')]
    public function testResumoComCampos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        $resumo = $cartao->filter('article.ps-processo > .ps-processo-resumo > .ps-processo-dado');
        self::assertSame(
            ['Órgão julgador', 'Valor da causa', 'Última distribuição', 'Instância'],
            $this->textos($resumo->filter('.ps-processo-dado > .ps-rotulo')),
        );
        self::assertSame(
            ['1ª VARA CÍVEL DE BRASÍLIA', 'R$ 15.234,50', '14/03/2025', 'G1'],
            $this->textos($resumo->filter('.ps-processo-dado > .ps-processo-valor')),
        );
        self::assertCount(0, $resumo->filter('.ps-dado--vazio'), 'com dado, nada de vazio');
    }

    #[TestDox('E4 (dc de 13/10): a situação aparece "Em andamento" no cartão e no painel; o valor gravado continua EM_ANDAMENTO e o ponto verde continua')]
    public function testSituacaoLegivelSemMudarODado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        $situacao = $cartao->filter('article.ps-processo .ps-processo-dado > .ps-processo-situacao');
        self::assertCount(1, $situacao);
        self::assertSame('Em andamento', trim($situacao->text()));
        self::assertStringContainsString('ps-processo-situacao--ativa', (string) $situacao->attr('class'), 'a cor verde segue o código, não o rótulo');
        self::assertSame(
            'Em andamento',
            trim($cartao->filter('article.ps-processo > .ps-processo-detalhes > [data-proc-campo="situacao"] > .ps-processo-campo-valor')->text()),
        );

        $this->em()->clear();
        $gravado = $this->em()->find(Processo::class, $processo->getId());
        self::assertSame('EM_ANDAMENTO', $gravado?->getSituacaoProcesso(), 'é apresentação: o dado persistido não muda');
    }

    #[TestDox('E4: situação fora da lista conhecida aparece exatamente como foi gravada (sem rótulo inventado)')]
    public function testSituacaoDesconhecidaApareceComoVeio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->processoCompleto($pasta);
        $processo->setSituacaoProcesso('Em grau de recurso');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        self::assertSame('EM GRAU DE RECURSO', trim($cartao->filter('article.ps-processo .ps-processo-dado > .ps-processo-situacao')->text()));
    }

    #[TestDox('PMC-007: sem órgão, distribuição nem valor da causa, o resumo mostra os três com "Não informado" em itálico; a Instância vazia some')]
    public function testResumoSemDadoDizNaoInformado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO)); // só a classe

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        $dados = $cartao->filter('article.ps-processo > .ps-processo-resumo > .ps-processo-dado');
        self::assertSame(
            ['orgao', 'valor', 'distribuicao'],
            $dados->each(static fn (Crawler $n): string => (string) $n->attr('data-proc-campo')),
            'os três do desenho, na ordem, e sem a Instância',
        );
        foreach (['orgao', 'valor', 'distribuicao'] as $campo) {
            $valor = $cartao->filter('article.ps-processo > .ps-processo-resumo > [data-proc-campo="' . $campo . '"] > .ps-processo-valor.ps-dado--vazio');
            self::assertCount(1, $valor, "{$campo} vazio leva .ps-dado--vazio");
            self::assertSame('Não informado', trim($valor->text()));
        }
        self::assertCount(0, $cartao->filter('[data-proc-campo="instancia"]'));
        // A faixa de cima do desenho (P5d): Ação · Classe · Tribunal · Situação, com "Não informado(a)".
        self::assertCount(4, $cartao->filter('article.ps-processo > .ps-processo-dados > .ps-processo-dado'));
        self::assertSame('Não informada', trim($cartao->filter('article.ps-processo > .ps-processo-dados > [data-proc-campo="acao"] > .ps-processo-valor.ps-dado--vazio')->text()));
    }

    #[TestDox('Resumo: valor da causa ZERO é dado (entra na média por CPF) e aparece como R$ 0,00; só o nulo some')]
    public function testValorDaCausaZeroAparece(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setValorCausa('0.00');
        $this->em()->flush();
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO));

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        self::assertSame(
            'R$ 0,00',
            trim($cartao->filter('article.ps-processo > .ps-processo-resumo > [data-proc-campo="valor"] > .ps-processo-valor')->text()),
        );
    }

    // =========================================================================
    // "Ver todas as informações"
    // =========================================================================

    #[TestDox('Ver todas: botão com aria-expanded=false que controla o painel escondido, irmão dele no cartão')]
    public function testVerTodasComAriaExpanded(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        $botao = $cartao->filter('article.ps-processo > .ps-processo-mais-linha > button.js-proc-alternar');
        self::assertCount(1, $botao);
        self::assertSame('false', $botao->attr('aria-expanded'));
        self::assertSame('Ver todas as informações', trim($botao->text()));

        $painelId = (string) $botao->attr('aria-controls');
        self::assertNotSame('', $painelId);
        $painel = $cartao->filter('article.ps-processo > .ps-processo-detalhes#' . $painelId);
        self::assertCount(1, $painel, 'o painel controlado pelo botão mora no próprio cartão');
        self::assertNotNull($painel->attr('hidden'), 'fechado na carga');
    }

    #[TestDox('Ver todas (P5b): os 11 campos do desenho sempre, com "Não informado"; depois os do Datajud que houver (partes, baixa, origem, atualização)')]
    public function testPainelComCamposReais(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->processoCompleto($pasta);
        $processo->setClasseCodigo(7);
        $processo->setNivelSigilo(5);
        $processo->setDataBaixa(new \DateTime('2026-01-20'));
        $processo->setProcessoPai('07099990020248070001');
        $processo->setDataAtualizacao(new \DateTimeImmutable('2026-09-30 10:15'));

        $parte = new ParteProcesso();
        $parte->setTenant($tenant);
        $parte->setTipo('PARTE');
        $parte->setNome('CONDOMÍNIO RESIDENCIAL TOP LIFE');
        $parte->setPapel('POLO PASSIVO');
        $processo->addParte($parte);
        $this->em()->persist($parte);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        $painel = $cartao->filter('article.ps-processo > .ps-processo-detalhes');
        $campos = $painel->filter('.ps-processo-detalhes > .ps-processo-campo');
        self::assertSame(
            ['classe', 'assuntos', 'jurisdicao', 'orgao', 'distribuicao', 'valorCausa', 'segredo', 'digital', 'gratuita', 'tutela', 'prioridade',
                'instancia', 'tribunal', 'situacao', 'baixa', 'origem', 'partes', 'atualizacao'],
            $campos->each(static fn (Crawler $n): string => (string) $n->attr('data-proc-campo')),
            'os 11 do desenho, na ordem; depois só os do Datajud com dado (sem sistema e formato)',
        );
        self::assertSame(
            ['Classe judicial', 'Assunto', 'Jurisdição', 'Órgão julgador', 'Última distribuição', 'Valor da causa',
                'Segredo de justiça?', 'Juízo 100% digital?', 'Justiça gratuita?', 'Tutela/liminar?', 'Prioridade?'],
            array_slice($this->textos($campos->filter('.ps-processo-campo > .ps-rotulo')), 0, 11),
        );

        self::assertSame('PROCEDIMENTO COMUM (7)', trim($painel->filter('[data-proc-campo="classe"] > .ps-processo-campo-valor')->text()));
        self::assertSame('Não informado', trim($painel->filter('[data-proc-campo="jurisdicao"] > .ps-processo-campo-valor.ps-dado--vazio')->text()));
        $selo = $painel->filter('[data-proc-campo="segredo"] > .ps-processo-selo');
        self::assertSame('SIM', trim($selo->text()), 'o sigilo do tribunal vira o "Sim" do desenho');
        self::assertStringContainsString('ps-processo-selo--sim', (string) $selo->attr('class'));
        self::assertSame('Não informado', trim($painel->filter('[data-proc-campo="gratuita"] > .ps-dado--vazio')->text()));
        self::assertCount(0, $painel->filter('.ps-processo-manual'), 'sem edição manual, sem selo "manual"');
        self::assertSame('20/01/2026', trim($painel->filter('[data-proc-campo="baixa"] > .ps-processo-campo-valor')->text()));
        self::assertSame('0709999-00.2024.8.07.0001', trim($painel->filter('[data-proc-campo="origem"] > .ps-processo-campo-valor')->text()));
        self::assertSame(
            ['CONDOMÍNIO RESIDENCIAL TOP LIFE · POLO PASSIVO'],
            $this->textos($painel->filter('[data-proc-campo="partes"] > .ps-processo-partes > li')),
        );
        self::assertSame('30/09/2026 10:15', trim($painel->filter('[data-proc-campo="atualizacao"] > .ps-processo-campo-valor')->text()));
        // Marcas: só a flag efetiva "Sim" (o segredo do tribunal).
        self::assertSame(['Segredo de justiça'], $this->textos($cartao->filter('article.ps-processo > .ps-processo-marcas > .ps-processo-marca')));
    }

    #[TestDox('Ver todas: nível de sigilo 0 vira "NÃO" no "Segredo de justiça?", sem o selo de "sim" e sem marca')]
    public function testSigiloPublico(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $processo->setNivelSigilo(0);
        $this->em()->flush();
        $this->vincular($pasta, $processo);

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));
        $selo = $cartao->filter('article.ps-processo > .ps-processo-detalhes > [data-proc-campo="segredo"] > .ps-processo-selo');

        self::assertSame('NÃO', trim($selo->text()));
        self::assertStringNotContainsString('ps-processo-selo--sim', (string) $selo->attr('class'));
        self::assertCount(0, $cartao->filter('.ps-processo-marcas'));
    }

    #[TestDox('Ver todas (P5b): mesmo sem nenhum dado no cadastro, o botão e o painel existem (os 11 campos com "Não informado") e a linha de fonte diz que o PJe não está configurado')]
    public function testSemDadoNaoHaVerTodas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $processo->setClasseProcessual('');
        $this->em()->flush();
        $this->vincular($pasta, $processo);

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-mais-linha > .js-proc-alternar'));
        self::assertCount(11, $cartao->filter('article.ps-processo > .ps-processo-detalhes > .ps-processo-campo > .ps-dado--vazio'));
        $fonte = $cartao->filter('article.ps-processo > .ps-processo-mais-linha > .ps-processo-fonte');
        self::assertSame('Integração com o PJe não configurada. Preencha manualmente.', trim($fonte->text()));
        self::assertCount(1, $fonte->filter('i.bi-cloud-slash'));
        self::assertStringNotContainsString('Dados do PJe', $cartao->text());
    }

    // =========================================================================
    // Menu ⋮
    // =========================================================================

    #[TestDox('⋮: gatilho nas ações do cartão abre o menu do PRÓPRIO processo; cada item aponta para uma função real')]
    public function testMenuComFuncoesReais(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $cartao  = $this->cartao($crawler);

        $gatilho = $cartao->filter('article.ps-processo > .ps-processo-topo > .ps-processo-acoes > button.js-proc-menu-gatilho');
        self::assertCount(1, $gatilho);
        self::assertSame('menu', $gatilho->attr('aria-haspopup'));
        self::assertSame('false', $gatilho->attr('aria-expanded'));

        $menu = $cartao->filter('article.ps-processo > .ps-processo-menu#' . $gatilho->attr('aria-controls'));
        self::assertCount(1, $menu);
        self::assertSame('menu', $menu->attr('role'));
        self::assertNotNull($menu->attr('hidden'));
        self::assertSame('Processo ' . self::NUMERO_MASCARA, trim($menu->filter('.ps-processo-menu > .ps-processo-menu-titulo')->text()));

        // Ordem do procMenu do desenho (dc L.2914; o Encaminhar via Chat I.A de L3069 entra com a Central); o único processo é o principal, então não
        // há "Tornar processo principal". Desvincular é o último, como no desenho. "Editar dados
        // do processo" (PMC-004) aparece porque o admin tem EDIT no processo.
        self::assertSame(
            ['Adicionar nota técnica', 'Ver processo completo', 'Ver todas as informações', 'Editar dados do processo', 'Ver movimentações (Push)', 'Copiar número', 'Copiar resumo do processo', 'Compartilhar', 'Encaminhar via Chat I.A', 'Relatório geral de processos', 'Imprimir resumo', 'Editar ação da pasta', 'Desvincular da pasta'],
            $this->textos($menu->filter('.ps-processo-menu .ps-processo-menu-item')),
        );
        self::assertSame(
            '/processos/' . $processo->getId(),
            parse_url((string) $menu->filter('.ps-processo-menu > a.ps-processo-menu-item')->attr('href'), \PHP_URL_PATH),
        );

        // Nota técnica: o gatilho do nota-tecnica.js aponta para o bloco DESTE processo, que existe.
        $alvoNota = (string) $menu->filter('.js-nota-nova')->attr('data-notas');
        self::assertSame('#notas-processo-' . $processo->getId(), $alvoNota);
        self::assertCount(1, $crawler->filter($alvoNota . '.ps-notas'));

        // Ver todas: o item aciona o botão do cartão, que existe.
        $alvoBotao = (string) $menu->filter('.js-proc-alternar-menu')->attr('data-botao');
        self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-mais-linha > ' . $alvoBotao));

        // Movimentações: a aba Push da própria pasta.
        $aba = (string) $menu->filter('.js-proc-ver-movimentacoes')->attr('data-aba');
        self::assertSame('push-tab', $aba);
        self::assertCount(1, $crawler->filter('#pastaTabs > button#' . $aba));

        // Copiar número: o número cru (o mesmo do `data-copy` ao lado do número).
        self::assertSame(self::NUMERO, $menu->filter('.js-proc-copiar[data-copiar]')->attr('data-copiar'));

        // C6-EDT-03 / C6-PMC-04 (dc:2926-2927): os avisos das duas cópias com o texto do desenho.
        self::assertSame('Número do processo copiado', $menu->filter('.js-proc-copiar[data-copiar]')->attr('data-aviso'));
        self::assertSame('Resumo do processo copiado', $menu->filter('.js-proc-copiar[data-resumo]')->attr('data-aviso'));

        // Compartilhar (EDT-006/PMC-002, dc L.2928): o item NÃO some sem Web Share — sem ele, copia
        // o resumo e avisa com o texto do desenho.
        $compartilhar = $menu->filter('.js-proc-compartilhar');
        self::assertNull($compartilhar->attr('hidden'), 'Compartilhar nasce visível');
        self::assertSame('Resumo copiado para compartilhar', $compartilhar->attr('data-aviso'));

        // Nada de link morto.
        self::assertCount(0, $menu->filter('a[href="#"], [disabled]'));
    }

    #[TestDox('N4: no cartão só o copiar, o selo Principal e o ⋮; estrela, abrir e desvincular moram no ⋮ do PRÓPRIO processo')]
    public function testAcoesDoCartaoMoramNoMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        // O primeiro vínculo vira o principal; o segundo é o que oferece "tornar principal".
        $principal = $this->criarProcesso($tenant, '07022222220258070007');
        $this->vincular($pasta, $principal);
        $outro = $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $cartoes = $crawler->filter('#processoTabContent > .ps-processos > .ps-registro > article.ps-processo');
        self::assertCount(2, $cartoes);

        foreach ([$principal, $outro] as $proc) {
            $cartao = $crawler->filter('#processoTabContent > .ps-processos > .ps-registro > article.ps-processo[data-processo-id="' . $proc->getId() . '"]');
            self::assertCount(1, $cartao);

            // Ações do cartão: só o ⋮ (o copiar fica ao lado do número).
            $acoes = $cartao->filter('article.ps-processo > .ps-processo-topo > .ps-processo-acoes');
            self::assertSame(['button'], $acoes->children()->each(static fn (Crawler $n): string => $n->nodeName()));
            self::assertCount(1, $acoes->filter('.ps-processo-acoes > button.js-proc-menu-gatilho'));
            self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-topo > .ps-copiar[data-copy]'));
            // P4f (desenho N:1725): o único form no topo é o X do APENSADO; o principal não tem nenhum.
            $ehApensado = $proc === $outro;
            self::assertCount($ehApensado ? 1 : 0, $cartao->filter('.ps-processo-topo form'));
            self::assertCount($ehApensado ? 1 : 0, $cartao->filter('.ps-processo-topo > form.js-desvincular-apensado'));
            self::assertCount(0, $cartao->filter('.ps-processo-topo .bi-trash, .ps-processo-topo .bi-star, .ps-processo-topo .bi-box-arrow-up-right'));

            // Os forms com CSRF continuam, agora como itens do ⋮ (no apensado, o do apensado: P4f).
            $menu = $cartao->filter('article.ps-processo > .ps-processo-menu');
            $desv = $menu->filter($ehApensado ? '.ps-processo-menu > form.js-desvincular-apensado' : '.ps-processo-menu > form.js-ajax-desvincular-processo');
            self::assertCount(1, $desv);
            self::assertSame((string) $proc->getId(), $desv->filter('input[name="processo_id"]')->attr('value'));
            self::assertNotSame('', (string) $desv->filter('input[name="_token"]')->attr('value'));
            self::assertSame('Desvincular da pasta', trim($desv->filter('button[type="submit"].ps-processo-menu-item[role="menuitem"]')->text()));
        }

        $menuPrincipal = $crawler->filter('article.ps-processo[data-processo-id="' . $principal->getId() . '"] > .ps-processo-menu');
        self::assertCount(0, $menuPrincipal->filter('form.js-ajax-processo-principal'), 'o principal não oferece "tornar principal"');
        self::assertCount(1, $crawler->filter('article.ps-processo[data-processo-id="' . $principal->getId() . '"] > .ps-processo-topo > .ps-processo-principal'));

        $menuOutro = $crawler->filter('article.ps-processo[data-processo-id="' . $outro->getId() . '"] > .ps-processo-menu');
        $tornar    = $menuOutro->filter('.ps-processo-menu > form.js-ajax-processo-principal');
        self::assertCount(1, $tornar);
        self::assertSame((string) $outro->getId(), $tornar->filter('input[name="processo_id"]')->attr('value'));
        self::assertSame('Tornar processo principal', trim($tornar->filter('button[type="submit"].ps-processo-menu-item')->text()));
    }

    #[TestDox('⋮ Copiar resumo: texto montado só com os dados reais do processo e da pasta — linha vazia não entra')]
    public function testCopiarResumoComDadosReais(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Cobrança de taxas condominiais');
        $this->em()->flush();
        $processo = $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $menu = $this->cartao($this->abrir($client, $pasta))->filter('article.ps-processo > .ps-processo-menu');

        $linhas = json_decode((string) $menu->filter('.js-proc-copiar[data-resumo]')->attr('data-resumo'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'Processo ' . self::NUMERO_MASCARA,
            'Ação: ' . $pasta->getNomeAcao(),
            'Classe: PROCEDIMENTO COMUM',
            'Tribunal: TJDFT',
            'Situação: Em andamento',   // E4: rótulo legível, mesmo do PDF (SituacaoProcessoRotulo)
            'Órgão julgador: 1ª VARA CÍVEL DE BRASÍLIA',
            'Valor da causa: R$ 15.234,50',
            'Pasta ' . $pasta->getNup(),
        ], $linhas);
        self::assertSame(
            $menu->filter('.js-proc-copiar[data-resumo]')->attr('data-resumo'),
            $menu->filter('.js-proc-compartilhar')->attr('data-resumo'),
            'Compartilhar envia o mesmo resumo',
        );

        // EDT-005/PMC-003 (dc L.2930): "Imprimir resumo" depois de Compartilhar e do "Relatório geral de
        // processos" (spec relatorio-processos-controladoria), com o MESMO resumo (o pasta-processo.js
        // reaproveita `linhasDoResumo`) e o título "Processo N".
        $imprimir = $menu->filter('.ps-processo-menu > .js-proc-compartilhar + button.js-encaminhar + a.js-relatorio-processos + button.js-proc-imprimir[role="menuitem"]');
        self::assertCount(1, $imprimir);
        self::assertSame('Imprimir resumo', trim($imprimir->text()));
        self::assertCount(1, $imprimir->filter('i.bi.bi-printer'));
        self::assertNull($imprimir->attr('hidden'));
        self::assertSame($menu->filter('.js-proc-copiar[data-resumo]')->attr('data-resumo'), $imprimir->attr('data-resumo'));
        self::assertSame('Processo ' . self::NUMERO_MASCARA, $imprimir->attr('data-titulo'));

        // C6-EDT-06: o PDF do processo é montado no servidor com as MESMAS linhas do resumo da
        // tela — juntando rótulo e valor como o "Copiar resumo", o texto é idêntico.
        $doServidor = array_map(
            static fn (array $l): string => $l['rotulo'] . ($l['valor'] !== '' ? ': ' . $l['valor'] : ''),
            static::getContainer()->get(ResumoDoProcesso::class)->linhas($this->em()->find(Pasta::class, $pasta->getId()), $this->em()->find(Processo::class, $processo->getId())),
        );
        self::assertSame($linhas, $doServidor);
    }

    #[TestDox('⋮ Copiar resumo (P5d): processo sem dados mostra as linhas do desenho com "Não informado"')]
    public function testCopiarResumoSemDados(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $processo->setClasseProcessual('');
        $this->em()->flush();
        $this->vincular($pasta, $processo);

        $this->logarComTenant($client, $user, $tenant);
        $menu = $this->cartao($this->abrir($client, $pasta))->filter('article.ps-processo > .ps-processo-menu');

        $linhas = json_decode((string) $menu->filter('.js-proc-copiar[data-resumo]')->attr('data-resumo'), true, flags: \JSON_THROW_ON_ERROR);
        // P5d (desenho, procMenu N:3059): as linhas do desenho sempre, com "Não informado".
        self::assertSame([
            'Processo ' . self::NUMERO_MASCARA,
            'Ação: Não informado',
            'Classe: Não informado',
            'Tribunal: Não informado',
            'Situação: Não informado',
            'Órgão julgador: Não informado',
            'Valor da causa: Não informado',
            'Pasta ' . $pasta->getNup(),
        ], $linhas);
    }

    // =========================================================================
    // XHR e isolamento
    // =========================================================================

    #[TestDox('XHR: tornar principal re-renderiza o parcial com o resumo, o "Ver todas" e o ⋮ (o cartão novo nasce completo)')]
    public function testXhrReRenderizaCartaoCompleto(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        // O primeiro vínculo vira o principal; o completo entra depois, para ser promovido.
        $this->vincular($pasta, $this->criarProcesso($tenant, '07022222220258070007'));
        $processo        = $this->processoCompleto($pasta);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/processo/principal', [
            '_token'      => 'TOKEN_pasta_definir_principal_processo_' . $pasta->getId(),
            'processo_id' => $processo->getId(),
        ], [], self::XHR);
        self::assertResponseIsSuccessful();

        $dados = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $html  = new Crawler('<div id="processoTabContent">' . $dados['html'] . '</div>');

        $cartao = $html->filter('#processoTabContent > .ps-processos > .ps-registro > article.ps-processo[data-processo-id="' . $processo->getId() . '"]');
        self::assertCount(1, $cartao);
        self::assertCount(4, $cartao->filter('article.ps-processo > .ps-processo-resumo > .ps-processo-dado'));
        self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-mais-linha > button.js-proc-alternar[aria-expanded="false"]'));
        self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-topo > .ps-processo-acoes > button.js-proc-menu-gatilho'));
        self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-menu'));
        // Os contratos do XHR continuam.
        self::assertCount(1, $html->filter('form.js-ajax-processo-principal'), 'só o outro processo oferece "tornar principal"');
        // O promovido desvincula pelo form de sempre; o antigo principal virou apensado (P4f).
        self::assertCount(1, $html->filter('form.js-ajax-desvincular-processo'));
        self::assertCount(2, $html->filter('form.js-desvincular-apensado'), 'o X e o ⋮ do apensado');
        // Ids do ⋮ e do painel são únicos por processo.
        $ids = $html->filter('.ps-processo-menu, .ps-processo-detalhes')->each(static fn (Crawler $n): string => (string) $n->attr('id'));
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    #[TestDox('Isolamento: usuário de outro escritório não vê o cartão (nem órgão, nem valor da causa) da pasta alheia')]
    public function testOutroEscritorioNaoVe(): void
    {
        $client                  = static::createClient();
        [, $tenantA]             = $this->criarAdmin();
        [$userB, $tenantB]       = $this->criarAdmin();
        $pasta                   = $this->criarPasta($tenantA);
        $this->processoCompleto($pasta);

        $idPasta = (int) $pasta->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        // Sem o clear() a pasta do setup fica no cache do Doctrine, o `find` não chega ao
        // banco e o TenantFilter não tem o que filtrar (o teste provaria outra barreira).
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $idPasta);

        self::assertResponseStatusCodeSame(404);
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('1ª VARA CÍVEL DE BRASÍLIA', $corpo);
        self::assertStringNotContainsString('15.234,50', $corpo);
    }

    #[TestDox('Isolamento: parte gravada com o tenant de outro escritório não aparece no painel (a coleção lazy passa pelo TenantFilter)')]
    public function testParteDeOutroEscritorioNaoAparece(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [, $tenantAlheio] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->processoCompleto($pasta);

        foreach ([[$tenant, 'PARTE DO ESCRITÓRIO'], [$tenantAlheio, 'PARTE DO VIZINHO']] as [$dono, $nome]) {
            $parte = new ParteProcesso();
            $parte->setTenant($dono);
            $parte->setTipo('PARTE');
            $parte->setNome($nome);
            $processo->addParte($parte);
            $this->em()->persist($parte);
        }
        $this->em()->flush();
        $idPasta = (int) $pasta->getId();

        $this->logarComTenant($client, $user, $tenant);
        // Sem o clear() a coleção `partes` já estaria em memória com as duas partes e a
        // consulta (onde mora o filtro) nem aconteceria.
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $idPasta);
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['PARTE DO ESCRITÓRIO'],
            $this->textos($this->cartao($crawler)->filter('article.ps-processo > .ps-processo-detalhes > [data-proc-campo="partes"] > .ps-processo-partes > li')),
        );
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    #[TestDox('P5d: faixa Ação · Classe · Tribunal · Situação (Ação da pasta no principal) e a janela de vincular com os campos e as listas do desenho')]
    public function testFaixaEJanelaDoDesenho02(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Cobrança de taxas condominiais');
        $this->em()->flush();
        $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $pagina = $this->abrir($client, $pasta);
        $cartao = $this->cartao($pagina);

        self::assertSame(['Ação', 'Classe', 'Tribunal', 'Situação'], $this->textos($cartao->filter('article.ps-processo > .ps-processo-dados > .ps-processo-dado > .ps-rotulo')));
        self::assertSame('COBRANÇA DE TAXAS CONDOMINIAIS', trim($cartao->filter('article.ps-processo > .ps-processo-dados > [data-proc-campo="acao"] > .ps-processo-valor')->text()));

        $janela = $pagina->filter('#modalVincularProcesso');
        self::assertSame('Vincular processo', trim($janela->filter('#modalVincularProcessoLabel')->text()));
        self::assertSame('R$ 0,00', $janela->filter('input[name="valorCausa"]')->attr('placeholder'));
        self::assertSame('Ex.: Brasília · Fórum Des. Joaquim Sousa Neto', $janela->filter('input[name="jurisdicao"]')->attr('placeholder'));
        foreach (['segredo', 'digital', 'gratuita', 'tutela', 'prioridade'] as $flag) {
            self::assertSame(['Não informado', 'Sim', 'Não'], $this->textos($janela->filter('select[name="' . $flag . '"] > option')), $flag);
        }
        self::assertSame(['Em andamento', 'Suspenso', 'Arquivado', 'Baixado', 'Transitado em julgado'], $this->textos($janela->filter('select[name="situacaoProcesso"] > option')));
        self::assertSame(['G1 - Primeira Instância', 'G2 - Segunda Instância', 'Juizado Especial', 'Turma Recursal', 'Tribunal Superior'], $this->textos($janela->filter('select[name="instancia"] > option')));
        self::assertSame(['Parte', 'Advogado', 'Terceiro interessado', 'Ministério Público', 'Perito'], $this->textos($janela->filter('select[name="partes[0][tipo]"] > option')));
        self::assertStringContainsString('"Buscar no CNJ" (aba Informações) importa as movimentações do PJe e dos diários oficiais. Depois de vinculado, o Push processual passa a monitorar o processo.', $janela->text());
        self::assertSame('Abrir a tela de peticionamento desta pasta (anexos e textos)', $pagina->filter('.ps-processos a[aria-label="Peticionar na pasta"]')->attr('title'));
    }
}
