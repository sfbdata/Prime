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
        // E8: o atraso e a recusa afirmam que a batida não entrou: vão à tela vermelha (ou ao aviso flutuante, com a tela
        // cheia desligada) pelo `mostrarFalhaDoToque`. O texto do atraso continua o mesmo. E12 + E8: a batida externa
        // atrasada (ou com o texto recusado) volta para a janela, sem a tela vermelha; a recusa com `foraDoRaio` vai à
        // porta do fora do raio (TrabalhoExternoForaDoRaioJsTest).
        self::assertMatchesRegularExpression("/if \\(resultado\\.tipo === 'atrasado'\\) \\{\\s+(?:\\/\\/[^\\n]*\\n\\s+)*if \\(payload\\.trabalhoExterno === true\\) \\{\\s+devolverExternoParaAJanela\\(payload, AVISO_ATRASADO, false\\);\\s+\\} else \\{\\s+mostrarFalhaDoToque\\(payload\\.tipo, AVISO_ATRASADO, ''\\);\\s+\\}/", $tela);
        self::assertStringContainsString("const AVISO_ATRASADO = 'Demorou demais para enviar e a hora da batida ficaria errada. Aperte de novo com a tela ligada.';", $tela);
        self::assertMatchesRegularExpression("/'recusa'\\) \\{.*?\\} else \\{\\s+mostrarFalhaDoToque\\(payload\\.tipo, resultado\\.mensagem, '', \\[\\], externo\\);\\s+\\}\\s+\\} else if \\(resultado\\.tipo === 'nao_entrou'\\)/s", $tela);
        self::assertMatchesRegularExpression("/\\} else if \\(resultado\\.tipo === 'sucesso'\\) \\{\\s+sucesso = true;/", $tela);
        self::assertSame(1, substr_count($tela, 'sucesso = true;'), 'sucesso só no ramo de sucesso');
        // O "não entrou" fica na caixa, com o "Atualizar"; o da batida externa também (`devolverExternoParaAJanela`, com
        // `oferecerAtualizar` true), e a janela volta.
        self::assertMatchesRegularExpression("/'nao_entrou'\\) \\{\\s+(?:\\/\\/[^\\n]*\\n\\s+)*if \\(payload\\.trabalhoExterno === true\\) \\{\\s+devolverExternoParaAJanela\\(payload, AVISO_NAO_ENTROU, true\\);\\s+\\} else \\{\\s+mostrarAvisoBatida\\(AVISO_NAO_ENTROU, 'danger', true\\);/", $tela);
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
