<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use App\Cobranca\Service\RegistrarEventoHistorico;
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

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);
        $this->importar = new ImportarRelatorioCarteiraUseCase(
            $carteiraRepo,
            $objetoRepo,
            $casoRepo,
            $obrigacaoRepo,
            $vinculoRepo,
            new CriarObjetoUseCase($objetoRepo, $carteiraRepo),
            new CriarPessoaUseCase($pessoaRepo),
            new VincularPessoaAObjetoUseCase($vinculoRepo, $pessoaRepo, $objetoRepo),
            new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento),
            new RegistrarObrigacaoUseCase($obrigacaoRepo, $casoRepo, $registrarEvento),
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

    #[TestDox('Índice único rejeita duas obrigações com a mesma referência externa no mesmo caso')]
    public function testIndiceUnicoBloqueiaObrigacaoDuplicada(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);
        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        $existente = $this->em->getRepository(Obrigacao::class)->findOneBy(['tenant' => $tenant, 'referenciaExterna' => '1001']);
        self::assertNotNull($existente);

        // Insere à força uma 2ª obrigação com a MESMA (caso, referencia_externa) → o índice parcial barra.
        $duplicada = new Obrigacao();
        $duplicada->setTenant($tenant);
        $duplicada->setCaso($existente->getCaso());
        $duplicada->setDescricao('duplicada proibida');
        $duplicada->setValorOriginal(1000);
        $duplicada->setVencimentoOriginal(new \DateTimeImmutable('2026-02-10'));
        $duplicada->setReferenciaExterna('1001');

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
            encargosCentavos: 1317,
            honorariosInformadosCentavos: 4063,
            vencimento: new \DateTimeImmutable('2026-02-10'),
            competencia: '02/2026',
            acordoTexto: null,
            linhas: [],
        );
        $leitura = new \App\Cobranca\Service\Importacao\ResultadoLeitura([$boleto], [], 0);

        $resultado = $this->importar->confirmar($carteira, $leitura, $tenant, $user);

        self::assertSame(1, $resultado->pessoasCriadas, 'novo sacado vira nova Pessoa');
        self::assertSame(['01-01'], $resultado->sacadosDivergentes);
        self::assertSame(0, $resultado->totalImportadas());
        self::assertSame(1, $resultado->totalAtualizadas(), 'a obrigação NN=1001 já existia');

        // A pessoa cobrada do caso PERMANECE a original (troca é decisão humana, §28).
        $caso = $this->em->getRepository(CasoCobranca::class)->findOneBy(['tenant' => $tenant]);
        self::assertSame('DEVEDOR UM EXEMPLO', $caso?->getPessoaCobradaAtual()?->getNome());
        // Agora há 5 pessoas no tenant (4 do 1º import + a nova divergente).
        self::assertSame(5, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));

        // IDEMPOTÊNCIA (correção B1): reimportar o MESMO sacado divergente NÃO recria a Pessoa/vínculo.
        $reimport = $this->importar->confirmar($carteira, $leitura, $tenant, $user);
        self::assertSame(0, $reimport->pessoasCriadas, 'sacado divergente já vinculado não recria Pessoa');
        self::assertSame([], $reimport->sacadosDivergentes);
        self::assertSame(5, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]), 'sem acúmulo de duplicatas');
    }

    // ----------------------------------------------------------------- helpers

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
