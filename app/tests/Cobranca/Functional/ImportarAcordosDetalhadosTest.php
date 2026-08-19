<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\Importacao\AcordoDetalhadoImportavel;
use App\Cobranca\Service\Importacao\AcordoDoRelatorio;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ContaOriginalImportavel;
use App\Cobranca\Service\Importacao\ImpactoDaReativacaoDeAcordo;
use App\Cobranca\Service\Importacao\ParcelaAcordoImportavel;
use App\Cobranca\Service\Importacao\ResultadoLeitura;
use App\Cobranca\Service\Importacao\ResultadoLeituraAcordos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\Service\ResolvedorPessoaNoObjeto;
use App\Cobranca\Service\RestauradorObrigacoesOriginais;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\CriarObjetoUseCase;
use App\Cobranca\UseCase\CriarPessoaUseCase;
use App\Cobranca\UseCase\ImportarAcordosDetalhadosUseCase;
use App\Cobranca\UseCase\ImportarRelatorioCarteiraUseCase;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Cobranca\UseCase\VincularPessoaAObjetoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Importação dos acordos detalhados — spec `docs/specs/cobranca-importar-acordos-detalhados.md`.
 *
 * Risco ALTO: isto corrige um bug de DINHEIRO que já está em produção. O cenário-base reproduz o
 * acordo 37 real (QUADRA 05 CHACARA 03/04, Gessi Pereira dos Santos): 4 contas originais de R$ 170,00
 * que continuam abertas no sistema mesmo depois de o acordo as ter substituído — R$ 680,00 cobrados a
 * mais, com juros e multa correndo por cima.
 *
 * A carteira de teste tem encargos NEUTROS (default da `CarteiraFactory`), então o saldo exigível é
 * exatamente a soma dos valores originais — o que permite asserir o efeito em centavos, e não "diminuiu".
 *
 * O estado ANTERIOR é semeado pelo `ImportarRelatorioCarteiraUseCase`, o mesmo caminho por onde
 * produção chegou onde chegou: objeto, caso, pessoa, obrigações e o próprio Acordo nascem da
 * inadimplência. Fixture montada à mão não reproduziria o bug.
 *
 * Cada teste aqui foi provado reintroduzindo o defeito que ele guarda.
 */
