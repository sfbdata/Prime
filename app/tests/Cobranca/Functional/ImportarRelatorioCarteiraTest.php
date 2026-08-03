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
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\Importacao\AcordoDoRelatorio;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ResultadoLeitura;
use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
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
 * Importação em lote da Etapa 7 contra o BANCO REAL, reusando os UseCases do núcleo. Prova: cria a
 * estrutura (objeto/pessoa/caso/obrigação) a partir do relatório, agrega por boleto, é IDEMPOTENTE na
 * reimportação (não duplica), reporta importado/atualizado/rejeitado/ignorado, e NUNCA cruza tenants
 * (invariável 24). Usa a fixture anonimizada `toplife_amostra.xlsx`.
 */
#[CoversClass(ImportarRelatorioCarteiraUseCase::class)]
#[CoversClass(TopLifeInadimplenciaAdapter::class)]
final class ImportarRelatorioCarteiraTest extends KernelTestCase
{
    use Factories;

    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/toplife_amostra.xlsx';

    private EntityManagerInterface $em;
    private ImportarRelatorioCarteiraUseCase $importar;
    private TopLifeInadimplenciaAdapter $adapter;

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
        );
        $this->adapter = new TopLifeInadimplenciaAdapter();
    }

    #[TestDox('Importa cria objetos/pessoas/casos/obrigações agregando por boleto')]
    public function testImportaCriaEstruturaCompleta(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $resultado = $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        self::assertSame(6, $resultado->totalImportadas(), '6 boletos importáveis');
        self::assertSame(0, $resultado->totalAtualizadas());
        self::assertSame(4, $resultado->totalRejeitadas(), 'sem sacado, competência inválida, valor não numérico, sem principal');
        self::assertSame(8, $resultado->linhasIgnoradas);
        self::assertSame(4, $resultado->objetosCriados, '01-01, 01-03A, 02-01, 04-01');
        self::assertSame(4, $resultado->pessoasCriadas);
        self::assertSame(4, $resultado->casosCriados);

        self::assertSame(6, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]));
        self::assertSame(4, $this->em->getRepository(ObjetoCobranca::class)->count(['tenant' => $tenant]));
        self::assertSame(4, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));
        self::assertSame(4, $this->em->getRepository(CasoCobranca::class)->count(['tenant' => $tenant]));

        // Agregação por NN (Taxa 100 + Energia 45 = principal 145; encargos 9,90).
        $obrig = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1002']);
        self::assertNotNull($obrig);
        self::assertSame(14500, $obrig->getValorOriginal());
        self::assertSame(990, $obrig->getEncargosReconhecidos());

        // Desconto reduz o principal (170 - 10 = 160).
        $comDesconto = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1005']);
        self::assertSame(16000, $comDesconto?->getValorOriginal());

        // Mesmo nome em objetos diferentes NÃO funde a Pessoa (decisão A): 2 "DEVEDOR UM EXEMPLO".
        self::assertSame(2, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant, 'nome' => 'DEVEDOR UM EXEMPLO']));
    }

    #[TestDox('Reimportar o mesmo relatório atualiza e não duplica (idempotência)')]
    public function testReimportacaoEhIdempotente(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);
        $segunda = $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        self::assertSame(0, $segunda->totalImportadas(), 'nada novo na reimportação');
        self::assertSame(6, $segunda->totalAtualizadas(), 'os 6 boletos são atualizados');
        self::assertSame(0, $segunda->objetosCriados);
        self::assertSame(0, $segunda->pessoasCriadas);
        self::assertSame(0, $segunda->casosCriados);

        // Sem duplicação silenciosa: contagens intactas.
        self::assertSame(6, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]));
        self::assertSame(4, $this->em->getRepository(ObjetoCobranca::class)->count(['tenant' => $tenant]));
        self::assertSame(4, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('Encargos importados entram separados e o valor exigível não muda (INV-E1)')]
    public function testEncargosImportadosEntramSeparadosSemMudarOExigivel(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);
        $repo = $this->em->getRepository(Obrigacao::class);

        // NN=1001: coluna I 9,37 → juros; coluna J 3,80 → multa; coluna K 0 → correção.
        $simples = $repo->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1001']);
        self::assertNotNull($simples);
        self::assertSame(937, $simples->getJuros());
        self::assertSame(380, $simples->getMulta());
        self::assertSame(0, $simples->getCorrecao());
        // INV-E1 ponta a ponta: o agregado é o MESMO 1317 de antes da separação, e o exigível é o
        // principal mais esse agregado — nenhum saldo existente se move por causa da mudança.
        self::assertSame(1317, $simples->getEncargosReconhecidos(), 'agregado idêntico ao de antes');
        self::assertSame(19000 + 1317, $simples->valorExigivel());

        // NN=1004: mistura colunas I/J com lançamentos fechados 1.4 (juros) e 1.5 (multa).
        $comClasseEspecial = $repo->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1004']);
        self::assertNotNull($comClasseEspecial);
        self::assertSame(1409, $comClasseEspecial->getJuros(), '1,59 (col. I) + 12,50 (linha 1.4)');
        self::assertSame(680, $comClasseEspecial->getMulta(), '3,40 (col. J) + 3,40 (linha 1.5)');
        self::assertSame(0, $comClasseEspecial->getCorrecao());
        self::assertSame(2089, $comClasseEspecial->getEncargosReconhecidos(), 'agregado idêntico ao de antes');
        self::assertSame(17000 + 2089, $comClasseEspecial->valorExigivel());

        // INV-E2: honorários são materializados, mas FICAM FORA do exigível (não são dívida do credor).
        self::assertSame(5000, $comClasseEspecial->getHonorarios());
        self::assertSame(17000 + 2089, $comClasseEspecial->valorExigivel(), 'honorário não entra no exigível');
        self::assertSame(17000 + 2089 + 5000, $comClasseEspecial->totalComHonorarios());
    }

    #[TestDox('Obrigação importada nasce VIVA (ao vivo): materializa o split do relatório, mas NÃO congela')]
    public function testObrigacaoImportadaNasceViva(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);
        $repo = $this->em->getRepository(Obrigacao::class);

        // Ao vivo (D6): a importação materializa os números do relatório como valor INICIAL, mas a
        // obrigação nasce VIVA — a leitura recalcula (vencimento → hoje × taxa), que reproduz o relatório
        // ao centavo quando a carteira está configurada (spec §2). Nada mais congela.
        foreach ($repo->findBy(['tenant' => $tenant]) as $obrigacao) {
            self::assertFalse(
                $obrigacao->encargosCongelados(),
                "obrigação {$obrigacao->getReferenciaExterna()} deve nascer Viva (encargo ao vivo)",
            );
        }

        // NN=1003 tem I=J=K=0: nasce Viva como as demais (não há mais cron a "proteger").
        $semEncargos = $repo->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1003']);
        self::assertNotNull($semEncargos);
        self::assertSame(0, $semEncargos->getEncargosReconhecidos(), 'boleto sem encargo nenhum');
        self::assertFalse($semEncargos->encargosCongelados(), 'Viva mesmo com encargos zero');
    }

    #[TestDox('Reimportação restaura os números do relatório (cache) e mantém a obrigação VIVA')]
    public function testReimportacaoRestauraOsNumerosMantendoViva(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);
        $repo = $this->em->getRepository(Obrigacao::class);

        // A obrigação nasce Viva; altera-se o cache entre os dois relatórios para provar que a
        // reimportação restaura os números do relatório novo — sem congelar.
        $obrigacao = $repo->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1001']);
        self::assertNotNull($obrigacao);
        self::assertFalse($obrigacao->encargosCongelados(), 'importada nasce Viva');
        $obrigacao->definirEncargos(1, 2, 3, 4, new \DateTimeImmutable('2020-01-01'));
        $this->em->flush();

        $segunda = $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        self::assertSame(6, $segunda->totalAtualizadas());
        self::assertSame(0, $segunda->totalImportadas(), 'reimportar continua idempotente');
        self::assertSame(6, $repo->count(['tenant' => $tenant]), 'sem duplicação');

        $this->em->refresh($obrigacao);
        self::assertFalse($obrigacao->encargosCongelados(), 'a reimportação NÃO congela — segue Viva');
        self::assertSame(937, $obrigacao->getJuros(), 'e restaura os números do relatório');
        self::assertSame(380, $obrigacao->getMulta());
        self::assertSame(0, $obrigacao->getCorrecao());
        self::assertSame(1317, $obrigacao->getEncargosReconhecidos());
    }

    #[TestDox('Preview projeta o resultado sem persistir nada')]
    public function testPreviewNaoPersiste(): void
    {
        $tenant = $this->criarTenant();
        $carteira = $this->criarCarteira($tenant);

        $preview = $this->importar->prever($carteira, $this->adapter->ler(self::FIXTURE), $tenant);

        self::assertSame(6, $preview->totalImportadas());
        self::assertSame(0, $preview->totalAtualizadas());
        self::assertSame(4, $preview->objetosCriados);
        self::assertSame(4, $preview->pessoasCriadas, 'preview honesto: 4 pessoas novas');
        self::assertSame(4, $preview->casosCriados);
        self::assertSame([], $preview->sacadosDivergentes);
        self::assertSame(0, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]), 'preview não persiste');
        self::assertSame(0, $this->em->getRepository(ObjetoCobranca::class)->count(['tenant' => $tenant]));
    }

    /**
     * A invariante MUDOU nesta frente (spec `cobranca-importar-chave-competencia.md`): o índice único
     * deixou de ser (caso, NN) e passou a ser (caso, NN, competência), porque a contábil reaproveita o
     * NN e duas dívidas de competências diferentes precisam coexistir.
     *
     * O que continua garantido — e é o que este teste prova — é que o MESMO trio não duplica. O caso de
     * competências diferentes coexistirem está em `ImportarChaveCompetenciaTest`.
     */
    #[TestDox('Índice único rejeita duas obrigações com a mesma referência externa E competência no mesmo caso')]
    public function testIndiceUnicoBloqueiaObrigacaoDuplicada(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);
        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        $existente = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1001']);
        self::assertNotNull($existente);

        // Insere à força uma 2ª obrigação com a MESMA (caso, referencia_externa, competência) → barra.
        $duplicada = new Obrigacao();
        $duplicada->setTenant($tenant);
        $duplicada->setCaso($existente->getCaso());
        $duplicada->setDescricao('duplicada proibida');
        $duplicada->setValorOriginal(1000);
        $duplicada->setVencimentoOriginal(new \DateTimeImmutable('2026-02-10'));
        $duplicada->setReferenciaExterna('1001');
        $duplicada->setCompetencia($existente->getCompetencia());

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->persist($duplicada);
        $this->em->flush();
    }

    #[TestDox('Importação nunca cruza tenants (carteira de outro escritório é rejeitada)')]
    public function testNaoCruzaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraB = $this->criarCarteira($tenantB);

        // Importar a carteira do B usando o tenant A → carteira não encontrada (guarda de tenant).
        $this->expectException(CarteiraNaoEncontradaException::class);
        $this->importar->confirmar($carteiraB, $this->adapter->ler(self::FIXTURE), $tenantA, $user);
    }

    #[TestDox('Mesma fixture em dois tenants não vaza dados entre eles')]
    public function testMesmaFixtureEmDoisTenantsIsolada(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        $this->importar->confirmar($this->criarCarteira($tenantA), $this->adapter->ler(self::FIXTURE), $tenantA, $user);
        $this->importar->confirmar($this->criarCarteira($tenantB), $this->adapter->ler(self::FIXTURE), $tenantB, $user);

        self::assertSame(6, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenantA]));
        self::assertSame(6, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenantB]));
        self::assertSame(4, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenantA]));
        self::assertSame(4, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenantB]));
    }

    #[TestDox('Sacado diferente no mesmo objeto cria nova Pessoa sem trocar a cobrada (decisão A)')]
    public function testSacadoDivergenteNoMesmoObjetoCriaNovaPessoa(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // 1ª importação: cria objeto 01-01 com a pessoa "DEVEDOR UM EXEMPLO".
        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        // 2ª: MESMO objeto/NN, mas outro Sacado → nova Pessoa + vínculo, sem trocar a cobrada.
        $boleto = new \App\Cobranca\Service\Importacao\BoletoImportavel(
            nn: '1001',
            objetoIdentificacao: '01-01',
            unidadeMetadata: null,
            sacadoNome: 'NOVO PROPRIETARIO EXEMPLO',
            principalCentavos: 19000,
            jurosCentavos: 937,
            multaCentavos: 380,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: 4063,
            vencimento: new \DateTimeImmutable('2026-02-10'),
            competencia: '02/2026',
            acordoTexto: null,
            acordo: null,
            somaColunaValorCentavos: 19000,
            linhas: [],
        );
        $leitura = new \App\Cobranca\Service\Importacao\ResultadoLeitura([$boleto], [], 0);

        $resultado = $this->importar->confirmar($carteira, $leitura, $tenant, $user);

        self::assertSame(1, $resultado->pessoasCriadas, 'novo sacado vira nova Pessoa');
        self::assertSame(['01-01'], $resultado->sacadosDivergentes);
        self::assertSame(0, $resultado->totalImportadas());
        self::assertSame(1, $resultado->totalAtualizadas(), 'a obrigação NN=1001 já existia');

        // A pessoa cobrada do caso PERMANECE a original (troca é decisão humana, §28).
        // O caso é buscado pelo OBJETO 01-01, não por `findOneBy(['tenant' => ...])`: a fixture cria
        // vários objetos (4 pessoas / 6 obrigações), então buscar só por tenant devolve um caso
        // ARBITRÁRIO — sem ORDER BY o banco não promete qual. O assert passava pela ordem de inserção e
        // falhava esporadicamente; medido em 2026-08-03, uma falha a cada poucas execuções da suíte.
        $objeto = $this->em->getRepository(ObjetoCobranca::class)->findOneBy(['tenant' => $tenant, 'identificacao' => '01-01']);
        self::assertNotNull($objeto, 'o objeto do cenário tem de existir para o assert abaixo medir algo');
        $caso = $this->em->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objeto]);
        self::assertSame('DEVEDOR UM EXEMPLO', $caso?->getPessoaCobradaAtual()?->getNome());
        // Agora há 5 pessoas no tenant (4 do 1º import + a nova divergente).
        self::assertSame(5, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));

        // IDEMPOTÊNCIA (correção B1): reimportar o MESMO sacado divergente NÃO recria a Pessoa/vínculo.
        $reimport = $this->importar->confirmar($carteira, $leitura, $tenant, $user);
        self::assertSame(0, $reimport->pessoasCriadas, 'sacado divergente já vinculado não recria Pessoa');
        self::assertSame([], $reimport->sacadosDivergentes);
        self::assertSame(5, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]), 'sem acúmulo de duplicatas');
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    // Linhas de acordo (tarefa #7-B, spec cobranca-importar-linhas-acordo.md §3.2/§3.3)
    // ────────────────────────────────────────────────────────────────────────────────────────

    #[TestDox('NN com "Acordo 28 - Parc. 1/1" cria 1 Acordo + 1 parcela viva com honorários zero')]
    public function testAcordoDeParcelaUnicaCriaAcordoEParcela(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $boleto = $this->boletoComAcordo('9301', '20-01', 'DEVEDOR ACORDO 28', numeroAcordo: 28, parcelaIndice: 1, parcelaTotal: 1, somaColunaValorCentavos: 15000);
        $resultado = $this->importar->confirmar($carteira, new ResultadoLeitura([$boleto], [], 0), $tenant, $user);

        self::assertSame(1, $resultado->totalImportadas());

        // A transação da importação já fechou; lê como uma nova requisição leria (a coleção inversa
        // `Acordo::parcelas` não é auto-sincronizada em memória ao setar só o lado dono — mesmo padrão
        // do CriarAcordoUseCase, ver outros testes funcionais de Acordo).
        $this->em->clear();
        $acordoRepo = $this->em->getRepository(Acordo::class);
        self::assertSame(1, $acordoRepo->count(['tenant' => $tenant]), '1 acordo só');
        $acordo = $acordoRepo->findOneBy(['tenant' => $tenant, 'numeroExterno' => 28]);
        self::assertNotNull($acordo);
        self::assertFalse($acordo->estaIncompleto(), '1/1 é completo');
        self::assertSame(0, $acordo->parcelasFaltantes());
        self::assertCount(1, $acordo->getParcelas());
        self::assertSame(15000, $acordo->getValorTotalNegociado(), '1/1: total negociado é derivável = soma da coluna Valor da única parcela');

        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '9301']);
        self::assertNotNull($parcela);
        self::assertSame($acordo->getId(), $parcela->getAcordoOrigem()?->getId(), 'a obrigação é PARCELA do acordo');
        self::assertSame(15000, $parcela->getValorOriginal(), 'valorOriginal = soma da coluna Valor (principal negociado)');
        self::assertSame(0, $parcela->getTaxaHonorariosBp(), 'honorários zero (decisão #8: acordo não cobra honorário)');
        self::assertFalse($parcela->encargosCongelados(), 'parcela nasce VIVA como as demais obrigações importadas');
    }

    #[TestDox('Reimportar o mesmo relatório com acordo NÃO cria acordo nem parcela novos (idempotência)')]
    public function testReimportarAcordoNaoDuplica(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $boleto = $this->boletoComAcordo('9301', '20-01', 'DEVEDOR ACORDO 28', numeroAcordo: 28, parcelaIndice: 1, parcelaTotal: 1, somaColunaValorCentavos: 15000);
        $leitura = new ResultadoLeitura([$boleto], [], 0);

        $this->importar->confirmar($carteira, $leitura, $tenant, $user);
        $segunda = $this->importar->confirmar($carteira, $leitura, $tenant, $user);

        self::assertSame(0, $segunda->totalImportadas(), 'nada novo na reimportação');
        self::assertSame(1, $segunda->totalAtualizadas());
        self::assertSame(1, $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]), 'acordo dedup por carteira+número');
        self::assertSame(1, $this->em->getRepository(Obrigacao::class)->count(['tenant' => $tenant]), 'parcela dedup por NN');

        // A parcela continua apontando pro MESMO acordo (idempotência §3.2.4).
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 28]);
        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '9301']);
        self::assertSame($acordo?->getId(), $parcela?->getAcordoOrigem()?->getId());
        // Idempotência do total negociado derivado (1/1): reimportar o MESMO valor não regrava (nem quebra).
        self::assertSame(15000, $acordo?->getValorTotalNegociado());
    }

    #[TestDox('NN com "Acordo 31 - Parc. 1/3" cria acordo incompleto (faltam 2 parcelas)')]
    public function testAcordoMultiParcelaFicaIncompleto(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $boleto = $this->boletoComAcordo('9302', '20-02', 'DEVEDOR ACORDO 31', numeroAcordo: 31, parcelaIndice: 1, parcelaTotal: 3, somaColunaValorCentavos: 10000);
        $this->importar->confirmar($carteira, new ResultadoLeitura([$boleto], [], 0), $tenant, $user);

        $this->em->clear();
        $acordo = $this->em->getRepository(Acordo::class)->findOneBy(['tenant' => $tenant, 'numeroExterno' => 31]);
        self::assertNotNull($acordo);
        self::assertSame(3, $acordo->getNumeroParcelasTotal());
        self::assertCount(1, $acordo->getParcelas());
        self::assertTrue($acordo->estaIncompleto());
        self::assertSame(2, $acordo->parcelasFaltantes());
        self::assertNull($acordo->getValorTotalNegociado(), 'multi-parcela: total NÃO é derivável (parcelas 2..N não estão no relatório)');
    }

    #[TestDox('NN sem acordo segue como obrigação comum (regressão): sem acordoOrigem, valorOriginal = principal')]
    public function testNnSemAcordoSegueComoObrigacaoComum(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // Boleto SEM acordo (regressão): coluna "Informações do acordo" vazia. principalCentavos
        // (14500) difere de propósito de somaColunaValorCentavos (14900) para provar que o UseCase usa
        // o campo certo quando NÃO há acordo.
        $boleto = new BoletoImportavel(
            nn: '9501',
            objetoIdentificacao: '40-01',
            unidadeMetadata: null,
            sacadoNome: 'DEVEDOR SEM ACORDO',
            principalCentavos: 14500,
            jurosCentavos: 500,
            multaCentavos: 200,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: 3100,
            vencimento: new \DateTimeImmutable('2026-03-10'),
            competencia: '03/2026',
            acordoTexto: null,
            acordo: null,
            somaColunaValorCentavos: 14900,
            linhas: [],
        );
        $this->importar->confirmar($carteira, new ResultadoLeitura([$boleto], [], 0), $tenant, $user);

        $obrig = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '9501']);
        self::assertNotNull($obrig);
        self::assertNull($obrig->getAcordoOrigem(), 'sem acordoOrigem — regressão do comportamento sem acordo');
        self::assertSame(14500, $obrig->getValorOriginal(), 'valorOriginal continua sendo o principal (não a soma da coluna Valor)');
        self::assertSame(0, $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]), 'nenhum acordo criado');
    }

    #[TestDox('"Acordo 31" em carteiras diferentes não se confunde (dedup escopado por carteira)')]
    public function testAcordoDeCarteirasDiferentesNaoSeConfunde(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraA = $this->criarCarteira($tenant);
        $carteiraB = $this->criarCarteira($tenant);

        $boletoA = $this->boletoComAcordo('9401', '30-01', 'DEVEDOR CARTEIRA A', numeroAcordo: 31, parcelaIndice: 1, parcelaTotal: 1, somaColunaValorCentavos: 12000);
        $boletoB = $this->boletoComAcordo('9402', '30-01', 'DEVEDOR CARTEIRA B', numeroAcordo: 31, parcelaIndice: 1, parcelaTotal: 1, somaColunaValorCentavos: 8000);

        $this->importar->confirmar($carteiraA, new ResultadoLeitura([$boletoA], [], 0), $tenant, $user);
        $this->importar->confirmar($carteiraB, new ResultadoLeitura([$boletoB], [], 0), $tenant, $user);

        self::assertSame(2, $this->em->getRepository(Acordo::class)->count(['tenant' => $tenant]), 'dois acordos DISTINTOS, um por carteira');

        $parcelaA = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '9401']);
        $parcelaB = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '9402']);
        self::assertNotSame($parcelaA?->getAcordoOrigem()?->getId(), $parcelaB?->getAcordoOrigem()?->getId(), 'acordos não se cruzam entre carteiras');
        self::assertSame(12000, $parcelaA?->getValorOriginal());
        self::assertSame(8000, $parcelaB?->getValorOriginal());
    }

    #[TestDox('Override de honorários zero do acordo vence AO VIVO na hidratação real (não só no cache do import)')]
    public function testHonorarioDoAcordoFicaZeroNaHidratacaoAoVivoReal(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // Relatório com honorário INFORMADO > 0 na linha do acordo (decisão do dono: o override
        // taxaHonorariosBp=0 tem de vencer mesmo assim — acordo não cobra honorário sobre honorário).
        $boleto = $this->boletoComAcordo('9601', '50-01', 'DEVEDOR HONORARIO ACORDO', numeroAcordo: 90, parcelaIndice: 1, parcelaTotal: 1, somaColunaValorCentavos: 20000, honorariosInformadosCentavos: 6000);
        $this->importar->confirmar($carteira, new ResultadoLeitura([$boleto], [], 0), $tenant, $user);

        // Lê como uma nova requisição leria (mesmo padrão dos outros testes de acordo desta classe): a
        // coleção inversa `Acordo::parcelas` não é auto-sincronizada em memória ao setar só o lado dono,
        // então sem o `clear()` a hidratação abaixo não acharia a parcela pra hidratar.
        $this->em->clear();

        $repo = $this->em->getRepository(Obrigacao::class);
        $parcela = $repo->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '9601']);
        self::assertNotNull($parcela);
        self::assertSame(0, $parcela->getTaxaHonorariosBp(), 'override zero gravado na parcela');
        // Cache CRU do import: `materializarEncargosImportados` grava o honorário INFORMADO como veio
        // do relatório (não aplica o override na hora de cachear) — é exatamente esse cache que uma
        // leitura ingênua exibiria errado se não passasse pela hidratação ao vivo.
        self::assertSame(6000, $parcela->getHonorarios(), 'cache cru = valor informado no relatório (ainda sem o override aplicado)');

        $acordo = $parcela->getAcordoOrigem();
        self::assertNotNull($acordo);

        /** @var \App\Cobranca\UseCase\MontarDetalheAcordoUseCase $montarDetalheAcordo */
        $montarDetalheAcordo = static::getContainer()->get(\App\Cobranca\UseCase\MontarDetalheAcordoUseCase::class);
        $montarDetalheAcordo->executar($acordo, $tenant);

        // Mesma instância managed (identity map do Doctrine): a hidratação em memória reescreveu o cache.
        self::assertSame(0, $parcela->getHonorarios(), 'ao vivo: override taxaHonorariosBp=0 vence, honorário cru é sobrescrito para 0');
    }

    // ----------------------------------------------------------------- helpers

    /** Boleto sintético com acordo reconhecido — usado pelos testes de #7-B (sem depender do xlsx). */
    private function boletoComAcordo(
        string $nn,
        string $objetoIdentificacao,
        string $sacadoNome,
        int $numeroAcordo,
        int $parcelaIndice,
        int $parcelaTotal,
        int $somaColunaValorCentavos,
        int $honorariosInformadosCentavos = 0,
    ): BoletoImportavel {
        return new BoletoImportavel(
            nn: $nn,
            objetoIdentificacao: $objetoIdentificacao,
            unidadeMetadata: null,
            sacadoNome: $sacadoNome,
            // Sem sentido de "principal" quando há acordo (o UseCase usa somaColunaValorCentavos), mas o
            // adapter real sempre entrega principalCentavos > 0 — mantido coerente com a fonte.
            principalCentavos: $somaColunaValorCentavos,
            jurosCentavos: 0,
            multaCentavos: 0,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: $honorariosInformadosCentavos,
            vencimento: new \DateTimeImmutable('2026-03-10'),
            competencia: '03/2026',
            acordoTexto: "Acordo {$numeroAcordo} - Parc. {$parcelaIndice}/{$parcelaTotal}",
            acordo: new AcordoDoRelatorio($numeroAcordo, $parcelaIndice, $parcelaTotal),
            somaColunaValorCentavos: $somaColunaValorCentavos,
            linhas: [],
        );
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant IMPORT ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('import_' . uniqid() . '@test.com');
        $user->setFullName('User Importação');
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
