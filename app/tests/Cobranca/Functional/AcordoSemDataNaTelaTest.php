<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\Acordo;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * ACORDO SEM DATA NA TELA — spec `cobranca-espelho-violacoes-do-importe.md` §2.2 e §2.4.
 *
 * 🔴 O DEFEITO QUE ESTES TESTES GUARDAM É MUDO. O filtro `date` do Twig converte `null` para `"now"`:
 * `{{ null|date('d/m/Y') }}` imprime **a data de hoje**, sem erro e sem aviso. Ao tornar `dataAcordo`
 * anulável, os quatro pontos que a formatavam passariam a INVENTAR data na tela — a mesma violação que
 * esta frente removeu do importador, reaparecendo na view.
 *
 * A suíte não pegaria isso sozinha: ela lê HTML, e "17/08/2026" é HTML perfeitamente válido. Por isso
 * cada teste aqui procura a data de HOJE explicitamente e exige que ela NÃO esteja lá.
 */
#[CoversClass(ObjetoController::class)]
final class AcordoSemDataNaTelaTest extends CobrancaWebTestCase
{
    /**
     * 🔴 PROVA POR REINTRODUÇÃO. Tirar o `{% if g.dataAcordo %}` de `_divida.html.twig` faz este teste
     * ficar vermelho, porque a data de hoje volta a aparecer no lugar de "data não informada".
     */
    #[TestDox('Twig: acordo sem data NAO imprime a data de hoje no grupo da divida')]
    public function testAcordoSemDataNaoImprimeDataDeHoje(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'dataAcordo' => null])->_real();
        // Uma parcela viva mantém o grupo do acordo visível na aba da dívida.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 45000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()?->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $hoje = (new \DateTimeImmutable())->format('d/m/Y');
        self::assertStringNotContainsString(
            'firmado em ' . $hoje,
            $html,
            'O Twig imprimiu a data de HOJE para um acordo sem data — a guarda `{% if %}` caiu.',
        );
        self::assertStringContainsString('firmado em data não informada', $html);
    }

    /**
     * §2.4 — traço COM O MOTIVO NA LINHA, e o motivo não pode ser só tooltip: é o que deixa a gerência
     * julgar varrendo a coluna com o olho.
     */
    #[TestDox('Encargo nao calculado mostra traco com o motivo NA LINHA, nunca um numero')]
    public function testEncargoNaoCalculadoMostraTracoComMotivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'dataAcordo' => null])->_real();
        // O grupo do acordo na aba da dívida só existe se ele tiver PARCELA VIVA — é ela que carrega o
        // collapse onde as substituídas aparecem.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 45000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ]);
        // A substituída carrega encargo RESIDUAL da última hidratação ao vivo — o "número velho" que a
        // tela não pode mostrar. Se ele vazar, o total (600,00+77,77+12,00+34,00 = 723,77) aparece.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Dívida renegociada',
            'valorOriginal' => 60000, 'encargosReconhecidos' => 0,
            'juros' => 7777, 'multa' => 1200, 'correcao' => 0, 'honorarios' => 3400,
            'acordoSubstituto' => $acordo,
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()?->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('⚠ acordo sem data', $html, 'O motivo sumiu da linha.');
        self::assertStringNotContainsString(
            '723,77',
            $html,
            'A tela somou o encargo RESIDUAL da última hidratação — número velho de uma data arbitrária.',
        );
        // O principal continua visível: ele é dado real, veio da contabilidade.
        self::assertStringContainsString('600,00', $html);
    }

    /** Acordo COM data segue mostrando o número: a mudança não pode apagar o caminho normal. */
    #[TestDox('Acordo COM data continua mostrando a data e o encargo normalmente')]
    public function testAcordoComDataSegueNormal(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'dataAcordo' => new \DateTimeImmutable('2025-03-14'),
        ])->_real();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 45000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Dívida renegociada',
            'valorOriginal' => 60000, 'encargosReconhecidos' => 0, 'juros' => 7777,
            'acordoSubstituto' => $acordo,
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()?->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('14/03/2025', $html);
        self::assertStringNotContainsString('⚠ acordo sem data', $html);
    }

    /**
     * §2.3 item 9 — no PostgreSQL `ORDER BY dataAcordo DESC` põe NULL PRIMEIRO. O acordo sem data
     * lideraria a lista do caso, passando por "o mais recente". Não é exibição: é a ordem em que a
     * gerência lê os acordos.
     */
    #[TestDox('Ordenacao: acordo sem data NAO lidera a lista do caso')]
    public function testAcordoSemDataNaoLideraAOrdenacao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'dataAcordo' => null]);
        AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'dataAcordo' => new \DateTimeImmutable('2025-03-14')]);

        $repo = static::getContainer()->get(\App\Cobranca\Repository\AcordoRepository::class);
        $acordos = $repo->doCaso($caso);

        self::assertCount(2, $acordos);
        self::assertInstanceOf(Acordo::class, $acordos[0]);
        self::assertNotNull(
            $acordos[0]->getDataAcordo(),
            'O acordo SEM data liderou a lista — o NULL voltou a vir primeiro na ordenação.',
        );
    }
}