#[CoversClass(ImportarAcordosDetalhadosUseCase::class)]
final class ImportarAcordosDetalhadosTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ImportarAcordosDetalhadosUseCase $importarAcordos;
    private ImportarRelatorioCarteiraUseCase $importarInadimplencia;
    private CalculadoraSaldo $saldo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->saldo = static::getContainer()->get(CalculadoraSaldo::class);

        $carteiraRepo = $this->em->getRepository(Carteira::class);
        $objetoRepo = $this->em->getRepository(ObjetoCobranca::class);
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        $pessoaRepo = $this->em->getRepository(Pessoa::class);
        $vinculoRepo = $this->em->getRepository(VinculoPessoaObjeto::class);
        $eventoRepo = $this->em->getRepository(EventoHistorico::class);
        /** @var AcordoRepository $acordoRepo */
        $acordoRepo = $this->em->getRepository(Acordo::class);

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);
        $registrarObrigacao = new RegistrarObrigacaoUseCase(
            $obrigacaoRepo,
            $casoRepo,
            $registrarEvento,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            new ConversorTaxaEncargo(new CalculadoraEncargos()),
        );

        $this->importarInadimplencia = new ImportarRelatorioCarteiraUseCase(
            $carteiraRepo,
            $objetoRepo,
            $casoRepo,
            $obrigacaoRepo,
            $vinculoRepo,
            $acordoRepo,
            new CriarObjetoUseCase($objetoRepo, $carteiraRepo),
            new CriarPessoaUseCase($pessoaRepo),
            new VincularPessoaAObjetoUseCase($vinculoRepo, $pessoaRepo, $objetoRepo),
            new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento),
            $registrarObrigacao,
            $this->em,
            new ResolvedorPessoaNoObjeto($vinculoRepo),
        );

        $this->importarAcordos = new ImportarAcordosDetalhadosUseCase(
            $carteiraRepo,
            $acordoRepo,
            $obrigacaoRepo,
            $objetoRepo,
            $casoRepo,
            $registrarObrigacao,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            $registrarEvento,
            static::getContainer()->get(RestauradorObrigacoesOriginais::class),
            new ImpactoDaReativacaoDeAcordo(
                $acordoRepo,
                $obrigacaoRepo,
                $this->em->getRepository(AlocacaoPagamento::class),
            ),
            $this->em->getRepository(AlocacaoPagamento::class),
            $this->em,
        );
    }

    // ---------------------------------------------------------------------------------------------
    // §3.1 — completar as parcelas futuras
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Parcela ausente é criada com honorários ZERO e apontando para o acordo certo')]
    public function testParcelaAusenteEhCriada(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertSame(['61601', '61602', '61603'], $resultado->nnsParcelasCriadas());
        self::assertSame(59815, $resultado->valorParcelasCriadasCentavos(), 'R$ 199,39 + 199,38 + 199,38');

        $parcela = $this->obrigacao($tenant, '61601');
        self::assertNotNull($parcela);
        self::assertSame(19939, $parcela->getValorOriginal(), 'a soma da coluna Valor acordado do NN');
        self::assertSame($acordo->getId(), $parcela->getAcordoOrigem()?->getId(), 'a parcela pertence ao acordo');
        self::assertSame(0, $parcela->getHonorarios(), 'acordo não cobra honorário sobre honorário (§3.1)');
        self::assertSame(0, $parcela->getTaxaHonorariosBp(), 'o zero é uma TAXA gravada, não um cache que a hidratação reescreve');
        self::assertSame('2026-08-10', $parcela->getVencimentoOriginal()->format('Y-m-d'));
        self::assertSame('08/2026', $parcela->getCompetencia(), 'competência da COLUNA — é a chave que a inadimplência usará');
        self::assertNull($parcela->getAcordoSubstituto());
    }

    #[TestDox('Parcela que já existe não é recriada nem tem o valor sobrescrito')]
    public function testParcelaExistenteNaoEhTocada(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $antes = $this->obrigacao($tenant, '61600');
        self::assertNotNull($antes);
        $valorAntes = $antes->getValorOriginal();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertContains('61600', $resultado->porAcordo()[0]->parcelasExistentes);
        self::assertNotContains('61600', $resultado->nnsParcelasCriadas());
        self::assertSame(1, $this->contar($tenant, '61600'), 'nunca uma segunda obrigação para o mesmo boleto');
        self::assertSame($valorAntes, $this->obrigacao($tenant, '61600')?->getValorOriginal());
    }

    // ---------------------------------------------------------------------------------------------
    // §3.2 — reconciliar as contas originais (A CORREÇÃO)
    // ---------------------------------------------------------------------------------------------

    #[TestDox('As 4 contas originais abertas são marcadas e o saldo cai EXATAMENTE R$ 680,00')]
    public function testSaldoCaiExatamenteOPrincipalReconciliado(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $saldoAntes = $this->saldo->saldoExigivel($caso);
        self::assertSame(87939, $saldoAntes, '4 originais de R$ 170,00 + a parcela 1/4 de R$ 199,39 — a dívida contada DUAS vezes');

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertSame(['60145', '60334', '60812', '61326'], $resultado->nnsContasMarcadas());
        self::assertSame(68000, $resultado->principalReconciliadoCentavos(), 'os R$ 680,00 do §1 da spec');

        $this->em->clear();
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);

        // O saldo cai os R$ 680,00 das originais e SOBE as 3 parcelas futuras que faltavam.
        self::assertSame($saldoAntes - 68000 + 59815, $this->saldo->saldoExigivel($caso));

        foreach (['60145', '60334', '60812', '61326'] as $nn) {
            self::assertNotNull($this->obrigacao($tenant, $nn), 'a obrigação NUNCA é apagada (invariável 14)');
            self::assertTrue($this->obrigacao($tenant, $nn)?->foiSubstituida(), "conta {$nn} marcada");
        }
    }

    #[TestDox('Conta já marcada não é remarcada: segunda execução não muda nada')]
    public function testContaJaMarcadaNaoEhRemarcada(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);
        $this->em->clear();
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        $saldoDepoisDaPrimeira = $this->saldo->saldoExigivel($caso);

        $segunda = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertSame([], $segunda->nnsContasMarcadas(), 'nada novo a marcar');
        self::assertSame(['60145', '60334', '60812', '61326'], $segunda->nnsContasJaMarcadas());
        self::assertSame(0, $segunda->principalReconciliadoCentavos(), 'nenhum centavo sai do saldo de novo');
        self::assertSame([], $segunda->nnsParcelasCriadas());

        $this->em->clear();
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        self::assertSame($saldoDepoisDaPrimeira, $this->saldo->saldoExigivel($caso), 'idempotente ao centavo');
    }

    // ---------------------------------------------------------------------------------------------
    // §3.2.1 — reconstruir as contas originais ausentes
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Conta original ausente nasce JÁ substituída e NÃO entra no saldo')]
    public function testContaAusenteNasceSubstituidaEForaDoSaldo(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo31();

        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo31(), $tenant, $user);

        self::assertSame(['60049', '60240'], $resultado->nnsContasReconstruidas());
        self::assertSame(0, $resultado->principalReconciliadoCentavos(), 'reconstruída nunca esteve no saldo — não o altera');

        $reconstruida = $this->obrigacao($tenant, '60049');
        self::assertNotNull($reconstruida);
        self::assertSame(17000, $reconstruida->getValorOriginal());
        self::assertSame('01/2026', $reconstruida->getCompetencia());
        self::assertSame($acordo->getId(), $reconstruida->getAcordoSubstituto()?->getId(), 'nasce substituída');

        $this->em->clear();
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        self::assertSame($saldoAntes, $this->saldo->saldoExigivel($caso), 'o saldo NÃO se mexe por causa das reconstruídas');
    }

    #[TestDox('Conta reconstruída carrega a marcação de procedência (planilha + data de emissão)')]
    public function testContaReconstruidaCarregaProcedencia(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo31();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo31(), $tenant, $user);

        $descricao = (string) $this->obrigacao($tenant, '60049')?->getDescricao();
        self::assertStringContainsString('planilha de acordos', $descricao, 'sem isto ninguém distingue boleto importado de conta reconstruída');
        self::assertStringContainsString('29/07/2026', $descricao, 'a data de emissão do relatório de origem');
    }

    /**
     * Spec `cobranca-honorario-no-total.md` §10 — o override entra quando a obrigação VIRA PARCELA.
     *
     * A produção de 19/08 mostra a regra que o sistema já cumpre: 301 parcelas de acordo com o
     * override e honorário R$ 0,00 (igual ao relatório dela) contra 135 sem, cobrando R$ 2.764,16 que
     * ela não cobra. As 135 chegaram lá por ESTE ramo — ligadas ao acordo sem receber o override.
     *
     * ⚠️ A carteira NEUTRA da factory (taxas em zero) faria este teste passar sem o conserto. Por isso
     * o cenário usa honorário de 20%, e a asserção de juros > 0 prova que a cascata está VIVA quando o
     * honorário dá zero.
     */
    #[TestDox('Parcela SOLTA ligada ao acordo recebe o override: parcela não cobra honorário')]
    public function testParcelaVinculadaRecebeOverrideDeHonorario(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant, [
            'taxaJurosMensalBp' => 100,
            'taxaMultaBp' => 200,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ]);

        // Nasce SOLTA, pela inadimplência, sem acordo — o estado das 135 antes de serem ligadas.
        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61600', competencia: '07/2026', vencimento: '2026-07-15', valor: 19939),
        ]);

        $solta = $this->obrigacao($tenant, '61600');
        self::assertNotNull($solta);
        self::assertNull($solta->getTaxaHonorariosBp(), 'o cenário começa no estado defeituoso: sem override');

        // A planilha de acordos declara que aquele boleto é parcela do acordo 37.
        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 37,
            contas: [],
            parcelas: [['61600', 1, 4, '07/2026', '2026-07-15', 19939]],
        )], [], 0);

        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);
        self::assertSame(['61600'], $feito->parcelasVinculadas());

        $this->em->clear();
        $agora = $this->obrigacao($tenant, '61600');
        self::assertNotNull($agora);
        self::assertNotNull($agora->getAcordoOrigem(), 'virou parcela');
        self::assertSame(0, $agora->getTaxaHonorariosBp(), 'o vínculo e o override andam juntos — sem isto nasce mais uma das 135');

        // A CONSEQUÊNCIA, não só a coluna: o override tem de VENCER a cascata. A primeira asserção é o
        // que impede o teste de ser tautológico — ela prova que, sem o override, a carteira cobraria
        // 20% nesta obrigação.
        $resolvedor = new ResolvedorConfigEncargos();
        $caso = $agora->getCaso();
        self::assertNotNull($caso);
        $configCaso = $resolvedor->resolverDoCaso($caso);
        self::assertSame(2000, $configCaso->taxaHonorariosBp, 'sem os 20% na cascata a carteira está neutra e o teste não prova nada');
        self::assertSame(0, $resolvedor->aplicarObrigacao($configCaso, $agora)->taxaHonorariosBp, 'o override da obrigação tem de vencer os 20% da carteira');
    }

    /**
     * 🔴 O teste que a 2ª revisão apontou como FALTANTE, e ele guarda dinheiro em dois lugares.
     *
     * Quando o valor do sistema NÃO é o Valor acordado dela, gravar `bp = 0` seria errado duas vezes:
     * tiraria honorário de uma dívida cujo valor não o embute, e — pior — ligaria o sinal
     * `$honorarioEmbutidoNoValorOriginal` de `ImportarReceitasUseCase`, que manda alocar o recebimento
     * BRUTO. Aquele UseCase já cometeu e reverteu esse defeito uma vez; o guard não pode reintroduzi-lo
     * por outra porta.
     */
    #[TestDox('Parcela vinculada com valor DIVERGENTE da planilha NÃO recebe o override')]
    public function testParcelaVinculadaComValorDivergenteNaoRecebeOverride(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant, [
            'taxaJurosMensalBp' => 100,
            'taxaMultaBp' => 200,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ]);

        // A avulsa nasce com o principal da inadimplência — que NÃO é o valor negociado.
        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61600', competencia: '07/2026', vencimento: '2026-07-15', valor: 15000),
        ]);

        // A planilha declara a parcela por outro valor: o sistema não tem o valor negociado dela.
        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 37,
            contas: [],
            parcelas: [['61600', 1, 4, '07/2026', '2026-07-15', 19939]],
        )], [], 0);

        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame(['61600'], $feito->parcelasVinculadas(), 'o VÍNCULO entra de qualquer jeito — ele não depende do valor');
        self::assertNotSame([], $feito->divergenciasDeValor(), 'a divergência tem de ser reportada ao humano');

        $this->em->clear();
        $agora = $this->obrigacao($tenant, '61600');
        self::assertNotNull($agora);
        self::assertNotNull($agora->getAcordoOrigem(), 'virou parcela');
        self::assertNull(
            $agora->getTaxaHonorariosBp(),
            'sem o valor negociado, gravar bp=0 mandaria a importação de receitas alocar o BRUTO contra um valor que não embute o honorário',
        );
    }

    /**
     * O contrapeso do teste acima, e ele guarda os R$ 102.126,32 que a primeira versão desta fatia
     * quase apagou: conta original reconstruída **não é parcela**. É a dívida VELHA que o acordo
     * engoliu, e nela a carteira cobra honorário — 3.473 assim em produção, todas de propósito.
     */
    #[TestDox('Conta reconstruída NÃO recebe o override: dívida velha engolida cobra honorário normal')]
    public function testContaReconstruidaNaoRecebeOverride(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant, [
            'taxaJurosMensalBp' => 100,
            'taxaMultaBp' => 200,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ]);

        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61372', competencia: '07/2026', vencimento: '2026-07-01', valor: 40068, acordo: new AcordoDoRelatorio(31, 1, 3)),
        ]);
        $this->casoEAcordo($tenant, 31);

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo31(), $tenant, $user);

        $reconstruida = $this->obrigacao($tenant, '60049');
        self::assertNotNull($reconstruida);
        self::assertNull($reconstruida->getAcordoOrigem(), 'conta original NÃO é parcela — nasce só substituída');
        self::assertNull($reconstruida->getTaxaHonorariosBp(), 'sem override: a carteira cobra honorário na dívida velha, e a contabilidade também');
        self::assertGreaterThan(0, $reconstruida->getHonorarios(), 'zerar isto apagaria R$ 102.126,32 de honorário legítimo em produção');
    }

    #[TestDox('Conta reconstruída não é recriada na segunda execução (idempotência por NN+competência)')]
    public function testContaReconstruidaNaoEhRecriada(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo31();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo31(), $tenant, $user);
        $segunda = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo31(), $tenant, $user);

        self::assertSame([], $segunda->nnsContasReconstruidas(), 'é o ponto mais fácil de duplicar dinheiro (§7)');
        self::assertSame(['60049', '60240'], $segunda->nnsContasJaMarcadas());
        self::assertSame(1, $this->contar($tenant, '60049'));
        self::assertSame(1, $this->contar($tenant, '60240'));
    }

    // ---------------------------------------------------------------------------------------------
    // Dívida que nunca teve boleto — spec `cobranca-divida-sem-numero-de-boleto.md`.
    // Este é o caminho que grava 54 das 99 obrigações da frente (R$ 6.750,00, medido em 04/08/2026)
    // e que a 1ª revisão apontou como o mais exposto: tem lógica própria (ObrigacoesTocadasNaImportacao,
    // nascimento já substituído) e não era exercitado por teste nenhum de gravação.
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Conta original sem boleto é reconstruída pela referência substituta e reportada à parte')]
    public function testContaSemBoletoEhReconstruida(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo31();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoSemBoleto(), $tenant, $user);

        self::assertSame(['SNN:2019-09-10', '60240'], $resultado->nnsContasReconstruidas());
        self::assertSame(['SNN:2019-09-10'], $resultado->nnsContasSemBoleto(), 'só a de 2019 nunca teve boleto');
        self::assertSame(14500, $resultado->centavosSemBoleto(), 'o relatório precisa do VALOR, não só da contagem');

        $reconstruida = $this->obrigacao($tenant, 'SNN:2019-09-10');
        self::assertNotNull($reconstruida);
        self::assertSame(14500, $reconstruida->getValorOriginal());
        self::assertSame('09/2019', $reconstruida->getCompetencia());
        self::assertSame($acordo->getId(), $reconstruida->getAcordoSubstituto()?->getId(), 'nasce substituída, como toda reconstruída');
    }

    /**
     * O motivo de a chave existir. Sem ela, a segunda importação criaria uma segunda dívida idêntica —
     * e este caminho tem um registro em memória (`ObrigacoesTocadasNaImportacao`) chaveado pelo trio
     * (caso, NN, competência) que precisa reconhecer a referência substituta como reconhece um NN.
     */
    #[TestDox('Reimportar a conta sem boleto não cria uma segunda dívida')]
    public function testContaSemBoletoNaoDuplicaNaReimportacao(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo31();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoSemBoleto(), $tenant, $user);
        $segunda = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoSemBoleto(), $tenant, $user);

        self::assertSame([], $segunda->nnsContasReconstruidas(), 'nada de novo');
        // A 2ª revisão pegou aqui o espelho do defeito que a 1ª achou: na reimportação a conta migra
        // para o balde "já marcada", e o relatório precisa continuar mostrando-a — com valor.
        self::assertSame(['SNN:2019-09-10'], $segunda->nnsContasSemBoleto(), 'e ela continua visível no relatório');
        self::assertSame(14500, $segunda->centavosSemBoleto(), 'o valor também não pode sumir na 2ª rodada');
        self::assertSame(1, $this->contar($tenant, 'SNN:2019-09-10'), 'cobrar duas vezes é o pior defeito possível aqui');
    }

    /** Acordo 31 com uma conta que nunca teve boleto (09/2019) e uma normal, para contraste. */
    private function leituraAcordoSemBoleto(): ResultadoLeituraAcordos
    {
        return new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 31,
            contas: [
                ['SNN:2019-09-10', '09/2019', '2019-09-10', 14500],
                ['60240', '02/2026', '2026-02-13', 17000],
            ],
            parcelas: [['61372', 1, 3, '07/2026', '2026-07-01', 40068]],
        )], [], 0);
    }

    #[TestDox('Rompimento: as originais (reais e reconstruídas) voltam ao saldo UMA vez e as parcelas saem')]
    public function testRompimentoRestauraOriginaisUmaVezSo(): void
    {
        // Este é o teste que cobre o RISCO ACEITO em §3.2.1. O acordo 37 tem 4 contas originais na
        // planilha, mas o sistema só conhece 2 — as outras 2 nascem reconstruídas, já substituídas. Ao
        // romper o acordo, as 4 têm de voltar à cobrança UMA vez cada: se a reconstruída nascesse como
        // parcela em vez de substituída, ou se qualquer uma entrasse duas vezes, o devedor seria cobrado
        // a mais no dia do rompimento — justamente o cenário que o dono aceitou correr.
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37(originaisNoSistema: ['60145', '60334']);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);
        self::assertSame(['60145', '60334'], $resultado->nnsContasMarcadas());
        self::assertSame(['60812', '61326'], $resultado->nnsContasReconstruidas());

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        $acordo->romper('teste de rompimento');
        $this->em->flush();
        $this->em->clear();

        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);

        $exigiveis = $this->em->getRepository(Obrigacao::class)->doCasoExigiveis($caso);
        $nns = array_map(static fn (Obrigacao $o): ?string => $o->getReferenciaExterna(), $exigiveis);
        sort($nns);

        self::assertSame(['60145', '60334', '60812', '61326'], $nns, 'as 4 originais voltam; NENHUMA parcela do acordo rompido fica');
        self::assertCount(4, $exigiveis, 'uma vez cada — nada dobra');
        self::assertSame(68000, $this->saldo->saldoExigivel($caso), 'exatamente o principal original, sem duplicar');
    }

    // ---------------------------------------------------------------------------------------------
    // A TRAVA: casar por NN + competência, nunca só pelo NN
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Dívida de 2022 com o MESMO NN não é marcada por um acordo de 2026 (R$ 435,00 em risco)')]
    public function testNaoMarcaDividaDeOutraCompetenciaComMesmoNn(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo31();

        // O caso já tem uma dívida ANTIGA cujo NN a contábil reaproveitou anos depois: taxa de 01/2022,
        // R$ 145,00. É outro boleto, de outra dívida — só o número coincide.
        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('60049', competencia: '01/2022', vencimento: '2022-01-10', valor: 14500),
        ]);

        $saldoAntes = $this->saldo->saldoExigivel($caso);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo31(), $tenant, $user);

        $antiga = $this->obrigacaoPorCompetencia($tenant, '60049', '01/2022');
        self::assertNotNull($antiga);
        self::assertFalse($antiga->foiSubstituida(), 'casar só pelo NN apagaria R$ 145,00 de cobrança legítima de terceiro');
        self::assertSame(14500, $antiga->getValorOriginal(), 'e o valor dela não pode ser tocado');

        self::assertSame(['60049', '60240'], $resultado->nnsContasReconstruidas(), 'a conta de 01/2026 nasce à parte, sem se confundir com a de 01/2022');
        self::assertSame(2, $this->contar($tenant, '60049'), 'as duas dívidas coexistem');

        $this->em->clear();
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        self::assertSame($saldoAntes, $this->saldo->saldoExigivel($caso), 'nenhum centavo de cobrança legítima sai do saldo');
    }

    #[TestDox('Casamento pelo fallback legado (obrigação sem competência) é REPORTADO, nunca silencioso')]
    public function testCasamentoPeloFallbackLegadoEhReportado(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // Dado anterior ao backfill de competência: casa só pelo NN, e o operador tem de saber disso.
        $legada = $this->obrigacao($tenant, '60145');
        self::assertNotNull($legada);
        $legada->setCompetencia(null);
        $this->em->flush();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertContains('60145', $resultado->casadasSemCompetencia());
        self::assertContains('60145', $resultado->nnsContasMarcadas());
        self::assertTrue($resultado->temAvisos());
    }

    // ---------------------------------------------------------------------------------------------
    // Invariantes do domínio e recusas
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Parcela de acordo nunca é marcada como substituída (INV-I: duplicaria a dívida ao romper)')]
    public function testNaoMarcaParcelaComoSubstituida(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // A planilha lista como "conta original" um NN que no sistema é PARCELA de acordo. Marcar isso é
        // o estado "acordo sobre acordo" que o CriarAcordoUseCase proíbe (INV-I): ao romper o acordo de
        // origem, a original que ELE substituiu volta ao saldo E esta parcela continua nele — a dívida
        // passa a contar duas vezes.
        $leitura = $this->leituraAcordo37(contasExtras: [
            ['61600', '07/2026', '2026-07-15', 19939],
        ]);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61600', $resultado->nnsContasMarcadas());
        self::assertFalse($this->obrigacao($tenant, '61600')?->foiSubstituida());

        $recusadas = $resultado->porAcordo()[0]->contasRecusadas;
        self::assertCount(1, $recusadas, 'recusar em silêncio seria tão ruim quanto aceitar');
        self::assertStringContainsString('61600', $recusadas[0]);
        self::assertStringContainsString('INV-I', $recusadas[0]);
    }

    #[TestDox('Divergência de valor é reportada e NUNCA aplicada (a planilha não manda no dinheiro lançado)')]
    public function testDivergenciaDeValorEhReportadaENaoAplicada(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // A planilha diz R$ 175,00 para uma conta que o sistema lançou como R$ 170,00.
        $leitura = $this->leituraAcordo37(valorOriginalDe: ['60145' => 17500]);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame(17000, $this->obrigacao($tenant, '60145')?->getValorOriginal(), 'o valor lançado não é sobrescrito (§4)');
        self::assertCount(1, $resultado->divergenciasDeValor());
        self::assertStringContainsString('60145', $resultado->divergenciasDeValor()[0]);
        self::assertTrue($resultado->temAvisos());
        self::assertContains('60145', $resultado->nnsContasMarcadas(), 'divergir no valor não impede reconciliar a dívida');
    }

    // ---------------------------------------------------------------------------------------------
    // ITEM 5 — o importador passa a CRIAR o acordo
    // spec `docs/specs/cobranca-importar-acordos-criar-acordo.md`
    //
    // Revoga a recusa da §3.1 da spec-mãe ("o acordo nunca é criado aqui"). Medido em 07/08 contra as
    // planilhas reais: 38 dos 392 acordos declarados pela contábil não nascem hoje, porque a única
    // porta de criação é a Receitas — que só cria quando ALGUÉM PAGOU uma parcela. Acordo fechado há
    // poucas semanas, sem nenhum pagamento, não existe para o sistema, e com ele ficam de fora
    // R$ 28.926,43 em parcelas a receber que nenhum relatório enxerga.
    //
    // Os testes vêm em PARES de sentido oposto: T1–T3/T9 provam que o acordo certo é ACEITO, T4–T8
    // provam que o errado é RECUSADO. Só a recusa é o erro que deixa a suíte verde com a importação
    // travada em produção (achado da 2ª revisão do item 6).
    // ---------------------------------------------------------------------------------------------

    #[TestDox('T1 — acordo inexistente é CRIADO com o caso, o status, a data e o número da planilha')]
    public function testAcordoInexistenteEhCriado(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        self::assertSame([999], $resultado->numerosDeAcordosCriados(), 'a aba nasce, não é mais ignorada');
        self::assertSame(0, $resultado->totalAbasIgnoradas());

        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado, 'o acordo tem de existir no banco depois da confirmação');
        self::assertSame($caso->getId(), $criado->getCaso()?->getId(), 'pendurado no caso ATIVO da unidade da aba');
        self::assertSame(StatusAcordo::Ativo, $criado->getStatus(), '"Em andamento" vira Ativo');
        self::assertSame('2026-06-30', $criado->getDataAcordo()->format('Y-m-d'), 'D3: a Data base, não o Criado em');
        self::assertSame(2, $criado->getNumeroParcelasTotal(), 'o "t" do indicador p/t das parcelas');
        // 33998 é o CABEÇALHO da aba; as duas parcelas somam 34000. Os dois números divergem de
        // propósito na fixture — igualá-los deixaria este assert incapaz de distinguir "leu o cabeçalho"
        // de "somou as parcelas", que é a diferença que ele existe para provar.
        self::assertSame(33998, $criado->getValorTotalNegociado(), 'o "Valor final acordado" do cabeçalho, não a soma das parcelas');
        self::assertNotSame(34000, $criado->getValorTotalNegociado());
        self::assertSame($user->getId(), $criado->getCriadoPor()?->getId());
    }

    /**
     * ⚠️ Este teste guarda uma decisão MEDIDA, e a direção dele já foi a oposta: a 1ª revisão pediu um
     * evento de histórico para o acordo criado, e a 2ª mediu o efeito colateral —
     * `TipoEventoHistorico::AcordoCriado` é exatamente o que a **Central de Acompanhamento**, que está
     * em PRODUÇÃO, conta como a coluna "Acordos" do trabalho humano de cobrança
     * (`EventoHistoricoRepository::agregarAtividadePorUsuario`), além de alimentar a "Última ação".
     * Registrá-lo creditaria dezenas de acordos "fechados" num único dia a quem rodou a importação.
     *
     * A procedência não se perde: `numeroExterno` só é preenchido por importação, e as contas
     * reconstruídas carregam "Reconstruída da planilha de acordos (emissão …)" na descrição.
     */
    // ---------------------------------------------------------------------------------------------
    // Violação #3 (removida) e 6ª violação — spec `cobranca-espelho-violacoes-do-importe.md` §2
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 PROVA POR REINTRODUÇÃO da violação #3. `cenarioAcordo37()` cria o acordo 37 pelo importador de
     * INADIMPLÊNCIA (o boleto 61600 carrega `AcordoDoRelatorio(37, 1, 4)`), e esse relatório NÃO traz a
     * data do acordo. Até 17/08 o importador chutava `dataAcordoPadrao()` — o 1º dia do mês da
     * competência, que para a competência 07/2026 daria 2026-07-01.
     *
     * Repor `setDataAcordo($this->dataAcordoPadrao($boleto))` derruba este teste.
     *
     * Medido em produção antes de remover: 375 de 395 acordos com a data chutada.
     */
    #[TestDox('#3: a inadimplencia cria o acordo SEM data — nao chuta o 1o dia da competencia')]
    public function testInadimplenciaCriaAcordoSemData(): void
    {
        [$tenant] = $this->cenarioAcordo37();

        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertNull(
            $acordo->getDataAcordo(),
            'O importador de inadimplência voltou a INVENTAR a data do acordo (violação #3).',
        );
        self::assertFalse($acordo->temData());
    }

    /**
     * 🔑 6ª VIOLAÇÃO — o ramo de ATUALIZAÇÃO preenche a data.
     *
     * É a metade que faz a decisão (B) do dono ser espelho e não regressão. Sem ela o acordo nasceria
     * sem data pela inadimplência e ficaria sem data para sempre, porque `setDataAcordo($aba->dataBase)`
     * só existia no ramo de CRIAÇÃO — e o relatório que TEM a data verdadeira não conseguia corrigi-la.
     *
     * A cadeia inteira, nos dois importadores: inadimplência cria sem data → acordos detalhados preenche.
     */
    #[TestDox('6a violacao: o relatorio de acordos PREENCHE a data do acordo que nasceu sem ela')]
    public function testAcordosDetalhadosPreencheADataQueFaltava(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertNull($acordo->getDataAcordo(), 'pré-condição: nasceu sem data');

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertSame(
            '2026-06-30',
            $acordo->getDataAcordo()?->format('Y-m-d'),
            'O ramo de atualização não preencheu a "Data base" (6ª violação de volta).',
        );
    }

    /**
     * §2.4 — enquanto o acordo não tem data, as obrigações que ele substitui ficam com o encargo NÃO
     * CALCULADO (a tela mostra "— ⚠ acordo sem data"). Quando a data chega, elas são materializadas e o
     * traço vira número. Sem esta parte o traço ficaria na tela para sempre.
     */
    #[TestDox('§2.4: substituidas ficam NAO CALCULADAS sem data e sao materializadas quando ela chega')]
    public function testSubstituidasSaoMaterializadasQuandoADataChega(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);

        // Antes: sem data, nenhuma substituída pode estar calculada.
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertTrue($acordo->temData(), 'a data chegou nesta mesma passada');

        $substituidas = $this->em->getRepository(Obrigacao::class)->findBy(['acordoSubstituto' => $acordo]);
        self::assertNotEmpty($substituidas, 'o cenário tem contas originais trocadas pelo acordo');

        foreach ($substituidas as $substituida) {
            self::assertFalse(
                $substituida->encargosNaoCalculados(),
                'com a data preenchida, nenhuma substituída pode continuar "não calculada"',
            );
            self::assertSame(
                '2026-06-30',
                $substituida->getEncargosAtualizadosEm()?->format('Y-m-d'),
                'o encargo tem de ser materializado NA data do acordo, não em outra',
            );
        }
    }

    /**
     * 🔑 O LAÇO DO BACKFILL — achado 🟡4 da revisão de 17/08.
     *
     * A primeira versão deste teste NÃO provava nada: usava uma passada só, e nela as substituídas ainda
     * não existiam quando o laço rodava (`reconciliarContasOriginais` marca depois, na mesma
     * `processarAba`). A materialização que ele via vinha de lá, não do laço — apagar o `foreach` inteiro
     * deixava tudo verde.
     *
     * A forma que EXERCITA o laço são DUAS passadas, e é o caminho real de produção:
     *   1ª — aba SEM `Data base`: o acordo já existe (veio da inadimplência, sem data), as contas
     *        originais são marcadas como substituídas, mas NÃO são materializadas (sem data não há o que
     *        calcular). Ficam com `encargosNaoCalculados() === true` e a tela mostra o traço.
     *   2ª — aba COM `Data base`: o backfill preenche a data e o laço materializa as que ficaram para trás.
     *
     * 🔴 PROVA POR REINTRODUÇÃO: apagar o `foreach` de `processarAba` derruba este teste (verificado).
     */
    #[TestDox('🟡4: o laco do backfill materializa as substituidas que ficaram para tras')]
    public function testBackfillMaterializaSubstituidasDeUmaPassadaAnterior(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // 1ª passada: a aba não traz "Data base".
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37SemDataBase(), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertNull($acordo->getDataAcordo(), 'sem "Data base", a data continua vazia — nada é inventado');

        $substituidas = $this->em->getRepository(Obrigacao::class)->findBy(['acordoSubstituto' => $acordo]);
        self::assertNotEmpty($substituidas, 'a 1ª passada precisa ter marcado substituídas para o teste valer');
        foreach ($substituidas as $substituida) {
            self::assertTrue(
                $substituida->encargosNaoCalculados(),
                'sem data, a substituída tem de ficar NÃO CALCULADA (a tela mostra o traço)',
            );
        }

        // 2ª passada: agora a aba traz a "Data base". É aqui que o laço do backfill trabalha.
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertSame('2026-06-30', $acordo->getDataAcordo()?->format('Y-m-d'));

        $substituidas = $this->em->getRepository(Obrigacao::class)->findBy(['acordoSubstituto' => $acordo]);
        self::assertNotEmpty($substituidas);
        foreach ($substituidas as $substituida) {
            // ⚠️ Esta assertion NÃO detecta a falha do laço (achado 🔵I da 2ª revisão): depois do backfill
            // o acordo já tem data, então `encargosNaoCalculados()` é `false` mesmo sem materializar. Fica
            // porque descreve o estado final esperado — mas quem PROVA o laço é a assertion seguinte.
            self::assertFalse($substituida->encargosNaoCalculados(), 'estado final: a substituída saiu do traço');
            // 🔴 ESTA é a que morre quando o `foreach` do backfill some: sem ele, `encargosAtualizadosEm`
            // fica na data da hidratação ao vivo, não na data do acordo.
            self::assertSame(
                '2026-06-30',
                $substituida->getEncargosAtualizadosEm()?->format('Y-m-d'),
                'o laço do backfill não materializou esta substituída NA data do acordo',
            );
        }
    }

    /**
     * 🔴 A PRÉVIA NÃO PODE GRAVAR — achado 🟡B da 3ª revisão, e o mais sério dela.
     *
     * O bloco do backfill faz `setDataAcordo` + `salvar($acordo, true)` (**com flush**) + materializa as
     * substituídas. A única coisa entre a prévia e uma escrita real é o guard `if ($usuario !== null)` —
     * e ele não tinha assert nenhum, num caminho que `cenarioAcordo37` percorre em todo teste desta
     * classe (o acordo 37 nasce SEM data).
     *
     * O agravante: `prever()` **não roda em transação** (só `confirmar()` usa `wrapInTransaction`,
     * `:121-126`). Numa regressão aqui, a escrita não seria desfeita — ficaria gravada.
     *
     * A casa já tem a regra "prévia que só consulta o banco mente". Este é o inverso, e é pior: prévia
     * que pode gravar.
     *
     * 🔴 PROVA POR REINTRODUÇÃO: tirar o `if ($usuario !== null)` do backfill derruba este teste.
     */
    #[TestDox('🟡B: a PREVIA nao grava a data do acordo nem materializa as substituidas')]
    public function testPreviaNaoGravaDataNemMaterializa(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // 1ª passada CONFIRMADA sem "Data base": marca as substituídas sem materializar (não há data).
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37SemDataBase(), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertNull($acordo->getDataAcordo(), 'pré-condição: o acordo está sem data');

        $substituidas = $this->em->getRepository(Obrigacao::class)->findBy(['acordoSubstituto' => $acordo]);
        self::assertNotEmpty($substituidas, 'pré-condição: há substituídas esperando a data');
        $antes = [];
        foreach ($substituidas as $substituida) {
            $antes[(int) $substituida->getId()] = $substituida->getEncargosAtualizadosEm()?->format('Y-m-d H:i:s');
        }

        // Agora a PRÉVIA, com a aba que TRAZ a "Data base" — o caminho que gravaria.
        $resultado = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(), $tenant);

        // A prévia DECIDE igual (é o invariante desta classe): ela anuncia o preenchimento...
        $anunciados = array_filter($resultado->porAcordo(), static fn ($a): bool => $a->dataPreenchidaAgora !== null);
        self::assertNotEmpty($anunciados, 'a prévia tem de DECIDIR igual e anunciar o preenchimento');

        // ...mas NÃO escreve. Nem a data...
        //
        // ⚠️ `flush()` ANTES do `clear()`, como o T11 desta mesma classe (`:1051-1053`) documenta: uma
        // sujeira deixada só EM MEMÓRIA pela prévia (ex.: `setDataAcordo` fora do guard, com o `salvar`
        // dentro) seria descartada pelo `clear()` e o teste passaria com o defeito presente. Com o flush,
        // ela é escrita e o assert a pega. Achado da 4ª revisão.
        $this->em->flush();
        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 37]);
        self::assertNotNull($acordo);
        self::assertNull(
            $acordo->getDataAcordo(),
            'A PRÉVIA GRAVOU A DATA DO ACORDO. `prever()` não tem transação: isto ficaria no banco.',
        );

        // ...nem os encargos das substituídas.
        $depois = $this->em->getRepository(Obrigacao::class)->findBy(['acordoSubstituto' => $acordo]);
        self::assertCount(count($antes), $depois);
        foreach ($depois as $substituida) {
            self::assertTrue(
                $substituida->encargosNaoCalculados(),
                'a prévia materializou uma substituída — ela saiu do estado "não calculado"',
            );
            self::assertSame(
                $antes[(int) $substituida->getId()] ?? null,
                $substituida->getEncargosAtualizadosEm()?->format('Y-m-d H:i:s'),
                'a prévia MEXEU nos encargos de uma substituída',
            );
        }
    }

    /** A mesma aba do acordo 37, sem a linha "Data base:" — o caso do arquivo que não traz a data. */
    private function leituraAcordo37SemDataBase(): ResultadoLeituraAcordos
    {
        return new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 37,
            contas: [
                ['60145', '01/2026', '2026-01-13', 17000],
                ['60334', '02/2026', '2026-02-13', 17000],
                ['60812', '04/2026', '2026-04-13', 17000],
                ['61326', '06/2026', '2026-06-13', 17000],
            ],
            parcelas: [
                ['61600', 1, 4, '07/2026', '2026-07-15', 19939],
                ['61601', 2, 4, '08/2026', '2026-08-10', 19939],
                ['61602', 3, 4, '09/2026', '2026-09-10', 19938],
                ['61603', 4, 4, '10/2026', '2026-10-10', 19938],
            ],
            dataBase: null,
        )], [], 0);
    }

    #[TestDox('T1b — o acordo criado NÃO vira evento de trabalho de cobrança (não polui a Central)')]
    public function testAcordoCriadoNaoPoluiACentralDeAcompanhamento(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $antes = $this->contarEventos($caso, TipoEventoHistorico::AcordoCriado);

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        $this->em->clear();
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        self::assertSame($antes, $this->contarEventos($caso, TipoEventoHistorico::AcordoCriado), 'importação não é trabalho de cobrança');
        self::assertTrue(TipoEventoHistorico::AcordoCriado->ehTrabalhoDeCobranca(), 'e é por ISTO que ele não pode ser registrado aqui');
    }

    #[TestDox('T1c — a procedência do acordo criado sobrevive sem o evento')]
    public function testProcedenciaDoAcordoCriadoSobrevive(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertSame(999, $criado->getNumeroExterno(), 'o número externo só é preenchido por importação');

        $reconstruida = $this->obrigacao($tenant, '70001');
        self::assertNotNull($reconstruida);
        self::assertStringContainsString('planilha de acordos', (string) $reconstruida->getDescricao(), 'a dívida que ele substituiu diz de onde veio');
    }

    #[TestDox('T2 — criado o acordo, as parcelas e as contas da aba são processadas na mesma passada')]
    public function testAcordoCriadoTemAAbaProcessada(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        self::assertSame(['70998', '70999'], $resultado->nnsParcelasCriadas(), 'criar o acordo sem processar a aba não entrega nada');
        self::assertSame(34000, $resultado->valorParcelasCriadasCentavos());
        self::assertSame(['70001'], $resultado->nnsContasReconstruidas());

        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertSame($criado->getId(), $this->obrigacao($tenant, '70998')?->getAcordoOrigem()?->getId(), 'a parcela aponta para o acordo recém-criado');
        self::assertSame($criado->getId(), $this->obrigacao($tenant, '70001')?->getAcordoSubstituto()?->getId(), 'a conta original nasce já substituída por ele');
    }

    #[TestDox('T3 — aba "Liquidado" nasce CUMPRIDO, e por ser vigente tem a aba processada')]
    public function testAcordoLiquidadoNasceCumprido(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(situacao: 'Liquidado'), $tenant, $user);

        self::assertSame([999], $resultado->numerosDeAcordosCriados());
        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertSame(StatusAcordo::Cumprido, $criado->getStatus(), '"Liquidado" vira Cumprido — 13 dos 38 medidos são assim');
        self::assertTrue($criado->getStatus()->ehVigente(), 'Cumprido é vigente: a aba NÃO pode ser pulada');
        self::assertSame(['70001'], $resultado->nnsContasReconstruidas(), 'e por isso o conteúdo dela é processado');

        // ⚠️ Sem esta asserção o teste passa com o acordo NASCENDO ERRADO: criado como `Ativo`, a
        // sobrescrita de situação logo abaixo o corrigiria para `Cumprido` e o estado final seria o
        // mesmo. Achado por injeção (o status certo pelo caminho errado deixava tudo verde). A
        // diferença observável é esta: nascer certo não produz sobrescrita nem evento de edição.
        self::assertSame([], $resultado->situacoesSobrescritas(), 'nasceu Cumprido — não nasceu Ativo e foi corrigido depois');
        self::assertNull($resultado->porAcordo()[0]->situacaoSobrescrita);
    }

    #[TestDox('T3b — a forma REAL do "Liquidado": todas as parcelas pagas, acordo nasce sem parcela e SEM aviso falso')]
    public function testAcordoLiquidadoRealNasceSemParcelaESemAvisoFalso(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // ⚠️ Este é o caminho que a produção vai percorrer, e o T3 acima NÃO o exercita. Medido em
        // 07/08 nos 3 arquivos `*_LIQUIDADO` reais: 627 de 627 parcelas trazem data de liquidação, em
        // 310 de 310 abas. Nenhuma aba "Liquidado" tem parcela em aberto — a combinação do T3
        // (Cumprido + parcela aberta) não existe na fonte.
        $resultado = $this->importarAcordos->confirmar(
            $carteiraId,
            $this->leituraAcordoNovo(situacao: 'Liquidado', todasLiquidadas: true),
            $tenant,
            $user,
        );

        self::assertSame([999], $resultado->numerosDeAcordosCriados());
        self::assertSame([], $resultado->nnsParcelasCriadas(), 'parcela paga não é criada (§5): o acordo nasce sem linha de parcela');
        self::assertSame(['70998', '70999'], $resultado->parcelasLiquidadasIgnoradas(), 'e as duas são reportadas, não silenciadas');
        self::assertSame(['70001'], $resultado->nnsContasReconstruidas(), 'a dívida renegociada nasce fora do saldo — que é o ganho deste caso');

        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertSame(StatusAcordo::Cumprido, $criado->getStatus());

        // O aviso "⚠ Faltam N parcelas" da tela do acordo compara `numeroParcelasTotal` com as linhas
        // existentes. Gravar o total num acordo cujas parcelas esta importação se recusa a criar deixaria
        // os 13 acordos Cumpridos desta frente com um aviso permanente e FALSO.
        $this->em->clear();
        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertNull($criado->getNumeroParcelasTotal(), 'sem parcela a materializar, o total não é gravado');
        self::assertFalse($criado->estaIncompleto(), 'e a tela não estampa "faltam parcelas" num acordo cumprido e vazio');
        self::assertSame(0, $criado->parcelasFaltantes());
    }

    #[TestDox('T3c — aba com parcela em aberto CONTINUA gravando o total (o outro sentido do T3b)')]
    public function testAbaComParcelaEmAbertoGravaOTotal(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        $this->em->clear();
        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertSame(2, $criado->getNumeroParcelasTotal(), 'aqui o total é conferível: as 2 parcelas viram linha');
        self::assertFalse($criado->estaIncompleto(), 'e as 2 linhas existem, então nada falta');
    }

    #[TestDox('T14 — unidade com parênteses resolve pela mesma régua dos outros dois relatórios')]
    public function testUnidadeComParentesesResolveOMesmoObjeto(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        // 26 das 325 abas reais da TOP LIFE 1 vêm assim: a identificação seguida das unidades agregadas
        // entre parênteses (`01-01 (05-03,06-01,...)`). O objeto é criado pela inadimplência SEM o
        // parêntese — régua divergente aqui penduraria o acordo no imóvel errado, ou em nenhum.
        $resultado = $this->importarAcordos->confirmar(
            $carteiraId,
            $this->leituraAcordoNovo(unidade: 'QUADRA 05 CHACARA 03/04 (05-03,06-01)'),
            $tenant,
            $user,
        );

        self::assertSame([999], $resultado->numerosDeAcordosCriados(), 'o parêntese não pode fazer a unidade sumir');
        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertSame($caso->getId(), $criado->getCaso()?->getId(), 'o MESMO caso da unidade sem parênteses');
    }

    #[TestDox('T4 — RECUSA: unidade sem objeto na carteira não cria acordo nem escreve nada')]
    public function testUnidadeSemObjetoRecusa(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $obrigacoesAntes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);
        $acordosAntes = $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]);

        $resultado = $this->importarAcordos->confirmar(
            $carteiraId,
            $this->leituraAcordoNovo(unidade: 'QUADRA 99 CHACARA 99/99'),
            $tenant,
            $user,
        );

        self::assertSame([], $resultado->numerosDeAcordosCriados());
        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertStringContainsString('inadimplência', (string) $resultado->porAcordo()[0]->ignoradoPorque, 'o aviso tem de dizer O QUE FAZER');
        self::assertSame([], $resultado->nnsParcelasCriadas());
        self::assertSame([], $resultado->nnsContasReconstruidas());
        self::assertSame($obrigacoesAntes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]), 'D2: este relatório não abre cobrança nova');
        self::assertSame($acordosAntes, $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('T5 — RECUSA: objeto existe mas sem caso ATIVO não cria acordo')]
    public function testObjetoSemCasoAtivoRecusa(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $caso->setStatus(StatusCaso::Encerrado);
        $this->em->flush();
        $this->em->clear();

        $acordosAntes = $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        self::assertSame([], $resultado->numerosDeAcordosCriados(), 'caso encerrado não recebe obrigação (SPEC §17) — nem acordo');
        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertSame($acordosAntes, $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]));
        self::assertSame([], $resultado->nnsContasReconstruidas());
    }

    #[TestDox('T6 — RECUSA: situação fora do mapa não cria acordo (nunca adivinhar status)')]
    public function testSituacaoDesconhecidaNaoCriaAcordo(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(situacao: 'Em negociação'), $tenant, $user);

        self::assertSame([], $resultado->numerosDeAcordosCriados());
        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertNull($this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]));
        self::assertSame([], $resultado->nnsContasReconstruidas());
    }

    #[TestDox('T7 — RECUSA: aba "Cancelado" não cria acordo (não seria vigente)')]
    public function testSituacaoCanceladaNaoCriaAcordo(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(situacao: 'Cancelado'), $tenant, $user);

        self::assertSame([], $resultado->numerosDeAcordosCriados(), 'criá-lo deixaria um acordo vazio: a aba é pulada de qualquer forma');
        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertNull($this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]));
        self::assertSame([], $resultado->nnsContasReconstruidas());
    }

    #[TestDox('T8 — RECUSA: aba sem "Data base" não cria acordo (é a data que para os juros)')]
    public function testAbaSemDataBaseNaoCriaAcordo(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(dataBase: null), $tenant, $user);

        self::assertSame([], $resultado->numerosDeAcordosCriados());
        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertNull($this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]));
        self::assertSame([], $resultado->nnsContasReconstruidas());
    }

    #[TestDox('T9 — D3: a data gravada é a "Data base", NÃO o "Criado em", quando divergem')]
    public function testDataDoAcordoVemDaDataBase(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // A fixture diverge de propósito: Data base 13/07, Criado em 15/07 — a forma do acordo 39 real,
        // em que a secretária digitou o acordo depois da data combinada (lá, 17 dias).
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(dataBase: '2026-07-13'), $tenant, $user);

        $criado = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 999]);
        self::assertNotNull($criado);
        self::assertSame('2026-07-13', $criado->getDataAcordo()->format('Y-m-d'), 'a Data base');

        // A data não é enfeite: é ela que o `materializarNaDataDoAcordo` usa para parar o relógio dos
        // encargos da conta original substituída. Provar a data no acordo e não no EFEITO dela deixaria
        // passar uma implementação que grava a data certa e congela na errada.
        $reconstruida = $this->obrigacao($tenant, '70001');
        self::assertNotNull($reconstruida);
        // `materializarNaDataDoAcordo` MATERIALIZA, não congela (ver o docblock dele): o campo que
        // guarda a data do snapshot é `encargosAtualizadosEm`.
        self::assertSame('2026-07-13', $reconstruida->getEncargosAtualizadosEm()?->format('Y-m-d'), 'o snapshot da renegociada é tirado na Data base');
    }

    #[TestDox('T10 — idempotência: a segunda execução não cria o acordo de novo nem duplica parcela')]
    public function testSegundaExecucaoNaoRecriaOAcordo(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);
        $segunda = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        self::assertSame([], $segunda->numerosDeAcordosCriados(), 'o acordo já existe — nada a criar');
        self::assertSame(1, $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant, 'numeroExterno' => 999]), 'nunca um segundo acordo com o mesmo número');
        self::assertSame([], $segunda->nnsParcelasCriadas());
        self::assertSame(1, $this->contar($tenant, '70998'));
        self::assertSame(['70001'], $segunda->nnsContasJaMarcadas());
    }

    #[TestDox('T11 — a prévia projeta o MESMO que a confirmação efetiva, e NÃO grava acordo nenhum')]
    public function testPreviaNaoGravaAcordoEProjetaOMesmo(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $acordosAntes = $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]);
        $obrigacoesAntes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);

        $previa = $this->importarAcordos->prever($carteiraId, $this->leituraAcordoNovo(), $tenant);

        // Um `persist` escondido no dry-run só aparece quando ALGUÉM dá flush depois — então o flush
        // vem aqui de propósito, antes da contagem. Sem ele o teste passaria com o defeito presente.
        $this->em->flush();
        $this->em->clear();
        self::assertSame($acordosAntes, $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]), 'a prévia não escreve');
        self::assertSame($obrigacoesAntes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]));

        // O `clear()` acima soltou tenant e user da unidade de trabalho; a confirmação precisa deles
        // gerenciados, como a requisição os entregaria.
        $tenant = $this->em->getRepository(Tenant::class)->find($tenant->getId());
        $user = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($tenant);
        self::assertNotNull($user);

        $confirmacao = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        self::assertSame($confirmacao->numerosDeAcordosCriados(), $previa->numerosDeAcordosCriados());
        self::assertSame($confirmacao->nnsParcelasCriadas(), $previa->nnsParcelasCriadas());
        self::assertSame($confirmacao->nnsContasReconstruidas(), $previa->nnsContasReconstruidas());
        self::assertSame($confirmacao->nnsContasMarcadas(), $previa->nnsContasMarcadas());
        self::assertSame($confirmacao->valorParcelasCriadasCentavos(), $previa->valorParcelasCriadasCentavos());
        self::assertSame($confirmacao->principalReconciliadoCentavos(), $previa->principalReconciliadoCentavos());
        self::assertSame($confirmacao->totalAbasIgnoradas(), $previa->totalAbasIgnoradas());

        // Os dois campos que o dry-run IMPRIME sobre o acordo que vai nascer. Ficaram de fora da
        // primeira versão deste teste, e é justamente neles que uma divergência prévia×confirmação
        // passaria despercebida: o operador confere na tela uma situação ou uma data base que a
        // confirmação não vai gravar. A data é a decisão D3 — a que congela os juros.
        $criadoNaPrevia = $previa->porAcordo()[0];
        $criadoNaConfirmacao = $confirmacao->porAcordo()[0];
        self::assertSame($criadoNaConfirmacao->situacaoDoAcordoCriado, $criadoNaPrevia->situacaoDoAcordoCriado);
        self::assertSame(
            $criadoNaConfirmacao->dataDoAcordoCriado?->format('Y-m-d'),
            $criadoNaPrevia->dataDoAcordoCriado?->format('Y-m-d'),
        );
        self::assertSame('2026-06-30', $criadoNaPrevia->dataDoAcordoCriado?->format('Y-m-d'), 'e é a Data base nos DOIS modos');
        self::assertSame('Em andamento', $criadoNaPrevia->situacaoDoAcordoCriado);
    }

    #[TestDox('T12 — acordo criado NÃO é sobrescrita de situação e não vira evento de edição')]
    public function testAcordoCriadoNaoEhSobrescritaDeSituacao(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $eventosAntes = $this->contarEventos($caso, TipoEventoHistorico::AcordoEditado);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordoNovo(), $tenant, $user);

        self::assertSame([], $resultado->situacoesSobrescritas(), 'nascer com o status da planilha não é MUDAR de status');
        self::assertNull($resultado->porAcordo()[0]->situacaoSobrescrita);

        $this->em->clear();
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        self::assertSame($eventosAntes, $this->contarEventos($caso, TipoEventoHistorico::AcordoEditado), '"editado" descreveria errado o que aconteceu');
    }

    /**
     * ⚠️ **O que este teste prova, e o que NÃO prova.** Ele prova que a criação resolve a unidade dentro
     * da CARTEIRA da execução: com a mesma unidade existindo nos dois escritórios, B cria o dele e A não
     * é tocado. Ele **não** consegue provar o filtro `tenant` de
     * `ObjetoCobrancaRepository::findOnePorIdentificacaoNaCarteira` — medido: removendo só esse filtro a
     * suíte fica verde, porque a carteira já é tenant-bound a montante (`resolverCarteira` usa
     * `findOneByIdDoTenant`). Aquele filtro é defesa em profundidade preexistente, de outro escopo;
     * registro aqui para ninguém ler o nome do teste e supor cobertura que ele não tem.
     */
    #[TestDox('T13 — a criação resolve a unidade dentro da carteira: escritório vizinho não é tocado')]
    public function testCriacaoNaoAtravessaTenant(): void
    {
        [$tenantA, $userA, $carteiraA] = $this->cenarioAcordo37();

        // O escritório B tem a MESMA unidade, com o mesmo texto — e nenhum vínculo com a carteira de A.
        $tenantB = $this->criarTenant();
        $userB = $this->criarUser();
        $carteiraB = $this->criarCarteira($tenantB);
        $this->semear($carteiraB, $tenantB, $userB, [
            $this->boleto('80001', competencia: '01/2026', vencimento: '2026-01-13', valor: 17000),
        ]);

        $resultado = $this->importarAcordos->confirmar($carteiraB, $this->leituraAcordoNovo(), $tenantB, $userB);

        self::assertSame([999], $resultado->numerosDeAcordosCriados(), 'B cria o dele, no caso DELE');
        $doB = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenantB, 'numeroExterno' => 999]);
        self::assertNotNull($doB);
        self::assertSame($tenantB->getId(), $doB->getCaso()?->getTenant()?->getId(), 'o caso é do tenant B');
        self::assertNull(
            $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenantA, 'numeroExterno' => 999]),
            'e nada nasceu no escritório A',
        );
    }

    #[TestDox('Acordo com o mesmo número em OUTRA carteira nunca se confunde')]
    public function testAcordoDeOutraCarteiraNaoSeConfunde(): void
    {
        [$tenant, $user] = $this->cenarioAcordo37(); // carteira A: acordo 37 + as 4 originais

        // Carteira B do MESMO escritório, com um acordo 37 SEU e contas de mesmíssimo NN. O número do
        // acordo é sequencial POR CARTEIRA — "acordo 37" existe uma vez em cada uma, e são outros acordos.
        $carteiraB = $this->criarCarteira($tenant);
        $this->semear($carteiraB, $tenant, $user, [
            $this->boleto('60145', competencia: '01/2026', vencimento: '2026-01-13', valor: 17000, objeto: 'OUTRA UNIDADE'),
            $this->boleto('61600', competencia: '07/2026', vencimento: '2026-07-15', valor: 19939, objeto: 'OUTRA UNIDADE', acordo: new AcordoDoRelatorio(37, 1, 4)),
        ]);

        // A importação aponta para a carteira B. Uma busca de acordo SEM escopo de carteira acharia o 37
        // da carteira A (criado antes, id menor) e reconciliaria o caso errado — de outro condomínio.
        $this->importarAcordos->confirmar($carteiraB, $this->leituraAcordo37(), $tenant, $user);

        $daCarteiraA = $this->obrigacaoDoObjeto($tenant, '60145', 'QUADRA 05 CHACARA 03/04');
        $daCarteiraB = $this->obrigacaoDoObjeto($tenant, '60145', 'OUTRA UNIDADE');
        self::assertNotNull($daCarteiraA);
        self::assertNotNull($daCarteiraB);

        self::assertFalse($daCarteiraA->foiSubstituida(), 'a carteira A não foi importada e não pode ser tocada');
        self::assertTrue($daCarteiraB->foiSubstituida(), 'a carteira B, que foi a importada, sim');
    }

    /**
     * A aba cujo status FINAL não é vigente é pulada inteira.
     *
     * Não é só "não mexer no status". Escrever contra um acordo não-vigente **cria dívida**: a conta
     * reconstruída pelo §3.2.1 nasce marcada com `acordoSubstituto`, e `doCasoExigiveis` só exclui o que
     * está substituído por acordo VIGENTE — com o acordo cancelado ela entra no saldo, cobrando de novo
     * uma dívida que a planilha listou como já renegociada. A parcela futura teria o mesmo efeito ao
     * contrário: nasce ligada a um acordo que não vale mais.
     *
     * 🔑 O que MUDOU com a spec `cobranca-importar-acordos-situacao.md`: a guarda passou a consultar o
     * status que a PLANILHA diz, não o que estava no banco. Antes, quem disparava esta guarda era um
     * acordo rompido no sistema; agora esse caso é reativado (ver `testPlanilhaLiquidadoReativaRompido`)
     * e quem a dispara é a planilha dizendo `Cancelado`.
     */
    #[TestDox('Planilha CANCELADO: status é sobrescrito e a aba é pulada — nada é criado nem marcado')]
    public function testPlanilhaCanceladoPulaAAbaInteira(): void
    {
        // Uma das 4 originais NÃO está no sistema: se a aba fosse processada, o §3.2.1 a reconstruiria —
        // e é exatamente essa conta que entraria no saldo por causa do cancelamento.
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37(originaisNoSistema: ['60145', '60334', '60812']);

        $obrigacoesAntes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);
        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant, $user);

        self::assertSame(1, $previsto->totalAbasIgnoradas(), 'a prévia precisa avisar ANTES de alguém mandar gravar');
        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertStringContainsString('cancelado', (string) $resultado->porAcordo()[0]->ignoradoPorque);
        // "Ignorada" NÃO quer dizer "nada foi escrito": a sobrescrita de status é independente das
        // guardas e tem de atravessar para o relatório, senão o operador não vê a escrita que saiu.
        self::assertCount(1, $resultado->situacoesSobrescritas(), 'o status foi gravado mesmo com o conteúdo da aba pulado');
        self::assertStringContainsString('de ativo para cancelado', $resultado->situacoesSobrescritas()[0]);

        self::assertSame([], $resultado->nnsContasReconstruidas(), 'reconstruir aqui criaria dívida: sem acordo vigente a conta nasce EXIGÍVEL');
        self::assertSame([], $resultado->nnsContasMarcadas());
        self::assertSame([], $resultado->nnsParcelasCriadas());

        self::assertSame($obrigacoesAntes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]), 'nenhuma obrigação nasceu');

        // O saldo SE MOVE, e não por escrita nenhuma da aba: cancelar o acordo descarta as parcelas dele
        // por DERIVAÇÃO (invariável 20), então a parcela 61600 — que já existia, ligada ao acordo 37 —
        // sai do exigível. As 3 originais continuam lá: nunca chegaram a ser substituídas.
        self::assertSame(70939, $saldoAntes, '3 originais de R$ 170,00 + a parcela 61600 de R$ 199,39');
        self::assertSame(51000, $this->saldo->saldoExigivel($caso), 'sobram as 3 originais; a parcela do acordo cancelado sai por derivação');

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Cancelado, $acordo->getStatus(), 'o importe sobrescreve o sistema, mesmo quando o conteúdo da aba é pulado');
    }

    /**
     * Spec §10, caso 4 — que não tinha sido escrito (achado da 1ª revisão).
     *
     * Reativar um acordo CANCELADO tem de limpar o `motivoCancelamento`, senão a tela mostra "cancelado
     * porque X" num acordo ativo. O teste da reativação que existia partia de **rompido** e só asseria
     * `getMotivoRompimento()` — a linha que limpa o motivo de CANCELAMENTO nunca era exercitada, e
     * removê-la não quebrava nada.
     */
    #[TestDox('§10 caso 4: reativar acordo CANCELADO limpa o motivoCancelamento')]
    public function testReativarCanceladoLimpaOMotivoDeCancelamento(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        $acordo->setStatus(StatusAcordo::Cancelado);
        $acordo->setMotivoCancelamento('cancelado por engano em 2025');
        $this->em->flush();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Em andamento'), $tenant, $user);

        $this->em->clear();
        $acordoDepois = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordoDepois);
        self::assertSame(StatusAcordo::Ativo, $acordoDepois->getStatus());
        self::assertNull(
            $acordoDepois->getMotivoCancelamento(),
            'motivo do estado que saiu não pode sobreviver: a tela mostraria "cancelado porque X" num acordo ativo',
        );
    }

    /**
     * 🔑 O outro lado da §5.1: quando a reativação REALMENTE tira dinheiro do exigível, o importe tem de
     * DIZER — medido e reportado, nunca corrigido em silêncio.
     *
     * Este é o teste que discrimina `SobrescritaDeSituacao::reativa()`. O assert de "cumprido → ativo não
     * é reativação" não consegue: lá o acordo é vigente e o serviço nem mede, então a lista fica vazia
     * dos dois lados. Aqui o acordo está ROMPIDO e a original tem dinheiro alocado — se `reativa()`
     * passar a devolver `false`, a lista esvazia e este teste cai.
     */
    #[TestDox('🔑 Reativação com dinheiro na original REPORTA o impacto — é o que discrimina reativa()')]
    public function testReativacaoComDinheiroNaOriginalReportaOImpacto(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        // O acordo já substituiu as originais e depois foi ROMPIDO: as originais voltaram ao exigível, e
        // alguém recebeu numa delas enquanto o acordo estava fora do ar.
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);
        $acordo->setStatus(StatusAcordo::Rompido);
        $this->em->flush();

        $original = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '60145']);
        self::assertNotNull($original);
        $this->pagar($original, $tenant, $user, 17000);

        // Agora a planilha diz que o acordo está liquidado → reativa.
        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant, $user);

        $dinheiro = implode(' | ', $resultado->dinheiroParadoPelaReativacao());
        self::assertNotSame('', $dinheiro, 'a reativação tira do exigível uma original COM dinheiro: tem de reportar');
        self::assertStringContainsString('170,00', $dinheiro, 'e QUANTO deixa de abater');
        self::assertStringContainsString('60145', $dinheiro, 'e em QUAL obrigação');

        // A prévia diz o mesmo — o aviso existe para segurar a mão de quem confirma.
        self::assertSame($resultado->dinheiroParadoPelaReativacao(), $previsto->dinheiroParadoPelaReativacao());
    }

    /**
     * §5.3 — A ÚNICA EXCEÇÃO a "o importe sobrescreve sempre" (decisão do dono, 04/08).
     *
     * O cenário é o que o dono descreveu: alguém clica em "receber" numa parcela, e a planilha seguinte
     * diz que o acordo foi cancelado. Aplicar o cancelamento tiraria as parcelas do exigível levando a
     * alocação junto — o dinheiro recebido pararia de abater o saldo e o devedor voltaria a ser cobrado
     * por algo que pagou. O caminho manual RECUSA esse cancelamento; aqui ele vira aviso acionável.
     *
     * ⚠️ O aviso não pode ser só "não deu": tem de dizer o que fazer. A saída existe e é a etapa 1 —
     * excluir o recebimento reabre a parcela e o cancelamento passa na importação seguinte.
     */
    #[TestDox('🔑 §5.3: planilha CANCELADO com parcela PAGA não cancela — avisa para excluir o pagamento')]
    public function testCanceladoComParcelaPagaNaoAplicaEAvisa(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37();

        // Alguém recebeu a parcela 61600 do acordo 37 pela tela.
        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '61600']);
        self::assertNotNull($parcela, 'pré-condição: a parcela do acordo existe');
        $this->pagar($parcela, $tenant, $user, 19939);

        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant, $user);

        // 1) NÃO cancelou.
        self::assertSame([], $resultado->situacoesSobrescritas(), 'nenhum status foi escrito');
        $this->em->clear();
        $acordoDepois = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordoDepois);
        self::assertSame(StatusAcordo::Ativo, $acordoDepois->getStatus(), 'o acordo continua ativo — parcela paga barra o cancelamento');

        // 2) Avisou — em canal PRÓPRIO, não junto das situações não reconhecidas: `Cancelado` FOI
        // reconhecida, e dizer "o importe não adivinha" sobre uma recusa deliberada manda o operador
        // para o lado errado.
        self::assertSame([], $resultado->situacoesDesconhecidas, 'a situação foi reconhecida; só não foi aplicada');
        $aviso = implode(' | ', $resultado->sobrescritasBarradas);
        self::assertStringContainsString('PARCELA PAGA', $aviso);
        self::assertStringContainsString('exclua o recebimento', $aviso, 'o aviso tem de dizer O QUE FAZER, não só que não deu');
        self::assertStringContainsString('37', $aviso, 'e QUAL acordo');

        // 3) A prévia avisou o mesmo — senão quem confere não veria isto antes de mandar gravar.
        self::assertSame($resultado->sobrescritasBarradas, $previsto->sobrescritasBarradas);
        self::assertSame([], $previsto->situacoesSobrescritas());

        // 4) 🔑 O DINHEIRO. É este assert que a §5.3 existe para garantir, e a primeira versão dele não
        // media nada: assertava `getAcordoSubstituto()` na parcela, campo que NENHUM caminho de
        // cancelamento escreve — a parcela sai do exigível por DERIVAÇÃO (`doCasoExigiveis` filtra
        // `aorig.status IN (:vigentes)`), não pelo campo. O assert passava com o cancelamento aplicado.
        //
        // Agora mede o efeito real: com o cancelamento barrado, os R$ 199,39 recebidos continuam
        // abatendo o saldo. Se a barreira cair, a parcela sai do exigível levando a alocação junto e o
        // saldo SOBE — o devedor volta a ser cobrado por algo que pagou.
        //
        // ⚠️ NÃO se mede pelo saldo total: ele muda de propósito, porque a aba foi processada
        // normalmente (o acordo segue vigente) e a reconciliação das originais tirou dívida do exigível.
        // Prender o saldo total aqui seria assert errado sobre a coisa certa.
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        $exigiveis = $this->em->getRepository(Obrigacao::class)->doCasoExigiveis($caso);
        $nnsExigiveis = array_map(static fn (Obrigacao $o): ?string => $o->getReferenciaExterna(), $exigiveis);

        //
        // ⚠️ Honestidade sobre o que ESTE assert é: ele documenta a CONSEQUÊNCIA, não discrimina o
        // defeito. Quebrando a barreira, quem cai primeiro é o assert (1) — este nem chega a rodar. O
        // que prova que a barreira protege dinheiro de verdade é
        // `testAcordoCanceladoTiraAParcelaPagaDoExigivel`, abaixo, que mede o mecanismo direto.
        self::assertContains(
            '61600',
            $nnsExigiveis,
            'a parcela PAGA continua exigível — é isso que faz os R$ 199,39 recebidos seguirem abatendo o saldo',
        );
    }

    /**
     * 🔑 Por que a barreira da §5.3 existe — o mecanismo, medido direto, sem passar pelo importador.
     *
     * Os testes da barreira não conseguem provar isto: quebrando a barreira, o primeiro assert deles
     * ("não cancelou") cai antes de o efeito no dinheiro ser tocado. Este teste fecha a lacuna pelo outro
     * lado — cancela o acordo À MÃO e mede o que acontece com a parcela paga.
     *
     * Se um dia `doCasoExigiveis` deixar de filtrar por `aorig.status IN (:vigentes)`, este teste cai e a
     * barreira vira zelo sem causa. É o único lugar que amarra a justificativa ao comportamento real.
     */
    #[TestDox('🔑 O risco é real: acordo cancelado TIRA a parcela paga do exigível')]
    public function testAcordoCanceladoTiraAParcelaPagaDoExigivel(): void
    {
        [$tenant, $user, , $caso, $acordo] = $this->cenarioAcordo37();

        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '61600']);
        self::assertNotNull($parcela);
        $this->pagar($parcela, $tenant, $user, 19939);

        $repo = $this->em->getRepository(Obrigacao::class);
        $nnsAntes = array_map(static fn (Obrigacao $o): ?string => $o->getReferenciaExterna(), $repo->doCasoExigiveis($caso));
        self::assertContains('61600', $nnsAntes, 'pré-condição: com o acordo vigente, a parcela paga é exigível');

        // O cancelamento que a barreira impede.
        $acordo->setStatus(StatusAcordo::Cancelado);
        $this->em->flush();
        $this->em->clear();

        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        $nnsDepois = array_map(static fn (Obrigacao $o): ?string => $o->getReferenciaExterna(), $repo->doCasoExigiveis($caso));

        self::assertNotContains(
            '61600',
            $nnsDepois,
            'cancelado o acordo, a parcela sai do exigível — e `CalculadoraSaldo` só abate alocação de '
            . 'obrigação EXIGÍVEL, então os R$ 199,39 recebidos param de abater o saldo. É o dano que a §5.3 impede.',
        );
    }

    /**
     * §5.3, segunda recusa: `AcordoComParcelasRenegociadasException` do caminho manual.
     *
     * A primeira versão desta frente replicou só a recusa de parcela paga e afirmou — na spec e no
     * commit — que "some a única contradição com o caminho manual". Não somia: sobrava esta, e a
     * afirmação era falsa (achado da 1ª revisão).
     *
     * Cancelar um acordo cujas parcelas outro acordo VIGENTE renegociou conta a MESMA dívida duas vezes:
     * as originais deste voltam ao exigível (`asub` deixa de ser vigente) e as parcelas do acordo novo
     * continuam exigíveis (`aorig` segue vigente).
     */
    #[TestDox('🔑 §5.3: planilha CANCELADO com parcelas RENEGOCIADAS por acordo vigente não cancela')]
    public function testCanceladoComParcelasRenegociadasNaoAplicaEAvisa(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        // A parcela do acordo 37 foi renegociada por um acordo B, que está VIGENTE — o estado que o
        // INV-I bloqueia criar hoje, mas que dado legado tem.
        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '61600']);
        self::assertNotNull($parcela);
        $acordoB = new Acordo();
        $acordoB->setTenant($tenant);
        $acordoB->setCaso($parcela->getCaso());
        $acordoB->setDataAcordo(new \DateTimeImmutable('2026-07-25'));
        $acordoB->setStatus(StatusAcordo::Ativo);
        $acordoB->setNumeroExterno(999);
        $this->em->persist($acordoB);
        $parcela->setAcordoSubstituto($acordoB);
        $this->em->flush();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant, $user);

        self::assertSame([], $resultado->situacoesSobrescritas(), 'não pode cancelar: contaria a dívida duas vezes');
        $aviso = implode(' | ', $resultado->sobrescritasBarradas);
        self::assertStringContainsString('renegociadas por outro acordo VIGENTE', $aviso);
        self::assertStringContainsString('duas vezes', $aviso, 'o aviso tem de dizer QUAL é o risco');

        $this->em->clear();
        $acordoDepois = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordoDepois);
        self::assertSame(StatusAcordo::Ativo, $acordoDepois->getStatus());
    }

    /**
     * §5.3 — com os DOIS impedimentos, o aviso mostra os dois (achado da 2ª revisão).
     *
     * Reportar só o primeiro fazia o aviso dizer "exclua o recebimento e importe de novo": o operador
     * destruiria um pagamento real — irreversível na prática — e a reimportação continuaria não
     * cancelando, agora pela segunda recusa.
     */
    #[TestDox('🔑 §5.3: com parcela paga E parcelas renegociadas, o aviso mostra os DOIS impedimentos')]
    public function testDoisImpedimentosAparecemJuntos(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '61600']);
        self::assertNotNull($parcela);
        $this->pagar($parcela, $tenant, $user, 19939);

        $acordoB = new Acordo();
        $acordoB->setTenant($tenant);
        $acordoB->setCaso($parcela->getCaso());
        $acordoB->setDataAcordo(new \DateTimeImmutable('2026-07-25'));
        $acordoB->setStatus(StatusAcordo::Ativo);
        $acordoB->setNumeroExterno(998);
        $this->em->persist($acordoB);
        $parcela->setAcordoSubstituto($acordoB);
        $this->em->flush();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant, $user);

        $aviso = implode(' | ', $resultado->sobrescritasBarradas);
        self::assertStringContainsString('DOIS impedimentos', $aviso);
        self::assertStringContainsString('PARCELA PAGA', $aviso);
        self::assertStringContainsString('renegociadas por outro acordo VIGENTE', $aviso);
        self::assertStringContainsString('resolver só um não destrava', $aviso, 'é o que impede o operador de apagar um pagamento à toa');

        self::assertSame([], $resultado->situacoesSobrescritas());
    }

    /**
     * §5.3: a régua é EXISTIR alocação, não ser positiva.
     *
     * `existeAlocacaoEmObrigacoes` e não `totalAlocadoEmObrigacoes(...) > 0`: alocação de valor ZERO é
     * uma linha de pagamento real, com histórico, e a etapa 2 criou 10 delas. Sem este teste, trocar uma
     * pela outra deixa a suíte inteira verde — medido na 1ª revisão.
     */
    #[TestDox('§5.3: alocação de valor ZERO também barra o cancelamento')]
    public function testAlocacaoDeValorZeroTambemBarra(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '61600']);
        self::assertNotNull($parcela);
        $this->pagar($parcela, $tenant, $user, 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant, $user);

        self::assertSame([], $resultado->situacoesSobrescritas(), 'R$ 0,00 alocados ainda é um recebimento registrado');
        self::assertStringContainsString('PARCELA PAGA', implode(' | ', $resultado->sobrescritasBarradas));

        $this->em->clear();
        $acordoDepois = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordoDepois);
        self::assertSame(StatusAcordo::Ativo, $acordoDepois->getStatus());
    }

    // ---------------------------------------------------------------------------------------------
    // Situação do acordo — spec `cobranca-importar-acordos-situacao.md`
    // "o importe sempre sobrescreve o sistema" (decisão do dono, 04/08/2026)
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Planilha LIQUIDADO sobre acordo ativo: status vira cumprido')]
    public function testPlanilhaLiquidadoMarcaCumprido(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Cumprido, $acordo->getStatus());

        self::assertCount(1, $resultado->situacoesSobrescritas());
        self::assertStringContainsString('de ativo para cumprido', $resultado->situacoesSobrescritas()[0]);
        // `Cumprido` é VIGENTE: a aba continua sendo processada normalmente.
        self::assertSame(['61601', '61602', '61603'], $resultado->nnsParcelasCriadas());
    }

    /**
     * O caso mais comum previsto na medição de 04/08: a importação de Receitas marca `Cumprido` quem
     * quitou as parcelas da janela de 2026, e a planilha de Acordos diz `Em andamento` porque ainda há
     * parcela futura. Os dois status são VIGENTES — o saldo não muda de lado.
     */
    #[TestDox('Planilha EM ANDAMENTO sobre acordo cumprido: status volta a ativo e o saldo não se move')]
    public function testPlanilhaEmAndamentoReabreCumprido(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37();

        $acordo->marcarCumprido();
        $this->em->flush();
        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        // ⚠️ Este assert é FRACO por construção e fica registrado como tal (achado da 1ª revisão): no
        // cenário o acordo está `Cumprido`, que é VIGENTE, então `ImpactoDaReativacaoDeAcordo` nem mede —
        // a lista é `[]` nos dois ramos do ternário, e quebrar `reativa()` o deixa verde. Quem discrimina
        // `reativa()` de verdade é `testReativacaoComDinheiroNaOriginalReportaOImpacto`, que parte de um
        // acordo NÃO vigente COM alocação e exige a lista PREENCHIDA. Aqui ele documenta a expectativa.
        self::assertSame([], $resultado->dinheiroParadoPelaReativacao(), 'cumprido → ativo NÃO é reativação: os dois são vigentes');

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Ativo, $acordo->getStatus());

        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        // O saldo se move pela RECONCILIAÇÃO (§3.2), não pela troca de status: saem as 4 originais
        // marcadas (R$ 680,00) e entram as 3 parcelas futuras criadas (R$ 598,15). Cumprido e Ativo são
        // ambos vigentes, então a transição em si não tira nem põe um centavo.
        self::assertSame(87939, $saldoAntes, '4 originais de R$ 170,00 + a parcela 61600 de R$ 199,39');
        self::assertSame($saldoAntes - 68000 + 59815, $this->saldo->saldoExigivel($caso), 'o status não move o saldo; quem move é a reconciliação');
    }

    /**
     * A inversão direta do comportamento anterior: o acordo estava ROMPIDO por decisão manual e a
     * planilha diz `Liquidado`. Antes, a aba inteira era pulada e o status mantido. Agora o importe
     * manda — vira `Cumprido`, que é vigente, e a aba É processada.
     */
    #[TestDox('Planilha LIQUIDADO sobre acordo rompido: reativa e a aba deixa de ser pulada')]
    public function testPlanilhaLiquidadoReativaRompido(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        $acordo->romper('o devedor parou de pagar');
        $this->em->flush();

        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant, $user);

        self::assertSame(0, $resultado->totalAbasIgnoradas(), 'a aba deixou de ser pulada: o status final é vigente');
        self::assertSame(['61601', '61602', '61603'], $resultado->nnsParcelasCriadas());
        self::assertSame(['60145', '60334', '60812', '61326'], $resultado->nnsContasMarcadas());
        self::assertStringContainsString('de rompido para cumprido', $resultado->situacoesSobrescritas()[0]);

        // §6: a prévia decidiu processar a MESMA aba que a confirmação processou. Se a guarda lesse o
        // status do banco em vez do status projetado, a prévia teria pulado esta aba e a confirmação não.
        self::assertSame($previsto->nnsParcelasCriadas(), $resultado->nnsParcelasCriadas());
        self::assertSame($previsto->nnsContasMarcadas(), $resultado->nnsContasMarcadas());
        self::assertSame($previsto->totalAbasIgnoradas(), $resultado->totalAbasIgnoradas());

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Cumprido, $acordo->getStatus());
        self::assertNull($acordo->getMotivoRompimento(), 'motivo do estado que saiu não pode sobreviver num acordo vigente');
    }

    #[TestDox('Reativação: o dry-run NÃO grava o status e projeta a mesma decisão da confirmação')]
    public function testDryRunNaoGravaOStatus(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        $acordo->romper('o devedor parou de pagar');
        $this->em->flush();
        $acordoId = $acordo->getId();

        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant);

        // Um flush qualquer depois da prévia: se o dry-run tivesse chamado `setStatus` na entidade
        // managed, a mudança que ele prometeu não fazer seria gravada AQUI.
        $this->em->flush();
        $this->em->clear();

        $acordo = $this->em->getRepository(Acordo::class)->find($acordoId);
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Rompido, $acordo->getStatus(), 'a prévia não escreve — nem por efeito colateral de UnitOfWork');
        self::assertSame('o devedor parou de pagar', $acordo->getMotivoRompimento());

        // Mas PROJETA a decisão, senão o operador não vê o que vai acontecer.
        self::assertCount(1, $previsto->situacoesSobrescritas());
        self::assertStringContainsString('de rompido para cumprido', $previsto->situacoesSobrescritas()[0]);
    }

    /**
     * ⚠️ O aviso mais grave desta importação. O acordo foi rompido, as originais voltaram ao exigível e
     * alguém RECEBEU dinheiro numa delas. Reativar tira a original do exigível de novo — e
     * `CalculadoraSaldo` só abate alocação de obrigação exigível, então o valor recebido **para de
     * abater o saldo**: o devedor volta a ser cobrado por algo que pagou.
     *
     * O importador não decide para onde esse dinheiro vai. Mede e põe na frente de quem confirma.
     */
    #[TestDox('Reativação com pagamento numa original: o dinheiro que para de abater é medido e reportado')]
    public function testReativacaoReportaDinheiroQueParaDeAbater(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37();

        // 1) Importa normalmente: as 4 originais ficam substituídas pelo acordo vigente.
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        // 2) O acordo é rompido à mão: as originais voltam ao exigível.
        [$caso, $acordo] = $this->casoEAcordo($tenant, 37);
        $acordo->romper('o devedor parou de pagar');
        $this->em->flush();

        // 3) Alguém recebe R$ 170,00 numa original que voltou.
        $original = $this->obrigacao($tenant, '60145');
        self::assertNotNull($original);
        $this->registrarPagamentoEm($original, 17000, $tenant, $user);

        // 4) A planilha seguinte diz "Em andamento" — o importe reativa.
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        $avisos = $resultado->dinheiroParadoPelaReativacao();
        self::assertCount(1, $avisos, 'exatamente a original que tem dinheiro alocado');
        self::assertStringContainsString('60145', $avisos[0]);
        self::assertStringContainsString('170,00', $avisos[0]);
        self::assertNotSame([], $resultado->impactoDaReativacaoNoSaldo(), 'o impacto no saldo é canal separado do dinheiro parado');
        self::assertTrue($resultado->temAvisos(), 'reativação com dinheiro parado TEM de acender o bloco A CONFERIR');
    }

    /**
     * §5.2 — a direção que devolve dívida à cobrança. O acordo estava vigente e substituía as 4
     * originais; a planilha diz `Cancelado`. As originais voltam ao exigível — e têm de voltar
     * **descongeladas**, senão voltam com os juros PARADOS (§D5: o defeito que o dono reportou e que a
     * frente `cobranca-cancelar-acordo` corrigiu).
     *
     * 🔑 A original congelada aqui é semeada à mão, e isso é fiel ao domínio: `liquidar()` é hoje o
     * único ponto que congela, e o restaurador PULA a liquidada (INV-C2). Quem o descongelamento
     * realmente alcança é o **legado** — obrigação com `encargosCongeladosEm` sem `liquidadaEm` —, o
     * mesmo caso que `materializarNaDataDoAcordo` já trata com um early-return. Sem semear esse estado,
     * o teste passaria com a chamada ao restaurador REMOVIDA, que é como um assert vacuoso nasce.
     */
    #[TestDox('Planilha CANCELADO: a original legada congelada volta ao saldo DESCONGELADA (§D5)')]
    public function testCancelarPelaPlanilhaDescongelaAsOriginais(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        // Legado: congelada sem estar liquidada. É o estado que o restaurador existe para desfazer.
        $legada = $this->obrigacao($tenant, '60145');
        self::assertNotNull($legada);
        $legada->congelarEncargos(new \DateTimeImmutable('2026-03-01'));
        $this->em->flush();

        // 1) Importa normalmente: as 4 originais ficam substituídas e saem do exigível.
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);
        [$caso] = $this->casoEAcordo($tenant, 37);
        $saldoComAcordo = $this->saldo->saldoExigivel($caso);
        self::assertTrue($this->obrigacao($tenant, '60145')?->encargosCongelados(), 'a marcação preserva o snapshot do legado');

        // 2) A planilha seguinte diz "Cancelado".
        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Cancelado'), $tenant, $user);

        $this->em->clear();
        [$caso, $acordo] = $this->casoEAcordo($tenant, 37);
        self::assertSame(StatusAcordo::Cancelado, $acordo->getStatus());

        $original = $this->obrigacao($tenant, '60145');
        self::assertNotNull($original);
        self::assertFalse($original->encargosCongelados(), 'sem o descongelamento a original volta ao saldo com os juros PARADOS (§D5)');

        // As 4 originais (R$ 680,00) voltam inteiras ao exigível e TODAS as parcelas do acordo saem —
        // as 3 criadas e a 61600 que já existia. O saldo cai em relação ao acordo vigente porque as
        // parcelas somavam mais que as originais que elas substituíam.
        self::assertSame(79754, $saldoComAcordo);
        self::assertSame(68000, $this->saldo->saldoExigivel($caso), 'só as 4 originais: nenhuma parcela de acordo cancelado conta');
    }

    #[TestDox('Idempotência: reimportar com a mesma situação não reescreve status nem registra evento')]
    public function testSobrescritaEhIdempotente(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $primeira = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant, $user);
        self::assertCount(1, $primeira->situacoesSobrescritas());

        [$caso] = $this->casoEAcordo($tenant, 37);
        $eventosApos1a = $this->contarEventos($caso, TipoEventoHistorico::AcordoEditado);

        $segunda = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant, $user);

        self::assertSame([], $segunda->situacoesSobrescritas(), 'a planilha e o sistema já concordam: nada a sobrescrever');
        [$caso] = $this->casoEAcordo($tenant, 37);
        self::assertSame($eventosApos1a, $this->contarEventos($caso, TipoEventoHistorico::AcordoEditado), 'nem um evento a mais');
    }

    #[TestDox('A sobrescrita fica registrada no histórico do caso, com origem e status anterior')]
    public function testSobrescritaRegistraEvento(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(situacao: 'Liquidado'), $tenant, $user);

        $this->em->clear();
        [$caso] = $this->casoEAcordo($tenant, 37);
        $eventos = $this->em->getRepository(EventoHistorico::class)->findBy(
            ['caso' => $caso, 'tipo' => TipoEventoHistorico::AcordoEditado],
        );

        self::assertCount(1, $eventos);
        $dados = $eventos[0]->getDados() ?? [];
        self::assertSame('importacao_acordos_detalhados', $dados['origem'] ?? null);
        self::assertSame('ativo', $dados['statusAnterior'] ?? null);
        self::assertSame('cumprido', $dados['statusNovo'] ?? null);
        self::assertSame('Liquidado', $dados['situacaoDaPlanilha'] ?? null);
    }

    #[TestDox('Situação desconhecida não altera o status: reporta e mantém (§3.3)')]
    public function testSituacaoDesconhecidaReportaEMantem(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37();

        $leitura = $this->leituraAcordo37(situacao: 'Em revisão pelo jurídico');
        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Ativo, $acordo->getStatus());
        self::assertCount(1, $resultado->situacoesDesconhecidas);
        self::assertStringContainsString('Em revisão pelo jurídico', $resultado->situacoesDesconhecidas[0]);
    }

    #[TestDox('Parcela liquidada que JÁ EXISTE é avisada, mas a baixa de pagamento NÃO é feita (§5)')]
    public function testParcelaLiquidadaSoAvisa(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // 61600 é a parcela 1/4 que o cenário já semeou: existe no sistema, aberta.
        $leitura = $this->leituraAcordo37(liquidadas: ['61600']);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertContains('61600', $resultado->parcelasLiquidadasNaPlanilha);
        self::assertFalse($this->obrigacao($tenant, '61600')?->estaLiquidada(), 'baixa de pagamento é irreversível na prática — fica fora desta entrega');
        self::assertTrue($resultado->temAvisos());
    }

    /**
     * Parcela que a planilha diz PAGA e que **não existe** no sistema: não é criada.
     *
     * Criá-la abriria uma dívida vencida, com juros e multa correndo, para cobrar de novo o que já foi
     * pago — a mesma "direção que cobra" que o importador recusa no NN ambíguo. Dar baixa também não é
     * opção: a liquidação está fora de escopo (§5), é irreversível na prática, e não se escreve pagamento
     * a partir de planilha. Então recusa e reporta.
     *
     * ⚠️ O teste irmão acima NÃO cobre isto: lá o NN já existe no sistema, então ele passa com o defeito.
     * A diferença entre os dois é o cenário, não a asserção — e era ela que faltava.
     */
    #[TestDox('Parcela paga na planilha e AUSENTE do sistema não é criada — criá-la cobraria de novo')]
    public function testParcelaLiquidadaAusenteNaoEhCriada(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $saldoAntes = $this->saldo->saldoExigivel($caso);
        self::assertSame(0, $this->contar($tenant, '61602'), 'a 3/4 ainda não existe em lugar nenhum');

        // A planilha diz que a parcela 3/4 (61602) já foi paga.
        $leitura = $this->leituraAcordo37(liquidadas: ['61602']);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertContains('61602', $feito->parcelasLiquidadasIgnoradas());
        self::assertSame($previsto->parcelasLiquidadasIgnoradas(), $feito->parcelasLiquidadasIgnoradas());
        self::assertNotContains('61602', $feito->nnsParcelasCriadas(), 'criar uma parcela paga é cobrar duas vezes');
        self::assertSame(0, $this->contar($tenant, '61602'), 'e ela não está no banco');

        // As outras parcelas ausentes (61601 e 61603) continuam sendo criadas normalmente.
        self::assertContains('61601', $feito->nnsParcelasCriadas());
        self::assertContains('61603', $feito->nnsParcelasCriadas());

        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        self::assertSame(
            $saldoAntes - 68000 + 19939 + 19938,
            $this->saldo->saldoExigivel($caso),
            'saem as 4 originais (R$ 680,00) e entram só as DUAS parcelas em aberto — a paga não entra',
        );
        self::assertTrue($feito->temAvisos());
    }

    /**
     * A prévia não pode divergir da confirmação quando o MESMO NN+competência aparece nas duas seções da
     * aba — como parcela e como conta original.
     *
     * Sem estado intra-execução, `completarParcelas` cria e flusha a parcela ANTES de a reconciliação
     * rodar: no dry-run o banco não muda, então a projeção diria "reconstruída" e o efeito diria
     * "recusada por INV-I". É o mesmo defeito que o importador de cadastro tinha, do outro lado.
     */
    #[TestDox('NN listado como parcela E como conta original: prévia e confirmação dizem a mesma coisa')]
    public function testNnEmDuasSecoesNaoDivergeEntrePreviaEConfirmacao(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // A planilha lista 61602 (parcela 3/4, que não existe no sistema) TAMBÉM como conta original.
        $leitura = $this->leituraAcordo37(contasExtras: [['61602', '09/2026', '2026-09-10', 19938]]);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($previsto->nnsContasReconstruidas(), $feito->nnsContasReconstruidas(), 'a prévia não pode prometer uma reconstrução que a confirmação recusa');
        self::assertSame($previsto->contasRecusadas(), $feito->contasRecusadas());
        self::assertNotContains('61602', $feito->nnsContasReconstruidas());
        self::assertNotEmpty(array_filter($feito->contasRecusadas(), static fn (string $r): bool => str_contains($r, '61602')));
        self::assertSame(1, $this->contar($tenant, '61602'), 'existe UMA obrigação com esse NN: a parcela');
    }

    // ---------------------------------------------------------------------------------------------
    // O acordo novo assume as parcelas do anterior
    // (spec `docs/specs/cobranca-acordo-assume-parcelas-do-anterior.md`)
    //
    // A INV-I recusava "acordo sobre acordo" SEM EXCEÇÃO. Só que renegociar parcela de acordo é a
    // operação normal da contábil, que documenta cada uma na coluna F ("Acordo 163 - Parcela 4/12").
    // Sem ler a coluna, a recusa cega passou a CAUSAR a dobra que existia para impedir: medido no
    // `saas_ux_zero`, 302 parcelas velhas / R$ 67.469,44 no saldo AO LADO das parcelas novas que as
    // substituem. A prova documental é a condição de aceitar — sem ela, a recusa continua idêntica.
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Com a prova da coluna F, o acordo novo ASSUME a parcela do anterior e ela sai do saldo')]
    public function testAssumeParcelaDoAcordoAnteriorComProvaDaColunaF(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $saldoAntes = $this->saldo->saldoExigivel($caso);
        $parcela = $this->obrigacao($tenant, '61600');
        self::assertNotNull($parcela);
        self::assertSame(37, $parcela->getAcordoOrigem()?->getNumeroExterno(), 'o cenário precisa da parcela ligada ao acordo 37');

        // O acordo 88 renegocia a parcela 1/4 do acordo 37 — exatamente a forma do 393 real, que
        // assumiu "Acordo 348 - Parcela 2/40".
        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 88,
            contas: [['61600', '07/2026', '2026-07-15', 19939, 37, '1/4']],
            parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
        )], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertContains('61600', $resultado->nnsContasMarcadas());
        self::assertSame([], $resultado->contasRecusadas(), 'com prova documental não há recusa');

        $this->em->refresh($parcela);
        self::assertTrue($parcela->foiSubstituida());
        self::assertSame(37, $parcela->getAcordoOrigem()?->getNumeroExterno(), 'o vínculo com o acordo antigo NÃO se rompe — é dele que vem a rastreabilidade');
        self::assertSame(88, $parcela->getAcordoSubstituto()?->getNumeroExterno());

        // O efeito que importa: a parcela velha (R$ 199,39) sai do saldo e entra a nova (R$ 210,00).
        // Sem a mudança as duas ficariam, e o devedor seria cobrado por R$ 409,39.
        self::assertSame($saldoAntes - 19939 + 21000, $this->saldo->saldoExigivel($caso));
    }

    #[TestDox('Sem a coluna F, a recusa da INV-I continua idêntica (é a maioria das contas originais)')]
    public function testSemAColunaFAINVIContinuaRecusando(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 88,
            contas: [['61600', '07/2026', '2026-07-15', 19939]],
            parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
        )], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61600', $resultado->nnsContasMarcadas());
        self::assertFalse($this->obrigacao($tenant, '61600')?->foiSubstituida());

        $recusadas = $resultado->contasRecusadas();
        self::assertCount(1, $recusadas);
        self::assertStringContainsString('INV-I', $recusadas[0]);
        self::assertStringContainsString('não declara de qual acordo ela é parcela', $recusadas[0], 'o motivo exato: quem confere precisa saber que falta a declaração, não que as fontes discordam');
        self::assertSame($saldoAntes + 21000, $this->saldo->saldoExigivel($caso), 'a parcela velha continua no saldo');
    }

    #[TestDox('Coluna F apontando OUTRO acordo é recusa: as duas fontes discordam da procedência')]
    public function testColunaFApontandoOutroAcordoEhRecusada(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // No sistema a 61600 é parcela do acordo 37; a planilha declara o acordo 12.
        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 88,
            contas: [['61600', '07/2026', '2026-07-15', 19939, 12, '3/9']],
            parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
        )], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61600', $resultado->nnsContasMarcadas());
        self::assertFalse($this->obrigacao($tenant, '61600')?->foiSubstituida());
        $recusadas = $resultado->contasRecusadas();
        self::assertCount(1, $recusadas);
        self::assertStringContainsString('as duas fontes discordam', $recusadas[0]);
    }

    #[TestDox('A conferência é pelo número EXTERNO do acordo, nunca pelo id interno')]
    public function testConfereProcedenciaPeloNumeroExternoENaoPeloIdInterno(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        // No dado real os dois números divergem sempre (id é sequência do sistema, número externo vem
        // da contábil). Se o código comparasse pelo id, este teste passaria com o número errado — e no
        // banco de produção marcaria como substituída a parcela de um acordo qualquer.
        $idInterno = (int) $acordo->getId();
        self::assertNotSame(37, $idInterno, 'o cenário exige id interno ≠ número externo, senão o assert não distingue nada');

        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 88,
            contas: [['61600', '07/2026', '2026-07-15', 19939, $idInterno, '1/4']],
            parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
        )], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61600', $resultado->nnsContasMarcadas(), 'declarar o id interno não é prova de nada');
        self::assertFalse($this->obrigacao($tenant, '61600')?->foiSubstituida());
    }

    #[TestDox('Acordo não substitui parcela de SI MESMO, ainda que a planilha declare')]
    public function testAcordoNaoSubstituiParcelaDeSiMesmo(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // A aba 37 lista a própria parcela 61600 como conta original, declarando "Acordo 37". Aceitar
        // deixaria a obrigação com acordoOrigem e acordoSubstituto na MESMA linha — estado que nenhuma
        // query de exigibilidade sabe responder.
        $leitura = $this->leituraAcordo37(contasExtras: [
            ['61600', '07/2026', '2026-07-15', 19939, 37, '1/4'],
        ]);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61600', $resultado->nnsContasMarcadas());
        $parcela = $this->obrigacao($tenant, '61600');
        self::assertFalse($parcela?->foiSubstituida());
        self::assertStringContainsString('PRÓPRIO acordo desta aba', implode(' ', $resultado->contasRecusadas()));
    }

    #[TestDox('Parcela de acordo NÃO vigente não é assumida: ela já está fora do saldo')]
    public function testNaoAssumeParcelaDeAcordoNaoVigente(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        $acordo->setStatus(StatusAcordo::Rompido);
        $this->em->flush();

        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 88,
            contas: [['61600', '07/2026', '2026-07-15', 19939, 37, '1/4']],
            parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
        )], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61600', $resultado->nnsContasMarcadas());
        self::assertFalse($this->obrigacao($tenant, '61600')?->foiSubstituida());
        self::assertStringContainsString('não está mais vigente', implode(' ', $resultado->contasRecusadas()));
    }

    #[TestDox('Porta B: a parcela criada por uma aba ANTERIOR da mesma execução também é assumida')]
    public function testAssumeParcelaCriadaNaMesmaExecucao(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        // Duas abas no mesmo arquivo: a 37 cria a parcela 61601 (2/4, que não existe no sistema) e a 88,
        // logo depois, a assume. É a porta que produziu 285 das 286 recusas da importação do zero.
        $leitura = new ResultadoLeituraAcordos([
            $this->leituraAcordo37()->acordos[0],
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['61601', '08/2026', '2026-08-10', 19939, 37, '2/4']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
        ], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertContains('61601', $resultado->nnsParcelasCriadas(), 'a aba 37 cria a parcela');
        self::assertContains('61601', $resultado->nnsContasMarcadas(), 'e a aba 88 a assume, na mesma execução');
        self::assertSame([], $resultado->contasRecusadas());

        $parcela = $this->obrigacao($tenant, '61601');
        self::assertSame(37, $parcela?->getAcordoOrigem()?->getNumeroExterno());
        self::assertSame(88, $parcela?->getAcordoSubstituto()?->getNumeroExterno());
        self::assertNotNull($caso);
    }

    #[TestDox('Porta B: prévia e confirmação projetam o MESMO principal (no dry-run a parcela nem existe)')]
    public function testPortaBNaoDivergeEntrePreviaEConfirmacao(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // ⚠️ O valor da conta original na planilha do 88 (R$ 190,00) DIFERE de propósito do valor com
        // que a parcela 61601 nasce na aba do 37 (R$ 199,39). Sem essa diferença o teste ficaria verde
        // lendo qualquer um dos dois, e não provaria de onde o principal sai. O certo é o que está no
        // sistema — 19939 —, porque `principalReconciliadoCentavos` é o principal que SAI do saldo.
        $leitura = new ResultadoLeituraAcordos([
            $this->leituraAcordo37()->acordos[0],
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['61601', '08/2026', '2026-08-10', 19000, 37, '2/4']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
        ], [], 0);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($previsto->nnsContasMarcadas(), $feito->nnsContasMarcadas());
        self::assertSame($previsto->contasRecusadas(), $feito->contasRecusadas());

        // 68000 = as 4 contas originais do acordo 37 (R$ 170,00 cada) + 19939 da parcela assumida.
        self::assertSame(
            68000 + 19939,
            $feito->principalReconciliadoCentavos(),
            'o principal que sai do saldo é o da obrigação no sistema, não o que a planilha do sucessor declara',
        );
        self::assertSame(
            $previsto->principalReconciliadoCentavos(),
            $feito->principalReconciliadoCentavos(),
            'é o número que o operador confere antes de mandar gravar; no dry-run a parcela não existe no banco, então ele TEM de sair do acumulador',
        );
    }

    #[TestDox('A importação NÃO desativa acordo cujas parcelas ela mesma renegociou (a ordem das abas não decide)')]
    public function testNaoDesativaAcordoComParcelaRenegociadaNaMesmaExecucao(): void
    {
        [$tenant, $user, $carteiraId, , $acordo] = $this->cenarioAcordo37();

        // A aba 88 assume a parcela do 37 (vem primeiro), e depois a aba do 37 chega dizendo
        // "Cancelado". Desativar aqui devolveria as originais do 37 ao saldo COM as parcelas do 88
        // dentro dele — a mesma dívida duas vezes, que é o dano do §2.1 do ajuste 9.
        $leitura = new ResultadoLeituraAcordos([
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['61600', '07/2026', '2026-07-15', 19939, 37, '1/4']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
            $this->acordoDaPlanilha(
                numero: 37,
                contas: [['60145', '01/2026', '2026-01-13', 17000]],
                parcelas: [['61600', 1, 4, '07/2026', '2026-07-15', 19939]],
                situacao: 'Cancelado',
            ),
        ], [], 0);

        // ⚠️ A PRÉVIA é o assert que importa aqui, e é ela que quase ficou de fora. Na confirmação a
        // marcação já foi para o banco quando a aba do 37 chega, então a query de
        // `parcelasRenegociadasPorAcordoVigente` enxerga tudo e a guarda funcionaria mesmo sem o
        // acumulador. No dry-run nada é gravado: sem consultar o acumulador, a prévia prometeria
        // "vou cancelar o acordo 37" e a confirmação não cancelaria — a divergência que a §6 proíbe.
        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotEmpty($previsto->sobrescritasBarradas, 'a prévia tem de avisar que o cancelamento NÃO vai acontecer');
        self::assertStringContainsString('renegociadas por outro acordo VIGENTE', implode(' ', $previsto->sobrescritasBarradas));
        self::assertSame($previsto->sobrescritasBarradas, $feito->sobrescritasBarradas, 'prévia e confirmação têm de dizer a mesma coisa sobre a desativação');

        $this->em->refresh($acordo);
        self::assertSame(StatusAcordo::Ativo, $acordo->getStatus(), 'o acordo antigo não pode ser desativado enquanto o novo carrega parcelas dele');
    }

    #[TestDox('Porta B: coluna F apontando outro acordo é recusada também na mesma execução')]
    public function testPortaBRecusaColunaFApontandoOutroAcordo(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // A aba 37 cria a parcela 61601; a aba 88 declara que ela é parcela do acordo 12. As duas portas
        // têm de recusar igual — se uma aceitasse e a outra não, o resultado dependeria da ordem do
        // arquivo, que é o defeito que a spec §8.3 proíbe.
        $leitura = new ResultadoLeituraAcordos([
            $this->leituraAcordo37()->acordos[0],
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['61601', '08/2026', '2026-08-10', 19939, 12, '3/9']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
        ], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61601', $resultado->nnsContasMarcadas());
        self::assertNull($this->obrigacao($tenant, '61601')?->getAcordoSubstituto());
        self::assertStringContainsString('as duas fontes discordam', implode(' ', $resultado->contasRecusadas()));
    }

    #[TestDox('Porta B lê a vigência do acordo AGORA: uma aba anterior pode tê-lo desativado')]
    public function testPortaBRecusaQuandoAbaAnteriorDesativouOAcordoDeOrigem(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo37] = $this->cenarioAcordo37();

        $saldoAntes = $this->saldo->saldoExigivel($caso);

        // A brecha de ORDEM, no sentido que a guarda de desativação não alcança: a aba 37 cria a parcela
        // (vigente), uma SEGUNDA aba 37 no mesmo lote a cancela — e aí, se a porta B supusesse que o
        // acordo de origem continua vigente, a aba 88 marcaria a parcela dele. Resultado: as originais
        // do 37 voltam ao saldo (cancelamento) E as parcelas do 88 entram nele. A mesma dívida duas
        // vezes — o dano do §2.1 do ajuste 9, entrando pela porta que a spec abriu.
        $leitura = new ResultadoLeituraAcordos([
            $this->leituraAcordo37()->acordos[0],
            $this->acordoDaPlanilha(
                numero: 37,
                contas: [],
                parcelas: [['61601', 2, 4, '08/2026', '2026-08-10', 19939]],
                situacao: 'Cancelado',
            ),
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['61601', '08/2026', '2026-08-10', 19939, 37, '2/4']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
        ], [], 0);

        // ⚠️ A PRÉVIA roda primeiro e tem de dizer a MESMA coisa. É aqui que mora a armadilha: quem
        // decide a vigência é `aplicarSobrescrita`, que só grava na confirmação — então perguntar o
        // status à entidade daria "ainda vigente" na prévia e "cancelado" na confirmação, e a porta B
        // aceitaria num modo e recusaria no outro. Quem responde é a DECISÃO registrada no acumulador,
        // que é tomada igual nos dois.
        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($previsto->nnsContasMarcadas(), $resultado->nnsContasMarcadas());
        self::assertSame($previsto->contasRecusadas(), $resultado->contasRecusadas());
        self::assertSame($previsto->principalReconciliadoCentavos(), $resultado->principalReconciliadoCentavos());

        $this->em->refresh($acordo37);
        self::assertSame(StatusAcordo::Cancelado, $acordo37->getStatus(), 'o cenário exige que a aba do meio tenha mesmo desativado o acordo');
        self::assertNotContains('61601', $resultado->nnsContasMarcadas());
        self::assertNull($this->obrigacao($tenant, '61601')?->getAcordoSubstituto());
        self::assertStringContainsString('não está mais vigente', implode(' ', $resultado->contasRecusadas()));

        // ⚠️ O efeito no dinheiro, medido e não suposto: com o 37 cancelado as 4 originais dele voltam
        // ao saldo (R$ 680,00), as parcelas dele saem, e entra a parcela do 88 (R$ 210,00) — R$ 890,00.
        // A recusa acima NÃO é o que segura esse número: a parcela 61601 já está fora do saldo por ser
        // de acordo não vigente, então marcá-la ou não daria o mesmo total. Esta guarda existe pela
        // COERÊNCIA do estado (a mesma régua da porta A: não se registra substituição contra acordo
        // morto), e é por isso que a prova dela são os asserts de recusa, não este.
        self::assertSame(68000 + 21000, $this->saldo->saldoExigivel($caso));
        self::assertSame(87939, $saldoAntes, 'ancora o cenário: 4 boletos de R$ 170,00 + a parcela de R$ 199,39');
    }

    #[TestDox('O sentido inverso: aba que REATIVA o acordo de origem também não diverge entre os modos')]
    public function testReativacaoDoAcordoDeOrigemNaoDivergeEntreOsModos(): void
    {
        [$tenant, $user, $carteiraId, , $acordo37] = $this->cenarioAcordo37();

        // A desativação é só uma das direções. A REATIVAÇÃO (rompido/cancelado → vigente) é caminho
        // normal deste importador, e produz a divergência no sentido oposto: a prévia recusaria (o banco
        // ainda diz "rompido") e a confirmação aceitaria, tirando dinheiro do saldo sem ter projetado.
        $acordo37->setStatus(StatusAcordo::Rompido);
        $this->em->flush();

        $leitura = new ResultadoLeituraAcordos([
            // a aba 37 reativa o acordo (Em andamento) e vincula a parcela…
            $this->acordoDaPlanilha(
                numero: 37,
                contas: [],
                parcelas: [['61600', 1, 4, '07/2026', '2026-07-15', 19939]],
            ),
            // …e a aba 88 a assume, com a prova da coluna F.
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['61600', '07/2026', '2026-07-15', 19939, 37, '1/4']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
        ], [], 0);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($previsto->nnsContasMarcadas(), $feito->nnsContasMarcadas(), 'a prévia decide pela situação que a PLANILHA declara, igual à confirmação');
        self::assertSame($previsto->contasRecusadas(), $feito->contasRecusadas());
        self::assertSame($previsto->principalReconciliadoCentavos(), $feito->principalReconciliadoCentavos());
        self::assertContains('61600', $feito->nnsContasMarcadas(), 'reativado pela planilha, o acordo volta a ser origem válida');
    }

    #[TestDox('Coluna F que declara a própria aba E discorda do sistema: a recusa diz que as fontes discordam')]
    public function testRecusaDizQualInvestigacaoResolve(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        // A 61600 é parcela do acordo 37 no sistema, e a coluna F declara o número da PRÓPRIA aba (88).
        // As duas recusas cabem — mas quem confere precisa investigar a divergência entre planilha e
        // sistema, não uma autorreferência que é só consequência.
        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 88,
            contas: [['61600', '07/2026', '2026-07-15', 19939, 88, '1/4']],
            parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
        )], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertNotContains('61600', $resultado->nnsContasMarcadas());
        $recusa = implode(' ', $resultado->contasRecusadas());
        self::assertStringContainsString('as duas fontes discordam', $recusa);
        self::assertStringNotContainsString('PRÓPRIO acordo desta aba', $recusa, 'mandaria o conferente investigar a coisa errada');
    }

    #[TestDox('Porta B: a PRÉVIA não grava nada, nem quando a parcela já existia no banco')]
    public function testPortaBNaPreviaNaoGrava(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo37] = $this->cenarioAcordo37();

        // O caminho `parcela-vinculada`: a obrigação JÁ EXISTE no banco e esta execução a liga ao acordo.
        // Nele o acumulador guarda a entidade real — inclusive no dry-run, onde nada foi escrito. Sem a
        // guarda de `$usuario`, a prévia marcaria e congelaria os encargos de uma obrigação de verdade,
        // fora de transação, sem o operador ter autorizado.
        $leitura = new ResultadoLeituraAcordos([
            $this->acordoDaPlanilha(
                numero: 37,
                contas: [],
                parcelas: [['60145', 1, 1, '01/2026', '2026-01-13', 17000]],
            ),
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['60145', '01/2026', '2026-01-13', 17000, 37, '1/1']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
        ], [], 0);

        $antes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);
        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);

        // `clear()` e não `refresh()`: a pergunta é "o que está NO BANCO", e `refresh` numa entidade suja
        // esconderia justamente um `set` sem flush. Tenant e usuário são reencontrados depois, porque o
        // clear os desanexa.
        $tenantId = (int) $tenant->getId();
        $userId = (int) $user->getId();
        $this->em->clear();

        $tenant = $this->em->find(Tenant::class, $tenantId);
        $user = $this->em->find(User::class, $userId);
        self::assertNotNull($tenant);
        self::assertNotNull($user);

        $alvo = $this->obrigacao($tenant, '60145');
        self::assertNotNull($alvo);
        self::assertNull($alvo->getAcordoSubstituto(), 'a PRÉVIA não pode gravar substituição');
        self::assertNull($alvo->getAcordoOrigem(), 'a PRÉVIA não pode gravar o vínculo de parcela');
        self::assertSame($antes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]));

        [$caso2] = $this->casoEAcordo($tenant, 37);
        self::assertSame($saldoAntes, $this->saldo->saldoExigivel($caso2), 'dry-run não mexe no saldo');

        // E a projeção continua dizendo o que a confirmação fará.
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);
        self::assertSame($previsto->nnsContasMarcadas(), $feito->nnsContasMarcadas());
        self::assertSame($previsto->principalReconciliadoCentavos(), $feito->principalReconciliadoCentavos());
        self::assertNotNull($acordo37);
        self::assertNotNull($caso);
    }

    #[TestDox('Porta B NÃO troca em silêncio um substituto que já existia')]
    public function testPortaBNaoTrocaSubstitutoExistente(): void
    {
        [$tenant, $user, $carteiraId, , $acordo37] = $this->cenarioAcordo37();

        // O estado que torna isto alcançável: `completarParcelas` vincula olhando SÓ `acordoOrigem`, sem
        // olhar o substituto. Uma obrigação já substituída por um acordo vigente chega à porta B como
        // "parcela-vinculada" e, sem a guarda, o `setAcordoSubstituto` a repontaria em silêncio — o
        // vínculo anterior é a única memória de quem a tirou do saldo.
        $anterior = $this->criarAcordoVigenteVazio($tenant, $acordo37);
        $solta = $this->obrigacao($tenant, '60145');
        self::assertNotNull($solta);
        $solta->setAcordoSubstituto($anterior);
        $this->em->flush();

        $leitura = new ResultadoLeituraAcordos([
            // a aba 37 lista 60145 como PARCELA (vincula acordoOrigem = 37)…
            $this->acordoDaPlanilha(
                numero: 37,
                contas: [],
                parcelas: [['60145', 1, 1, '01/2026', '2026-01-13', 17000]],
            ),
            // …e a aba 88 tenta assumi-la com a prova da coluna F.
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['60145', '01/2026', '2026-01-13', 17000, 37, '1/1']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
        ], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        $this->em->refresh($solta);
        self::assertSame($anterior->getId(), $solta->getAcordoSubstituto()?->getId(), 'o substituto anterior não pode ser trocado em silêncio');
        self::assertNotContains('60145', $resultado->nnsContasMarcadas());
        $recusa = implode(' ', $resultado->contasRecusadas());
        self::assertStringContainsString('já substituída pelo acordo 555', $recusa, 'a linha do relatório identifica o acordo pelo número da CONTÁBIL — o id interno não existe em fonte nenhuma para quem confere');
        self::assertNotSame(555, $anterior->getId(), 'o cenário exige id interno ≠ número externo, senão o assert não distingue nada');
        self::assertSame(0, $resultado->principalReconciliadoCentavos(), 'não pode somar como "sai do saldo" uma dívida que já estava fora');
    }

    #[TestDox('Cadeia de três acordos: cada parcela sai do saldo uma vez só')]
    public function testCadeiaDeTresAcordosNaoDobraDivida(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $saldoAntes = $this->saldo->saldoExigivel($caso);

        // 37 → 88 → 96, a forma real do 31 → 211 → 396. A parcela do 37 é assumida pelo 88, e a
        // parcela do 88 é assumida pelo 96.
        $leitura = new ResultadoLeituraAcordos([
            $this->acordoDaPlanilha(
                numero: 88,
                contas: [['61600', '07/2026', '2026-07-15', 19939, 37, '1/4']],
                parcelas: [['70500', 1, 1, '11/2026', '2026-11-10', 21000]],
            ),
            $this->acordoDaPlanilha(
                numero: 96,
                contas: [['70500', '11/2026', '2026-11-10', 21000, 88, '1/1']],
                parcelas: [['70600', 1, 1, '12/2026', '2026-12-10', 22000]],
            ),
        ], [], 0);

        $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        $meio = $this->obrigacao($tenant, '70500');
        self::assertSame(88, $meio?->getAcordoOrigem()?->getNumeroExterno());
        self::assertSame(96, $meio?->getAcordoSubstituto()?->getNumeroExterno(), 'a obrigação do meio da cadeia tem origem E substituto');

        // Só a ponta da cadeia cobra: −199,39 (parcela do 37) e +220,00 (parcela do 96). A parcela do
        // 88 (R$ 210,00) entra e sai na mesma execução.
        self::assertSame($saldoAntes - 19939 + 22000, $this->saldo->saldoExigivel($caso));
    }

    // ---------------------------------------------------------------------------------------------
    // Dry-run
    // ---------------------------------------------------------------------------------------------

    #[TestDox('Dry-run não escreve NADA e projeta exatamente o que a confirmação faz')]
    public function testDryRunNaoEscreveEProjetaOMesmo(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $antes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);
        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(), $tenant);

        self::assertSame($antes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]), 'dry-run não cria');
        self::assertSame($saldoAntes, $this->saldo->saldoExigivel($caso), 'dry-run não mexe no saldo');

        $feito = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        // TODOS os campos, não uma amostra: comparar só os cinco "bonitos" deixava passar divergência em
        // recusadas/ambíguas/já-marcadas — que é exatamente onde ela apareceu.
        self::assertSame($this->retrato($previsto), $this->retrato($feito), 'a projeção tem de bater com o efeito, campo a campo');
    }

    /**
     * Retrato COMPLETO do resultado, para comparar prévia × confirmação sem escolher a dedo o que olhar.
     *
     * @return array<string, mixed>
     */
    private function retrato(\App\Cobranca\Service\Importacao\ResultadoImportacaoAcordos $r): array
    {
        return [
            'parcelasCriadas' => $r->nnsParcelasCriadas(),
            'parcelasExistentes' => $r->nnsParcelasExistentes(),
            'parcelasVinculadas' => $r->parcelasVinculadas(),
            'parcelasAmbiguas' => $r->parcelasAmbiguas(),
            'parcelasLiquidadasIgnoradas' => $r->parcelasLiquidadasIgnoradas(),
            'contasMarcadas' => $r->nnsContasMarcadas(),
            'contasReconstruidas' => $r->nnsContasReconstruidas(),
            'contasJaMarcadas' => $r->nnsContasJaMarcadas(),
            'contasRecusadas' => $r->contasRecusadas(),
            'casadasSemCompetencia' => $r->casadasSemCompetencia(),
            'divergenciasDeValor' => $r->divergenciasDeValor(),
            'situacoesSobrescritas' => $r->situacoesSobrescritas(),
            'dinheiroParadoPelaReativacao' => $r->dinheiroParadoPelaReativacao(),
            'impactoDaReativacaoNoSaldo' => $r->impactoDaReativacaoNoSaldo(),
            'situacoesDesconhecidas' => $r->situacoesDesconhecidas,
            'conferenciasCabecalho' => $r->conferenciasCabecalho,
            'liquidadasNaPlanilha' => $r->parcelasLiquidadasNaPlanilha,
            'abasIgnoradas' => $r->totalAbasIgnoradas(),
            'principal' => $r->principalReconciliadoCentavos(),
            'valorParcelas' => $r->valorParcelasCriadasCentavos(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Casos-limite que a revisão de 31/07 apontou como não cobertos
    // ---------------------------------------------------------------------------------------------

    /**
     * Com encargos REAIS na carteira (a factory zera tudo de propósito), marcar a conta original
     * materializa juros/multa na DATA DO ACORDO — o mesmo que o `CriarAcordoUseCase` faz pela tela.
     *
     * O que isto guarda: a obrigação substituída deixa de ser hidratada ao vivo, então o número gravado
     * aqui é o que a tela passa a exibir para sempre. Sem a materialização ela mostraria o cache da
     * última vez que alguém abriu a página — uma data arbitrária — em vez do valor que valia quando a
     * dívida foi renegociada. E o SALDO não pode mudar por causa disso: substituída por acordo vigente
     * está fora do exigível de qualquer jeito.
     */
    #[TestDox('Com encargos ≠ 0: marcar materializa juros/multa na data do acordo, sem mexer no saldo')]
    public function testMarcarMaterializaEncargosNaDataDoAcordo(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        // 1% ao mês de juros simples e 2% de multa — próximo do padrão TOPLIFE, longe do neutro.
        $carteiraId = $this->criarCarteira($tenant, ['taxaJurosMensalBp' => 100, 'taxaMultaBp' => 200]);

        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('60145', competencia: '01/2026', vencimento: '2026-01-13', valor: 17000),
            $this->boleto('61600', competencia: '07/2026', vencimento: '2026-07-15', valor: 19939, acordo: new AcordoDoRelatorio(37, 1, 4)),
        ]);
        [$caso, $acordo] = $this->casoEAcordo($tenant, 37);

        $saldoAntes = $this->saldo->saldoExigivel($caso);

        // O relógio ANTES: a inadimplência materializou os encargos na data da importação (hoje).
        $jurosAntes = $this->obrigacao($tenant, '60145')?->getJuros();
        self::assertNotNull($jurosAntes);
        self::assertGreaterThan(0, $jurosAntes, 'o cenário só discrimina se a carteira gerar juros de verdade');

        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 37,
            contas: [['60145', '01/2026', '2026-01-13', 17000]],
            parcelas: [['61600', 1, 4, '07/2026', '2026-07-15', 19939]],
        )], [], 0);

        $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        $this->em->clear();
        $marcada = $this->obrigacao($tenant, '60145');
        self::assertNotNull($marcada);
        self::assertTrue($marcada->foiSubstituida());

        // O relógio DEPOIS: parou em 15/07/2026 (data do acordo), que é ANTERIOR a hoje — então o juros
        // gravado tem de ter DIMINUÍDO. "Maior que zero" não provaria nada: já era maior que zero antes.
        self::assertLessThan($jurosAntes, $marcada->getJuros(), 'sem a materialização o número continuaria o da data da importação');
        self::assertGreaterThan(0, $marcada->getJuros(), 'mas continua havendo juros — de 13/01 a 15/07');
        self::assertGreaterThan(0, $marcada->getMulta());

        // Saldo: só a parcela do acordo continua exigível. A original saiu — com encargos e tudo.
        $caso = $this->em->getRepository(CasoCobranca::class)->find($caso->getId());
        self::assertNotNull($caso);
        self::assertLessThan($saldoAntes, $this->saldo->saldoExigivel($caso), 'tirar a original do exigível derruba o saldo');
    }

    #[TestDox('Caso ENCERRADO: aba pulada e reportada — sem isso o UseCase derrubaria o lote inteiro')]
    public function testCasoEncerradoPulaAAba(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37(originaisNoSistema: ['60145', '60334', '60812']);

        $caso->setStatus(StatusCaso::Encerrado);
        $this->em->flush();

        $antes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertStringContainsString('ENCERRADO', (string) $resultado->porAcordo()[0]->ignoradoPorque);
        self::assertSame($antes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]));
    }

    /**
     * Conta original já substituída por OUTRO acordo: a importação recusa e reporta. Remarcar moveria a
     * dívida de um acordo para outro em silêncio — e ao romper o primeiro, ela não voltaria para onde
     * deveria.
     */
    #[TestDox('Conta já substituída por OUTRO acordo não é remarcada — recusa reportada')]
    public function testContaJaSubstituidaPorOutroAcordoEhRecusada(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo37] = $this->cenarioAcordo37();

        // Um segundo acordo no mesmo caso já tomou a conta 60145 para si.
        $outro = new Acordo();
        $outro->setTenant($tenant);
        $outro->setCaso($caso);
        $outro->setStatus(StatusAcordo::Ativo);
        $outro->setDataAcordo(new \DateTimeImmutable('2026-06-01'));
        $outro->setNumeroExterno(99);
        $outro->setCriadoPor($user);
        $this->em->persist($outro);
        $conta = $this->obrigacao($tenant, '60145');
        self::assertNotNull($conta);
        $conta->setAcordoSubstituto($outro);
        $this->em->flush();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        $this->em->clear();
        $conta = $this->obrigacao($tenant, '60145');
        self::assertSame($outro->getId(), $conta?->getAcordoSubstituto()?->getId(), 'o acordo 99 continua sendo o dono');
        self::assertNotContains('60145', $resultado->nnsContasMarcadas());

        $recusadas = $resultado->contasRecusadas();
        self::assertNotEmpty(array_filter($recusadas, static fn (string $r): bool => str_contains($r, '60145')));
        self::assertTrue($resultado->temAvisos());
    }

    /**
     * Parcela existente porém SOLTA (sem `acordoOrigem`) passa a apontar para o acordo. Não muda saldo
     * hoje; o que muda é o dia do rompimento — a invariável 20 descarta as parcelas POR DERIVAÇÃO, e
     * derivação precisa do vínculo. Sem ele a original volta ao exigível E a parcela órfã fica: a mesma
     * dívida contada duas vezes.
     */
    #[TestDox('Parcela que já existia solta passa a apontar para o acordo — e a prévia mostra isso')]
    public function testParcelaExistenteRecebeAcordoOrigem(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37();

        // Desfaz o vínculo que a inadimplência criou: reproduz a parcela lançada à mão ou importada
        // antes de o acordo ser reconhecido.
        $parcela = $this->obrigacao($tenant, '61600');
        self::assertNotNull($parcela);
        $parcela->setAcordoOrigem(null);
        $this->em->flush();

        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(), $tenant);
        self::assertContains('61600', $previsto->parcelasVinculadas(), 'é escrita: tem de aparecer na prévia');

        $feito = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);
        self::assertSame($previsto->parcelasVinculadas(), $feito->parcelasVinculadas());

        $this->em->clear();
        $parcela = $this->obrigacao($tenant, '61600');
        self::assertSame($acordo->getId(), $parcela?->getAcordoOrigem()?->getId());
    }

    /**
     * Parcela cujo NN já existe no caso com OUTRA competência: a importação NÃO cria.
     *
     * As duas chaves da spec discordam aqui (§7 diz "por NN", §3.2 exige NN+competência). Criar é a
     * direção que COBRA — adicionaria dinheiro ao saldo a partir de um casamento duvidoso. Não criar só
     * adia, e o resumo devolve a decisão para uma pessoa.
     */
    #[TestDox('Parcela com NN ambíguo (mesmo NN, outra competência) não é criada — reporta e devolve a decisão')]
    public function testParcelaComNnAmbiguoNaoEhCriada(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        $antes = $this->contar($tenant, '61601');
        self::assertSame(0, $antes);

        // O sistema já tem o NN 61601 no caso, mas em 12/2025 — a planilha traz esse NN em 08/2026.
        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61601', competencia: '12/2025', vencimento: '2025-12-10', valor: 17000),
        ]);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertContains('61601', $resultado->parcelasAmbiguas());
        self::assertNotContains('61601', $resultado->nnsParcelasCriadas());
        self::assertSame(1, $this->contar($tenant, '61601'), 'continua existindo UMA, a de 12/2025');
        self::assertSame(17000, $this->obrigacaoPorCompetencia($tenant, '61601', '12/2025')?->getValorOriginal(), 'e ela não foi tocada');
        self::assertTrue($resultado->temAvisos());
    }

    /**
     * DUAS abas do MESMO caso listando a mesma conta original. Nenhum cenário desta classe tinha isso, e
     * é onde a prévia inflava o número que a spec manda conferir.
     *
     * Dois acordos da mesma unidade compartilham o `CasoCobranca` (caso é por objeto). Sem registrar a
     * MARCAÇÃO no estado da execução, a prévia via a obrigação intocada nas duas abas e somava o
     * principal duas vezes; a confirmação marcava na 1ª e recusava na 2ª. `principalReconciliadoCentavos()`
     * é o "R$ 680,00 que sai do saldo" do §1 — inflá-lo é mentir no único número que o operador confere.
     */
    #[TestDox('Mesma conta em DUAS abas do mesmo caso: o principal não é contado duas vezes')]
    public function testMesmaContaEmDuasAbasDoMesmoCasoNaoDobraOPrincipal(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        // Um segundo acordo (38) no MESMO caso, nascido pela inadimplência como os outros.
        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61700', competencia: '11/2026', vencimento: '2026-11-10', valor: 15000, acordo: new AcordoDoRelatorio(38, 1, 1)),
        ]);

        // As duas abas listam a MESMA conta original 60145 (R$ 170,00), que existe e está aberta.
        $umaConta = [['60145', '01/2026', '2026-01-13', 17000]];
        $leitura = new ResultadoLeituraAcordos([
            $this->acordoDaPlanilha(numero: 37, contas: $umaConta, parcelas: [['61600', 1, 4, '07/2026', '2026-07-15', 19939]]),
            $this->acordoDaPlanilha(numero: 38, contas: $umaConta, parcelas: [['61700', 1, 1, '11/2026', '2026-11-10', 15000]]),
        ], [], 0);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($this->retrato($previsto), $this->retrato($feito), 'a projeção tem de bater com o efeito, campo a campo');
        self::assertSame(17000, $feito->principalReconciliadoCentavos(), 'a conta sai do saldo UMA vez, não duas');
        self::assertSame(['60145'], $feito->nnsContasMarcadas());
        self::assertNotEmpty(array_filter($feito->contasRecusadas(), static fn (string $r): bool => str_contains($r, '60145')), 'a 2ª aba precisa reportar por que não marcou');

        // E no banco: a obrigação pertence ao PRIMEIRO acordo que a reclamou, não ao último.
        $this->em->clear();
        [, $acordo37] = $this->casoEAcordo($tenant, 37);
        self::assertSame($acordo37->getId(), $this->obrigacao($tenant, '60145')?->getAcordoSubstituto()?->getId());
    }

    /**
     * O gap de chave que a terceira revisão achou: o **fallback do legado** faz dois trios diferentes
     * resolverem para a MESMA obrigação.
     *
     * Uma obrigação sem competência gravada (dado anterior ao backfill) casa com QUALQUER competência da
     * planilha. Se a planilha listar o mesmo NN em duas competências, `X|C1` e `X|C2` são chaves distintas
     * que devolvem a mesma linha: sem indexar também pela obrigação resolvida, a prévia soma o principal
     * dela duas vezes e a confirmação soma uma.
     *
     * ⚠️ É o caminho que **produção** terá (~30 obrigações sem competência) e que o replay do dev **não
     * exercitou** — lá não existe uma obrigação com competência nula sequer.
     */
    #[TestDox('Fallback legado: dois trios que resolvem para a MESMA obrigação não contam o principal duas vezes')]
    public function testFallbackLegadoNaoContaOMesmoPrincipalDuasVezes(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37(originaisNoSistema: ['60145']);

        // Dado legado: a obrigação perde a competência, como as ~30 que o backfill não alcançou.
        $legada = $this->obrigacao($tenant, '60145');
        self::assertNotNull($legada);
        $legada->setCompetencia(null);
        $this->em->flush();

        // A planilha lista o MESMO NN em duas competências — dois trios, uma obrigação só no banco.
        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 37,
            contas: [
                ['60145', '01/2026', '2026-01-13', 17000],
                ['60145', '02/2026', '2026-02-13', 17000],
            ],
            parcelas: [['61600', 1, 4, '07/2026', '2026-07-15', 19939]],
        )], [], 0);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($this->retrato($previsto), $this->retrato($feito), 'a projeção tem de bater com o efeito, campo a campo');
        self::assertSame(17000, $feito->principalReconciliadoCentavos(), 'é UMA obrigação: R$ 170,00 saem do saldo, não R$ 340,00');
        self::assertSame(['60145'], $feito->nnsContasMarcadas());
        self::assertNotEmpty($feito->casadasSemCompetencia(), 'o casamento pelo fallback tem de ser reportado');
    }

    /**
     * O segundo gap de chave: a guarda de ambiguidade da parcela pergunta por `(caso, NN)` **sem
     * competência**, então não enxerga o que a execução criou sob outro trio.
     *
     * Aba 1 cria `X|C1`; aba 2 traz `X|C2`. Sem o índice por NN, a prévia criaria as duas e a confirmação
     * recusaria a segunda por ambiguidade — divergindo no `valorParcelasCriadasCentavos`, que é o outro
     * número do §1 (o que ENTRA no saldo).
     */
    #[TestDox('Parcela criada por uma aba torna ambígua a de outra competência na aba seguinte — nos dois modos')]
    public function testParcelaCriadaEmOutraAbaTornaAProximaAmbigua(): void
    {
        [$tenant, $user, $carteiraId, $caso] = $this->cenarioAcordo37();

        // Segundo acordo no MESMO caso (mesma unidade).
        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61700', competencia: '11/2026', vencimento: '2026-11-10', valor: 15000, acordo: new AcordoDoRelatorio(38, 1, 1)),
        ]);

        // As duas abas trazem o NN 61601, em competências diferentes. Nenhuma existe no sistema.
        $leitura = new ResultadoLeituraAcordos([
            $this->acordoDaPlanilha(numero: 37, contas: [], parcelas: [['61601', 2, 4, '08/2026', '2026-08-10', 19939]]),
            $this->acordoDaPlanilha(numero: 38, contas: [], parcelas: [['61601', 1, 1, '12/2026', '2026-12-10', 15000]]),
        ], [], 0);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($this->retrato($previsto), $this->retrato($feito), 'a projeção tem de bater com o efeito, campo a campo');
        self::assertSame(['61601'], $feito->nnsParcelasCriadas(), 'a primeira aba cria');
        self::assertSame(['61601'], $feito->parcelasAmbiguas(), 'a segunda recusa — criar seria adicionar dinheiro a partir de casamento duvidoso');
        self::assertSame(19939, $feito->valorParcelasCriadasCentavos());
        self::assertSame(1, $this->contar($tenant, '61601'));
    }

    /**
     * Cobre o ramo `parcela-vinculada` do registro — que o revisor apontou como **não exercitado**: nos
     * outros testes as parcelas nascem do `semear` já com `acordoOrigem`.
     */
    #[TestDox('Parcela solta vinculada por uma aba não é revinculada nem recontada pela aba seguinte')]
    public function testParcelaVinculadaNaoEhRecontadaPelaAbaSeguinte(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo37] = $this->cenarioAcordo37();

        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61700', competencia: '11/2026', vencimento: '2026-11-10', valor: 15000, acordo: new AcordoDoRelatorio(38, 1, 1)),
        ]);

        // 61600 existe mas SOLTA — o cenário da parcela lançada antes de o acordo ser reconhecido.
        $parcela = $this->obrigacao($tenant, '61600');
        self::assertNotNull($parcela);
        $parcela->setAcordoOrigem(null);
        $this->em->flush();

        // As DUAS abas listam a mesma parcela 61600.
        $umaParcela = [['61600', 1, 4, '07/2026', '2026-07-15', 19939]];
        $leitura = new ResultadoLeituraAcordos([
            $this->acordoDaPlanilha(numero: 37, contas: [], parcelas: $umaParcela),
            $this->acordoDaPlanilha(numero: 38, contas: [], parcelas: $umaParcela),
        ], [], 0);

        $previsto = $this->importarAcordos->prever($carteiraId, $leitura, $tenant);
        $feito = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($this->retrato($previsto), $this->retrato($feito), 'a projeção tem de bater com o efeito, campo a campo');
        self::assertSame(['61600'], $feito->parcelasVinculadas(), 'vinculada UMA vez, pela primeira aba');

        $this->em->clear();
        self::assertSame($acordo37->getId(), $this->obrigacao($tenant, '61600')?->getAcordoOrigem()?->getId(), 'fica com o acordo que a reclamou primeiro');
    }

    #[TestDox('Carteira de OUTRO escritório: a importação nunca a alcança, nem com o mesmo número de acordo')]
    public function testNaoCruzaTenant(): void
    {
        // Escritório A: dono da carteira e do acordo 37.
        [$tenantA, $userA, $carteiraA] = $this->cenarioAcordo37();

        // Escritório B, independente, com um acordo 37 SEU e os mesmíssimos NNs.
        $tenantB = $this->criarTenant();
        $userB = $this->criarUser();
        $carteiraB = $this->criarCarteira($tenantB);
        $this->semear($carteiraB, $tenantB, $userB, [
            $this->boleto('60145', competencia: '01/2026', vencimento: '2026-01-13', valor: 17000),
            $this->boleto('61600', competencia: '07/2026', vencimento: '2026-07-15', valor: 19939, acordo: new AcordoDoRelatorio(37, 1, 4)),
        ]);

        // O escritório A tenta importar na carteira DE B: a carteira não é dele, e nada acontece.
        $this->expectException(CarteiraNaoEncontradaException::class);
        try {
            $this->importarAcordos->confirmar($carteiraB, $this->leituraAcordo37(), $tenantA, $userA);
        } finally {
            $this->em->clear();
            $daB = $this->obrigacao($tenantB, '60145');
            self::assertNotNull($daB);
            self::assertFalse($daB->foiSubstituida(), 'a dívida do outro escritório não pode ser tocada');
        }
    }

    // =============================================================================================
    // Cenários
    // =============================================================================================

    /**
     * Reproduz o acordo 37 REAL como produção o tem hoje: 4 contas originais abertas (R$ 680,00) e a
     * parcela 1/4 já importada da inadimplência (R$ 199,39) — a mesma dívida contada duas vezes.
     * As parcelas 2/4, 3/4 e 4/4 ainda não existem em lugar nenhum.
     *
     * `$originaisNoSistema` controla quais das 4 contas o SISTEMA já conhece (a planilha lista sempre as
     * 4). É assim que se produz o cenário do §3.2.1 — conta que nunca foi importada e será reconstruída.
     *
     * @param list<string> $originaisNoSistema
     *
     * @return array{0: Tenant, 1: User, 2: int, 3: CasoCobranca, 4: Acordo}
     */
    /**
     * Recebe uma obrigação como a tela receberia: `Pagamento` + `AlocacaoPagamento`. Usado pelo teste da
     * §5.3, que precisa do estado "alguém clicou em receber" ANTES de a planilha dizer `Cancelado`.
     */
    private function pagar(Obrigacao $obrigacao, Tenant $tenant, User $user, int $centavos): void
    {
        $pagamento = new Pagamento();
        $pagamento->setTenant($tenant);
        $pagamento->setCaso($obrigacao->getCaso());
        $pagamento->setData(new \DateTimeImmutable('2026-07-20'));
        $pagamento->setValorDivida($centavos);
        $pagamento->setValorEncargos(0);
        $pagamento->setValorHonorarios(0);
        $pagamento->setCriadoPor($user);

        $alocacao = new AlocacaoPagamento();
        $alocacao->setTenant($tenant);
        $alocacao->setObrigacao($obrigacao);
        $alocacao->setValor($centavos);
        $pagamento->adicionarAlocacao($alocacao);

        $this->em->persist($pagamento);
        $this->em->flush();
    }

    /** Um acordo vigente sem parcelas, no mesmo caso — só para ocupar o papel de substituto anterior. */
    private function criarAcordoVigenteVazio(Tenant $tenant, Acordo $modelo, int $numeroExterno = 555): Acordo
    {
        $acordo = (new Acordo())
            ->setTenant($tenant)
            ->setCaso($modelo->getCaso())
            ->setDataAcordo($modelo->getDataAcordo())
            ->setNumeroExterno($numeroExterno)
            ->setStatus(StatusAcordo::Ativo);
        $this->em->persist($acordo);
        $this->em->flush();

        return $acordo;
    }

    private function cenarioAcordo37(array $originaisNoSistema = ['60145', '60334', '60812', '61326']): array
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant);

        $originais = [
            '60145' => $this->boleto('60145', competencia: '01/2026', vencimento: '2026-01-13', valor: 17000),
            '60334' => $this->boleto('60334', competencia: '02/2026', vencimento: '2026-02-13', valor: 17000),
            '60812' => $this->boleto('60812', competencia: '04/2026', vencimento: '2026-04-13', valor: 17000),
            '61326' => $this->boleto('61326', competencia: '06/2026', vencimento: '2026-06-13', valor: 17000),
        ];

        $this->semear($carteiraId, $tenant, $user, [
            ...array_values(array_intersect_key($originais, array_flip($originaisNoSistema))),
            $this->boleto('61600', competencia: '07/2026', vencimento: '2026-07-15', valor: 19939, acordo: new AcordoDoRelatorio(37, 1, 4)),
        ]);

        [$caso, $acordo] = $this->casoEAcordo($tenant, 37);

        return [$tenant, $user, $carteiraId, $caso, $acordo];
    }

    /**
     * Acordo 31: as contas originais NUNCA foram importadas (viraram acordo na contábil antes de
     * qualquer importação passar por elas) — é o caso das 21 contas do §3.2.1.
     *
     * @return array{0: Tenant, 1: User, 2: int, 3: CasoCobranca, 4: Acordo}
     */
    private function cenarioAcordo31(): array
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant);

        $this->semear($carteiraId, $tenant, $user, [
            $this->boleto('61372', competencia: '07/2026', vencimento: '2026-07-01', valor: 40068, acordo: new AcordoDoRelatorio(31, 1, 3)),
        ]);

        [$caso, $acordo] = $this->casoEAcordo($tenant, 31);

        return [$tenant, $user, $carteiraId, $caso, $acordo];
    }

    /**
     * A aba do acordo 37 como a planilha real a traz: 4 contas originais e as 4 parcelas.
     *
     * @param list<array{0:string,1:string,2:string,3:int}> $contasExtras
     * @param array<string, int>      $valorOriginalDe sobrescreve o valor de uma conta na PLANILHA
     * @param list<string>            $liquidadas
     */
    private function leituraAcordo37(
        array $contasExtras = [],
        array $valorOriginalDe = [],
        array $liquidadas = [],
        string $situacao = 'Em andamento',
    ): ResultadoLeituraAcordos {
        $contas = [
            ['60145', '01/2026', '2026-01-13', 17000],
            ['60334', '02/2026', '2026-02-13', 17000],
            ['60812', '04/2026', '2026-04-13', 17000],
            ['61326', '06/2026', '2026-06-13', 17000],
        ];
        foreach ($contasExtras as $extra) {
            $contas[] = $extra;
        }
        foreach ($contas as $i => $conta) {
            if (isset($valorOriginalDe[$conta[0]])) {
                $contas[$i][3] = $valorOriginalDe[$conta[0]];
            }
        }

        return new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 37,
            contas: $contas,
            parcelas: [
                ['61600', 1, 4, '07/2026', '2026-07-15', 19939],
                ['61601', 2, 4, '08/2026', '2026-08-10', 19939],
                ['61602', 3, 4, '09/2026', '2026-09-10', 19938],
                ['61603', 4, 4, '10/2026', '2026-10-10', 19938],
            ],
            situacao: $situacao,
            liquidadas: $liquidadas,
        )], [], 0);
    }

    /**
     * ITEM 5 — a aba de um acordo que o sistema NÃO conhece: número 999, na mesma unidade do cenário
     * (que já tem objeto e caso ativo vindos da inadimplência). Uma conta original que nunca foi
     * importada e duas parcelas futuras — a forma dos 4 acordos de julho que concentram metade do
     * dinheiro medido (414, 407, 394, 411).
     */
    private function leituraAcordoNovo(
        string $situacao = 'Em andamento',
        string $unidade = 'QUADRA 05 CHACARA 03/04',
        ?string $dataBase = '2026-06-30',
        bool $todasLiquidadas = false,
        ?int $valorFinalAcordado = 33998,
    ): ResultadoLeituraAcordos {
        return new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 999,
            contas: [['70001', '01/2025', '2025-01-13', 17000]],
            parcelas: [
                ['70998', 1, 2, '08/2026', '2026-08-10', 17000],
                ['70999', 2, 2, '09/2026', '2026-09-10', 17000],
            ],
            situacao: $situacao,
            liquidadas: $todasLiquidadas ? ['70998', '70999'] : [],
            unidade: $unidade,
            dataBase: $dataBase,
            // ⚠️ DIFERE de propósito da soma das parcelas (34000): sem isso, gravar
            // `somaParcelasCentavos()` em vez do cabeçalho deixaria o T1 verde, e o assert não provaria
            // de qual campo o valor negociado sai. Medido no dado real: cabeçalho e soma divergem em 11
            // de 341 abas (no máximo R$ 0,05 — arredondamento da contábil).
            valorFinalAcordado: $valorFinalAcordado,
        )], [], 0);
    }

    private function leituraAcordo31(): ResultadoLeituraAcordos
    {
        return new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 31,
            contas: [
                ['60049', '01/2026', '2026-01-13', 17000],
                ['60240', '02/2026', '2026-02-13', 17000],
            ],
            parcelas: [['61372', 1, 3, '07/2026', '2026-07-01', 40068]],
        )], [], 0);
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:int}>               $contas   [NN, competência, vencimento, valor]
     * @param list<array{0:string,1:int,2:int,3:string,4:string,5:int}>   $parcelas [NN, p, t, competência, vencimento, valor]
     * @param list<string>                                                $liquidadas
     */
    private function acordoDaPlanilha(
        int $numero,
        array $contas,
        array $parcelas,
        string $situacao = 'Em andamento',
        array $liquidadas = [],
        string $unidade = 'QUADRA 05 CHACARA 03/04',
        ?string $dataBase = '2026-06-30',
        ?int $valorFinalAcordado = null,
    ): AcordoDetalhadoImportavel {
        return new AcordoDetalhadoImportavel(
            numero: $numero,
            unidade: $unidade,
            sacado: 'GESSI PEREIRA DOS SANTOS',
            situacao: $situacao,
            dataBase: $dataBase !== null ? new \DateTimeImmutable($dataBase) : null,
            criadoEm: new \DateTimeImmutable('2026-07-15'),
            valorTotalContasOriginaisCentavos: array_sum(array_map(static fn (array $c): int => $c[3], $contas)),
            valorFinalAcordadoCentavos: $valorFinalAcordado ?? array_sum(array_map(static fn (array $p): int => $p[5], $parcelas)),
            emissao: new \DateTimeImmutable('2026-07-29'),
            contasOriginais: array_map(
                // Índices 4 e 5 são a coluna F ("Detalhamento"): o número do acordo de que aquela conta
                // é parcela e a parcela ("4/12"). Ausentes = conta original comum, que é o dado da
                // maioria das abas (6.222 linhas contra 2.213 medidas em 04/08).
                static fn (array $c): ContaOriginalImportavel => new ContaOriginalImportavel(
                    nn: $c[0],
                    classe: '1.1 - Taxa de condomínio',
                    competencia: $c[1],
                    vencimento: new \DateTimeImmutable($c[2]),
                    valorCentavos: $c[3],
                    acordoOrigemDeclarado: $c[4] ?? null,
                    parcelaOrigemDeclarada: $c[5] ?? null,
                ),
                $contas,
            ),
            parcelas: array_map(
                static fn (array $p): ParcelaAcordoImportavel => new ParcelaAcordoImportavel(
                    acordoNumero: $numero,
                    nn: $p[0],
                    numero: $p[1],
                    total: $p[2],
                    competencia: $p[3],
                    vencimento: new \DateTimeImmutable($p[4]),
                    valorCentavos: $p[5],
                    classes: ['1.1 - Taxa de condomínio'],
                    constaLiquidada: in_array($p[0], $liquidadas, true),
                ),
                $parcelas,
            ),
        );
    }

    // =============================================================================================
    // Auxiliares
    // =============================================================================================

    /** @param list<BoletoImportavel> $boletos */
    private function semear(int $carteiraId, Tenant $tenant, User $user, array $boletos): void
    {
        $this->importarInadimplencia->confirmar($carteiraId, new ResultadoLeitura($boletos, [], 0), $tenant, $user);
    }

    private function boleto(
        string $nn,
        string $competencia,
        string $vencimento,
        int $valor,
        string $objeto = 'QUADRA 05 CHACARA 03/04',
        ?AcordoDoRelatorio $acordo = null,
    ): BoletoImportavel {
        return new BoletoImportavel(
            nn: $nn,
            objetoIdentificacao: $objeto,
            unidadeMetadata: null,
            sacadoNome: 'GESSI PEREIRA DOS SANTOS',
            principalCentavos: $valor,
            jurosCentavos: 0,
            multaCentavos: 0,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: 0,
            vencimento: new \DateTimeImmutable($vencimento),
            competencia: $competencia,
            acordoTexto: $acordo !== null ? sprintf('Acordo %d - Parc. %d/%d', $acordo->numero, $acordo->parcelaIndice, $acordo->parcelaTotal) : null,
            acordo: $acordo,
            somaColunaValorCentavos: $valor,
            // Sem linhas de encargo neste boleto, as duas versões do INV-E5 coincidem em zero.
            jurosDasColunasCentavos: 0,
            multaDasColunasCentavos: 0,
            honorariosDasColunasCentavos: 0,
            linhas: [['classe' => '1.1 - Taxa de condomínio']],
        );
    }

    /** @return array{0: CasoCobranca, 1: Acordo} */
    private function casoEAcordo(Tenant $tenant, int $numeroExterno): array
    {
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => $numeroExterno]);
        self::assertNotNull($acordo, "o cenário precisa de um acordo {$numeroExterno} semeado pela inadimplência");
        $caso = $acordo->getCaso();
        self::assertNotNull($caso);

        return [$caso, $acordo];
    }

    private function obrigacao(Tenant $tenant, string $nn): ?Obrigacao
    {
        return $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => $nn], ['id' => 'ASC']);
    }

    private function obrigacaoPorCompetencia(Tenant $tenant, string $nn, string $competencia): ?Obrigacao
    {
        return $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => $nn, 'competencia' => $competencia]);
    }

    private function obrigacaoDoObjeto(Tenant $tenant, string $nn, string $identificacao): ?Obrigacao
    {
        foreach ($this->em->getRepository(Obrigacao::class)->findBy(['tenant' => $tenant, 'referenciaExterna' => $nn]) as $obrigacao) {
            if ($obrigacao->getCaso()?->getObjeto()?->getIdentificacao() === $identificacao) {
                return $obrigacao;
            }
        }

        return null;
    }

    /** Um recebimento alocado NUMA obrigação — o dinheiro que a reativação faz parar de abater. */
    private function registrarPagamentoEm(Obrigacao $obrigacao, int $valor, Tenant $tenant, User $user): void
    {
        $caso = $obrigacao->getCaso();
        self::assertNotNull($caso);

        $pagamento = (new Pagamento())
            ->setTenant($tenant)
            ->setCaso($caso)
            ->setData(new \DateTimeImmutable('2026-07-20'))
            ->setValorDivida($valor);
        $this->em->persist($pagamento);

        $alocacao = (new AlocacaoPagamento())
            ->setTenant($tenant)
            ->setPagamento($pagamento)
            ->setObrigacao($obrigacao)
            ->setValor($valor);
        $this->em->persist($alocacao);
        $this->em->flush();
    }

    private function contarEventos(CasoCobranca $caso, TipoEventoHistorico $tipo): int
    {
        return $this->em->getRepository(EventoHistorico::class)->count(['caso' => $caso, 'tipo' => $tipo]);
    }

    private function contar(Tenant $tenant, string $nn): int
    {
        return $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant, 'referenciaExterna' => $nn]);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ACORDOS ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('acordos_' . uniqid() . '@test.com');
        $user->setFullName('User Acordos');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param array<string, mixed> $encargos sobrescreve a config NEUTRA da factory (juros/multa zerados) */
    private function criarCarteira(Tenant $tenant, array $encargos = []): int
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => ModoCarteira::Unico,
            ...$encargos,
        ]);

        return (int) $carteira->getId();
    }
}
