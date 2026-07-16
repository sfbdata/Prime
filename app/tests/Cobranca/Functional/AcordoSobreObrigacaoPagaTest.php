<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\DTO\ParcelaAcordoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\UseCase\CriarAcordoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Acordo sobre obrigação PARCIALMENTE PAGA (ajuste 10 T6, spec §5.3) — o bug de dinheiro confirmado em
 * prod: o form sugeria o valor CHEIO de uma obrigação que já recebeu pagamento, e o pagamento evaporava
 * do saldo (a substituída sai do exigível levando a alocação junto, `CalculadoraSaldo:57`).
 *
 * Dois papéis distintos nesta classe:
 *  - os testes de SALDO DOCUMENTAM o comportamento do domínio, que NÃO muda com D7 (não bloqueamos:
 *    renegociar o remanescente é fluxo legítimo). Existem para que a mudança seja CONSCIENTE;
 *  - o teste de CONVERGÊNCIA prova o conserto: a linha da dívida e o modal passam a oferecer o MESMO
 *    número (INV-U9) — antes, a barra dizia R$ 800 e o modal abria em R$ 1.200.
 */
final class AcordoSobreObrigacaoPagaTest extends CobrancaWebTestCase
{
    #[TestDox('Spec §5.3 (D7): renegociar o REMANESCENTE preserva o saldo — nada evapora')]
    public function testAcordoSobreObrigacaoPagaTrocaOSaldoPeloTotalNegociado(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = $this->obrigacaoPaga($tenant, $caso, valor: 120000, pago: 40000);

        $calculadora = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(80000, $calculadora->saldoExigivel($caso), 'saldo antes do acordo');

        // O gestor renegocia o REMANESCENTE — o que a UI passa a sugerir depois deste ajuste (T6).
        $this->criarAcordo($caso, $tenant, $user, [$obrigacao], totalNegociado: 80000, parcelas: 2);

        self::assertSame(80000, $calculadora->saldoExigivel($caso), 'o remanescente é preservado');
    }

    #[TestDox('Spec §5.3: renegociar o valor CHEIO faz o pagamento evaporar — o mecanismo do bug')]
    public function testAcordoPeloValorCheioFazOPagamentoEvaporarDoSaldo(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = $this->obrigacaoPaga($tenant, $caso, valor: 120000, pago: 40000);

        $calculadora = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(80000, $calculadora->saldoExigivel($caso), 'saldo antes do acordo');

        // Era EXATAMENTE isto que o form sugeria antes da T6: as parcelas somam o valor CHEIO (120000),
        // ignorando os R$ 400 já pagos.
        $this->criarAcordo($caso, $tenant, $user, [$obrigacao], totalNegociado: 120000, parcelas: 3);

        // Os R$ 400 evaporam: a original saiu do exigível e levou a alocação junto. NÃO bloqueamos (D7) —
        // o saldo virar o total negociado é o design do domínio. O defeito era SUGERIR este número.
        // Se alguém "consertar" `CalculadoraSaldo`, este teste cai e a decisão vira consciente.
        self::assertSame(120000, $calculadora->saldoExigivel($caso), 'o pagamento evapora do saldo');
    }

    #[TestDox('T6 (INV-U9): a linha da dívida e o modal de acordo oferecem o MESMO valor')]
    public function testOValorDaLinhaEDoModalConvergemNoRemanescente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = $this->obrigacaoPaga($tenant, $caso, valor: 120000, pago: 40000);
        $id = (string) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $naLinha = $crawler->filter('#secao-divida .jp-check[value="' . $id . '"]')->attr('data-valor-centavos');
        $noModal = $crawler->filter('#modalCriarAcordo input[name*="obrigacoesSubstituidasIds"][value="' . $id . '"]')
            ->attr('data-valor-centavos');

