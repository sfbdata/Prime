<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PAS-041: os itens do ⋮ da observação de Detalhes que o desenho tem e o sistema sabe fazer
 * (dc L.5958 `regsLinha` + L.6465 `extrasObs`): Copiar texto · Citar no novo registro · Criar
 * meta a partir deste registro · — · Editar · Excluir comentário, com os minutos da janela ou
 * "· bloqueado". Responder (migration) e Imprimir (IMP-A1) ficam de fora.
 *
 * Arranjo por FILHO DIRETO do menu: o item está no ⋮ desta observação, não em outro lugar.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaDetalhesObsMenuTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const MOTIVO_PRAZO = 'O prazo de 15 minutos para editar ou excluir terminou.';
    private const MOTIVO_AUTOR = 'Só o autor pode editar ou excluir, nos 15 minutos após publicar.';

    private function criarObs(Pasta $pasta, Tenant $tenant, User $autor, string $quando = 'now'): PastaObservacaoDetalhes
    {
        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setTenant($tenant);
        $obs->setAutor($autor);
        $obs->setConteudo('Relato do cliente sobre o acidente.');
        (new \ReflectionProperty(PastaObservacaoDetalhes::class, 'criadaEm'))->setValue($obs, new \DateTimeImmutable($quando));
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    /** @return array{Crawler, list<string>} o menu e o texto dos itens, na ordem */
    private function menu(Crawler $crawler, PastaObservacaoDetalhes $obs): array
    {
        $id   = $obs->getId();
        $menu = $crawler->filter("#detalhes-obs-{$id} > .ps-anotacao-topo > #psObsMenu{$id}[role=\"menu\"]");
        self::assertCount(1, $menu);

        $itens = $menu->filter("#psObsMenu{$id} > .ps-processo-menu-item")->each(
            static fn (Crawler $i): string => trim(preg_replace('/\s+/u', ' ', $i->text()) ?? ''),
        );

        return [$menu, $itens];
    }

    #[TestDox('PAS-041: autor dentro da janela — Copiar, Citar, Criar meta, separador, "Editar · 10 min" e "Excluir comentário · 10 min" ligados ao cartão')]
    public function testAutorDentroDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame([
            'Copiar texto',
            'Citar no novo registro',
            'Criar meta a partir deste registro',
            'Editar · 10 min',
            'Excluir comentário · 10 min',
        ], $itens);

        $id = $obs->getId();
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-citar + .js-obs-criar-meta[role=\"menuitem\"] > i.bi-check2-square"));
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-criar-meta + .ps-processo-menu-sep[role=\"separator\"] + .js-obs-editar"));
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-editar[data-obs-id=\"{$id}\"] > i.bi-pencil"));
        self::assertCount(1, $menu->filter("#psObsMenu{$id} > .js-obs-excluir[data-obs-id=\"{$id}\"] > i.bi-trash3"));
        self::assertCount(0, $menu->filter('[disabled], .bi-lock'));
        // A ação real é o botão da janela do cartão, que o item aciona.
        self::assertCount(1, $crawler->filter("#detalhes-obs-{$id} .ps-anotacao-acoes > .btn-editar-obs-det"));
        self::assertCount(1, $crawler->filter("#detalhes-obs-{$id} .ps-anotacao-acoes > .btn-excluir-obs-det"));
        self::assertStringNotContainsString('Responder', $menu->text());
        self::assertStringNotContainsString('Imprimir', $menu->text());
    }

    #[TestDox('PAS-041: autor passados 20 min — "Editar · bloqueado" e "Excluir · bloqueado", desabilitados, com bi-lock e o motivo do prazo')]
    public function testAutorForaDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-20 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame(['Copiar texto', 'Citar no novo registro', 'Criar meta a partir deste registro', 'Editar · bloqueado', 'Excluir · bloqueado'], $itens);
        $bloqueados = $menu->filter('.ps-obs-menu > .ps-processo-menu-item[disabled][aria-disabled="true"]');
        self::assertCount(2, $bloqueados);
        self::assertCount(2, $bloqueados->filter('i.bi-lock'));
        foreach ($bloqueados as $b) {
            self::assertSame(self::MOTIVO_PRAZO, $b->getAttribute('title'));
        }
        self::assertCount(0, $menu->filter('.js-obs-editar, .js-obs-excluir'), 'item bloqueado não aciona nada');
    }

    #[TestDox('PAS-041: observação de OUTRO autor — bloqueados com o motivo do autor')]
    public function testOutroAutor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $outro, '-2 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame(['Editar · bloqueado', 'Excluir · bloqueado'], array_slice($itens, -2));
        foreach ($menu->filter('.ps-obs-menu > .ps-processo-menu-item[disabled]') as $b) {
            self::assertSame(self::MOTIVO_AUTOR, $b->getAttribute('title'));
        }
    }

    #[TestDox('PAS-041: quem só vê a pasta não recebe "Criar meta a partir deste registro"')]
    public function testLeitorSemCriarMeta(): void
    {
        $client          = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $leitor          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $admin);

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertNotContains('Criar meta a partir deste registro', $itens);
        self::assertCount(0, $menu->filter('.js-obs-criar-meta'));
        $html     = (string) $client->getResponse()->getContent();
        $ini      = (int) strpos($html, 'function inserirItem(data)');
        $montador = substr($html, $ini, (int) strpos($html, 'obsLista.insertAdjacentHTML', $ini) - $ini);
        self::assertStringNotContainsString('js-obs-criar-meta', $montador, 'nem no cartão montado em JS');
    }

    #[TestDox('PAS-041: na lápide o ⋮ é só leitura — Copiar e Citar; sem Criar meta, sem Editar/Excluir')]
    public function testLapide(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObs($pasta, $tenant, $user, '-5 minutes');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        [$menu, $itens] = $this->menu($crawler, $obs);
        self::assertSame(['Copiar texto', 'Citar no novo registro'], $itens);
        self::assertCount(0, $menu->filter('.ps-processo-menu-sep'));
    }

    #[TestDox('PAS-041: o cartão montado em JS traz os mesmos itens novos, na ordem, e o script os liga ao modal Nova meta e aos botões do cartão')]
    public function testCartaoDoJsEScript(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        $html = (string) $client->getResponse()->getContent();

        $ini      = strpos($html, 'function inserirItem(data)');
        self::assertNotFalse($ini);
        $montador = substr($html, $ini, (int) strpos($html, 'obsLista.insertAdjacentHTML', $ini) - $ini);
        $ordem    = ['js-obs-copiar', 'js-obs-citar', 'js-obs-criar-meta', 'ps-processo-menu-sep', 'js-obs-editar', 'js-obs-excluir'];
        $pos      = array_map(static fn (string $c): int|false => strpos($montador, $c), $ordem);
        self::assertNotContains(false, $pos, 'falta item no cartão do JS');
        $ordenado = $pos;
        sort($ordenado);
        self::assertSame($ordenado, $pos, 'a ordem é a do Twig');
        self::assertStringContainsString('<i class="bi bi-check2-square"></i><span>Criar meta a partir deste registro</span>', $montador);
        self::assertMatchesRegularExpression('/<span>Editar · \d+ min<\/span>/', $montador);
        self::assertMatchesRegularExpression('/<span>Excluir comentário · \d+ min<\/span>/', $montador);

        $fn = substr($html, (int) strpos($html, 'function criarMetaObs(item)'), 1400);
        self::assertStringContainsString("getElementById('modalCriarTarefa')", $fn);
        self::assertStringContainsString('descricao.value = texto.slice(0, 600)', $fn);
        self::assertStringContainsString("titulo.value = ''", $fn, 'o desenho abre o título em branco');
        self::assertStringContainsString('p.setDate(p.getDate() + 7)', $fn);
        self::assertStringContainsString("modal.dataset.origemPush = '1'", $fn, 'o pasta-push.js limpa ao fechar');
        self::assertStringContainsString('bootstrap.Modal.getOrCreateInstance(modal).show()', $fn);

        self::assertStringContainsString("if (itemEditar) { acionarDoCartao(itemEditar, '.btn-editar-obs-det'); return; }", $html);
        self::assertStringContainsString("if (itemExcluir) { acionarDoCartao(itemExcluir, '.btn-excluir-obs-det'); return; }", $html);
        $acionar = substr($html, (int) strpos($html, 'function acionarDoCartao(item, seletor)'), 500);
        self::assertStringContainsString("querySelector('.ps-anotacao-acoes ' + seletor)", $acionar);
        self::assertStringContainsString('alvo.click()', $acionar);
    }
}
