<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPushProcessualController;
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
#[Group('pasta')]
final class PastaNotasTecnicasTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

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

    #[TestDox('⋮ do autor dentro dos 15 min: Copiar texto, Editar · 10 min e Excluir · 10 min, com as ações reais; sem Responder')]
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
        self::assertSame(['Copiar texto', 'Editar · 10 min', 'Excluir · 10 min'], $itens);
        self::assertCount(1, $menu->filter('.ps-nota-menu > .js-nota-copiar > i.bi-clipboard'));
        self::assertCount(1, $menu->filter('.ps-nota-menu > .js-nota-editar[data-url][data-csrf][data-conteudo][data-conteudo-html] > i.bi-pencil'));
        self::assertCount(1, $menu->filter('.ps-nota-menu > .js-nota-excluir[data-url][data-csrf] > i.bi-trash'));
        self::assertSame("/processos/{$processo->getId()}/nota-tecnica/{$recente->getId()}/editar", $menu->filter('.js-nota-editar')->attr('data-url'));
        self::assertCount(0, $menu->filter('.ps-nota-menu-bloqueado'));
        // Nota técnica não tem resposta no modelo: o item do desenho não é renderizado.
        self::assertStringNotContainsString('Responder', $menu->text());
        // O lápis e a lixeira soltos no topo deram lugar ao ⋮.
        self::assertCount(0, $crawler->filter("#nota-tecnica-{$recente->getId()} .ps-anotacao-acoes"));
    }

    #[TestDox('⋮ do autor passados 20 min: Editar · bloqueado e Excluir · bloqueado, desabilitados com bi-lock e sem URL')]
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
        self::assertSame(['Copiar texto', 'Editar · bloqueado', 'Excluir · bloqueado'], $itens);
        self::assertCount(2, $menu->filter('.ps-nota-menu > .ps-nota-menu-bloqueado[disabled][aria-disabled="true"] > i.bi-lock'));
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
        self::assertSame(['Copiar texto', 'Editar · bloqueado', 'Excluir · bloqueado'], $itens);
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
}
