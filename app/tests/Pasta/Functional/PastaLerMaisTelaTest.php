<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "continuar lendo (N parágrafos)" / "mostrar menos" no Relatório inicial de Atendimento
 * (aba Detalhes), desenho 1.2.3. Quem esconde os parágrafos excedentes é o
 * `pasta-ler-mais.js`, no navegador — o PHPUnit não roda JS. O que dá para provar aqui é o
 * CONTRATO com o script: a tela o carrega, a lista é opt-in por `data-ler-mais` e o texto de
 * cada observação está no lugar que o seletor do atributo aponta (filho direto do corpo do
 * cartão, nos cartões do Twig e no montado em JS). E que o servidor não corta nada: o texto
 * sai inteiro, a exibição é decisão só do cliente.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaLerMaisTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('a aba Detalhes carrega o pasta-ler-mais.js uma vez e a lista aponta o seletor do texto')]
    public function testDetalhesCarregaOScriptEMarcaALista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertSame(1, substr_count($html, '/js/pasta-ler-mais.js'));
        // Mesmo SEM observação a lista já é opt-in: o primeiro cartão nasce no JS.
        self::assertCount(1, $crawler->filter('#detalhesObsLista[data-ler-mais=".obs-conteudo-det"]'));
        // O cartão montado em JS (`inserirItem`) usa a mesma classe que o seletor aponta.
        self::assertStringContainsString('<div class="ps-anotacao-texto obs-conteudo-det">', $html);
    }

    #[TestDox('o texto longo sai inteiro do servidor, no filho direto do corpo do cartão que o script trata')]
    public function testTextoLongoSaiInteiroNoContainerDoScript(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $paragrafos = ['Primeiro parágrafo.', 'Segundo parágrafo.', 'Terceiro parágrafo.', 'Quarto parágrafo.', 'Quinto parágrafo.'];
        $obs        = (new PastaObservacaoDetalhes())
            ->setPasta($pasta)
            ->setAutor($user)
            ->setTenant($tenant)
            ->setConteudo(implode('', array_map(static fn (string $p): string => '<p>' . $p . '</p>', $paragrafos)));
        $this->em()->persist($obs);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $texto = $crawler->filter(
            '#detalhesObsLista[data-ler-mais] > #detalhes-obs-' . $obs->getId()
            . ' > .ps-anotacao-corpo > .obs-conteudo-det > .editor-rico-conteudo > .ql-editor'
        );
        self::assertCount(1, $texto);
        self::assertCount(5, $texto->filter('.ql-editor > p'), 'o servidor não corta parágrafo nenhum');
        foreach ($paragrafos as $p) {
            self::assertStringContainsString($p, $texto->text());
        }
        // O botão e as classes de ocultar são do script, nunca do servidor.
        self::assertCount(0, $crawler->filter('.ps-lermais, .ps-lermais-oculto, .ps-lermais-botao'));
    }

    #[TestDox('o texto legado (sem <p>, linhas com quebra) chega ao container como texto + <br> que o script conta')]
    public function testTextoLegadoChegaComQuebras(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $obs = (new PastaObservacaoDetalhes())
            ->setPasta($pasta)
            ->setAutor($user)
            ->setTenant($tenant)
            ->setConteudo("Linha um\nLinha dois\nLinha três\nLinha quatro");
        $this->em()->persist($obs);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $texto = $crawler->filter('#detalhes-obs-' . $obs->getId() . ' > .ps-anotacao-corpo > .obs-conteudo-det .ql-editor');
        self::assertCount(1, $texto);
        self::assertCount(0, $texto->filter('p'));
        self::assertCount(3, $texto->filter('br'), 'o script conta uma linha por quebra');
    }
}
