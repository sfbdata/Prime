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
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ResultadoLeitura;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\Service\ResolvedorPessoaNoObjeto;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\CriarObjetoUseCase;
use App\Cobranca\UseCase\CriarPessoaUseCase;
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
 * Chave de idempotência da importação (spec `cobranca-importar-chave-competencia.md`).
 *
 * O NN (Nosso Número) NÃO é único: a contábil o reaproveita. Medido em produção (2026-07-30): o NN 61457
 * é uma dívida de 08/2022 na carteira TOP LIFE I e outra de 07/2026 na TOP LIFE 2. Buscar a obrigação só
 * pelo NN faz o importador ENGOLIR o boleto novo (não cria, não avisa) e gravar os encargos dele sobre a
 * dívida antiga.
 *
 * A chave passa a ser **NN + competência**. NÃO pode ser o vencimento: boleto reemitido para o devedor
 * pagar mantém o NN e MUDA a data — chave por data duplicaria a dívida e cobraria duas vezes. Competência
 * é o mês a que a dívida se refere e não muda.
 *
 * Cada teste aqui foi provado reintroduzindo o defeito (busca só por NN) e vendo-o falhar.
 */
#[CoversClass(ImportarRelatorioCarteiraUseCase::class)]
final class ImportarChaveCompetenciaTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ImportarRelatorioCarteiraUseCase $importar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

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
        $this->importar = new ImportarRelatorioCarteiraUseCase(
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
            new RegistrarObrigacaoUseCase($obrigacaoRepo, $casoRepo, $registrarEvento, new CalculadoraEncargos(), new ResolvedorConfigEncargos(), new ConversorTaxaEncargo(new CalculadoraEncargos())),
            $this->em,
            new ResolvedorPessoaNoObjeto($vinculoRepo),
        );
    }

    #[TestDox('Mesmo NN e mesma competência: atualiza, não duplica (idempotência preservada)')]
    public function testMesmoNnMesmaCompetenciaAtualiza(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $primeira = $this->boleto('60049', '01-01', competencia: '01/2026', vencimento: '2026-01-13');
        $this->importar->confirmar($carteira, new ResultadoLeitura([$primeira], [], 0), $tenant, $user);

        $segunda = $this->boleto('60049', '01-01', competencia: '01/2026', vencimento: '2026-01-13', jurosCentavos: 2500);
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$segunda], [], 0), $tenant, $user);

        self::assertSame(0, $resultado->totalImportadas(), 'nada de novo');
        self::assertSame(1, $resultado->totalAtualizadas(), 'a mesma dívida foi atualizada');
        self::assertSame(1, $this->contarPorNn($tenant, '60049'), 'não pode existir uma segunda obrigação');
    }

    #[TestDox('Mesmo NN e competências diferentes: são dívidas DIFERENTES, cria as duas')]
    public function testMesmoNnCompetenciaDiferenteCriaAsDuas(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // Dívida antiga: taxa de janeiro/2022, R$ 145,00 (a taxa da época).
        $antiga = $this->boleto('60049', '01-01', competencia: '01/2022', vencimento: '2022-01-10', principalCentavos: 14500);
        $this->importar->confirmar($carteira, new ResultadoLeitura([$antiga], [], 0), $tenant, $user);

        // Dívida nova: MESMO número reaproveitado pela contábil, taxa de janeiro/2026, R$ 170,00.
        $nova = $this->boleto('60049', '01-01', competencia: '01/2026', vencimento: '2026-01-13', principalCentavos: 17000);
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$nova], [], 0), $tenant, $user);

        self::assertSame(1, $resultado->totalImportadas(), 'o boleto novo NÃO pode ser engolido');
        self::assertSame(0, $resultado->totalAtualizadas());
        self::assertSame(2, $this->contarPorNn($tenant, '60049'), 'as duas dívidas coexistem');

        $valores = array_map(
            static fn (Obrigacao $o): int => $o->getValorOriginal(),
            $this->em->getRepository(Obrigacao::class)->findBy(['tenant' => $tenant, 'referenciaExterna' => '60049']),
        );
        sort($valores);
        self::assertSame([14500, 17000], $valores, 'cada dívida manteve o próprio valor');
    }

    #[TestDox('Dívida antiga REEMITIDA (mesma competência, vencimento anos depois): atualiza, não duplica')]
    public function testDividaReemitidaNaoDuplica(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // Taxa de agosto/2022, vencida na época.
        $original = $this->boleto('61457', '02-08', competencia: '08/2022', vencimento: '2022-08-10', principalCentavos: 14500);
        $this->importar->confirmar($carteira, new ResultadoLeitura([$original], [], 0), $tenant, $user);

        // A contábil REEMITE o mesmo boleto com vencimento novo para o devedor conseguir pagar.
        // Mesmo NN, MESMA competência (continua sendo a taxa de 08/2022), vencimento 4 anos depois.
        $reemitido = $this->boleto('61457', '02-08', competencia: '08/2022', vencimento: '2026-08-10', principalCentavos: 14500);
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$reemitido], [], 0), $tenant, $user);

        self::assertSame(0, $resultado->totalImportadas(), 'reemissão NÃO é dívida nova');
        self::assertSame(1, $resultado->totalAtualizadas());
        self::assertSame(1, $this->contarPorNn($tenant, '61457'), 'cobrar duas vezes a mesma dívida é o pior defeito possível aqui');
    }

    #[TestDox('Obrigação sem competência registrada cai no comportamento antigo (só NN), sem regredir')]
    public function testFallbackSemCompetencia(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $boleto = $this->boleto('70001', '03-03', competencia: '05/2026', vencimento: '2026-05-13');
        $this->importar->confirmar($carteira, new ResultadoLeitura([$boleto], [], 0), $tenant, $user);

        // Simula dado legado: obrigação anterior ao backfill, sem competência gravada.
        $obrigacao = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '70001']);
        self::assertNotNull($obrigacao);
        $obrigacao->setCompetencia(null);
        $this->em->flush();

        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$boleto], [], 0), $tenant, $user);

        self::assertSame(1, $resultado->totalAtualizadas(), 'sem competência, casa só pelo NN — como antes');
        self::assertSame(1, $this->contarPorNn($tenant, '70001'), 'não duplica dado legado');
    }

    /**
     * O caso real 61457, entre CARTEIRAS. O que ele guarda é o **escopo por caso** da busca — não a chave
     * de competência: unidades diferentes produzem casos diferentes, e a busca sempre foi escopada por
     * caso, então o código antigo (NN sozinho) também separaria estes dois. Quem guarda a chave é
     * `testMesmoNnCompetenciaDiferenteCriaAsDuas`, onde o caso é o MESMO.
     *
     * O que este teste acrescenta é o dano concreto que a spec descreve: "engolir o boleto novo e gravar
     * os encargos dele sobre a dívida antiga". Contar duas obrigações não bastava — é preciso ver que a
     * dívida de 2022 continua com o valor e o vencimento dela.
     */
    #[TestDox('Mesmo NN em CARTEIRAS diferentes: nasce separado e a dívida antiga não é tocada (caso real 61457)')]
    public function testMesmoNnEmCarteirasDiferentesNaoSeConfunde(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $topLifeUm = $this->criarCarteira($tenant);
        $topLifeDois = $this->criarCarteira($tenant);

        // TOP LIFE I: unidade "02-08", taxa de 08/2022.
        $this->importar->confirmar(
            $topLifeUm,
            new ResultadoLeitura([$this->boleto('61457', '02-08', competencia: '08/2022', vencimento: '2022-08-10', principalCentavos: 14500)], [], 0),
            $tenant,
            $user,
        );

        // TOP LIFE 2: unidade "QUADRA 02 CHACARA 03/06", taxa de 07/2026 — mesmo NN, outro condomínio.
        $resultado = $this->importar->confirmar(
            $topLifeDois,
            new ResultadoLeitura([$this->boleto('61457', 'QUADRA 02 CHACARA 03/06', competencia: '07/2026', vencimento: '2026-07-13', principalCentavos: 17000)], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(1, $resultado->totalImportadas(), 'carteira diferente = dívida diferente');
        self::assertSame(0, $resultado->totalAtualizadas(), 'atualizar aqui seria ter engolido o boleto novo');
        self::assertSame(2, $this->contarPorNn($tenant, '61457'));

        // A dívida de 2022 permanece intacta: é ela que a busca por NN sozinho sobrescreveria.
        $antiga = $this->obrigacaoPorCompetencia($tenant, '61457', '08/2022');
        self::assertNotNull($antiga);
        self::assertSame(14500, $antiga->getValorOriginal(), 'o valor da dívida antiga não pode ter virado o do boleto novo');
        self::assertSame('2022-08-10', $antiga->getVencimentoOriginal()->format('Y-m-d'));
    }

    #[TestDox('Duas obrigações com o mesmo NN e competência NULA continuam bloqueadas (NULLS NOT DISTINCT)')]
    public function testDuasCompetenciasNulasNaoDuplicam(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $this->importar->confirmar(
            $carteira,
            new ResultadoLeitura([$this->boleto('80001', '09-09', competencia: '05/2026', vencimento: '2026-05-13')], [], 0),
            $tenant,
            $user,
        );

        $existente = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '80001']);
        self::assertNotNull($existente);
        $existente->setCompetencia(null); // dado legado que o backfill não alcançou
        $this->em->flush();

        // Sem `NULLS NOT DISTINCT` o Postgres trataria cada NULL como valor único e deixaria passar —
        // abrindo caminho para duplicar exatamente as obrigações mais antigas, que são as sem competência.
        $duplicada = new Obrigacao();
        $duplicada->setTenant($tenant);
        $duplicada->setCaso($existente->getCaso());
        $duplicada->setDescricao('duplicada proibida');
        $duplicada->setValorOriginal(1000);
        $duplicada->setVencimentoOriginal(new \DateTimeImmutable('2026-05-13'));
        $duplicada->setReferenciaExterna('80001');

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->persist($duplicada);
        $this->em->flush();
    }

    private function contarPorNn(Tenant $tenant, string $nn): int
    {
        return $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant, 'referenciaExterna' => $nn]);
    }

    private function obrigacaoPorCompetencia(Tenant $tenant, string $nn, string $competencia): ?Obrigacao
    {
        return $this->em->getRepository(Obrigacao::class)->findOneBy([
            'tenant' => $tenant,
            'referenciaExterna' => $nn,
            'competencia' => $competencia,
        ]);
    }

    private function boleto(
        string $nn,
        string $objetoIdentificacao,
        string $competencia,
        string $vencimento,
        int $principalCentavos = 17000,
        int $jurosCentavos = 0,
    ): BoletoImportavel {
        return new BoletoImportavel(
            nn: $nn,
            objetoIdentificacao: $objetoIdentificacao,
            unidadeMetadata: null,
            sacadoNome: 'DEVEDOR ' . $objetoIdentificacao,
            principalCentavos: $principalCentavos,
            jurosCentavos: $jurosCentavos,
            multaCentavos: 0,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: 0,
            vencimento: new \DateTimeImmutable($vencimento),
            competencia: $competencia,
            acordoTexto: null,
            acordo: null,
            somaColunaValorCentavos: $principalCentavos,
            linhas: [],
        );
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant CHAVE ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('chave_' . uniqid() . '@test.com');
        $user->setFullName('User Chave');
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
