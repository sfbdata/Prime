<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O formulário de cartão do Pagar.me (public/js/contribua-pagarme.js) lido como FOLHA contra o desenho 08
 * (checkout "Pagamento seguro", linhas 165-167 e 260-273). Não há navegador no PHPUnit: o que se prova é o
 * contrato do módulo com o desenho, com o contribua-checkout.js e com o CSS, e que o cartão não sai do
 * navegador a não ser para o Pagar.me. A aparência na tela é smoke do dono.
 */
final class ContribuaPagarmeJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function ler(string $arquivo): string
    {
        return (string) file_get_contents(self::RAIZ . $arquivo);
    }

    private static function js(): string
    {
        return self::ler('public/js/contribua-pagarme.js');
    }

    #[TestDox('contrato do módulo intacto: ContribuaPagarme com montar, tokenizar, concluirAcao e desmontar')]
    public function testContratoDoModulo(): void
    {
        self::assertStringContainsString('window.ContribuaPagarme = { montar, tokenizar, concluirAcao, desmontar };', self::js());
        self::assertStringContainsString("pagarme: 'ContribuaPagarme'", self::ler('public/js/contribua-checkout.js'));
    }

    #[TestDox('mensagens de erro do desenho (linha 273), literais, uma por campo e na ordem do desenho')]
    public function testMensagensDoDesenho(): void
    {
        $js = self::js();
        foreach ([
            "numero: 'Número do cartão inválido.'",
            "nome: 'Informe o nome como está no cartão.'",
            "validade: 'Validade inválida ou cartão vencido.'",
            "cvv: 'Informe o código de segurança (CVV).'",
        ] as $literal) {
            self::assertStringContainsString($literal, $js);
        }
        self::assertStringNotContainsString('Confira os dados do cartão.', $js, 'a mensagem genérica saiu');

        $ordem = array_map(static fn (string $campo): int|false => strpos($js, "throw recusar('{$campo}')"), ['numero', 'nome', 'validade', 'cvv']);
        self::assertNotContains(false, $ordem);
        $ordenada = $ordem;
        sort($ordenada);
        self::assertSame($ordenada, $ordem, 'número, nome, validade e CVV, como no coPagar do desenho');

        // O CPF/CNPJ do titular é conferido pelo checkout, com a mensagem do desenho.
        self::assertStringContainsString("erro('Informe o CPF ou CNPJ do titular.');", self::ler('public/js/contribua-checkout.js'));
        // "Processando com segurança…" e botão desabilitado durante a tokenização (checkout).
        $checkout = self::ler('public/js/contribua-checkout.js');
        self::assertStringContainsString("ligado ? 'Processando com segurança…'", $checkout);
        self::assertStringContainsString('botao.disabled = ligado;', $checkout);
    }

    #[TestDox('conferências do desenho: Luhn, nome com duas palavras, validade MM/AA não vencida e CVV de 3 ou 4 dígitos')]
    public function testConferencias(): void
    {
        $js = self::js();
        self::assertStringContainsString('if (!luhn(numero)) {', $js);
        self::assertStringContainsString('return digitos.length >= 13 && soma % 10 === 0;', $js, 'Luhn do desenho, a partir de 13 dígitos');
        self::assertStringContainsString("if (nome.split(/\\s+/).length < 2) {", $js);
        self::assertStringContainsString('new Date(2000 + ano, mes, 1) <= new Date()', $js, 'vencido a partir do mês seguinte ao impresso');
        self::assertStringContainsString("if (!/^\\d{3,4}$/.test(cvv)) {", $js);
    }

    #[TestDox('máscaras: número em grupos de 4 (Amex 4-6-5), validade MM/AA, CVV só dígitos e nome em caixa alta')]
    public function testMascaras(): void
    {
        $js = self::js();
        self::assertStringContainsString("return digitos.slice(0, 19).replace(/(\\d{4})(?=\\d)/g, '\$1 ');", $js, '4-4-4-4 do desenho');
        self::assertStringContainsString("if (/^3[47]/.test(digitos)) {", $js);
        self::assertStringContainsString('[d.slice(0, 4), d.slice(4, 10), d.slice(10, 15)]', $js, 'Amex 4-6-5');
        self::assertStringContainsString("soDigitos(valor).slice(0, 4).replace(/^(\\d{2})(\\d)/, '\$1/\$2')", $js, 'MM/AA');
        self::assertStringContainsString('const mascararCvv = (valor) => soDigitos(valor).slice(0, 4);', $js);
        self::assertStringContainsString('ao(nome, (v) => v.toUpperCase());', $js);
        self::assertStringContainsString("type: 'password'", $js, 'CVV mascarado, como no desenho');
        self::assertStringContainsString("placeholder: '•••'", $js);
    }

    #[TestDox('bandeiras do desenho com o selo; borda vermelha quando o Luhn falha')]
    public function testBandeiraELuhnNaTela(): void
    {
        $js = self::js();
        foreach (["['elo', 'ELO'", "['hipercard', 'HIPERCARD'", "['visa', 'VISA', /^4/]", "['mastercard', 'MASTERCARD', /^(5[1-5]|2[2-7])/]", "['amex', 'AMEX', /^3[47]/]"] as $bandeira) {
            self::assertStringContainsString($bandeira, $js);
        }
        self::assertLessThan(strpos($js, "['visa', 'VISA'"), strpos($js, "['elo', 'ELO'"), 'Elo antes de Visa: faixas da Elo começam com 4');
        self::assertStringContainsString("caixaNumero.classList.toggle('co-cartao-numero--invalido', digitos.length >= 13 && !luhn(digitos));", $js);
        self::assertStringContainsString('selo.textContent = achada ? achada.rotulo : \'\';', $js);

        $css = self::ler('public/css/contribua-checkout.css');
        foreach ([
            '--co-cartao-borda: #d3dde4;',
            '--co-cartao-borda-invalida: #d9534f;',
            '--co-bandeira-visa-fundo: #1a1f71;',
            '--co-bandeira-mastercard-cor: #eb001b;',
            '--co-bandeira-amex-fundo: #2e77bc;',
            '--co-bandeira-elo-fundo: #000;',
            '--co-bandeira-hipercard-fundo: #b3131b;',
            '.co-cartao-numero--invalido { border-color: var(--co-cartao-borda-invalida); }',
            '.co-bandeira--visa { background: var(--co-bandeira-visa-fundo); color: var(--co-bandeira-visa-cor); font-style: italic; }',
            '.co-bandeira[hidden] { display: none; }',
            '.co-cartao-grade { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, .8fr) minmax(0, 1.3fr); gap: 10px; align-items: end; }',
            '.co-campo > input[data-cartao="nome"] { text-transform: uppercase; }',
        ] as $regra) {
            self::assertStringContainsString($regra, $css);
        }
    }

    #[TestDox('rótulos do desenho em maiúscula, com a grade Validade/CVV/CPF')]
    public function testRotulosEGrade(): void
    {
        $js = self::js();
        $posicoes = array_map(static fn (string $r): int|false => strpos($js, $r), ["rotulo('NÚMERO DO CARTÃO', caixaNumero)", "rotulo('NOME IMPRESSO NO CARTÃO', nome)", 'grade,']);
        self::assertNotContains(false, $posicoes);
        self::assertStringContainsString("grade.append(rotulo('VALIDADE', validade), rotulo('CVV', cvv));", $js);
        self::assertStringContainsString('grade.append(documento);', $js, 'o CPF/CNPJ do checkout entra na terceira coluna');
        self::assertStringContainsString('raiz.replaceChildren(...(documento ? [documento] : []));', $js, 'e volta ao desmontar');
    }

    #[TestDox('o cartão só sai do navegador para o Pagar.me: o BlueJus recebe o token e o documento, nada mais')]
    public function testCartaoNaoVaiAoBackend(): void
    {
        $js = self::js();
        self::assertSame(1, substr_count($js, 'fetch('), 'uma única chamada de rede no módulo');
        self::assertStringContainsString("const ENDERECO = 'https://api.pagar.me/core/v5/tokens';", $js);
        self::assertStringContainsString("resposta = await fetch(ENDERECO + '?appId=' + encodeURIComponent(chavePublica), {", $js);
        self::assertStringContainsString('return dados.id;', $js, 'só o token sai do módulo');

        $checkout = self::ler('public/js/contribua-checkout.js');
        self::assertStringContainsString('{ token: token, documento: documento() }', $checkout, 'o backend recebe só o token e o documento');
        self::assertDoesNotMatchRegularExpression('/data-cartao="(numero|cvv|validade)"/', $checkout, 'o checkout não lê os campos do cartão');
    }

    #[TestDox('nada do cartão em log, console, armazenamento do navegador, dataset ou atributo do DOM')]
    public function testCartaoNaoVazaNaPagina(): void
    {
        $js = self::js();
        foreach (['console.', 'localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'innerHTML', "setAttribute('value'", "setAttribute('name'"] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, $proibido . ' no módulo do Pagar.me');
        }
        self::assertDoesNotMatchRegularExpression('/[{,]\s*name:/', $js, 'campo do cartão sem name: nada vai num envio de formulário');
        self::assertSame(1, preg_match_all('/\.dataset\.\w+\s*=/', $js), 'o único dataset é o nome do campo');
        self::assertStringContainsString('campo.dataset.cartao = nome;', $js);
        self::assertDoesNotMatchRegularExpression('/setAttribute\([^)]*(numero|cvv|validade)\.value/', $js);
    }
}
