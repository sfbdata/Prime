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

    #[TestDox('Situação: não ressuscita acordo rompido à mão — reporta a divergência e mantém')]
    public function testNaoRessuscitaAcordoRompido(): void
    {
        [$tenant, $user, $carteiraId, $caso, $acordo] = $this->cenarioAcordo37();

        $acordo->romper('o devedor parou de pagar');
        $this->em->flush();

        $resultado = $this->importarAcordos->confirmar($carteiraId, $this->leituraAcordo37(), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->find($acordo->getId());
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Rompido, $acordo->getStatus(), 'a decisão manual do escritório prevalece sobre a planilha');
        self::assertNotSame([], $resultado->situacoesDivergentes());
        self::assertTrue($resultado->temAvisos());
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

    #[TestDox('Parcela liquidada na planilha é avisada, mas a baixa de pagamento NÃO é feita (§5)')]
    public function testParcelaLiquidadaSoAvisa(): void
    {
        [$tenant, $user, $carteiraId] = $this->cenarioAcordo37();

        $leitura = $this->leituraAcordo37(liquidadas: ['61600']);
        $resultado = $this->importarAcordos->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertContains('61600', $resultado->parcelasLiquidadasNaPlanilha);
        self::assertFalse($this->obrigacao($tenant, '61600')?->estaLiquidada(), 'baixa de pagamento é irreversível na prática — fica fora desta entrega');
        self::assertTrue($resultado->temAvisos());
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

        self::assertSame($previsto->nnsParcelasCriadas(), $feito->nnsParcelasCriadas(), 'a projeção tem de bater com o efeito');
        self::assertSame($previsto->nnsContasMarcadas(), $feito->nnsContasMarcadas());
        self::assertSame($previsto->nnsContasReconstruidas(), $feito->nnsContasReconstruidas());
        self::assertSame($previsto->principalReconciliadoCentavos(), $feito->principalReconciliadoCentavos());
        self::assertSame($previsto->valorParcelasCriadasCentavos(), $feito->valorParcelasCriadasCentavos());
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

    private function criarCarteira(Tenant $tenant): int
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => ModoCarteira::Unico,
        ]);

        return (int) $carteira->getId();
    }
}
