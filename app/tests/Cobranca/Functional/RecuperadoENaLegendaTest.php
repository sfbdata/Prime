<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * `Recuperado` — o número no fim da legenda da barra de composição do cabeçalho (redesenho 1a).
 *
 * 🔴 Este teste existe por causa de UMA armadilha concreta. O handoff do desenho trazia DUAS fórmulas
 * para o mesmo número, em seções diferentes: `honorariosRecebidos + Σ pagamentos` (na tabela das abas)
 * e `Σ PagamentoOutput::valorTotal` (nas pendências). Elas não são equivalentes — o `valorTotal` de um
 * pagamento JÁ É `valorDivida + valorEncargos + valorHonorarios` (ver `PagamentoOutput::fromEntity`),
 * então a primeira conta o honorário DUAS VEZES. Com um pagamento de R$ 1.000,00 que tenha R$ 200,00 de
 * honorário, a primeira mostraria R$ 1.200,00 recuperados de um dinheiro que foi R$ 1.000,00.
 *
 * O cenário abaixo é montado exatamente para SEPARAR as duas: o pagamento tem honorário não-zero, então
 * a fórmula errada dá um número diferente da certa e o teste cai. Um pagamento sem honorário faria as
 * duas coincidirem e o teste passaria pelo motivo errado.
 */
#[CoversClass(ObjetoController::class)]
final class RecuperadoENaLegendaTest extends CobrancaWebTestCase
{
    #[TestDox('Recuperado é Σ do valor total dos pagamentos — o honorário NÃO entra duas vezes')]
    public function testRecuperadoNaoContaOHonorarioDuasVezes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Uma obrigação vencida, só para o cabeçalho ter vencido > 0 e a barra (com a legenda) nascer.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência vencida',
            'valorOriginal' => 500000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-120 days'),
        ]);

        // Dois recebimentos, ambos COM honorário: R$ 800,00 + R$ 200,00 = R$ 1.000,00 e
        // R$ 300,00 + R$ 100,00 = R$ 400,00. Recuperado = R$ 1.400,00.
        // A fórmula errada daria 1.400,00 + 300,00 (os honorários de novo) = R$ 1.700,00.
        PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 80000, 'valorEncargos' => 0, 'valorHonorarios' => 20000,
        ]);
        PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 30000, 'valorEncargos' => 0, 'valorHonorarios' => 10000,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $recuperado = $crawler->filter('.cob-legenda [data-meta="recuperado"]');
        self::assertCount(1, $recuperado, 'a legenda da barra tem de trazer o Recuperado');

        self::assertStringContainsString('1.400,00', $recuperado->text());
        self::assertStringNotContainsString(
            '1.700,00',
            $recuperado->text(),
            'somar honorariosRecebidos por fora conta o honorário duas vezes',
        );
    }

    #[TestDox('Sem vencido não há barra nem legenda — a composição de nada não se desenha')]
    public function testSemVencidoAComposicaoNaoNasce(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Só obrigação A VENCER: está em aberto, mas o cabeçalho soma o VENCIDO — que é zero.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência futura',
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('+30 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        // Um trilho cinza vazio leria como "0% de tudo", que é diferente de "não há vencido".
        self::assertCount(0, $crawler->filter('.cob-composicao'), 'sem vencido a barra não nasce');
        self::assertCount(0, $crawler->filter('.cob-legenda'), 'nem a legenda dela');
        // O herói continua na tela, dizendo R$ 0,00 — o vazio se anuncia, não some.
        self::assertStringContainsString('0,00', $crawler->filter('[data-card="total"]')->text());
    }

    #[TestDox('Os três segmentos da barra têm largura inline e somam 100%')]
    public function testOsTresSegmentosSomamCemNaTela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Competência vencida',
            'valorOriginal' => 333300, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-400 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $larguras = $crawler->filter('.cob-composicao > div')->each(
            static fn ($seg) => (float) str_replace(['width:', '%'], '', (string) $seg->attr('style')),
        );

        self::assertCount(3, $larguras, 'a barra tem três segmentos: principal, juros e multa, honorários');
        // Ponto e não vírgula na largura: `width:59,5%` é CSS inválido e o segmento não desenharia.
        foreach ($crawler->filter('.cob-composicao > div')->each(static fn ($s) => (string) $s->attr('style')) as $style) {
            self::assertStringNotContainsString(',', $style, 'a largura vai para o CSS com ponto decimal');
        }
        self::assertSame(100.0, round(array_sum($larguras), 1), 'os três segmentos preenchem a barra inteira');
    }
}
