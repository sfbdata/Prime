<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
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
 * Regra desta entrega: campo vazio NÃO aparece (nem "Não informado") nos blocos novos;
 * a faixa de cima (Classe/Tribunal/Situação) continua como estava.
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

    #[TestDox('Resumo: Órgão julgador, Valor da causa (da pasta), Distribuição e Instância aparecem no cartão quando existem')]
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
            ['Órgão julgador', 'Valor da causa', 'Distribuição', 'Instância'],
            $this->textos($resumo->filter('.ps-processo-dado > .ps-rotulo')),
        );
        self::assertSame(
            ['1ª VARA CÍVEL DE BRASÍLIA', 'R$ 15.234,50', '14/03/2025', 'G1'],
            $this->textos($resumo->filter('.ps-processo-dado > .ps-processo-valor')),
        );
    }

    #[TestDox('Resumo: sem órgão, distribuição, instância nem valor da causa, a faixa nem é renderizada — nada de "Não informado"')]
    public function testResumoSomeQuandoNulo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO)); // só a classe

        $this->logarComTenant($client, $user, $tenant);
        $cartao = $this->cartao($this->abrir($client, $pasta));

        self::assertCount(0, $cartao->filter('.ps-processo-resumo'));
        self::assertCount(0, $cartao->filter('[data-proc-campo="orgao"], [data-proc-campo="valor"], [data-proc-campo="distribuicao"], [data-proc-campo="instancia"]'));
        self::assertStringNotContainsString('Não informado', $cartao->filter('.ps-processo-detalhes')->text(''));
        // A faixa de cima não muda: Classe/Tribunal/Situação seguem com "Não informado(a)".
        self::assertCount(3, $cartao->filter('article.ps-processo > .ps-processo-dados > .ps-processo-dado'));
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

    #[TestDox('Ver todas: o painel traz os campos reais (sigilo como rótulo, partes com o polo, baixa, origem, atualização) e omite os vazios')]
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
            ['classe', 'orgao', 'distribuicao', 'valor', 'instancia', 'tribunal', 'situacao', 'baixa', 'sigilo', 'origem', 'partes', 'atualizacao'],
            $campos->each(static fn (Crawler $n): string => (string) $n->attr('data-proc-campo')),
            'sem assunto, sistema e formato: os vazios não entram',
        );

        self::assertSame('PROCEDIMENTO COMUM (7)', trim($painel->filter('[data-proc-campo="classe"] > .ps-processo-campo-valor')->text()));
        $selo = $painel->filter('[data-proc-campo="sigilo"] > .ps-processo-selo');
        self::assertSame('Segredo de justiça', trim($selo->text()), 'o rótulo da entidade, não o número');
        self::assertStringContainsString('ps-processo-selo--sim', (string) $selo->attr('class'));
        self::assertSame('20/01/2026', trim($painel->filter('[data-proc-campo="baixa"] > .ps-processo-campo-valor')->text()));
        self::assertSame('0709999-00.2024.8.07.0001', trim($painel->filter('[data-proc-campo="origem"] > .ps-processo-campo-valor')->text()));
        self::assertSame(
            ['CONDOMÍNIO RESIDENCIAL TOP LIFE · POLO PASSIVO'],
            $this->textos($painel->filter('[data-proc-campo="partes"] > .ps-processo-partes > li')),
        );
        self::assertSame('30/09/2026 10:15', trim($painel->filter('[data-proc-campo="atualizacao"] > .ps-processo-campo-valor')->text()));
    }

    #[TestDox('Ver todas: nível de sigilo 0 vira "Público", sem o selo de "sim"')]
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
        $selo = $this->cartao($this->abrir($client, $pasta))
            ->filter('article.ps-processo > .ps-processo-detalhes > [data-proc-campo="sigilo"] > .ps-processo-selo');

        self::assertSame('Público', trim($selo->text()));
        self::assertStringNotContainsString('ps-processo-selo--sim', (string) $selo->attr('class'));
    }

    #[TestDox('Ver todas: processo sem nenhum dado no cadastro não oferece o botão, o painel nem o item do ⋮')]
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

        self::assertCount(0, $cartao->filter('.js-proc-alternar'));
        self::assertCount(0, $cartao->filter('.ps-processo-detalhes'));
        self::assertCount(0, $cartao->filter('.js-proc-alternar-menu'));
        self::assertCount(1, $cartao->filter('article.ps-processo > .ps-processo-menu'), 'o ⋮ continua: nota, Push e copiar não dependem do cadastro');
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

        // Ordem do procMenu do desenho (dc L.2914); o único processo é o principal, então não
        // há "Tornar processo principal". Desvincular é o último, como no desenho.
        self::assertSame(
            ['Adicionar nota técnica', 'Ver processo completo', 'Ver todas as informações', 'Ver movimentações (Push)', 'Copiar número', 'Copiar resumo do processo', 'Compartilhar', 'Desvincular da pasta'],
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

        // Compartilhar: Web Share do navegador; nasce escondido e o JS só o mostra se existir.
        self::assertNotNull($menu->filter('.js-proc-compartilhar')->attr('hidden'));

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
            self::assertCount(0, $cartao->filter('.ps-processo-topo form, .ps-processo-topo .bi-trash, .ps-processo-topo .bi-star, .ps-processo-topo .bi-box-arrow-up-right'));

            // Os forms com CSRF continuam, agora como itens do ⋮.
            $menu = $cartao->filter('article.ps-processo > .ps-processo-menu');
            $desv = $menu->filter('.ps-processo-menu > form.js-ajax-desvincular-processo');
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
        $this->processoCompleto($pasta);

        $this->logarComTenant($client, $user, $tenant);
        $menu = $this->cartao($this->abrir($client, $pasta))->filter('article.ps-processo > .ps-processo-menu');

        $linhas = json_decode((string) $menu->filter('.js-proc-copiar[data-resumo]')->attr('data-resumo'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            'Processo ' . self::NUMERO_MASCARA,
            'Ação: ' . $pasta->getNomeAcao(),
            'Classe: PROCEDIMENTO COMUM',
            'Tribunal: TJDFT',
            'Situação: EM_ANDAMENTO',
            'Órgão julgador: 1ª VARA CÍVEL DE BRASÍLIA',
            'Distribuição: 14/03/2025',
            'Valor da causa: R$ 15.234,50',
            'Pasta ' . $pasta->getNup(),
        ], $linhas);
        self::assertSame(
            $menu->filter('.js-proc-copiar[data-resumo]')->attr('data-resumo'),
            $menu->filter('.js-proc-compartilhar')->attr('data-resumo'),
            'Compartilhar envia o mesmo resumo',
        );
    }

    #[TestDox('⋮ Copiar resumo: processo sem dados não ganha "Não informado" — só número e pasta')]
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
        self::assertSame(['Processo ' . self::NUMERO_MASCARA, 'Pasta ' . $pasta->getNup()], $linhas);
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
        self::assertCount(2, $html->filter('form.js-ajax-desvincular-processo'));
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
}
