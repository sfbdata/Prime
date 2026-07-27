<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Totais da aba Honorários (SPEC UX §14.2). São somas do que a própria aba lista — mas são DINHEIRO na
 * tela, e por isso valem prova numérica.
 *
 * O invariante protegido aqui é **o rodapé bater com a soma das linhas visíveis**: é o que permite
 * conferir a olho. Se o total passar a incluir algo que a lista não mostra (uma obrigação substituída
 * por acordo, por exemplo), a conta "não fecha" e ninguém confia mais na tela.
 *
 * Os testes NÃO fixam o valor do honorário: ele é calculado AO VIVO pela cascata (`EncargosVivos`) a
 * partir da configuração da carteira, e cravar um número aqui seria duplicar a regra de cálculo dentro
 * do teste — que passaria a passar por motivo errado no dia em que a fórmula mudasse. O que se afirma é
 * a RELAÇÃO entre os números da tela.
 */
#[CoversClass(ObjetoController::class)]
final class AbaHonorariosTotaisTest extends CobrancaWebTestCase
{
    #[TestDox('O rodapé "Total das obrigações" é exatamente a soma das linhas visíveis')]
    public function testRodapeBateComAsLinhasVisiveis(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [], self::carteiraQueCobraHonorarios());

        foreach (['Competência 01', 'Competência 02', 'Competência 03'] as $i => $descricao) {
            ObrigacaoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso, 'descricao' => $descricao,
                'valorOriginal' => 100000 * ($i + 1), 'encargosReconhecidos' => 0,
                'vencimentoOriginal' => new \DateTimeImmutable('-120 days'),
            ]);
        }

        $aba = $this->abrirAba($client, $caso->getObjeto()->getId());

        $linhas = $this->linhasDeHonorario($aba);
        self::assertCount(3, $linhas, 'as três obrigações têm de aparecer');
        self::assertGreaterThan(0, array_sum($linhas), 'a carteira cobra honorário — a lista não pode estar zerada');

        self::assertSame(
            array_sum($linhas),
            $this->centavos($this->totalDaLista($aba, 'Total das obrigações')),
            'o rodapé tem de ser a soma das linhas, centavo a centavo',
        );
    }

    #[TestDox('"A receber" exclui as quitadas; o rodapé continua somando todas as linhas')]
    public function testAReceberExcluiQuitadas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [], self::carteiraQueCobraHonorarios());

        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Em aberto',
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-120 days'),
        ]);

        // Quitada: alocação cobre o exigível. Fica FORA do "a receber" e DENTRO do total da lista.
        $quitada = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Quitada',
            'valorOriginal' => 20000, 'encargosReconhecidos' => 0, 'honorarios' => 3000,
            'vencimentoOriginal' => new \DateTimeImmutable('-120 days'),
        ]);
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 3000,
        ]);
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $quitada, 'valor' => 999999,
        ]);

        $aba = $this->abrirAba($client, $caso->getObjeto()->getId());

        self::assertSame(1, $aba->filter('.cob-hon-lista[data-lista="obrigacoes"] > li.is-quitada')->count(), 'a linha quitada tem de estar marcada');

        $total = $this->centavos($this->totalDaLista($aba, 'Total das obrigações'));
        $aReceber = $this->centavos($this->cardDoTopo($aba, 'A receber'));
        $quitadoNaLista = $this->centavos(
            $aba->filter('.cob-hon-lista[data-lista="obrigacoes"] > li.is-quitada .col-honorarios')->text(),
        );

        self::assertSame(array_sum($this->linhasDeHonorario($aba)), $total, 'o rodapé soma TODAS as linhas');
        self::assertSame($total - $quitadoNaLista, $aReceber, '"a receber" é o total menos o que já foi quitado');
        self::assertLessThan($total, $aReceber, 'com uma quitada na lista, a receber tem de ser menor que o total');
    }

    #[TestDox('Total recebido é a soma dos honorários dos recebimentos listados')]
    public function testTotalRecebido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [], self::carteiraQueCobraHonorarios());

        PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 50000, 'valorHonorarios' => 7500]);
        PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 2500]);

        $aba = $this->abrirAba($client, $caso->getObjeto()->getId());

        self::assertSame(10000, $this->centavos($this->totalDaLista($aba, 'Total recebido')), 'R$ 75,00 + R$ 25,00');
        self::assertSame(10000, $this->centavos($this->cardDoTopo($aba, 'Já recebido')), 'o card repete o rodapé');
        self::assertStringContainsString('em 2 recebimentos', $aba->text());
    }

    #[TestDox('Sem obrigação e sem recebimento, a aba não inventa total nenhum')]
    public function testAbaVaziaNaoMostraTotal(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $aba = $this->abrirAba($client, $caso->getObjeto()->getId());

        self::assertCount(0, $aba->filter('.cob-hon-total'), 'total de lista vazia é ruído, não informação');
        self::assertStringContainsString('Nenhuma obrigação registrada', $aba->text());
        self::assertStringContainsString('Nenhum recebimento registrado', $aba->text());
        // Os cards continuam, zerados — é resposta legítima a "quanto tenho a receber?".
        self::assertSame(0, $this->centavos($this->cardDoTopo($aba, 'A receber')));
        self::assertSame(0, $this->centavos($this->cardDoTopo($aba, 'Já recebido')));
    }

    /** @return array<string, mixed> */
    private static function carteiraQueCobraHonorarios(): array
    {
        return [
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
            'baseHonorarios' => BaseEncargo::Principal,
            'carenciaHonorariosDias' => 0,
        ];
    }

    private function abrirAba(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $objetoId): Crawler
    {
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertResponseIsSuccessful();

        return $crawler->filter('#tab-honorarios');
    }

    /**
     * Valores das linhas da lista "Por obrigação", em centavos, sem o rodapé. O `data-lista` importa: as
     * DUAS listas da aba usam `.cob-hon-lista`, e sem o recorte a soma varreria também os recebimentos —
     * foi exatamente esse o erro que este teste cometeu antes de o seletor ficar explícito.
     *
     * @return list<int>
     */
    private function linhasDeHonorario(Crawler $aba): array
    {
        return $aba
            ->filter('.cob-hon-lista[data-lista="obrigacoes"] > li:not(.cob-hon-total) .col-honorarios')
            ->each(fn (Crawler $n): int => $this->centavos($n->text()));
    }

    /** "R$ 1.234,56" → 123456. Sem float no caminho: o teste de dinheiro não pode ter erro de ponto flutuante. */
    private function centavos(string $texto): int
    {
        $digitos = preg_replace('/\D/', '', $texto) ?? '';
        self::assertNotSame('', $digitos, "Valor monetário ilegível: \"{$texto}\"");

        return (int) $digitos;
    }

    private function totalDaLista(Crawler $aba, string $rotulo): string
    {
        foreach ($aba->filter('.cob-hon-total')->each(fn (Crawler $n): Crawler => $n) as $linha) {
            if (str_contains($linha->text(), $rotulo)) {
                return $linha->filter('.col-honorarios')->text();
            }
        }

        self::fail("Não achei o rodapé \"{$rotulo}\" na aba Honorários.");
    }

    private function cardDoTopo(Crawler $aba, string $rotulo): string
    {
        foreach ($aba->filter('.cob-hon-card')->each(fn (Crawler $n): Crawler => $n) as $card) {
            if (str_contains($card->filter('.cob-hon-card-rot')->text(), $rotulo)) {
                return $card->filter('.cob-hon-card-val')->text();
            }
        }

        self::fail("Não achei o card \"{$rotulo}\" na aba Honorários.");
    }
}
