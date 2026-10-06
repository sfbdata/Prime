<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O `pasta-arquivos.js/.css` (gerenciador `.fm`) ficou CONGELADO para a tela do Objeto
 * (cobrança), que está fora desta frente e deve continuar como está. A aba Documentos da
 * Pasta tem explorador próprio (`pasta-explorador.js/.css`) e não carrega mais o `.fm`.
 *
 * Confere as FOLHAS e o TEMPLATE, não a tela: se alguém "simplificar" trocando o 14px no
 * próprio pasta-arquivos.css, ou voltar a carregar o `.fm` na pasta/show (dois scripts
 * brigando pelos mesmos ids globais — risco 6.1.1 do inventário), o Objeto muda de cara
 * ou a Pasta quebra sem nenhum teste de HTML ver.
 */
final class PastaArquivosCssIntactoTest extends TestCase
{
    private const PASTA_SHOW_TWIG    = __DIR__ . '/../../../templates/pasta/show.html.twig';
    private const PASTA_SHOW_CSS     = __DIR__ . '/../../../public/css/pasta-show.css';
    private const PASTA_ARQUIVOS_CSS = __DIR__ . '/../../../public/css/pasta-arquivos.css';

    #[TestDox('pasta/show não referencia mais pasta-arquivos.js nem pasta-arquivos.css')]
    public function testPastaShowNaoCarregaOGerenciadorCompartilhado(): void
    {
        $twig = (string) file_get_contents(self::PASTA_SHOW_TWIG);

        self::assertStringNotContainsString('/js/pasta-arquivos.js', $twig, 'o explorador próprio e o fm brigariam pelos mesmos ids globais');
        self::assertStringNotContainsString('/css/pasta-arquivos.css', $twig, 'a folha do fm não é mais da Pasta');
        self::assertStringContainsString('/js/pasta-explorador.js', $twig);
        self::assertStringContainsString('/css/pasta-explorador.css', $twig);

        // E a sobrescrita de raios do fm saiu junto: não há mais `.fm` para sobrescrever aqui.
        $css = (string) file_get_contents(self::PASTA_SHOW_CSS);
        self::assertDoesNotMatchRegularExpression('/\.ps-page \.fm\b/', $css, 'regra órfã: o fm não é carregado na pasta');
    }

    #[TestDox('o pasta-arquivos.css compartilhado continua com os raios originais (14px/9px) para o Objeto')]
    public function testArquivoCompartilhadoIntacto(): void
    {
        $css = (string) file_get_contents(self::PASTA_ARQUIVOS_CSS);

        self::assertMatchesRegularExpression('/\.fm \{[^}]*--fm-radius: 14px;/', $css);
        self::assertMatchesRegularExpression('/\.fm \{[^}]*--fm-radius-sm: 9px;/', $css);
    }
}
