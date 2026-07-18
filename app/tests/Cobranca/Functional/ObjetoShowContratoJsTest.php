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
                  '#modalExcluirObrigacao', '#modalConcluirAcao'] as $gancho) {
            self::assertSelectorExists($gancho, "Sumiu o gancho: {$gancho}");
        }
    }
}
