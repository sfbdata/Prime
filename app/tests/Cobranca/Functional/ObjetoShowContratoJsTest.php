<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\ProximaAcaoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Ajuste 11 (redesign): trava o CONTRATO de marcação que o JS de show.html.twig depende.
 * O redesign é reveste-primeiro: nenhum destes ganchos pode sumir. Se algum sumir, ESTE teste falha.
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoShowContratoJsTest extends CobrancaWebTestCase
{
    #[TestDox('A página do objeto mantém todos os ganchos de id/data-* que o JS usa')]
    public function testGanchosDeJsPresentes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        // A barra de seleção (`#barraSelecaoDivida`) só nasce no HTML quando há dívida original
        // acordável (INV-I) — sem obrigação avulsa, o gancho nem é renderizado.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota condominial', 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ]);

        // `#modalConcluirAcao` (`_acoes_modais.html.twig`) só nasce no HTML quando há uma próxima
        // ação PENDENTE no caso — sem ela, o gancho nem é renderizado.
        ProximaAcaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Ligar para o devedor',
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertResponseIsSuccessful();

        // Todos os ganchos são checados via SELETOR CSS contra o DOM parseado (assertSelectorExists),
        // nunca por substring do HTML bruto: o `<script>` incondicional de show.html.twig referencia
        // os MESMOS literais (ex.: `querySelector('[data-selecao-qtd]')`) — uma checagem por substring
        // continuaria verde mesmo se o restyle apagasse o gancho só do MARKUP, deixando o script morto
        // sem detectar nada (falso-positivo).

        // Abas + âncora (pasta-arquivos.js e os redirects #secao-divida dependem destes ids)
        foreach (['#objetoTabs', '#tab-cobranca', '#tab-documentos', '#tab-historico',
                  '#documentos-tab', '#secao-divida'] as $gancho) {
            self::assertSelectorExists($gancho, "Sumiu o gancho: {$gancho}");
        }

        // Seleção da dívida
        foreach (['#barraSelecaoDivida', '#secao-divida .jp-check', '[data-selecao-qtd]',
                  '[data-selecao-total]', '[data-acao="acordar-selecionadas"]',
                  '[data-acao="limpar-selecao"]'] as $gancho) {
            self::assertSelectorExists($gancho, "Sumiu o gancho: {$gancho}");
        }

        // Modais que o JS abre/rehidrata (ids fixos)
        foreach (['#modalRegistrarPagamento', '#modalCriarAcordo', '#modalEditarObrigacao',
                  '#modalRegistrarObrigacao', '#modalExcluirObrigacao', '#modalConcluirAcao'] as $gancho) {
            self::assertSelectorExists($gancho, "Sumiu o gancho: {$gancho}");
        }

        // Taxa por-obrigação (Task 9, espelho R$↔%): cada encargo é um bloco `.jp-encargo[data-encargo=…]`
        // com um TRIO de campos do Form (Task 8) — "%" (`jp-taxa-pct`), "R$" (`jp-taxa-reais`) e o modo
        // hidden (`jp-taxa-modo`) que diz ao servidor qual dos dois foi editado por último. Diferente do
        // F4/Ajuste-11 antigo, os DOIS (% e R$) são campos reais SUBMETIDOS — não há mais um "% auxiliar
        // sem name" (o servidor recalcula a %/taxa a partir de qualquer um deles, Task 5/7). Se qualquer
        // gancho sumir, o par se desfaz em silêncio: o gestor digitaria algo que não vira taxa nenhuma.
        // Vale nos DOIS modais.
        foreach (['#modalEditarObrigacao', '#modalRegistrarObrigacao'] as $modal) {
            self::assertSelectorExists($modal . ' .jp-encargos-wrap', "Sumiu o wrapper de encargos em {$modal}");

            foreach (['juros', 'multa', 'correcao'] as $encargo) {
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"][data-base="valorOriginal"]',
                    "Sumiu o bloco de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-pct',
                    "Sumiu o input de % de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-reais',
                    "Sumiu o campo em R$ de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-modo',
                    "Sumiu o modo (hidden) de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-herda',
                    "Sumiu o indicador \"herda do caso\" de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' .jp-encargo[data-encargo="' . $encargo . '"] .jp-taxa-limpar',
                    "Sumiu a ação de limpar (voltar a herdar) de {$encargo} em {$modal}",
                );
            }
            // A base do espelho: sem ela o JS não deriva o R$ de juros/multa/correção a partir do %.
            self::assertSelectorExists($modal . ' input[name$="[valorOriginal]"]', "Sumiu a base do % em {$modal}");

            // Ajuste 2 (Fatia B), preservado na taxa por-obrigação: o honorário é o 4º bloco, mas com base
            // COMPOSTA (`data-base="composta"`) — o JS soma valorOriginal + as R$ dos outros três. Se o
            // marcador `composta` sumir, o espelho leria o honorário sobre a base errada, em silêncio e
            // sobre dinheiro (risco 2 da spec).
            self::assertSelectorExists(
                $modal . ' .jp-encargo[data-encargo="honorarios"][data-base="composta"]',
                "Sumiu o bloco de honorários com base composta em {$modal}",
            );
            self::assertSelectorExists(
                $modal . ' .jp-encargo[data-encargo="honorarios"] .jp-taxa-pct',
                "Sumiu o input de % de honorários em {$modal}",
            );
            self::assertSelectorExists(
                $modal . ' .jp-encargo[data-encargo="honorarios"] .jp-taxa-reais',
                "Sumiu o campo em R$ de honorários em {$modal}",
            );
        }

        // O `modo` é o discriminador de qual campo venceu (herda|percent|reais) — tem de ser hidden, nunca
        // um campo visível/editável direto: quem o seta é o JS (ou o default 'herda' do Form), nunca a
        // digitação do usuário.
        self::assertSelectorExists('input[type="hidden"].jp-taxa-modo', 'o modo do encargo tem de ser hidden');
        self::assertSelectorNotExists('.jp-taxa-modo:not([type="hidden"])', 'o modo do encargo não pode ser um campo visível');

        // Ajuste 11 (T3): a "Próxima ação" migrou da coluna principal para o cartão de destaque
        // do trilho (`.cob-proxima`) — o gancho visual, não só o modal que ela abre.
        self::assertSelectorExists('.cob-proxima', 'Sumiu o cartão Próxima ação do trilho');

        // Ajuste 11 (T3): o cartão "Ações do caso" no trilho. Neste cenário há Obrigação com
        // restante > 0 → saldoExigivel > 0 → `caso.prontoParaEncerrar` é false → "Encerrar cobrança"
        // renderiza DESABILITADO (`.cob-acao-link.is-disabled`), ensinando a condição em vez de
        // deixar o item sumir. Cobre o ramo desabilitado; o ramo habilitado (saldo zerado) e
        // Judicializar (módulo pastas) exigem cenário/tenant extra — ver FOLLOW-UP no relatório.
        self::assertSelectorExists('.cob-acao-link.is-disabled', 'Sumiu a linha desabilitada de Encerrar cobrança');
    }
}
