<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Ponto\DTO\BatidaDesconsideradaOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

/**
 * O `_folha_table.html.twig` com uma linha montada à mão, como o FolhaPontoBuilder a entrega na E10: a marca "aj" do
 * desenho (L154, dica L749) depois da hora ajustada, na leitura (/ponto) e na ficha; o link "Ajustar batida" e o "x"
 * da janela "Desconsiderar batida" pelo alvo (b{id} e a{id}); a linha "Fora da conta" e a linha "Desconsideradas"
 * com o Restaurar (e sem ele na desconsideração da análise, decisão A).
 *
 * Arranjo por filho direto (CLAUDE.md, "o desenho manda"): a marca existir "em algum lugar da linha" não prova que
 * ela está depois da hora certa.
 */
#[Group('ponto')]
#[CoversClass(BatidaDesconsideradaOutput::class)]
final class FolhaDoAjusteRenderTest extends KernelTestCase
{
    private const DICA = 'Ajustado por Gestora do Ajuste em 21/10/2026. Registro original preservado.';

    protected function setUp(): void
    {
        self::bootKernel();
        // csrf_token() sem requisição: o token de cada id vira `TOKEN_<id>`.
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }

    #[TestDox('Leitura (/ponto): o "aj" é filho direto da célula da hora ajustada, depois da hora, com a dica no title e no aria-label')]
    public function testMarcaNaLeitura(): void
    {
        $linha = $this->linha($this->render(false));

        $marca = $linha->filter('td[data-r="Saída"] > span.pe-pl.pe-aj');
        self::assertCount(1, $marca);
        self::assertSame('aj', $marca->text());
        self::assertSame([self::DICA, self::DICA], [$marca->attr('title'), $marca->attr('aria-label')]);
        self::assertStringStartsWith('18:30:00', trim($linha->filter('td[data-r="Saída"]')->text()), 'a hora vem antes da marca');
        self::assertCount(0, $linha->filter('td[data-r="Entrada"] .pe-aj'), 'a batida sem ajuste não leva a marca');
        self::assertCount(0, $linha->filter('a, button.pe-mais--excluir'), 'a leitura não corrige batida');
    }

    #[TestDox('Ficha: a hora é o link "Ajustar batida" pelo alvo, o "aj" vem logo depois dentro da mesma célula e o "x" abre a janela com a cabeça')]
    public function testMarcaEAcoesNaFicha(): void
    {
        $linha = $this->linha($this->render(true));

        $celula = $linha->filter('td[data-r="Saída"] > span.pe-folha-celula');
        self::assertCount(1, $celula);
        $link = $celula->filter('a.pe-folha-hora');
        self::assertSame(['18:30:00', 'Ajustar batida'], [trim($link->text()), $link->attr('title')]);
        self::assertStringEndsWith('/tenant/7/user/9/ponto/a41/ajustar?competencia=2026-10', (string) $link->attr('href'));
        self::assertSame([self::DICA, self::DICA], [$celula->filter('span.pe-pl.pe-aj')->attr('title'), $celula->filter('span.pe-pl.pe-aj')->attr('aria-label')]);
        self::assertCount(1, $linha->filter('td[data-r="Saída"] > span.pe-folha-celula > span.pe-pl.pe-aj'), 'filho direto da célula da hora');

        $x = $celula->filter('button.pe-mais.pe-mais--excluir[data-bs-target="#modalDesconsiderarBatida"]');
        self::assertCount(1, $x);
        self::assertStringEndsWith('/tenant/7/user/9/ponto/a41/desconsiderar', (string) $x->attr('data-acao'));
        self::assertSame('50', $x->attr('data-cabeca'), 'a cabeça que a tela viu volta com a janela');
        self::assertNotSame('', (string) $x->attr('data-token'));
        self::assertSame('Saída 18:30:00 de 21/10/2026', $x->attr('data-batida'));

        $entrada = $linha->filter('td[data-r="Entrada"] > span.pe-folha-celula > a.pe-folha-hora');
        self::assertStringEndsWith('/tenant/7/user/9/ponto/b11/ajustar?competencia=2026-10', (string) $entrada->attr('href'));
        self::assertSame('', (string) $linha->filter('td[data-r="Entrada"] button.pe-mais--excluir')->attr('data-cabeca'), 'batida nunca ajustada: sem cabeça');
    }

    #[TestDox('Ficha: a batida fora da conta leva ao ajuste pelo alvo e tem o "x" da janela')]
    public function testForaDaConta(): void
    {
        $fora = $this->linha($this->render(true))->filter('.batidas-desconsideradas');

        self::assertStringContainsString('Fora da conta:', $fora->text());
        $link = $fora->filter('a.batida-desconsiderada[data-registro-id="13"][data-alvo="b13"]');
        self::assertStringEndsWith('/tenant/7/user/9/ponto/b13/ajustar?competencia=2026-10', (string) $link->attr('href'));
        self::assertStringEndsWith('/tenant/7/user/9/ponto/b13/desconsiderar', (string) $fora->filter('button.pe-mais--excluir')->attr('data-acao'));
    }

