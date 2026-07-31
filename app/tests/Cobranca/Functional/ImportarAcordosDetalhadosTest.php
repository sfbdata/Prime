<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\Importacao\AcordoDetalhadoImportavel;
use App\Cobranca\Service\Importacao\AcordoDoRelatorio;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ContaOriginalImportavel;
use App\Cobranca\Service\Importacao\ParcelaAcordoImportavel;
use App\Cobranca\Service\Importacao\ResultadoLeitura;
use App\Cobranca\Service\Importacao\ResultadoLeituraAcordos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
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
        );

        $this->importarAcordos = new ImportarAcordosDetalhadosUseCase(
            $carteiraRepo,
            $acordoRepo,
            $obrigacaoRepo,
            $registrarObrigacao,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
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

    #[TestDox('Acordo inexistente: a aba é ignorada e reportada, SEM nenhuma escrita')]
    public function testAcordoInexistenteAbaIgnorada(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $antes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);
        $leitura = new ResultadoLeituraAcordos([$this->acordoDaPlanilha(
            numero: 999,
            contas: [['70001', '01/2026', '2026-01-13', 17000]],
            parcelas: [['70999', 1, 1, '02/2026', '2026-02-13', 17000]],
        )], [], 0);

        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertNotNull($resultado->porAcordo()[0]->ignoradoPorque);
        self::assertSame([], $resultado->nnsParcelasCriadas());
        self::assertSame([], $resultado->nnsContasReconstruidas());
        self::assertSame($antes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]), 'nenhuma obrigação nasceu');
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
     * Acordo ROMPIDO (ou cancelado) no sistema: a aba inteira é pulada.
     *
     * Não é só "não mexer no status". Escrever contra um acordo não-vigente **cria dívida**: a conta
     * reconstruída pelo §3.2.1 nasce marcada com `acordoSubstituto`, e `doCasoExigiveis` só exclui o que
     * está substituído por acordo VIGENTE — com o acordo rompido ela entra no saldo, cobrando de novo
     * uma dívida que a planilha listou como já renegociada. A parcela futura teria o mesmo efeito ao
     * contrário: nasce ligada a um acordo que não vale mais.
     *
     * A janela é estreita na prática (romper é registrado nos dois lados, então a planilha seguinte já
     * vem alinhada), mas pular a aba custa nada e fecha a janela inteira.
     */
    #[TestDox('Acordo ROMPIDO: a aba inteira é pulada e reportada — nada é criado nem marcado')]
    public function testAcordoRompidoPulaAAbaInteira(): void
    {
        // Uma das 4 originais NÃO está no sistema: se a aba fosse processada, o §3.2.1 a reconstruiria —
        // e é exatamente essa conta que entraria no saldo por causa do rompimento.
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37(originaisNoSistema: ['60145', '60334', '60812']);

        $acordo->romper('o devedor parou de pagar');
        $this->em->flush();

        $obrigacoesAntes = $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]);
        $saldoAntes = $this->saldo->saldoExigivel($caso);

        $previsto = $this->importarAcordos->prever($carteiraId, $this->leituraAcordo37(), $tenant);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        self::assertSame(1, $previsto->totalAbasIgnoradas(), 'a prévia precisa avisar ANTES de alguém mandar gravar');
        self::assertSame(1, $resultado->totalAbasIgnoradas());
        self::assertStringContainsString('rompido', (string) $resultado->porAcordo()[0]->ignoradoPorque);
        self::assertTrue($resultado->temAvisos());

        self::assertSame([], $resultado->nnsContasReconstruidas(), 'reconstruir aqui criaria dívida: sem acordo vigente a conta nasce EXIGÍVEL');
        self::assertSame([], $resultado->nnsContasMarcadas());
        self::assertSame([], $resultado->nnsParcelasCriadas());

        self::assertSame($obrigacoesAntes, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]), 'nenhuma obrigação nasceu');
        self::assertSame($saldoAntes, $this->saldo->saldoExigivel($caso), 'e o saldo do devedor não se mexeu um centavo');

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Rompido, $acordo->getStatus(), 'a decisão manual do escritório prevalece sobre a planilha');
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
            'situacoesDivergentes' => $r->situacoesDivergentes(),
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
    ): AcordoDetalhadoImportavel {
        return new AcordoDetalhadoImportavel(
            numero: $numero,
            unidade: 'QUADRA 05 CHACARA 03/04',
            sacado: 'GESSI PEREIRA DOS SANTOS',
            situacao: $situacao,
            dataBase: new \DateTimeImmutable('2026-06-30'),
            criadoEm: new \DateTimeImmutable('2026-07-15'),
            valorTotalContasOriginaisCentavos: array_sum(array_map(static fn (array $c): int => $c[3], $contas)),
            valorFinalAcordadoCentavos: array_sum(array_map(static fn (array $p): int => $p[5], $parcelas)),
            emissao: new \DateTimeImmutable('2026-07-29'),
            contasOriginais: array_map(
                static fn (array $c): ContaOriginalImportavel => new ContaOriginalImportavel(
                    nn: $c[0],
                    classe: '1.1 - Taxa de condomínio',
                    competencia: $c[1],
                    vencimento: new \DateTimeImmutable($c[2]),
                    valorCentavos: $c[3],
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
