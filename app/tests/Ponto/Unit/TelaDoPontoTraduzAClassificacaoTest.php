<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Depois da extração para `ponto-batida.js` (revisão do commit 143018be): a tela traduz cada classificação do módulo no
 * aviso certo, mantém o `catch` que o envio tinha e responde mesmo sem o módulo carregado.
 */
final class TelaDoPontoTraduzAClassificacaoTest extends TestCase
{
    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    #[TestDox('Cada classificação tem o seu ramo; sucesso só no ramo de sucesso; o resto cai em "sem confirmação"')]
    public function testTraducao(): void
    {
        $tela = self::tela();
        self::assertMatchesRegularExpression("/if \\(resultado\\.tipo === 'atrasado'\\) \\{\\s+mostrarAvisoBatida\\(\\s+'Demorou demais/", $tela);
        self::assertMatchesRegularExpression("/\\} else if \\(resultado\\.tipo === 'sucesso'\\) \\{\\s+sucesso = true;/", $tela);
        self::assertSame(1, substr_count($tela, 'sucesso = true;'), 'sucesso só no ramo de sucesso');
        self::assertMatchesRegularExpression("/'nao_entrou'\\) \\{\\s+mostrarAvisoBatida\\(AVISO_NAO_ENTROU, 'danger', true\\);/", $tela);
        self::assertMatchesRegularExpression("/'sem_rede'\\) \\{[^}]*mostrarAvisoBatida\\(AVISO_SEM_REDE, 'danger', false\\);/s", $tela);
        self::assertMatchesRegularExpression("/\\} else \\{[^}]*mostrarAvisoBatida\\(AVISO_SEM_CONFIRMACAO, 'danger', true\\);/s", $tela);
    }

    #[TestDox('Erro da própria tela vira "sem confirmação" (catch); sem o módulo carregado, o toque avisa e nada sai')]
    public function testFalhasNaoSilenciam(): void
    {
        $tela = self::tela();
        self::assertMatchesRegularExpression("/\\} catch \\(erro\\) \\{[^}]*mostrarAvisoBatida\\(AVISO_SEM_CONFIRMACAO, 'danger', true\\);\\s+\\} finally \\{/s", $tela);
        self::assertMatchesRegularExpression("/btnPonto\\.addEventListener\\('click', async function\\(\\) \\{\\s+if \\(!window\\.PontoBatida\\) \\{\\s+mostrarAvisoBatida\\(AVISO_PAGINA_INCOMPLETA/", $tela);
    }

    #[TestDox('Link do cabeçalho ?esquecimento=AAAA-MM-DD:saida abre o MESMO "+" da folha, se ele existir; o parâmetro sai da URL')]
    public function testLinkDoRegularizarOntem(): void
    {
        $modal = (string) file_get_contents(__DIR__ . '/../../../templates/ponto/_justificativa_esquecimento_modal.html.twig');
        self::assertStringContainsString("get('esquecimento')", $modal);
        self::assertStringContainsString('(entrada|repouso|retorno|saida)', $modal, 'só os quatro tipos');
        self::assertStringContainsString('[data-esquecimento-dia="\' + pedido[1] + \'"][data-esquecimento-campo="\' + pedido[2] + \'"]', $modal, 'o botão do "+" da folha, nenhuma regra própria');
        self::assertStringContainsString("if (alvo) { alvo.scrollIntoView({ block: 'center' }); alvo.click(); return; }", $modal);
        self::assertStringContainsString("if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', abrirPedidoDoLink); }", $modal, 'o Bootstrap vem no fim da página: o clique espera');
        self::assertStringContainsString("window.bjAviso('Não há pedido de esquecimento a fazer para '", $modal, 'sem o "+", avisa em vez de ficar mudo');
        self::assertStringContainsString("url.searchParams.delete('esquecimento');", $modal);
    }
}
