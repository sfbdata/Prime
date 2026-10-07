<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O JS do interruptor "Administrativo sem processo" (fim de `public/js/pasta-processo.js`) lido
 * como FOLHA: não há harness de navegador no PHPUnit, então o que se prova aqui é o contrato com o
 * template (`form.js-pasta-administrativa`, `data-confirmar`, `button[role="switch"]`) e com o
 * endpoint (XHR, FormData com o token, `{sucesso, html}` / `{erro}`), o caminho sem JS e o
 * desfazer em erro. O clique em si é do smoke do dono.
 */
final class PastaAdministrativaJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-processo.js';

    private function bloco(): string
    {
        $js     = (string) file_get_contents(self::JS);
        $inicio = strpos($js, '"Administrativo sem processo" sem recarregar');
        self::assertNotFalse($inicio, 'o bloco do interruptor existe');

        return substr($js, $inicio);
    }

    /** Corpo do listener de submit (do `addEventListener('submit'` ao fim do arquivo). */
    private function submit(): string
    {
        $bloco  = $this->bloco();
        $inicio = strpos($bloco, "document.addEventListener('submit'");
        self::assertNotFalse($inicio, 'o submit é delegado no document (o parcial volta por XHR)');

        return substr($bloco, $inicio);
    }

    #[TestDox('intercepta só o form do interruptor, por delegação no document')]
    public function testDelegacaoNoFormDoInterruptor(): void
    {
        self::assertStringContainsString("form.matches('form.js-pasta-administrativa')", $this->submit());
    }

    #[TestDox('sem fetch/FormData não chama preventDefault: o form segue o POST com recarga (fallback sem JS)')]
    public function testFallbackSemFetch(): void
    {
        $corpo   = $this->submit();
        $guarda  = strpos($corpo, "typeof window.fetch !== 'function'");
        $prevent = strpos($corpo, 'e.preventDefault()');
        self::assertNotFalse($guarda);
        self::assertNotFalse($prevent);
        self::assertLessThan($prevent, $guarda, 'a guarda vem antes do preventDefault');
    }

    #[TestDox('confirmação vem de data-confirmar e, recusada, nada muda nem é enviado')]
    public function testConfirmacaoAntesDeTudo(): void
    {
        $corpo = $this->submit();
        self::assertStringContainsString("form.getAttribute('data-confirmar')", $corpo);
        $confirma = strpos($corpo, 'window.confirm(confirmar)');
        self::assertNotFalse($confirma);
        self::assertLessThan(strpos($corpo, 'pintarInterruptor(botao, !ligadoAntes)'), $confirma);
        self::assertLessThan(strpos($corpo, 'fetch(form.action'), $confirma);
    }

    #[TestDox('envia por XHR o FormData do próprio form (o _token do CSRF vai junto) para a action do form')]
    public function testContratoComOEndpoint(): void
    {
        $corpo = $this->submit();
        self::assertStringContainsString('new FormData(form)', $corpo);
        self::assertStringContainsString('fetch(form.action', $corpo);
        self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $corpo);
        self::assertStringContainsString("method: 'POST'", $corpo);
        // FormData lido ANTES de mexer no botão (disabled não afeta o FormData, mas a ordem é a do contrato).
        self::assertLessThan(strpos($corpo, 'botao.disabled = true'), strpos($corpo, 'new FormData(form)'));
    }

    #[TestDox('vira o interruptor na hora (classe is-ligado + aria-checked) e só troca o cartão com sucesso + html')]
    public function testOtimistaETrocaComHtmlDoServidor(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString("botao.classList.toggle('is-ligado', ligado)", $bloco);
        self::assertStringContainsString("botao.setAttribute('aria-checked', ligado ? 'true' : 'false')", $bloco);

        $corpo = $this->submit();
        self::assertStringContainsString("r.dados.sucesso !== true || typeof r.dados.html !== 'string'", $corpo);
        self::assertStringContainsString('window.mpSwapProcessos(r.dados.html)', $corpo);
    }

    #[TestDox('erro do servidor ou da rede desfaz o interruptor para o estado anterior e mostra a mensagem (`erro` do JSON)')]
    public function testRollbackEmErro(): void
    {
        $bloco = $this->bloco();
        $falha = substr($bloco, (int) strpos($bloco, 'function falhaAdministrativa('));
        $falha = substr($falha, 0, (int) strpos($falha, "document.addEventListener('submit'"));
        self::assertStringContainsString('pintarInterruptor(botao, ligadoAntes)', $falha);
        self::assertStringContainsString('botao.disabled = false', $falha);
        self::assertStringContainsString('window.alert(', $falha);

        $corpo = $this->submit();
        self::assertStringContainsString("falhaAdministrativa(form, botao, ligadoAntes, typeof r.dados.erro === 'string' ? r.dados.erro : '')", $corpo);
        // Resposta que não é JSON (sessão expirada → HTML) cai no desfazer, não estoura.
        self::assertStringContainsString('resp.json().catch(function () { return {}; })', $corpo);
        // Falha de rede também desfaz — e só ela fica no rejeitado do fetch: um erro DEPOIS da troca
        // não pode desligar na tela o que o servidor gravou.
        self::assertStringContainsString("}, function () {\n            // Só a falha de REDE", $corpo);
        self::assertStringNotContainsString('.catch(function () {', str_replace('resp.json().catch(function () {', '', $corpo));
    }

    #[TestDox('nada de innerHTML montado aqui nem storage: o HTML vem pronto do Twig via mpSwapProcessos')]
    public function testSemInnerHtmlNemStorage(): void
    {
        $bloco = $this->bloco();
        $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $bloco);
        self::assertStringNotContainsString('innerHTML', $codigo);
        self::assertStringNotContainsString('insertAdjacentHTML', $codigo);
        self::assertStringNotContainsString('localStorage', $codigo);
    }

    #[TestDox('evita envio duplo enquanto o pedido está no ar')]
    public function testSemEnvioDuplo(): void
    {
        $corpo = $this->submit();
        self::assertStringContainsString('if (form.dataset.enviando) { return; }', $corpo);
        self::assertStringContainsString("form.dataset.enviando = '1'", $corpo);
    }
}