    #[TestDox('Ficha: a linha "Desconsideradas" traz o Restaurar na desconsideração da tela e não na da análise (decisão A), com o porquê na dica')]
    public function testDesconsideradas(): void
    {
        $itens = $this->linha($this->render(true))->filter('.batidas-fora-por-ajuste > .batida-fora-por-ajuste');
        self::assertCount(2, $itens);

        $daTela = $itens->filter('[data-ajuste-id="60"]');
        self::assertSame('Desconsiderada por Gestora do Ajuste em 21/10/2026. Registro original preservado.', $daTela->attr('title'));
        $restaurar = $daTela->filter('button.pe-mais.pe-mais--restaurar[data-bs-target="#modalRestaurarBatida"]');
        self::assertSame(['Restaurar batida', 'Restaurar batida'], [$restaurar->attr('title'), $restaurar->attr('aria-label')]);
        self::assertStringEndsWith('/tenant/7/user/9/ponto/ajuste/60/restaurar', (string) $restaurar->attr('data-acao'));
        self::assertSame('Repouso 12:00:00 de 21/10/2026', $restaurar->attr('data-batida'));

        $daAnalise = $itens->filter('[data-ajuste-id="61"]');
        self::assertSame('Esta batida saiu da conta pela análise da justificativa e só volta com um novo abono.', $daAnalise->attr('title'));
        self::assertCount(0, $daAnalise->filter('button'));
    }

    #[TestDox('Ficha de quem não pode corrigir (P43, a própria batida): sem link, sem "x", sem Restaurar; o "aj" fica')]
    public function testFichaSemCorrecao(): void
    {
        $linha = $this->linha($this->render(true, editar: false));

        self::assertCount(0, $linha->filter('a, button'));
        self::assertCount(1, $linha->filter('td[data-r="Saída"] > span.pe-pl.pe-aj'));
    }

    private function render(bool $ficha, bool $editar = true): Crawler
    {
        $html = static::getContainer()->get(Environment::class)->render('ponto/_folha_table.html.twig', [
            'titulo' => 'Folha de Ponto',
            'folhaRows' => [$this->linhaMontada()],
            'competenciaSelecionada' => '2026-10',
            'carregarCssDaFolha' => false,
            'editarBatidasHabilitado' => $ficha && $editar,
            'editarBatidasRotaAdd' => 'app_tenant_user_ponto_add',
            'editarBatidasRotaAjustar' => 'app_tenant_user_ponto_ajustar',
            'editarBatidasRotaDesconsiderar' => 'app_tenant_user_ponto_desconsiderar',
            'editarBatidasRotaRestaurar' => 'app_tenant_user_ponto_restaurar',
            'editarBatidasParametros' => ['tenantId' => 7, 'id' => 9],
            'desconsideradasPorDia' => $ficha ? ['2026-10-21' => $this->desconsideradas()] : [],
        ]);

        return new Crawler($html);
    }

    private function linha(Crawler $tela): Crawler
    {
        $linha = $tela->filter('tr[data-chave-dia="2026-10-21"]');
        self::assertCount(1, $linha);

        return $linha;
    }

    /** @return array<string, mixed> a linha do dia 21/10/2026 como o FolhaPontoBuilder a monta, com a saída ajustada */
    private function linhaMontada(): array
    {
        return [
            'diaMes' => '21', 'diaSemana' => 'Quarta', 'chaveDia' => '2026-10-21',
            'entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '18:30:00',
            'entradaId' => 11, 'repousoId' => 12, 'retornoId' => 14, 'saidaId' => null,
            'entradaAlvo' => 'b11', 'repousoAlvo' => 'b12', 'retornoAlvo' => 'b14', 'saidaAlvo' => 'a41',
            'entradaCabeca' => null, 'repousoCabeca' => null, 'retornoCabeca' => null, 'saidaCabeca' => 50,
            'entradaAjuste' => null, 'repousoAjuste' => null, 'retornoAjuste' => null,
            'saidaAjuste' => ['tipo' => 'alteracao', 'por' => 'Gestora do Ajuste', 'em' => '21/10/2026'],
            'aConferir' => [],
            'batidasDesconsideradas' => [['id' => 13, 'alvo' => 'b13', 'tipo' => 'repouso', 'hora' => '12:05:00']],
            'fimSemana' => false, 'domingo' => false, 'antesDoPrimeiroRegistro' => false, 'registroIncompleto' => false,
            'minutosTrabalhadosDia' => 570, 'saldoDia' => 90, 'saldoAcumulado' => 90, 'saldoAcumuladoAteOntem' => 0,
            'justificadoDia' => false, 'justificativa' => null, 'homeOffice' => false, 'semInternet' => false,
        ];
    }

    /** @return list<BatidaDesconsideradaOutput> as duas desconsiderações do dia: a da tela e a da análise */
    private function desconsideradas(): array
    {
        return [
            new BatidaDesconsideradaOutput(60, 'repouso', '2026-10-21', '12:00:00', false, 'Gestora do Ajuste', '21/10/2026'),
            new BatidaDesconsideradaOutput(61, 'repouso', '2026-10-21', '12:00:00', true, 'Gestora do Ajuste', '21/10/2026'),
        ];
    }
}