        // A dívida herdada da T5: a barra de seleção somava o RESTANTE (80000) e o modal abria no valor
        // CHEIO (120000) — a mesma obrigação valia dois números na mesma tela. Agora convergem.
        self::assertSame('80000', $naLinha, 'a linha da dívida mostra o restante');
        self::assertSame('80000', $noModal, 'o modal tem de oferecer o MESMO número da linha');
        self::assertSame($naLinha, $noModal);
    }

    #[TestDox('T6: o rótulo da opção mostra o remanescente e avisa quanto já foi recebido')]
    public function testORotuloDaOpcaoMostraORemanescenteEOQueJaEntrou(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->obrigacaoPaga($tenant, $caso, valor: 120000, pago: 40000, descricao: 'Abril/2026');

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('R$ 800,00 (R$ 400,00 já recebidos)', $html);
        // O valor cheio não pode ser oferecido como se fosse o devido.
        self::assertStringNotContainsString('Abril/2026 — venc 10/04/2026 — R$ 1.200,00', $html);
    }

    #[TestDox('T6: o modal carrega o gatilho do aviso (alocado) e ele nasce escondido')]
    public function testOModalCarregaOAlocadoQueDisparaOAvisoENasceEscondido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = $this->obrigacaoPaga($tenant, $caso, valor: 120000, pago: 40000);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // O aviso é revelado pelo JS a partir deste dado (o functional não roda JS): sem o atributo, o
        // aviso nunca apareceria — é ele que o teste tem de provar.
        self::assertSame('40000', $crawler
            ->filter('#modalCriarAcordo input[name*="obrigacoesSubstituidasIds"][value="' . $obrigacao->getId() . '"]')
            ->attr('data-alocado-centavos'));
        // Sem seleção não há o que avisar: nasce escondido.
        $aviso = $crawler->filter('#avisoAcordoComPagamento');
        self::assertCount(1, $aviso);
        self::assertStringContainsString('d-none', (string) $aviso->attr('class'));
    }

    #[TestDox('T6: obrigação sem pagamento não dispara o aviso nem perde valor')]
    public function testObrigacaoSemPagamentoNaoDisparaOAviso(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Abril/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-04-10'),
        ])->_real();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $check = $crawler->filter('#modalCriarAcordo input[name*="obrigacoesSubstituidasIds"][value="' . $obrigacao->getId() . '"]');
        // Sem alocação o gatilho é 0 → o JS nunca revela o aviso, e o valor segue o exigível cheio.
        self::assertSame('0', $check->attr('data-alocado-centavos'));
        self::assertSame('120000', $check->attr('data-valor-centavos'));
        self::assertStringNotContainsString('já recebidos', (string) $client->getResponse()->getContent());
    }

    /** Obrigação do caso com um pagamento parcial já alocado a ela. */
    private function obrigacaoPaga(
        Tenant $tenant,
        CasoCobranca $caso,
        int $valor,
        int $pago,
        string $descricao = 'Abril/2026',
    ): Obrigacao {
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => $descricao, 'valorOriginal' => $valor, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-04-10'),
        ])->_real();

        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorDivida' => $pago, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();

        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => $pago,
        ]);

        return $obrigacao;
    }

    /**
     * Acordo real pelo UseCase (nada de atalho por entidade): substitui as obrigações e gera as parcelas,
     * que dividem o total negociado em partes iguais.
     *
     * @param list<Obrigacao> $substituindo
     */
    private function criarAcordo(
        CasoCobranca $caso,
        Tenant $tenant,
        User $user,
        array $substituindo,
        int $totalNegociado,
        int $parcelas,
    ): void {
        $input = new CriarAcordoInput();
        $input->casoId = $caso->getId();
        $input->dataAcordo = new \DateTimeImmutable('2026-05-01');
        $input->valorTotalNegociado = $totalNegociado;
        $input->valorEntrada = 0;
        $input->obrigacoesSubstituidasIds = array_map(static fn (Obrigacao $o): int => (int) $o->getId(), $substituindo);

        $resto = $totalNegociado;
        for ($i = 1; $i <= $parcelas; ++$i) {
            $parcela = new ParcelaAcordoInput();
            $parcela->descricao = sprintf('Parcela %d/%d', $i, $parcelas);
            // A última leva o resto: centavos não somem no arredondamento.
            $parcela->valor = $i === $parcelas ? $resto : intdiv($totalNegociado, $parcelas);
            $parcela->vencimento = new \DateTimeImmutable(sprintf('2026-06-%02d', $i));
            $resto -= (int) $parcela->valor;
            $input->parcelas[] = $parcela;
        }

        static::getContainer()->get(CriarAcordoUseCase::class)->executar($input, $tenant, $user);
    }
}
