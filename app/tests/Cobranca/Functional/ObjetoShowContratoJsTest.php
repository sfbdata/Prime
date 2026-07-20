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

        // F4 (encargos separados): o espelho % ↔ R$ casa cada input de percentual (`data-encargo-pct`,
        // auxiliar e SEM `name`) com o campo do Form em reais (`data-encargo`), e lê a base no campo
        // apontado por `data-encargo-base`. Se qualquer ponta sumir, o par se desfaz em silêncio: o
        // gestor digitaria um percentual que não vira dinheiro nenhum. Vale nos DOIS modais.
        foreach (['#modalEditarObrigacao', '#modalRegistrarObrigacao'] as $modal) {
            foreach (['juros', 'multa', 'correcao'] as $encargo) {
                self::assertSelectorExists(
                    $modal . ' [data-encargo-pct="' . $encargo . '"][data-encargo-base="valorOriginal"]',
                    "Sumiu o input de % de {$encargo} em {$modal}",
                );
                self::assertSelectorExists(
                    $modal . ' [data-encargo="' . $encargo . '"]',
                    "Sumiu o campo em R$ de {$encargo} em {$modal}",
                );
            }
            // A base do espelho: sem ela o JS desabilita os % (não há por que dividir).
            self::assertSelectorExists($modal . ' input[name$="[valorOriginal]"]', "Sumiu a base do % em {$modal}");

            // Ajuste 2 (Fatia B): o honorário é o 4º par, mas com base COMPOSTA
            // (`data-encargo-base="composta"`) — o JS soma valorOriginal + juros + multa + correção.
            // Se o marcador `composta` sumir, o espelho leria o honorário sobre a base errada, em silêncio
            // e sobre dinheiro (risco 2 da spec). O R$ é o campo do Form (submetido); o % é auxiliar.
            self::assertSelectorExists(
                $modal . ' [data-encargo-pct="honorarios"][data-encargo-base="composta"]',
                "Sumiu o input de % de honorários com base composta em {$modal}",
            );
            self::assertSelectorExists(
                $modal . ' [data-encargo="honorarios"]',
                "Sumiu o campo em R$ de honorários em {$modal}",
            );
        }

        // O % é DERIVADO do R$ e nunca é submetido: se ganhar `name`, vira uma segunda fonte de verdade
        // sobre dinheiro, que o B5 teria de reidratar e que divergiria do R$ por arredondamento.
        self::assertSelectorNotExists('[data-encargo-pct][name]', 'o input de % não pode ser submetido ao servidor');

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
