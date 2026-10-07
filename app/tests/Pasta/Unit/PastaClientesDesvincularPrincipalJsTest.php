<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * P13, lido como FOLHA: ao desvincular o cliente principal, o JS de clientes do
 * `pasta/show.html.twig` aplica o promovido que o servidor devolve (`novoPrincipalId`, `html`,
 * `principal`) — troca a linha pela re-renderizada e passa pelo `trocaClientePrincipal` existente,
 * que leva a linha ao bloco do principal e atualiza a Média por CPF.
 *
 * Não há harness de navegador no PHPUnit: o que se prova é o contrato e a ordem. O clique é smoke
 * do dono.
 */
#[CoversNothing]
final class PastaClientesDesvincularPrincipalJsTest extends TestCase
{
    private const SHOW = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private static function show(): string
    {
        $twig = file_get_contents(self::SHOW);
        self::assertIsString($twig);

        return $twig;
    }

    /** O ramo `form.js-ajax-desvincular-cliente` do listener de submit, até o ramo seguinte. */
    private static function ramoDesvincular(): string
    {
        $twig   = self::show();
        $inicio = strpos($twig, "if (form.matches('form.js-ajax-desvincular-cliente')) {");
        self::assertNotFalse($inicio, 'o ramo de desvincular cliente existe');
        $fim = strpos($twig, "if (form.matches('form.js-ajax-cliente-principal')) {", $inicio);
        self::assertNotFalse($fim);

        return substr($twig, $inicio, $fim - $inicio);
    }

    private static function funcaoAplicar(): string
    {
        $twig   = self::show();
        $inicio = strpos($twig, 'function aplicarNovoPrincipal(dados) {');
        self::assertNotFalse($inicio, 'aplicarNovoPrincipal existe');
        $fim = strpos($twig, 'function trocaClientePrincipal(dados) {', $inicio);
        self::assertNotFalse($fim, 'e vem logo antes do trocaClientePrincipal');

        return substr($twig, $inicio, $fim - $inicio);
    }

    #[TestDox('desvincular: remove a linha e SÓ DEPOIS aplica o promovido, e só quando o servidor mandou um')]
    public function testRemoveEDepoisAplicaOPromovido(): void
    {
        $ramo = self::ramoDesvincular();

        $remove = strpos($ramo, "removeClienteRow(dados.clienteId ?? '', form);");
        $guarda = strpos($ramo, 'if (dados.novoPrincipalId && dados.principal) {');
        $aplica = strpos($ramo, 'aplicarNovoPrincipal(dados);');
        self::assertNotFalse($remove);
        self::assertNotFalse($guarda, 'sem promovido (não era o principal, ou era o último) não há o que mover');
        self::assertNotFalse($aplica);
        self::assertLessThan($guarda, $remove, 'a linha sai antes: o bloco do principal vazio é recolhido e remontado');
        self::assertLessThan($aplica, $guarda);
    }

    #[TestDox('aplicarNovoPrincipal: troca a linha do promovido pelo html do servidor e passa pelo trocaClientePrincipal')]
    public function testAplicaPelaLinhaDoServidorEPeloTrocaExistente(): void
    {
        $f = self::funcaoAplicar();

        self::assertStringContainsString('String(dados.novoPrincipalId)', $f);
        self::assertStringContainsString('.cliente-linha[data-cliente-id="${novoId}"]', $f);
        self::assertStringContainsString('linhaDoHtml(dados.html)', $f, 'a linha vem do partial, não de espelho JS');
        self::assertStringContainsString("String(nova.dataset.clienteId ?? '') === novoId", $f, 'html de outra linha não substitui');
        self::assertStringContainsString('atual.replaceWith(nova);', $f);
        self::assertStringContainsString('trocaClientePrincipal(dados.principal);', $f, 'bloco, estrela, selo e Média por CPF pelo caminho de sempre');
        self::assertLessThan(
            strpos($f, 'trocaClientePrincipal(dados.principal);'),
            strpos($f, 'atual.replaceWith(nova);'),
            'troca a linha antes de movê-la',
        );
    }
}
