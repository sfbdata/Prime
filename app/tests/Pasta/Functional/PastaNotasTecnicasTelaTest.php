<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPushProcessualController;
use App\Pasta\DTO\PastaPushOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Twig\NotaTecnicaExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As notas técnicas NA TELA da pasta: o bloco sob o cartão do processo na aba Processo (desenho
 * 1.2.3), a mesma nota em toda pasta que vincula o processo, a janela de 15 min e o teor do
 * Push com só as notas daquela movimentação.
 *
 * Arranjo com combinador de FILHO DIRETO: "está sob o cartão, dentro da lista" é diferente de
 * "existe em algum lugar da página".
 */
#[CoversClass(NotaTecnicaExtension::class)]
#[CoversClass(PastaPushProcessualController::class)]
#[CoversClass(PastaPushOutput::class)]
#[Group('pasta')]
final class PastaNotasTecnicasTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use DesligaTenantFilterNaRequisicao;

    private const NUMERO = '07011345720258070007';

    private function criarNota(
        Processo $processo,
        User $autor,
        Tenant $tenant,
        string $conteudo = '<p>Prazo de 15 dias para contestar.</p>',
        ?PublicacaoDjen $pub = null,
        ?string $quando = null,
    ): NotaTecnica {
        $nota = new NotaTecnica($tenant, $processo, $autor, $conteudo, $pub);
        if ($quando !== null) {
            (new \ReflectionProperty(NotaTecnica::class, 'criadaEm'))->setValue($nota, new \DateTimeImmutable($quando));
        }
        $this->em()->persist($nota);
        $this->em()->flush();

        return $nota;
    }

    #[TestDox('na aba Processo, o bloco de notas fica logo abaixo do cartão do processo, dentro da lista')]
    public function testBlocoSobOCartaoDoProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $nota = $this->criarNota($processo, $user, $tenant);
        $pid  = $processo->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // O bloco é filho direto da lista da aba Processo, irmão imediato do cartão do processo.
        self::assertCount(1, $crawler->filter("#processo .ps-processos > .ps-processos-lista > .ps-notas#notas-processo-{$pid}"));
        self::assertCount(1, $crawler->filter("#processo .ps-processos-lista > article[data-processo-id=\"{$pid}\"] + .ps-notas#notas-processo-{$pid}"));

        $bloco = $crawler->filter("#notas-processo-{$pid}");
        self::assertSame("/processos/{$pid}/nota-tecnica", $bloco->attr('data-url-criar'));
        self::assertSame((string) $pasta->getId(), $bloco->attr('data-pasta-id'));
        self::assertSame('', $bloco->attr('data-publicacao-id'));
        self::assertNotEmpty($bloco->attr('data-csrf-criar'));

        // Cabeçalho: título, contagem, o aviso de que a nota é do processo e o botão de adicionar.
        $cab = $bloco->filter('.ps-notas > .ps-notas-cab');
        self::assertCount(1, $cab);
        self::assertSame('Notas técnicas', trim($cab->filter('.ps-notas-titulo')->text()));
        self::assertSame('1', trim($cab->filter('.ps-notas-contagem')->text()));
        self::assertStringContainsString('aparece em toda pasta que o vincula', $cab->filter('.ps-notas-aviso')->text());
        self::assertCount(1, $cab->filter('.ps-notas-cab > .js-nota-nova'));

        // O compositor existe, fechado; a nota está na lista, com o selo e o texto.
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-nota-editor[hidden] textarea'));
        // Desenho dc:1693–1710 (auditoria 2 P3): a lista vem antes do compositor.
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-notas-lista + .ps-nota-editor[hidden]'));
        $item = $bloco->filter(".ps-notas > .ps-notas-lista > #nota-tecnica-{$nota->getId()}");
        self::assertCount(1, $item);
        self::assertSame('Nota técnica', trim($item->filter('.ps-nota > .ps-nota-topo > .ps-nota-selo')->text()));
        self::assertSame('Admin Push', trim($item->filter('.ps-nota-topo > .ps-nota-autor')->text()));
        self::assertSame('AP', trim($item->filter('.ps-nota-topo > .ps-nota-avatar')->text()));
        self::assertStringContainsString('Prazo de 15 dias', $item->filter('.ps-nota > .ps-nota-texto')->text());
        // Nota sem publicação: nenhum rótulo de movimentação.
        self::assertCount(0, $item->filter('.ps-nota-mov'));
    }

    #[TestDox('a nota é do processo: aparece também em OUTRA pasta que vincula o mesmo processo')]
    public function testMesmaNotaEmOutraPastaDoMesmoProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $pastaA          = $this->criarPasta($tenant);
        $pastaB          = $this->criarPasta($tenant);
        $this->vincular($pastaA, $processo);
        $this->vincular($pastaB, $processo);
        $nota = $this->criarNota($processo, $user, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        foreach ([$pastaA, $pastaB] as $pasta) {
            $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter("#notas-processo-{$processo->getId()} > .ps-notas-lista > #nota-tecnica-{$nota->getId()}"), 'pasta ' . $pasta->getId());
        }
    }

    #[TestDox('nota pendurada numa movimentação diz qual, na aba Processo ("Intimação · 20/08/2026")')]
    public function testRotuloDaMovimentacaoNaAbaProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub  = $this->criarPublicacao($tenant, '60000001', self::NUMERO, '2026-08-20', $processo);
        $nota = $this->criarNota($processo, $user, $tenant, '<p>Sobre a intimação</p>', $pub);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $mov = $crawler->filter("#nota-tecnica-{$nota->getId()} > .ps-nota-topo > .ps-nota-mov");
        self::assertCount(1, $mov);
        self::assertSame('Intimação · 20/08/2026', trim($mov->text()));
    }

    /**
     * O ⋮ de uma nota: o gatilho e o menu são filhos diretos do topo do cartão, e o menu traz os
     * itens como filhos diretos (nada de item solto em outro lugar do cartão).
     *
     * @return array{\Symfony\Component\DomCrawler\Crawler, list<string>} o menu e o texto dos itens, na ordem
     */
    private function menuDaNota(\Symfony\Component\DomCrawler\Crawler $crawler, NotaTecnica $nota): array
    {
        $id      = $nota->getId();
        $gatilho = $crawler->filter("#nota-tecnica-{$id} > .ps-nota-topo > button.js-nota-menu[aria-haspopup=\"menu\"]");
        self::assertCount(1, $gatilho, 'o ⋮ fica no topo do cartão');
        self::assertSame("psNotaMenu{$id}", $gatilho->attr('aria-controls'));
        self::assertSame('Opções da nota', $gatilho->attr('aria-label'));
        self::assertCount(1, $gatilho->filter('i.bi-three-dots'), 'o ícone do desenho é o ⋯ (bi-three-dots)');

        $menu = $crawler->filter("#nota-tecnica-{$id} > .ps-nota-topo > #psNotaMenu{$id}.ps-nota-menu[role=\"menu\"][hidden]");
        self::assertCount(1, $menu, 'o menu nasce fechado, ao lado do gatilho');
        self::assertSame('Nota técnica · ' . $nota->getAutor()?->getFullName(), trim($menu->filter('.ps-nota-menu > .ps-processo-menu-titulo')->text()));

        $itens = $menu->filter('.ps-nota-menu > .ps-processo-menu-item')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''),
        );

        return [$menu, $itens];
    }

    #[TestDox('⋮ do autor dentro dos 15 min: Responder com outra nota, Copiar texto, Editar · 10 min e Excluir · 10 min, com as ações reais')]
    public function testMenuDoAutorDentroDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $recente = $this->criarNota($processo, $user, $tenant, '<p>Recente</p>', null, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        [$menu, $itens] = $this->menuDaNota($crawler, $recente);
        self::assertSame(['Responder com outra nota', 'Copiar texto', 'Editar · 10 min', 'Excluir · 10 min'], $itens);
        self::assertCount(1, $menu->filter('.ps-nota-menu > .js-nota-copiar > i.bi-clipboard'));
        self::assertCount(1, $menu->filter('.ps-nota-menu > .js-nota-editar[data-url][data-csrf][data-conteudo][data-conteudo-html] > i.bi-pencil'));
        self::assertCount(1, $menu->filter('.ps-nota-menu > .js-nota-excluir[data-url][data-csrf] > i.bi-trash'));
        self::assertSame("/processos/{$processo->getId()}/nota-tecnica/{$recente->getId()}/editar", $menu->filter('.js-nota-editar')->attr('data-url'));
        self::assertCount(0, $menu->filter('.ps-nota-menu-bloqueado'));
        // PMC-057: "Responder com outra nota" é o `abrirNova` do desenho — só abre o compositor;
        // não carrega URL nem vínculo (nota técnica não tem resposta no modelo).
        self::assertCount(1, $menu->filter('.ps-nota-menu > .js-nota-responder[role="menuitem"] > i.bi-reply'));
        self::assertCount(0, $menu->filter('.js-nota-responder[data-url], .js-nota-responder[data-csrf]'));
        // O lápis e a lixeira soltos no topo deram lugar ao ⋮.
        self::assertCount(0, $crawler->filter("#nota-tecnica-{$recente->getId()} .ps-anotacao-acoes"));
    }

    #[TestDox('⋮ do autor passados 20 min: Editar · bloqueado e Excluir · bloqueado com bi-lock, sem URL, clicáveis com o aviso (C6-PMC-05)')]
    public function testMenuDoAutorForaDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $antiga = $this->criarNota($processo, $user, $tenant, '<p>Antiga</p>', null, '-20 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        [$menu, $itens] = $this->menuDaNota($crawler, $antiga);
        self::assertSame(['Responder com outra nota', 'Copiar texto', 'Editar · bloqueado', 'Excluir · bloqueado'], $itens);
        self::assertCount(2, $menu->filter('.ps-nota-menu > .ps-nota-menu-bloqueado.js-nota-bloqueado[aria-disabled="true"] > i.bi-lock'));
        // C6-PMC-05 (dc:5405-5406): clicável — sem `disabled`, que engolia o clique e deixava o
        // motivo só no `title` —, com o aviso do desenho no `data-aviso`.
        self::assertCount(0, $menu->filter('.ps-nota-menu-bloqueado[disabled]'));
        self::assertSame(
            ['Só o autor edita a nota, em até 15 minutos', 'Só o autor exclui a nota, em até 15 minutos'],
            $menu->filter('.ps-nota-menu > .js-nota-bloqueado')->each(static fn ($b): string => (string) $b->attr('data-aviso')),
        );
        self::assertCount(0, $menu->filter('.js-nota-editar, .js-nota-excluir, [data-url], [data-csrf]'), 'item bloqueado não carrega ação');
    }

    #[TestDox('⋮ de nota de OUTRO autor, mesmo dentro da janela: só Copiar texto ativo; Editar/Excluir bloqueados')]
    public function testMenuDeOutroAutor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $nota = $this->criarNota($processo, $outro, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        [$menu, $itens] = $this->menuDaNota($crawler, $nota);
        self::assertSame(['Responder com outra nota', 'Copiar texto', 'Editar · bloqueado', 'Excluir · bloqueado'], $itens);
        self::assertCount(0, $menu->filter('.js-nota-editar, .js-nota-excluir, [data-url]'));
        self::assertCount(0, $crawler->filter("#nota-tecnica-{$nota->getId()} .ps-anotacao-acoes"));
    }

    #[TestDox('pasta excluída (lápide): o ⋮ da nota é só leitura — Copiar texto, sem editar nem excluir, na aba Processo e no teor do Push')]
    public function testMenuNaLapideSoLeitura(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub  = $this->criarPublicacao($tenant, '60000030', self::NUMERO, '2026-08-20', $processo);
        // Do próprio usuário e dentro da janela: fora da lápide teria Editar/Excluir ativos.
        $nota = $this->criarNota($processo, $user, $tenant, '<p>Na lápide</p>', $pub, '-5 minutes');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        foreach (["/pasta/{$pasta->getId()}", "/pasta/{$pasta->getId()}/push/{$pub->getId()}"] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);

            [$menu, $itens] = $this->menuDaNota($crawler, $nota);
            self::assertSame(['Copiar texto'], $itens, $url);
            self::assertCount(0, $menu->filter('.ps-processo-menu-sep, .ps-nota-menu-bloqueado, [data-url], [data-csrf]'), $url);
        }
    }

    #[TestDox('PMC-058: quem só lê a pasta vê as notas, sem "Adicionar nota técnica", sem compositor e sem Responder — na aba Processo e no teor do Push')]
    public function testLeitorNaoRecebeAEscritaDaNota(): void
    {
        $client          = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $leitor          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub  = $this->criarPublicacao($tenant, '60000050', self::NUMERO, '2026-08-20', $processo);
        $nota = $this->criarNota($processo, $admin, $tenant, '<p>Lida por todos</p>', $pub);

        $alvos = [
            "/pasta/{$pasta->getId()}"                      => "notas-processo-{$processo->getId()}",
            "/pasta/{$pasta->getId()}/push/{$pub->getId()}" => "notas-publicacao-{$pub->getId()}",
        ];

        $this->logarComTenant($client, $admin, $tenant);
        foreach ($alvos as $url => $blocoId) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $botao = $crawler->filter("#{$blocoId} > .ps-notas-cab > button.js-nota-nova > i.bi-journal-plus");
            self::assertCount(1, $botao, "quem edita cria nota ({$url})");
            self::assertSame('Adicionar nota técnica', trim($crawler->filter("#{$blocoId} > .ps-notas-cab > .js-nota-nova")->text()), $url);
            self::assertCount(1, $crawler->filter("#{$blocoId} > .ps-nota-editor"), $url);
            self::assertCount(1, $crawler->filter("#{$blocoId} #nota-tecnica-{$nota->getId()} .js-nota-responder"), $url);
        }

        $this->logarComTenant($client, $leitor, $tenant);
        foreach ($alvos as $url => $blocoId) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount(1, $crawler->filter("#{$blocoId} > .ps-notas-lista > #nota-tecnica-{$nota->getId()}"), "o leitor ainda lê a nota ({$url})");
            self::assertCount(0, $crawler->filter("#{$blocoId} .js-nota-nova"), $url);
            self::assertCount(0, $crawler->filter("#{$blocoId} .ps-nota-editor"), $url);
            self::assertCount(0, $crawler->filter("#{$blocoId} .js-nota-responder"), $url);
            self::assertStringNotContainsString('Adicionar nota técnica', $crawler->filter("#{$blocoId}")->text(), $url);
        }
    }

    #[TestDox('PMC-058: na lápide nem o admin recebe "Adicionar nota técnica" nem o compositor, na aba Processo e no teor')]
    public function testLapideSemAdicionarNota(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '60000051', self::NUMERO, '2026-08-20', $processo);
        $this->criarNota($processo, $user, $tenant, '<p>Na lápide</p>', $pub);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        foreach (["/pasta/{$pasta->getId()}" => "notas-processo-{$processo->getId()}", "/pasta/{$pasta->getId()}/push/{$pub->getId()}" => "notas-publicacao-{$pub->getId()}"] as $url => $blocoId) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount(1, $crawler->filter("#{$blocoId} > .ps-notas-lista > .ps-nota"), $url);
            self::assertCount(0, $crawler->filter("#{$blocoId} .js-nota-nova, #{$blocoId} .ps-nota-editor"), $url);
        }
    }

    #[TestDox('EDT-021: o editor da nota aponta o "Salvar nota" do PRÓPRIO bloco como botão de envio (apagado com o editor vazio), na aba Processo e no teor')]
    public function testSalvarNotaEhOBotaoDeEnvioDoEditor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '60000040', self::NUMERO, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $alvos = [
            "/pasta/{$pasta->getId()}"                      => "notas-processo-{$processo->getId()}",
            "/pasta/{$pasta->getId()}/push/{$pub->getId()}" => "notas-publicacao-{$pub->getId()}",
        ];
        foreach ($alvos as $url => $blocoId) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);

            $textarea = $crawler->filter("#{$blocoId} > .ps-nota-editor > textarea");
            self::assertCount(1, $textarea, $url);
            self::assertSame("{$blocoId}-salvar", $textarea->attr('data-editor-rico-envio'), $url);
            // O id aponta para o Salvar DESTE bloco, dentro das ações do compositor — e é único na página.
            self::assertCount(1, $crawler->filter("#{$blocoId} > .ps-nota-editor > .ps-nota-editor-acoes > button.js-nota-salvar#{$blocoId}-salvar"), $url);
            self::assertCount(1, $crawler->filter("#{$blocoId}-salvar"), $url);
            self::assertSame('Salvar nota', trim($crawler->filter("#{$blocoId}-salvar")->text()), $url);
        }
    }

    #[TestDox('o teor do Push mostra só as notas DAQUELA movimentação, com o compositor apontando para o processo')]
    public function testTeorDoPushMostraSoAsNotasDaMovimentacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub1 = $this->criarPublicacao($tenant, '60000010', self::NUMERO, '2026-08-20', $processo);
        $pub2 = $this->criarPublicacao($tenant, '60000011', self::NUMERO, '2026-08-21', $processo);
        $daPub1     = $this->criarNota($processo, $user, $tenant, '<p>Da primeira</p>', $pub1);
        $daPub2     = $this->criarNota($processo, $user, $tenant, '<p>Da segunda</p>', $pub2);
        $soProcesso = $this->criarNota($processo, $user, $tenant, '<p>Só do processo</p>');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub1->getId()}");
        self::assertResponseIsSuccessful();

        $bloco = $crawler->filter(".ps-push-teor-corpo > .ps-notas#notas-publicacao-{$pub1->getId()}");
        self::assertCount(1, $bloco);
        self::assertSame((string) $processo->getId(), $bloco->attr('data-processo-id'));
        self::assertSame((string) $pub1->getId(), $bloco->attr('data-publicacao-id'));
        self::assertSame((string) $pasta->getId(), $bloco->attr('data-pasta-id'));
        self::assertStringContainsString('Sobre esta movimentação', $bloco->filter('.ps-notas-aviso')->text());

        self::assertCount(1, $bloco->filter(".ps-notas-lista > #nota-tecnica-{$daPub1->getId()}"));
        self::assertCount(0, $bloco->filter("#nota-tecnica-{$daPub2->getId()}"));
        self::assertCount(0, $bloco->filter("#nota-tecnica-{$soProcesso->getId()}"));
        // Dentro do teor da própria publicação o rótulo da movimentação é redundante.
        self::assertCount(0, $bloco->filter('.ps-nota-mov'));
    }

    #[TestDox('FPN-020: a nota da movimentação fica pendurada sob o cartão do Push com o teor FECHADO; o cartão sem nota ganha o bloco ESCONDIDO (C6-FPN-04)')]
    public function testNotaPenduradaSobOCartaoComOTeorFechado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $comNota = $this->criarPublicacao($tenant, '60000060', self::NUMERO, '2026-08-20', $processo);
        $semNota = $this->criarPublicacao($tenant, '60000061', self::NUMERO, '2026-08-21', $processo);
        $antiga  = $this->criarNota($processo, $user, $tenant, '<p>Primeira leitura</p>', $comNota, '-30 minutes');
        $nova    = $this->criarNota($processo, $user, $tenant, '<p>Prazo de 5 dias</p>', $comNota);
        $soDoProcesso = $this->criarNota($processo, $user, $tenant, '<p>Só do processo</p>');
        $cid = $comNota->getId();
        $sid = $semNota->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // Arranjo: no <li> do cartão, depois do teor (fechado), como filho direto.
        $bloco = $crawler->filter("#push .ps-push-lista > li[data-push-id=\"{$cid}\"] > .ps-push-cartao + .ps-push-teor[hidden] + .ps-notas#notas-publicacao-{$cid}");
        self::assertCount(1, $bloco, 'a nota fica sob o cartão sem abrir o teor');
        self::assertSame((string) $processo->getId(), $bloco->attr('data-processo-id'));
        self::assertSame((string) $cid, $bloco->attr('data-publicacao-id'));
        self::assertSame((string) $pasta->getId(), $bloco->attr('data-pasta-id'));
        self::assertSame(
            ["nota-tecnica-{$antiga->getId()}-push", "nota-tecnica-{$nova->getId()}-push"],
            $bloco->filter('.ps-notas > .ps-notas-lista > .ps-nota')->each(static fn ($n): string => (string) $n->attr('id')),
            'só as notas DESTA movimentação, em ordem cronológica (C6-FPN-01: a mais antiga primeiro)',
        );
        self::assertSame('2', trim($bloco->filter('.ps-notas-contagem')->text()));
        self::assertStringContainsString('Prazo de 5 dias', $bloco->filter("#nota-tecnica-{$nova->getId()}-push > .ps-nota-texto")->text());
        // A mesma nota continua na aba Processo com o id canônico (a âncora da @menção); a cópia
        // do Push tem ids próprios, e o ⋮ dela abre o PRÓPRIO menu.
        self::assertCount(1, $crawler->filter("#processo #notas-processo-{$processo->getId()} #nota-tecnica-{$nova->getId()}"));
        self::assertCount(1, $crawler->filter("#nota-tecnica-{$nova->getId()}"));
        self::assertCount(1, $crawler->filter("#nota-tecnica-{$nova->getId()}-push"));
        self::assertSame(
            "psNotaMenu{$nova->getId()}-push",
            $bloco->filter("#nota-tecnica-{$nova->getId()}-push > .ps-nota-topo > .js-nota-menu")->attr('aria-controls'),
        );
        self::assertCount(1, $bloco->filter("#nota-tecnica-{$nova->getId()}-push > .ps-nota-topo > #psNotaMenu{$nova->getId()}-push"));
        self::assertCount(1, $crawler->filter("#psNotaMenu{$nova->getId()}-push"));
        self::assertSame((string) $nova->getId(), $bloco->filter("#nota-tecnica-{$nova->getId()}-push")->attr('data-nota-id'));
        // Dentro do cartão da própria movimentação o rótulo dela é redundante.
        self::assertCount(0, $bloco->filter('.ps-nota-mov'));
        // Quem edita a pasta escreve ali mesmo: botão e compositor do bloco.
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-notas-cab > .js-nota-nova'));
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-nota-editor[hidden]'));
        // Fora do cartão: o botão direito ali é o da nota, não o menu da movimentação.
        self::assertCount(0, $crawler->filter('.ps-push-cartao .ps-notas'));
        // A nota que é só do processo não se pendura em movimentação nenhuma.
        self::assertCount(0, $crawler->filter("#push #nota-tecnica-{$soDoProcesso->getId()}, #nota-tecnica-{$soDoProcesso->getId()}-push"));

        // Com nota, o bloco está à vista (não nasce escondido).
        self::assertNull($bloco->attr('hidden'));

        // O link do teor avisa que o bloco já está na página — nos dois cartões: o sem nota agora
        // também traz o bloco, escondido (C6-FPN-04), e o teor não pode repeti-lo (id único).
        self::assertSame(
            "/pasta/{$pasta->getId()}/push/{$cid}?penduradas=1",
            $crawler->filter("li[data-push-id=\"{$cid}\"] .ps-push-cab")->attr('data-push-url'),
        );
        self::assertSame(
            "/pasta/{$pasta->getId()}/push/{$sid}?penduradas=1",
            $crawler->filter("li[data-push-id=\"{$sid}\"] .ps-push-cab")->attr('data-push-url'),
        );
        self::assertCount(1, $crawler->filter("li[data-push-id=\"{$sid}\"] > .ps-push-teor + .ps-notas#notas-publicacao-{$sid}[hidden][data-oculto-se-vazio]"));
        self::assertCount(0, $crawler->filter("li[data-push-id=\"{$sid}\"] .ps-nota"));

        // Mostrar a nota não é abrir o teor: a publicação continua não lida.
        $this->em()->clear();
        self::assertFalse($this->em()->find(PublicacaoDjen::class, $cid)?->isLida());
        self::assertCount(1, $crawler->filter("li[data-push-id=\"{$cid}\"].ps-push-item--nova"));
    }

    #[TestDox('FPN-020: com penduradas=1 o teor não repete o bloco de notas; sem o parâmetro, traz como antes')]
    public function testTeorNaoDuplicaANotaPendurada(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub  = $this->criarPublicacao($tenant, '60000062', self::NUMERO, '2026-08-20', $processo);
        $nota = $this->criarNota($processo, $user, $tenant, '<p>Pendurada</p>', $pub);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}?penduradas=1");
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ps-notas, .ps-nota'), 'o bloco já mora sob o cartão');
        // O resto do teor continua: o texto e as ações.
        self::assertCount(1, $crawler->filter('.ps-push-teor-corpo > .djen-teor'));
        self::assertCount(1, $crawler->filter('.ps-push-teor-corpo > .ps-push-acoes .js-push-lida'));

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(".ps-push-teor-corpo > .ps-notas#notas-publicacao-{$pub->getId()} > .ps-notas-lista > #nota-tecnica-{$nota->getId()}"));
    }

    #[TestDox('FPN-020: nota de OUTRO processo do escritório (de outra pasta) pendurada na publicação desta pasta não aparece aqui')]
    public function testNotaDeOutroProcessoNaoSePendura(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $outro           = $this->criarProcesso($tenant, '07022222222222222222');
        $this->vincular($pasta, $processo);
        $this->vincular($this->criarPasta($tenant), $outro);
        $pub   = $this->criarPublicacao($tenant, '60000063', self::NUMERO, '2026-08-20', $processo);
        $alheia = $this->criarNota($outro, $user, $tenant, '<p>Da outra pasta</p>', $pub);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter("#push li[data-push-id=\"{$pub->getId()}\"]"));
        // Para esta pasta a movimentação não tem nota: o bloco pendurado é o vazio, escondido
        // (C6-FPN-04 — só existe para o "Adicionar nota técnica" do botão direito).
        self::assertCount(1, $crawler->filter("#push li[data-push-id=\"{$pub->getId()}\"] > .ps-notas[hidden]"));
        self::assertCount(0, $crawler->filter("#push li[data-push-id=\"{$pub->getId()}\"] > .ps-notas .ps-nota"));
        self::assertCount(0, $crawler->filter("#push #nota-tecnica-{$alheia->getId()}-push"));
    }

    #[TestDox('FPN-020b: publicação do processo B com nota antiga do processo A (os dois da pasta) pendurada → o compositor do bloco cria no B, o processo da PUBLICAÇÃO')]
    public function testCompositorDaNotaPenduradaUsaOProcessoDaPublicacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processoA       = $this->criarProcesso($tenant, '07033333333333333333');
        $processoB       = $this->criarProcesso($tenant, self::NUMERO);
        // A primeiro: é o da primeira (e única) nota — o critério antigo escolheria ele.
        $this->vincular($pasta, $processoA);
        $this->vincular($pasta, $processoB);
        $pub     = $this->criarPublicacao($tenant, '60000065', self::NUMERO, '2026-08-20', $processoB);
        $antigaA = $this->criarNota($processoA, $user, $tenant, '<p>Nota antiga do A</p>', $pub);
        $pubId   = $pub->getId();
        $bId     = $processoB->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $bloco = $crawler->filter("#push .ps-push-lista > li[data-push-id=\"{$pubId}\"] > .ps-notas#notas-publicacao-{$pubId}");
        self::assertCount(1, $bloco);
        self::assertCount(1, $bloco->filter("#nota-tecnica-{$antigaA->getId()}-push"), 'a nota antiga do A segue pendurada');
        self::assertSame((string) $bId, $bloco->attr('data-processo-id'), 'a nota nova vai para o processo da publicação');
        self::assertSame("/processos/{$bId}/nota-tecnica", $bloco->attr('data-url-criar'));
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-nota-editor[hidden]'), 'quem edita segue com o compositor');
    }

    #[TestDox('FPN-020 cross-tenant: nota de OUTRO escritório pendurada na publicação desta pasta não aparece — com o TenantFilter desligado, prova o recorte explícito')]
    public function testNotaDeOutroEscritorioNaoSePendura(): void
    {
        $client            = static::createClient();
        [$user, $tenant]   = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pasta             = $this->criarPasta($tenant);
        $processo          = $this->criarProcesso($tenant, self::NUMERO);
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '60000064', self::NUMERO, '2026-08-20', $processo);
        // Registro inconsistente de propósito: escritório B com nota na publicação de A.
        $deB    = $this->criarNota($processoB, $userB, $tenantB, '<p>Segredo de B</p>', $pub);
        $daCasa = $this->criarNota($processo, $user, $tenant, '<p>Da casa</p>', $pub);
        $pastaId = $pasta->getId();
        $pubId   = $pub->getId();
        $deBId   = $deB->getId();
        $casaId  = $daCasa->getId();

        $this->logarComTenant($client, $user, $tenant);
        $this->desligarTenantFilterNaProximaRequisicao();
        $crawler = $client->request('GET', '/pasta/' . $pastaId);
        self::assertResponseIsSuccessful();
        $this->assertTenantFilterFoiDesligado();

        $lista = $crawler->filter("#push li[data-push-id=\"{$pubId}\"] > .ps-notas#notas-publicacao-{$pubId} > .ps-notas-lista");
        self::assertCount(1, $lista);
        self::assertCount(1, $lista->filter("#nota-tecnica-{$casaId}-push"));
        self::assertCount(0, $crawler->filter("#nota-tecnica-{$deBId}, #nota-tecnica-{$deBId}-push"));
        self::assertStringNotContainsString('Segredo de B', (string) $client->getResponse()->getContent());
    }

    #[TestDox('FPN-020: quem só lê a pasta vê a nota pendurada, sem "Adicionar nota técnica", compositor nem Responder')]
    public function testNotaPenduradaParaLeitorENaLapide(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $leitor           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta            = $this->criarPasta($tenant);
        $processo         = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub  = $this->criarPublicacao($tenant, '60000065', self::NUMERO, '2026-08-20', $processo);
        $nota = $this->criarNota($processo, $admin, $tenant, '<p>Para todos</p>', $pub);
        $bloco = "#push li[data-push-id=\"{$pub->getId()}\"] > #notas-publicacao-{$pub->getId()}";

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter("{$bloco} > .ps-notas-lista > #nota-tecnica-{$nota->getId()}-push"), 'o leitor lê a nota');
        self::assertCount(0, $crawler->filter("{$bloco} .js-nota-nova, {$bloco} .ps-nota-editor, {$bloco} .js-nota-responder"));
    }

    #[TestDox('FPN-020: na lápide a nota pendurada segue legível, sem escrita nenhuma — nem para o autor dentro dos 15 min')]
    public function testNotaPenduradaNaLapide(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta            = $this->criarPasta($tenant);
        $processo         = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub  = $this->criarPublicacao($tenant, '60000066', self::NUMERO, '2026-08-20', $processo);
        $nota = $this->criarNota($processo, $admin, $tenant, '<p>Na lápide</p>', $pub, '-5 minutes');
        $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();
        $bloco = "#push li[data-push-id=\"{$pub->getId()}\"] > #notas-publicacao-{$pub->getId()}";

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter("{$bloco} > .ps-notas-lista > #nota-tecnica-{$nota->getId()}-push"));
        self::assertCount(0, $crawler->filter("{$bloco} .js-nota-nova, {$bloco} .ps-nota-editor, {$bloco} .js-nota-responder, {$bloco} .js-nota-editar, {$bloco} .js-nota-excluir"));
    }

    #[TestDox('publicação sem a FK de processo (chegou antes do cadastro) ainda resolve o processo pelo número')]
    public function testTeorDePublicacaoSemFkResolveOProcessoPeloNumero(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $semFk = $this->criarPublicacao($tenant, '60000020', self::NUMERO, '2026-08-20');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$semFk->getId()}");
        self::assertResponseIsSuccessful();

        $bloco = $crawler->filter(".ps-push-teor-corpo > .ps-notas#notas-publicacao-{$semFk->getId()}");
        self::assertCount(1, $bloco);
        self::assertSame((string) $processo->getId(), $bloco->attr('data-processo-id'));
    }

    #[TestDox('processo cadastrado com máscara CNJ casa com a publicação, que grava só dígitos')]
    public function testTeorResolveProcessoComNumeroMascarado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '0701134-57.2025.8.07.0007');
        $this->vincular($pasta, $processo);
        $semFk = $this->criarPublicacao($tenant, '60000021', self::NUMERO, '2026-08-20');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$semFk->getId()}");
        self::assertResponseIsSuccessful();

        $bloco = $crawler->filter(".ps-push-teor-corpo > .ps-notas#notas-publicacao-{$semFk->getId()}");
        self::assertCount(1, $bloco, 'o processo mascarado ficou sem o bloco de notas no teor');
        self::assertSame((string) $processo->getId(), $bloco->attr('data-processo-id'));
    }

    #[TestDox('C6-FPN-01: as notas saem em ordem cronológica — a mais antiga primeiro — na aba Processo e no teor do Push')]
    public function testNotasEmOrdemCronologica(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub     = $this->criarPublicacao($tenant, '60000090', self::NUMERO, '2026-08-20', $processo);
        $antiga  = $this->criarNota($processo, $user, $tenant, '<p>Primeira</p>', $pub, '-40 minutes');
        $meio    = $this->criarNota($processo, $user, $tenant, '<p>Segunda</p>', null, '-20 minutes');
        $recente = $this->criarNota($processo, $user, $tenant, '<p>Terceira</p>', $pub);
        $pid     = $processo->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(
            ["nota-tecnica-{$antiga->getId()}", "nota-tecnica-{$meio->getId()}", "nota-tecnica-{$recente->getId()}"],
            $crawler->filter("#notas-processo-{$pid} > .ps-notas-lista > .ps-nota")->each(static fn ($n): string => (string) $n->attr('id')),
            'aba Processo: de cima para baixo, como foram escritas (a nova logo acima do compositor)',
        );

        // O teor, sem o bloco pendurado (link sem `penduradas`), traz as da movimentação na mesma ordem.
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");
        self::assertResponseIsSuccessful();
        self::assertSame(
            ["nota-tecnica-{$antiga->getId()}", "nota-tecnica-{$recente->getId()}"],
            $crawler->filter(".ps-push-teor-corpo > #notas-publicacao-{$pub->getId()} > .ps-notas-lista > .ps-nota")->each(static fn ($n): string => (string) $n->attr('id')),
        );
    }

    #[TestDox('C6-FPN-04: para quem escreve, o cartão SEM nota traz o bloco escondido com o "Adicionar nota técnica" — o menu abre o compositor sem abrir o teor')]
    public function testCartaoSemNotaTrazBlocoEscondidoParaQuemEscreve(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '60000091', self::NUMERO, '2026-08-20', $processo);
        $id  = $pub->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // Filho direto do <li>, depois do teor fechado: o mesmo lugar do bloco com nota (FPN-020).
        $bloco = $crawler->filter("#push .ps-push-lista > li[data-push-id=\"{$id}\"][data-push-aceita-nota=\"1\"] > .ps-push-cartao + .ps-push-teor[hidden] + .ps-notas#notas-publicacao-{$id}");
        self::assertCount(1, $bloco);
        self::assertNotNull($bloco->attr('hidden'), 'vazio, nasce escondido');
        self::assertSame('display: none', $bloco->attr('style'), 'o display:flex do .ps-notas venceria o hidden');
        self::assertNotNull($bloco->attr('data-oculto-se-vazio'));
        self::assertSame((string) $processo->getId(), $bloco->attr('data-processo-id'));
        self::assertSame('0', trim($bloco->filter('.ps-notas-contagem')->text()));
        self::assertCount(0, $bloco->filter('.ps-nota'));
        // É o que o menu clica (pasta-push.js: `:scope > #notas-publicacao-<id>` → `.js-nota-nova`).
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-notas-cab > .js-nota-nova'));
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-nota-editor[hidden]'));
        // O teor não repete o bloco (id único).
        self::assertSame(
            "/pasta/{$pasta->getId()}/push/{$id}?penduradas=1",
            $crawler->filter("li[data-push-id=\"{$id}\"] > .ps-push-cartao > .ps-push-cab")->attr('data-push-url'),
        );
        self::assertCount(1, $crawler->filter("#notas-publicacao-{$id}"));

        // Renderizar o bloco não é ler: a publicação continua nova.
        $this->em()->clear();
        self::assertFalse($this->em()->find(PublicacaoDjen::class, $id)?->isLida());
    }

    #[TestDox('C6-FPN-04: quem só VÊ não recebe o bloco vazio (não há nota para criar) e o link do teor segue sem penduradas')]
    public function testSemBlocoVazioParaLeitor(): void
    {
        $client           = static::createClient();
        [, $tenant]       = $this->criarAdmin();
        $leitor           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta            = $this->criarPasta($tenant);
        $processo         = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '60000092', self::NUMERO, '2026-08-20', $processo);
        $id  = $pub->getId();
        $url = "/pasta/{$pasta->getId()}/push/{$id}";

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter("#push li[data-push-id=\"{$id}\"]"), 'o cartão está lá (recurso irmão)');
        self::assertCount(0, $crawler->filter("#notas-publicacao-{$id}"));
        self::assertSame($url, $crawler->filter("li[data-push-id=\"{$id}\"] .ps-push-cab")->attr('data-push-url'));
    }

    #[TestDox('C6-FPN-04: na lápide nem o admin recebe o bloco vazio, e o link do teor segue sem penduradas')]
    public function testSemBlocoVazioNaLapide(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta            = $this->criarPasta($tenant);
        $processo         = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '60000093', self::NUMERO, '2026-08-20', $processo);
        $id  = $pub->getId();
        $url = "/pasta/{$pasta->getId()}/push/{$id}";
        $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter("#push li[data-push-id=\"{$id}\"]"));
        self::assertCount(0, $crawler->filter("#notas-publicacao-{$id}"), 'na lápide nem o admin escreve');
        self::assertSame($url, $crawler->filter("li[data-push-id=\"{$id}\"] .ps-push-cab")->attr('data-push-url'));
    }
}

