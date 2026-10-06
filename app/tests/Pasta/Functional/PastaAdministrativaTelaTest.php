<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Interruptor "Administrativo sem processo" na aba Processo (desenho 1.2.3, dc L.1580) e o vazio
 * da aba quando a pasta está marcada.
 *
 * Os seletores usam filho direto (`>`) a partir de `#processoTabContent > .ps-processos`, para
 * provar que o interruptor está NO cabeçalho do cartão de processos e o vazio NO cartão — não em
 * algum lugar da página.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaAdministrativaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';
    private const NUMERO_MASCARA = '0701134-57.2025.8.07.0007';

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function formInterruptor(Crawler $crawler): Crawler
    {
        $form = $crawler->filter('#processoTabContent > .ps-processos > .ps-card-cab > form.js-pasta-administrativa');
        self::assertCount(1, $form, 'o interruptor mora no cabeçalho do cartão "Processos vinculados"');

        return $form;
    }

    private function marcar(Pasta $pasta): void
    {
        $pasta->setAdministrativa(true);
        $this->em()->flush();
    }

    #[TestDox('Desligado: rótulo do desenho, aria-checked=false, POST para a rota com CSRF e valor "1" (ligar); fica antes de "Vincular processo"')]
    public function testInterruptorDesligado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);
        $form    = $this->formInterruptor($crawler);
        self::assertStringEndsWith('/pasta/' . $pasta->getId() . '/administrativa', (string) $form->attr('action'));
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
        self::assertSame('1', $form->filter('input[name="administrativa"]')->attr('value'));

        $botao = $form->filter('button[role="switch"]');
        self::assertCount(1, $botao);
        self::assertSame('false', $botao->attr('aria-checked'));
        self::assertStringNotContainsString('is-ligado', (string) $botao->attr('class'));
        self::assertSame('Administrativo sem processo', trim($botao->filter('.ps-adm-sw-rotulo')->text()));
        self::assertStringContainsString('não terá processo judicial', (string) $botao->attr('title'));
        self::assertNull($form->attr('onsubmit'), 'sem processo vinculado não há o que confirmar');

        // Ordem do desenho no cabeçalho: … espaço · interruptor · Vincular processo · Peticionar.
        $filhos = $crawler->filter('#processoTabContent > .ps-processos > .ps-card-cab')->children()->each(
            static fn (Crawler $n): string => $n->nodeName() . '.' . (string) $n->attr('class'),
        );
        $posForm     = array_search(true, array_map(static fn (string $f): bool => str_contains($f, 'js-pasta-administrativa'), $filhos), true);
        $posVincular = array_search(true, array_map(static fn (string $f): bool => str_starts_with($f, 'button.') && str_contains($f, 'ps-btn--suave'), $filhos), true);
        $posEspaco   = array_search(true, array_map(static fn (string $f): bool => str_contains($f, 'ps-cab-espaco'), $filhos), true);
        self::assertIsInt($posForm);
        self::assertIsInt($posVincular);
        self::assertIsInt($posEspaco);
        self::assertSame($posEspaco + 1, $posForm, 'o interruptor vem logo depois do espaço flexível');
        self::assertSame($posForm + 1, $posVincular, 'e logo antes de "Vincular processo"');

        // Vazio de sempre: convite a vincular.
        self::assertCount(1, $crawler->filter('#processoTabContent > .ps-processos > .ps-vazio:not(.ps-vazio--administrativa)'));
    }

    #[TestDox('Ligado sem processo: aria-checked=true, classe is-ligado, valor "0" (desligar) e o vazio da aba vira "Administrativo sem processo"')]
    public function testInterruptorLigadoMostraEstadoNoVazio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->marcar($pasta);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);
        $form    = $this->formInterruptor($crawler);
        $botao   = $form->filter('button[role="switch"]');
        self::assertSame('true', $botao->attr('aria-checked'));
        self::assertStringContainsString('is-ligado', (string) $botao->attr('class'));
        self::assertSame('0', $form->filter('input[name="administrativa"]')->attr('value'));

        $vazio = $crawler->filter('#processoTabContent > .ps-processos > .ps-vazio.ps-vazio--administrativa');
        self::assertCount(1, $vazio);
        self::assertSame('Administrativo sem processo', trim($vazio->filter('.ps-vazio-titulo')->text()));
        self::assertStringContainsString('não terá processo judicial', $vazio->filter('.ps-vazio-nota')->text());
        self::assertCount(0, $crawler->filter('#processoTabContent > .ps-processos > .ps-vazio:not(.ps-vazio--administrativa)'), 'o convite "Nenhum processo judicial vinculado" some');

        // Vincular continua possível com a pasta marcada (o desenho mantém o botão).
        self::assertCount(1, $crawler->filter('#processoTabContent > .ps-processos > .ps-card-cab > button[data-bs-target="#modalVincularProcesso"]'));
    }

    #[TestDox('Com processo vinculado e desligado: o form pede confirmação com o número da pasta e do processo (texto do desenho)')]
    public function testConfirmacaoQuandoTemProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO));
        $this->logarComTenant($client, $user, $tenant);

        $onsubmit = (string) $this->formInterruptor($this->abrir($client, $pasta))->attr('onsubmit');
        self::assertStringStartsWith('return confirm(', $onsubmit);
        // O texto passa por |e('js'): espaço vira   e as aspas, ". Decodificado:
        $texto = json_decode('"' . str_replace("'", '', substr($onsubmit, strlen('return confirm('), -2)) . '"', false, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            'A Pasta ' . $pasta->getNup() . ' tem o processo ' . self::NUMERO_MASCARA . ' vinculado. Marcar mesmo assim como "Administrativo sem processo"?',
            $texto,
        );
    }

    #[TestDox('Ligado e com processo: a lista de processos continua, sem o vazio administrativo, e desligar não pede confirmação')]
    public function testLigadoComProcessoMostraALista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO));
        $this->marcar($pasta);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);
        $form    = $this->formInterruptor($crawler);
        self::assertSame('true', $form->filter('button[role="switch"]')->attr('aria-checked'));
        self::assertNull($form->attr('onsubmit'));
        self::assertCount(1, $crawler->filter('#processoTabContent > .ps-processos > .ps-registro > article.ps-processo'));
        self::assertCount(0, $crawler->filter('#processoTabContent > .ps-processos > .ps-vazio'));
    }
}
