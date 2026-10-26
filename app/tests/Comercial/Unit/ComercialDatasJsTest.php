<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O comercial-datas.js (F4; desenho 05 VS 21.10.26 L337 e L678-682): o contrato do "Ver mensagem" com a Central
 * (evento `mc:modelo-abrir`), as rotas com o token da raiz e os textos do dc no catálogo.
 */
#[CoversNothing]
final class ComercialDatasJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/master/comercial-datas.js';
    private const CENTRAL = __DIR__ . '/../../../public/js/master/comercial-campanha.js';

    #[TestDox('"Ver mensagem" dispara mc:modelo-abrir com o modelo data-<id> e o aviso; só a instância da seção Comercial ouve')]
    public function testVerMensagem(): void
    {
        $js = (string) file_get_contents(self::JS);
        $central = (string) file_get_contents(self::CENTRAL);

        self::assertStringContainsString("new CustomEvent('mc:modelo-abrir', {\n      detail: { chave: 'data-' + d.chave, aviso: BJ_T.t('comercial.js.datas_previa', { nome: d.nome }) },", $js);
        $ganchos = substr($central, (int) strpos($central, 'if (M.ganchos) {'));
        self::assertStringContainsString("document.addEventListener('mc:modelo-abrir'", $ganchos, 'dentro do bloco dos ganchos: o modo venda não abre as datas');
    }

    #[TestDox('O POST leva o X-CSRF-Token da raiz [data-mc] e o estado inteiro da linha; o nome sai escapado')]
    public function testPostEEscape(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("const TOKEN = raiz.dataset.token || '';", $js);
        self::assertStringContainsString("'X-CSRF-Token': TOKEN", $js);
        self::assertStringContainsString('{ ligada: atual.ligada, antecedencia: atual.antecedencia, publico: atual.publico }', $js);
        self::assertStringContainsString("<b class=\"mc-dt-nome\">' + esc(d.nome) + '</b>", $js);
    }

    #[TestDox('Os textos do dc (L679-681) estão no catálogo, sem travessão')]
    public function testTextosDoDesenho(): void
    {
        $catalogo = CatalogoPtBr::carregar()['comercial']['js'];

        self::assertSame(
            ['hoje', 'amanhã', 'em {n} dias', 'envio {data} às 9h', 'No dia', '1 dia antes', '3 dias antes', 'Todos', 'Primários', 'Base de contatos', 'Ver mensagem', 'Prévia da mensagem de {nome}. Use Duplicar para personalizar o texto.'],
            [$catalogo['datas_hoje'], $catalogo['datas_amanha'], $catalogo['datas_em_dias'], $catalogo['datas_envio'], $catalogo['datas_ant_0'], $catalogo['datas_ant_1'], $catalogo['datas_ant_3'], $catalogo['datas_pub_todos'], $catalogo['datas_pub_prim'], $catalogo['datas_pub_leads'], $catalogo['datas_ver'], $catalogo['datas_previa']],
        );
        self::assertSame(['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'], array_map(static fn (int $i): string => $catalogo['datas_sem_' . $i], range(0, 6)));
        $todos = (string) json_encode(array_filter($catalogo, static fn (string $k): bool => str_starts_with($k, 'datas_'), ARRAY_FILTER_USE_KEY), JSON_UNESCAPED_UNICODE);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $todos);
    }
}
