<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PAS-034 — a janela de confirmação e o aviso do desenho (dc L.280-288 `cf`, L.597 `edAviso`)
 * no lugar do `confirm`/`alert` do navegador, no Registro e nas observações de Detalhes.
 *
 * O comportamento roda no navegador (pasta-dialogos.js); aqui se prova a marcação que ele usa
 * e que os scripts da tela pedem a janela, não o `confirm`.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaDialogosTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function html(object $client, Pasta $pasta): string
    {
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    #[TestDox('a janela do desenho: faixa com o triângulo, título, Fechar; texto; Cancelar e o botão vermelho "Excluir"')]
    public function testJanelaDeConfirmacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $fundo = $crawler->filter('#psConfirmar.ps-cf-fundo');
        self::assertCount(1, $fundo);
        self::assertNotNull($fundo->attr('hidden'));
        $caixa = $fundo->filter('.ps-cf-fundo > .ps-cf[role="alertdialog"][aria-modal="true"]');
        self::assertSame('psConfirmarTitulo', $caixa->attr('aria-labelledby'));
        self::assertSame(
            ['i', 'h2', 'button'],
            $caixa->filter('.ps-cf > .ps-cf-cab')->children()->each(static fn ($c): string => $c->nodeName()),
        );
        self::assertCount(1, $caixa->filter('.ps-cf-cab > i.bi-exclamation-triangle'));
        self::assertSame('Fechar', $caixa->filter('.ps-cf-cab > button[data-ps-cf-cancelar]')->attr('aria-label'));
        self::assertCount(1, $caixa->filter('.ps-cf > #psConfirmarTexto'));
        self::assertSame(
            ['Cancelar', 'Excluir'],
            $caixa->filter('.ps-cf > .ps-cf-rodape > button')->each(static fn ($b): string => trim($b->text())),
        );
        self::assertCount(1, $caixa->filter('.ps-cf-rodape > button.ps-cf-btn--ok[data-ps-cf-ok] > i.bi-trash3'));

        $aviso = $crawler->filter('#psAviso.ps-aviso[role="status"]');
        self::assertCount(1, $aviso);
        self::assertNotNull($aviso->attr('hidden'));
        self::assertCount(1, $aviso->filter('.ps-aviso > i.bi-check-circle-fill'));
    }

    #[TestDox('Registro e Detalhes pedem a janela ("Excluir o comentário de NOME?") e avisam pelo aviso da tela, sem confirm/alert de exclusão')]
    public function testRegistroEDetalhesUsamAJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $html = $this->html($client, $pasta);

        self::assertStringNotContainsString("confirm('Excluir esta mensagem?", $html);
        self::assertStringNotContainsString("confirm('Excluir esta observação?", $html);
        self::assertStringContainsString("if (!(await confirmarExclusaoReg(document.getElementById('pasta-msg-' + msgId)))) { return; }", $html);
        self::assertStringContainsString("if (!(await confirmarExclusaoObs(document.getElementById('detalhes-obs-' + obsId)))) { return; }", $html);
        foreach (['function confirmarExclusaoReg(cartao)', 'function confirmarExclusaoObs(artigo)'] as $funcao) {
            $ini = strpos($html, $funcao);
            self::assertNotFalse($ini, $funcao);
            $corpo = substr($html, $ini, 1100);
            if ($funcao === 'function confirmarExclusaoObs(artigo)') {
                // Detalhes: o texto vem do catálogo do idioma (`pasta_detalhes.js.*`).
                self::assertStringContainsString("titulo: BJ_T.t('pasta_detalhes.js.excluir_titulo', { autor: autor.trim() }),", $corpo);
                self::assertStringContainsString("BJ_T.t('pasta_detalhes.js.excluir_texto', { trecho:", $corpo);
            } else {
                // Registro (aba Dados): o texto vem do catálogo do idioma (`pasta_show.js.registro.*`).
                self::assertStringContainsString("titulo: BJ_T.t('pasta_show.js.registro.excluir_titulo', {autor: autor.trim()}),", $corpo);
                self::assertStringContainsString("BJ_T.t('pasta_show.js.registro.excluir_texto', {trecho:", $corpo);
            }
            self::assertStringContainsString('window.confirmarPs ? confirmarPs(opcoes)', $corpo);
        }
        $catalogo = CatalogoPtBr::carregar()['pasta_detalhes']['js'];
        self::assertSame('Excluir o comentário de {autor}?', $catalogo['excluir_titulo']);
        self::assertSame('"{trecho}". Ele some para toda a equipe e a exclusão fica registrada no histórico.', $catalogo['excluir_texto']);
        $registro = CatalogoPtBr::carregar()['pasta_show']['js']['registro'];
        self::assertSame('Excluir o comentário de {autor}?', $registro['excluir_titulo']);
        self::assertSame('"{trecho}". Ele some para toda a equipe e a exclusão fica registrada no histórico.', $registro['excluir_texto']);
        foreach (["alert('A mensagem não pode ser vazia.')", "alert('A observação não pode ser vazia.')"] as $antigo) {
            self::assertStringNotContainsString($antigo, $html);
        }
        // O Financeiro (outra frente) ainda usa alert: aqui se conferem os dois scripts deste lote.
        // Registro: os avisos vêm do catálogo do idioma (`pasta_show.js`).
        self::assertStringContainsString("avisarErroReg(data.erro ?? BJ_T.t('pasta_show.js.registro.erro_editar'))", $html);
        self::assertStringContainsString("avisarErroReg(data.erro ?? BJ_T.t('pasta_show.js.registro.erro_excluir'))", $html);
        self::assertSame(2, substr_count($html, "avisarErroReg(BJ_T.t('pasta_show.js.erro_rede'))"), 'editar e excluir');
        self::assertStringContainsString("avisarErroReg(BJ_T.t('pasta_show.js.registro.vazia'))", $html);
        $show = CatalogoPtBr::carregar()['pasta_show']['js'];
        self::assertSame('Erro ao editar.', $show['registro']['erro_editar']);
        self::assertSame('Erro ao excluir.', $show['registro']['erro_excluir']);
        self::assertSame('Erro de rede.', $show['erro_rede']);
        self::assertSame('A mensagem não pode ser vazia.', $show['registro']['vazia']);
        // Detalhes: os mesmos avisos, com o texto do catálogo do idioma.
        self::assertStringContainsString("avisarErroObs(data.erro ?? BJ_T.t('pasta_detalhes.js.erro_editar'))", $html);
        self::assertStringContainsString("avisarErroObs(data.erro ?? BJ_T.t('pasta_detalhes.js.erro_excluir'))", $html);
        self::assertSame(2, substr_count($html, "avisarErroObs(BJ_T.t('pasta_detalhes.js.erro_rede'))"), 'editar e excluir');
        self::assertStringContainsString("avisarErroObs(BJ_T.t('pasta_detalhes.js.vazia'))", $html);
        self::assertSame('Erro ao editar.', $catalogo['erro_editar']);
        self::assertSame('Erro ao excluir.', $catalogo['erro_excluir']);
        self::assertSame('Erro de rede.', $catalogo['erro_rede']);
        self::assertSame('A observação não pode ser vazia.', $catalogo['vazia']);
    }
}
