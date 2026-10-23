<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Edição sem prazo (spec `edicao-sem-prazo-master-primario.md` §5) no cartão que o JS monta logo depois de publicar
 * (Registro, observação de Detalhes e do Financeiro), lido como FOLHA: o cartão traz `data-sem-prazo-rotulo` com o
 * poder de quem publicou, e o ⋮ lê esse dado ao abrir. Sem ele, Master e primário travariam depois dos 15 minutos
 * até recarregar a página.
 */
#[Group('pasta')]
final class SeloSemPrazoNoCartaoDoJsTest extends TestCase
{
    private const PASTA = __DIR__ . '/../../../templates/pasta/';
    private const ATRIBUTO = "{% if sem_prazo_selo(false) %} data-sem-prazo-rotulo=\"{{ ('edicao_sem_prazo.selo.' ~ sem_prazo_selo(false))|trans|e('html_attr') }}\"{% endif %}>";

    /** @return iterable<string, array{string, string, string, string}> arquivo do cartão, início do cartão, arquivo do ⋮, leitura no ⋮ */
    public static function cartoes(): iterable
    {
        yield 'Registro' => ['show.html.twig', '<article class="ps-anotacao${ehResposta', 'show.html.twig', "const seloSemPrazo = temBotao && !pelaRegra ? (cartao.dataset.semPrazoRotulo || '') : '';"];
        yield 'Detalhes' => ['_detalhes_obs.html.twig', '<article class="ps-anotacao detalhes-obs-item${ehResposta', '_detalhes_obs.html.twig', "const selo = pelaRegra ? '' : (artigo.dataset.semPrazoRotulo || '');"];
        yield 'Financeiro' => ['_financeiro_obs_script.html.twig', 'id="financeiro-obs-${data.id}"', '_financeiro.html.twig', "const selo = nasceuLiberado && !pelaRegra ? (registro.dataset.semPrazoRotulo || '') : '';"];
    }

    #[DataProvider('cartoes')]
    #[TestDox('O cartão $_dataName do JS leva o rótulo do selo na própria tag, e o ⋮ o lê ao abrir')]
    public function testCartaoDoJsLevaORotulo(string $arquivo, string $inicio, string $arquivoDoMenu, string $leitura): void
    {
        $fonte = (string) file_get_contents(self::PASTA . $arquivo);
        $de = strpos($fonte, $inicio);
        self::assertNotFalse($de, "cartão do JS em {$arquivo}");
        $tag = substr($fonte, $de, (int) strpos($fonte, '>', (int) strpos($fonte, 'data-janela-ate', $de)) - $de + 1);

        self::assertStringContainsString('data-janela-ate=', $tag);
        self::assertStringEndsWith(self::ATRIBUTO, $tag, 'o rótulo fica na tag de abertura do cartão');
        self::assertStringContainsString($leitura, (string) file_get_contents(self::PASTA . $arquivoDoMenu));
    }
}
