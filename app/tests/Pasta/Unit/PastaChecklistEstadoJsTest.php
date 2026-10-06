<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O JS do interruptor "Ativo" (fim de `public/js/pasta-checklist.js`) lido como FOLHA: não há
 * harness de navegador no PHPUnit, então o que se prova aqui é o contrato com o template e com o
 * endpoint — o clique em si é do smoke do dono.
 */
final class PastaChecklistEstadoJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-checklist.js';

    private function blocoDoEstado(): string
    {
        $js     = (string) file_get_contents(self::JS);
        $inicio = strpos($js, "Interruptor \"Ativo\" do checklist (DOC-73");
        self::assertNotFalse($inicio, 'o bloco do interruptor existe');

        return substr($js, $inicio);
    }

    #[TestDox('o bloco do interruptor é independente do de cima: só precisa de #pexChecklist (desativado não há #checklistLista)')]
    public function testBlocoIndependente(): void
    {
        $bloco = $this->blocoDoEstado();

        self::assertStringContainsString("document.getElementById('pexChecklist')", $bloco);
        self::assertStringNotContainsString("getElementById('checklistLista')", $bloco);
    }

    #[TestDox('lê URL, token e estado de data-* do #pexChecklist e manda {_token, ativo, motivo}')]
    public function testContratoComOEndpoint(): void
    {
        $bloco = $this->blocoDoEstado();

        foreach (["'data-url-estado'", "'data-csrf-pasta'", "'data-checklist-ativo'"] as $atributo) {
            self::assertStringContainsString($atributo, $bloco);
        }
        foreach (["fd.append('_token'", "fd.append('ativo'", "fd.append('motivo'"] as $campo) {
            self::assertStringContainsString($campo, $bloco);
        }
        foreach (['btnChecklistEstado', 'checklistDesativarPainel', 'checklistEstadoErro', 'btnChecklistReativar', 'btnChecklistDesativar', 'btnChecklistManterAtivo', 'btnChecklistAtualizarEmVez'] as $id) {
            self::assertStringContainsString("'" . $id . "'", $bloco, "#{$id} é contrato com o template");
        }
    }

    #[TestDox('sem motivo não desativa: a guarda vem antes do POST')]
    public function testMotivoObrigatorio(): void
    {
        $bloco  = $this->blocoDoEstado();
        $clique = strpos($bloco, "conf.addEventListener('click'");
        self::assertNotFalse($clique);
        $corpo = substr($bloco, $clique);

        self::assertLessThan(strpos($corpo, 'gravar(false'), strpos($corpo, "if (motivo === '') { return; }"));
    }

    #[TestDox('nada de innerHTML nem storage no interruptor; o estado volta do servidor no recarregamento')]
    public function testSemInnerHtmlNemStorage(): void
    {
        $bloco = $this->blocoDoEstado();

        self::assertStringNotContainsString('innerHTML', $bloco);
        self::assertStringNotContainsString('localStorage', $bloco);
        self::assertStringContainsString('window.location.reload()', $bloco);
    }
}
