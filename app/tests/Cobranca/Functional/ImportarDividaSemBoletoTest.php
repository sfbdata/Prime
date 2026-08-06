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
use App\Cobranca\Service\Importacao\ReferenciaSubstituta;
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
 * Gravação da dívida que nunca teve boleto (spec `cobranca-divida-sem-numero-de-boleto.md`).
 *
 * O adapter transforma a linha sem Nosso Número em `SNN:<vencimento>`; estes testes provam o outro
 * lado — que essa referência funciona como chave de deduplicação de verdade, no banco, contra o índice
 * único parcial `uniq_cobranca_obrigacao_ref_competencia (caso_id, referencia_externa, competencia)`.
 *
 * É a metade que importa: aceitar linha sem NN sem chave que funcione trocaria "dinheiro faltando" por
 * "dinheiro dobrado", que é pior. Medido em 04/08/2026: 99 obrigações, R$ 17.444,66.
 *
 * Cada teste foi provado reintroduzindo o defeito e conferindo QUAL assert ficou vermelho.
 */
#[CoversClass(ImportarRelatorioCarteiraUseCase::class)]
final class ImportarDividaSemBoletoTest extends KernelTestCase
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

    #[TestDox('Dívida sem boleto entra e é contada à parte no relatório da importação')]
    public function testDividaSemBoletoEntraEEContada(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([
            $this->semBoleto('20-03C', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 14500),
            $this->boleto('60049', '20-03C', competencia: '01/2026', vencimento: '2026-01-13'),
        ], [], 0), $tenant, $user);

        self::assertSame(2, $resultado->totalImportadas());
        self::assertSame(1, $resultado->totalSemBoleto(), 'só a de 2019 nunca teve boleto');
        self::assertSame(14500, $resultado->centavosSemBoleto, 'o operador precisa do VALOR, não só da contagem');
        self::assertSame(1, $this->contarPorReferencia($tenant, 'SNN:2019-09-10'));
    }

    /**
     * 🔑 A ÚNICA regra de dinheiro que o relatório criou: o valor é **principal + juros + multa +
     * correção**, com honorário FORA (INV-E2 — honorário não é dívida do credor).
     *
     * A primeira versão deste assert era vacuosa e a 2ª revisão pegou: o fixture nascia com encargos e
     * honorários ZERO, então `principal`, `principal + encargos` e `principal + honorários` davam o
     * mesmo número, e trocar a fórmula deixava o teste verde. Aqui os três valores são distintos de
     * propósito — no dado real a diferença entre eles é R$ 5.760,00 × R$ 8.912,21 × R$ 10.694,66.
     */
    #[TestDox('O valor reportado soma encargos e NÃO soma honorário (INV-E2)')]
    public function testValorReportadoSomaEncargosENaoHonorario(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // Números de uma linha real da unidade 20-03C, competência 09/2021.
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([
            $this->semBoleto(
                '20-03C',
                competencia: '09/2021',
                vencimento: '2021-09-10',
                principalCentavos: 14500,
                jurosCentavos: 8646,
                multaCentavos: 290,
                honorariosCentavos: 4688,
            ),
        ], [], 0), $tenant, $user);

        self::assertSame(23436, $resultado->centavosSemBoleto, 'principal 145,00 + juros 86,46 + multa 2,90');
        self::assertNotSame(14500, $resultado->centavosSemBoleto, 'não é só o principal');
        self::assertNotSame(28124, $resultado->centavosSemBoleto, 'e o honorário de 46,88 NÃO entra');
    }

    /**
     * A partir da 2ª importação as dívidas sem boleto migram para `obrigacoesAtualizadas`. Sem um
     * contador próprio elas sumiam do relatório justamente nas rodadas de rotina — que são todas menos
     * a primeira.
     */
    #[TestDox('Na reimportação a dívida sem boleto continua visível no relatório')]
    public function testDividaSemBoletoContinuaVisivelNaReimportacao(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $divida = $this->semBoleto('20-03C', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 14500);
        $this->importar->confirmar($carteira, new ResultadoLeitura([$divida], [], 0), $tenant, $user);
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$divida], [], 0), $tenant, $user);

        self::assertSame(0, $resultado->totalSemBoleto(), 'nada de novo foi criado');
        self::assertSame(1, $resultado->totalSemBoletoAtualizadas(), 'mas ela não pode sumir do relatório');
        self::assertSame(14500, $resultado->centavosSemBoleto, 'e o valor continua sendo reportado');
    }

    /**
     * A idempotência é a razão de existir da chave. Sem ela, a segunda importação cria uma segunda
     * dívida idêntica e o devedor passa a dever duas vezes o mesmo mês.
     */
    #[TestDox('Reimportar a MESMA dívida sem boleto atualiza — não duplica')]
    public function testReimportarNaoDuplica(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $divida = $this->semBoleto('20-03C', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 14500);
        $this->importar->confirmar($carteira, new ResultadoLeitura([$divida], [], 0), $tenant, $user);
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$divida], [], 0), $tenant, $user);

        self::assertSame(0, $resultado->totalImportadas(), 'nada de novo na segunda rodada');
        self::assertSame(1, $resultado->totalAtualizadas());
        self::assertSame(1, $this->contarPorReferencia($tenant, 'SNN:2019-09-10'), 'cobrar duas vezes é o pior defeito possível aqui');
    }

    /**
     * 🔑 O teste que amarra esta frente à decisão do dono de 05/08 (*"o importe é a fonte da verdade"*):
     * o valor tem de poder mudar entre duas emissões SEM criar dívida nova. É por isso que o valor está
     * fora da chave. Se entrasse, o item 3 do checklist (sobrescrever valor divergente) e o item 1 se
     * destruiriam — cada centavo corrigido viraria uma segunda obrigação.
     *
     * Honestidade sobre o alcance: hoje a propriedade é garantida por CONSTRUÇÃO — nem
     * `ReferenciaSubstituta::para()` nem `findOnePorReferenciaECompetenciaNoCaso()` recebem o valor, e
     * por isso não existe injeção que deixe só este teste vermelho. Ele é uma trava contra a mudança
     * FUTURA de pôr o valor na chave, que é justamente a tentação de quem for mexer no item 3.
     */
    #[TestDox('A contábil corrige o valor entre duas emissões: atualiza a mesma dívida, não cria outra')]
    public function testValorCorrigidoNaoCriaSegundaDivida(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $antes = $this->semBoleto('20-03C', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 10000);
        $this->importar->confirmar($carteira, new ResultadoLeitura([$antes], [], 0), $tenant, $user);

        $depois = $this->semBoleto('20-03C', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 14500);
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$depois], [], 0), $tenant, $user);

        self::assertSame(0, $resultado->totalImportadas(), 'valor diferente NÃO é dívida diferente');
        self::assertSame(1, $this->contarPorReferencia($tenant, 'SNN:2019-09-10'));
    }

    /**
     * A referência substituta NÃO é única no arquivo: todo devedor com dívida vencendo em 10/09/2019
     * produz `SNN:2019-09-10`. Quem separa é o `caso_id`, que compõe o índice único. Sem isso, a dívida
     * de um condômino apareceria na conta de outro.
     */
    #[TestDox('Duas unidades com o mesmo vencimento: duas dívidas, cada uma no seu caso')]
    public function testUnidadesDiferentesNaoSeMisturam(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([
            $this->semBoleto('20-03C', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 10000),
            $this->semBoleto('17-01', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 20000),
        ], [], 0), $tenant, $user);

        self::assertSame(2, $resultado->totalImportadas());
        self::assertSame(2, $this->contarPorReferencia($tenant, 'SNN:2019-09-10'), 'mesma referência, casos diferentes');

        $porCaso = [];
        foreach ($this->em->getRepository(Obrigacao::class)->findBy(['tenant' => $tenant, 'referenciaExterna' => 'SNN:2019-09-10']) as $o) {
            $porCaso[(int) $o->getCaso()->getId()] = $o->getValorOriginal();
        }
        self::assertCount(2, $porCaso, 'as duas dívidas em casos distintos');
        $valores = array_values($porCaso);
        sort($valores);
        self::assertSame([10000, 20000], $valores, 'nenhum dinheiro passou de um devedor para o outro');
    }

    /**
     * Dois escritórios importando o MESMO arquivo produzem dívidas independentes. Roda sem request,
     * como a importação de verdade — e é aí que importa, porque o `TenantFilter` SQL global só liga por
     * request e em CLI fica DESLIGADO.
     *
     * ⚠️ O que este teste NÃO prova: que o filtro de tenant da query é o que protege. Medido por
     * injeção — removendo `'tenant' => $caso->getTenant()` de
     * `ObrigacaoRepository::findOnePorReferenciaECompetenciaNoCaso`, os cinco testes seguem VERDES.
     * Quem separa os dois escritórios é o escopo por **caso**: cada tenant tem carteira, objeto e caso
     * próprios. O filtro de tenant é defesa em profundidade que nenhum teste consegue distinguir daqui,
     * e dizer o contrário seria vender uma garantia que este arquivo não entrega.
     */
    #[TestDox('Mesma referência e competência em outro escritório: dívidas independentes')]
    public function testNaoCruzaTenant(): void
    {
        $user = $this->criarUser();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $carteiraA = $this->criarCarteira($tenantA);
        $carteiraB = $this->criarCarteira($tenantB);

        $divida = $this->semBoleto('20-03C', competencia: '09/2019', vencimento: '2019-09-10', principalCentavos: 10000);
        $this->importar->confirmar($carteiraA, new ResultadoLeitura([$divida], [], 0), $tenantA, $user);
        $resultado = $this->importar->confirmar($carteiraB, new ResultadoLeitura([$divida], [], 0), $tenantB, $user);

        self::assertSame(1, $resultado->totalImportadas(), 'o outro escritório tem a própria dívida');
        self::assertSame(0, $resultado->totalAtualizadas(), 'nunca pode casar com a obrigação do vizinho');
        self::assertSame(1, $this->contarPorReferencia($tenantA, 'SNN:2019-09-10'));
        self::assertSame(1, $this->contarPorReferencia($tenantB, 'SNN:2019-09-10'));
    }

    private function contarPorReferencia(Tenant $tenant, string $referencia): int
    {
        return $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant, 'referenciaExterna' => $referencia]);
    }

    /** Dívida que nunca teve boleto: a referência é derivada do vencimento, como o adapter faz. */
    private function semBoleto(
        string $objetoIdentificacao,
        string $competencia,
        string $vencimento,
        int $principalCentavos,
        int $jurosCentavos = 0,
        int $multaCentavos = 0,
        int $honorariosCentavos = 0,
    ): BoletoImportavel {
        return $this->boleto(
            ReferenciaSubstituta::para(new \DateTimeImmutable($vencimento)),
            $objetoIdentificacao,
            $competencia,
            $vencimento,
            $principalCentavos,
            $jurosCentavos,
            $multaCentavos,
            $honorariosCentavos,
        );
    }

    private function boleto(
        string $nn,
        string $objetoIdentificacao,
        string $competencia,
        string $vencimento,
        int $principalCentavos = 17000,
        int $jurosCentavos = 0,
        int $multaCentavos = 0,
        int $honorariosCentavos = 0,
    ): BoletoImportavel {
        return new BoletoImportavel(
            nn: $nn,
            objetoIdentificacao: $objetoIdentificacao,
            unidadeMetadata: null,
            sacadoNome: 'DEVEDOR ' . $objetoIdentificacao,
            principalCentavos: $principalCentavos,
            jurosCentavos: $jurosCentavos,
            multaCentavos: $multaCentavos,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: $honorariosCentavos,
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
        $tenant->setName('Tenant SEM BOLETO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('semboleto_' . uniqid() . '@test.com');
        $user->setFullName('User Sem Boleto');
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
