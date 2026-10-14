<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E5c — o ⋮ de Minhas Metas (R118/R236) leva à aba Metas da pasta pelo MESMO realce da meta recém-criada
 * (`?meta=<id>#tarefas`, PMC-044) e, no "Editar nome", pede também `acao=renomear`: a pasta abre a edição
 * do nome que já existe no ⋮ dela — mesmo formulário, mesma regra de exibição (só aparece quando a meta é
 * editável), mesmo POST `tarefa_renomear`. Nada novo no servidor.
 *
 * Duas mudanças no pasta-metas.js, provadas aqui como folha (o efeito na tela é a comparação visual):
 * - o realce escolhe o filtro em que a linha aparece ("Abertas", ou "Concluídas" se a meta já foi
 *   concluída) — antes punha sempre "Abertas", e a meta concluída ficava escondida;
 * - `acao=renomear` sai da URL junto com `meta` e aciona o item "Editar nome" da própria linha, depois
 *   que os ouvintes da lista já estão ligados.
 */
final class PastaMetaRealceVindoDeMinhasMetasJsTest extends TestCase
{
    private const JS_METAS = __DIR__ . '/../../../public/js/pasta-metas.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS_METAS);
    }

    private function bloco(string $inicio, int $tamanho = 1600): string
    {
        $pos = strpos($this->js(), $inicio);
        self::assertNotFalse($pos, 'existe: ' . $inicio);

        return substr($this->js(), $pos, $tamanho);
    }

    #[TestDox('o realce mostra a linha no filtro em que ela aparece: "Abertas", ou "Concluídas" para meta concluída')]
    public function testFiltroDaLinhaRealcada(): void
    {
        $destacar = $this->bloco('function destacarNaLista(', 700);
        self::assertStringContainsString("var filtro = passaNoFiltro(linha.getAttribute('data-meta-estado'), 'abertas') ? 'abertas' : 'concluidas';", $destacar);
        self::assertStringContainsString('aplicarFiltro(cartao, filtro)', $destacar);
    }

    #[TestDox('acao=renomear sai da URL junto com meta e só aciona "Editar nome" da própria linha, se ele existir')]
    public function testRenomearPedidoPelaUrl(): void
    {
        $bloco = $this->bloco('function realceDaMetaNova()');
        self::assertStringContainsString("var renomear = params.get('acao') === 'renomear';", $bloco);
        self::assertStringContainsString("params.delete('acao');", $bloco);
        self::assertStringContainsString("linha.querySelector('[data-ps-meta-renomear=\"' + id + '\"]')", $bloco);
        // Os ouvintes do "Editar nome" (renomearNaLista) ligam DEPOIS do realce no iniciar():
        // o clique espera a vez, senão cairia no vazio.
        self::assertStringContainsString('window.setTimeout(function () { editar.click(); }, 0);', $bloco);
    }
}
