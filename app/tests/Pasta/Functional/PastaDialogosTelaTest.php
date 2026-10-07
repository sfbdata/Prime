<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
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
            self::assertStringContainsString("titulo: 'Excluir o comentário de ' + autor.trim() + '?',", $corpo);
            self::assertStringContainsString('". Ele some para toda a equipe e a exclusão fica registrada no histórico.', $corpo);
            self::assertStringContainsString('window.confirmarPs ? confirmarPs(opcoes)', $corpo);
        }
        foreach (["alert('A mensagem não pode ser vazia.')", "alert('A observação não pode ser vazia.')"] as $antigo) {
            self::assertStringNotContainsString($antigo, $html);
        }
        // O Financeiro (outra frente) ainda usa alert: aqui se conferem os dois scripts deste lote.
        foreach (['Reg', 'Obs'] as $s) {
            self::assertStringContainsString("avisarErro{$s}(data.erro ?? 'Erro ao editar.')", $html);
            self::assertStringContainsString("avisarErro{$s}(data.erro ?? 'Erro ao excluir.')", $html);
            self::assertSame(2, substr_count($html, "avisarErro{$s}('Erro de rede.')"), 'editar e excluir');
        }
        self::assertStringContainsString("avisarErroReg('A mensagem não pode ser vazia.')", $html);
        self::assertStringContainsString("avisarErroObs('A observação não pode ser vazia.')", $html);
    }
}
