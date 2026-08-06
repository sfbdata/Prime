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
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Spec: `docs/specs/cobranca-importe-nao-duplica-devedor-do-cadastro.md`.
 *
 * Cenário que só passou a existir quando a AMLI BR 060 recebeu o relatório de **Dados cadastrais**:
 * a unidade JÁ existe com a pessoa vinculada (nome + CPF + contato), mas ainda NÃO tem caso de
 * cobrança. Antes desta correção o importe criava uma SEGUNDA pessoa com o mesmo nome, sem documento,
 * e abria o caso apontando para ela — medido: 9 unidades pela inadimplência e 45 pela receita, de 51.
 *
 * Testa os DOIS sentidos (nome igual reusa · nome diferente continua criando) porque unir demais é
 * tão defeito quanto duplicar: `QUADRA D LOTE 03` da AMLI tem dois proprietários DIFERENTES.
 */
#[CoversClass(ImportarRelatorioCarteiraUseCase::class)]
#[CoversClass(ResolvedorPessoaNoObjeto::class)]
final class ImporteNaoDuplicaDevedorDoCadastroTest extends KernelTestCase
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

    #[TestDox('Unidade do cadastro (pessoa vinculada, sem caso): o importe REUSA a pessoa, não duplica')]
    public function testReusaPessoaJaVinculadaAoObjetoQuandoNaoHaCaso(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // Estado que o importador de CADASTRO deixa: objeto + pessoa (com CPF) + vínculo, e NENHUM caso.
        $pessoaDoCadastro = $this->criarUnidadeDoCadastro($tenant, $carteira, 'QUADRA B LOTE 15', 'ADRIANA ALVES RAMOS', '72395990191');

        $resultado = $this->importar->confirmar(
            $carteira,
            new ResultadoLeitura([$this->boleto('QUADRA B LOTE 15', 'ADRIANA ALVES RAMOS')], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(0, $resultado->pessoasCriadas, 'a pessoa do cadastro tem de ser REUSADA, não duplicada');
        self::assertSame(1, $resultado->casosCriados, 'o caso continua sendo aberto');
        self::assertSame(1, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]), 'segue existindo UMA pessoa no tenant');

        // O caso tem de cobrar a pessoa COMPLETA (a do cadastro, com CPF), não uma cópia sem documento.
        $objeto = $this->em->getRepository(ObjetoCobranca::class)->findOneBy(['tenant' => $tenant, 'identificacao' => 'QUADRA B LOTE 15']);
        $caso = $this->em->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objeto]);
        $cobrada = $caso?->getPessoaCobradaAtual();
        self::assertSame($pessoaDoCadastro->getId(), $cobrada?->getId(), 'o caso aponta para a pessoa do cadastro');
        self::assertSame('72395990191', $cobrada?->getCpf(), 'e ela é a que tem CPF — o defeito cobrava a cópia sem documento');

        // Reusar não pode criar vínculo a mais: o objeto continua com UM vínculo, o do cadastro.
        self::assertCount(
            1,
            $this->em->getRepository(VinculoPessoaObjeto::class)->findBy(['objeto' => $objeto]),
            'nenhum vínculo duplicado nasce quando a pessoa é reusada',
        );
    }

    #[TestDox('Sentido contrário: sacado com nome DIFERENTE na mesma unidade continua criando pessoa')]
    public function testSacadoComNomeDiferenteContinuaCriandoPessoa(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // Caso real da AMLI: QUADRA D LOTE 03 tem DOIS proprietários distintos. Unir seria defeito.
        $this->criarUnidadeDoCadastro($tenant, $carteira, 'QUADRA D LOTE 03', 'CARLOS ALBERTO DE LIMA', '35172711104');

        $resultado = $this->importar->confirmar(
            $carteira,
            new ResultadoLeitura([$this->boleto('QUADRA D LOTE 03', 'EDUARDO TAVARES DE LIMA')], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(1, $resultado->pessoasCriadas, 'nome diferente é OUTRA pessoa: continua nascendo');
        self::assertSame(2, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]), 'as duas convivem na unidade');
    }

    #[TestDox('O nome é comparado normalizado: caixa e espaços não criam pessoa duplicada')]
    public function testComparacaoDeNomeEhNormalizada(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $this->criarUnidadeDoCadastro($tenant, $carteira, 'QUADRA E LOTE 07', 'ROBSON DOS REIS SILVA', '03212892188');

        $resultado = $this->importar->confirmar(
            $carteira,
            new ResultadoLeitura([$this->boleto('QUADRA E LOTE 07', '  robson  dos reis   silva ')], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(0, $resultado->pessoasCriadas, 'mesma pessoa escrita diferente continua sendo a mesma');
        self::assertSame(1, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('Prévia e confirmação dão o MESMO número de pessoas criadas neste cenário')]
    public function testPreviaEConfirmacaoBatemNoCenarioDoCadastro(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        $this->criarUnidadeDoCadastro($tenant, $carteira, 'QUADRA B LOTE 15', 'ADRIANA ALVES RAMOS', '72395990191');
        $leitura = new ResultadoLeitura([$this->boleto('QUADRA B LOTE 15', 'ADRIANA ALVES RAMOS')], [], 0);

        // A prévia roda ANTES, contra o mesmo estado — é o número que o operador lê para decidir.
        $previa = $this->importar->prever($carteira, $leitura, $tenant);
        $confirmado = $this->importar->confirmar($carteira, $leitura, $tenant, $user);

        self::assertSame(
            $previa->pessoasCriadas,
            $confirmado->pessoasCriadas,
            'a prévia não pode prometer pessoa que a confirmação não cria',
        );
        self::assertSame(0, $previa->pessoasCriadas, 'e o número certo é ZERO nos dois');
        self::assertSame($previa->casosCriados, $confirmado->casosCriados, 'idem para os casos');
    }

    #[TestDox('Isolamento: pessoa de OUTRO objeto com o mesmo nome não é reusada')]
    public function testNaoReusaPessoaDeOutroObjeto(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // A pessoa existe no tenant, mas vinculada a OUTRA unidade. A regra é por objeto (spec §3).
        $this->criarUnidadeDoCadastro($tenant, $carteira, 'QUADRA A LOTE 01', 'JORGE DA COSTA DOMINGUES', '82200033168');

        $resultado = $this->importar->confirmar(
            $carteira,
            new ResultadoLeitura([$this->boleto('QUADRA Z LOTE 99', 'JORGE DA COSTA DOMINGUES')], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(1, $resultado->objetosCriados, 'a unidade nova nasce');
        self::assertSame(1, $resultado->pessoasCriadas, 'e a pessoa dela também: reuso é DENTRO do objeto');
        self::assertSame(2, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('Duas linhas do MESMO objeto: a de nome igual reusa e a divergente cria — sem se atrapalharem')]
    public function testLinhaQueReusaELinhaDivergenteNoMesmoObjeto(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteira = $this->criarCarteira($tenant);

        // É o único caminho em que o ramo NOVO (caso nulo → reusa) e o `elseif` do §28 (sacado
        // divergente → cria e só reporta) decidem em sequência sobre o mesmo objeto.
        $pessoaDoCadastro = $this->criarUnidadeDoCadastro($tenant, $carteira, 'QUADRA D LOTE 03', 'CARLOS ALBERTO DE LIMA', '35172711104');

        $resultado = $this->importar->confirmar(
            $carteira,
            new ResultadoLeitura([
                $this->boleto('QUADRA D LOTE 03', 'CARLOS ALBERTO DE LIMA'),
                $this->boleto('QUADRA D LOTE 03', 'EDUARDO TAVARES DE LIMA', nn: '9002'),
            ], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(1, $resultado->pessoasCriadas, 'só a divergente nasce; a do cadastro é reusada');
        self::assertSame(1, $resultado->casosCriados);
        self::assertSame(['QUADRA D LOTE 03'], $resultado->sacadosDivergentes, 'a segunda é reportada, não silenciada');
        self::assertSame(2, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));

        // §28: a cobrada continua sendo a primeira — quem troca é humano.
        $objeto = $this->em->getRepository(ObjetoCobranca::class)->findOneBy(['tenant' => $tenant, 'identificacao' => 'QUADRA D LOTE 03']);
        $caso = $this->em->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objeto]);
        self::assertSame($pessoaDoCadastro->getId(), $caso?->getPessoaCobradaAtual()?->getId());
    }

    /**
     * Provado por injeção: trocando o resolvedor por uma busca global (ignorando objeto e tenant), este
     * teste fica VERMELHO — com `PessoaNaoEncontradaException` vinda de `AbrirCasoUseCase`, que
     * revalida a pessoa por tenant e derruba a transação inteira.
     *
     * ⚠️ O que ele NÃO prova (achado da 2ª revisão): o filtro `v.tenant` da query do vínculo não é
     * exercitado aqui, porque a unidade do tenant B é criada pelo próprio importe e nasce sem vínculo
     * nenhum — a query devolveria vazio com ou sem o filtro. O que este teste trava é a busca por nome
     * escapando do objeto/tenant, e a segunda barreira que a impede de virar cobrança.
     */
    #[TestDox('🔒 Cross-tenant: pessoa homônima de OUTRO escritório nunca é reusada')]
    public function testNaoReusaPessoaDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraB = $this->criarCarteira($tenantB);

        // O escritório A tem a unidade e a pessoa. O importe roda no escritório B, com a MESMA
        // identificação de unidade e o MESMO nome de sacado — o pior caso para um vazamento.
        $carteiraA = $this->criarCarteira($tenantA);
        $pessoaDoOutroEscritorio = $this->criarUnidadeDoCadastro($tenantA, $carteiraA, 'QUADRA B LOTE 15', 'ADRIANA ALVES RAMOS', '72395990191');

        $resultado = $this->importar->confirmar(
            $carteiraB,
            new ResultadoLeitura([$this->boleto('QUADRA B LOTE 15', 'ADRIANA ALVES RAMOS')], [], 0),
            $tenantB,
            $user,
        );

        self::assertSame(1, $resultado->objetosCriados, 'a unidade do outro escritório não é vista');
        self::assertSame(1, $resultado->pessoasCriadas, 'nem a pessoa dele');

        $objetoB = $this->em->getRepository(ObjetoCobranca::class)->findOneBy(['tenant' => $tenantB, 'identificacao' => 'QUADRA B LOTE 15']);
        $casoB = $this->em->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objetoB]);
        $cobradaB = $casoB?->getPessoaCobradaAtual();
        self::assertNotSame(
            $pessoaDoOutroEscritorio->getId(),
            $cobradaB?->getId(),
            'jamais cobrar a pessoa de outro escritório',
        );
        self::assertSame($tenantB->getId(), $cobradaB?->getTenant()?->getId(), 'a cobrada é do próprio tenant');
        self::assertSame(1, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenantA]), 'o tenant A fica intacto');
    }

    private function criarUnidadeDoCadastro(Tenant $tenant, int $carteiraId, string $unidade, string $nome, string $cpf): Pessoa
    {
        $carteira = $this->em->getRepository(Carteira::class)->find($carteiraId);
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $carteira,
            'identificacao' => $unidade,
        ])->_real();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nome, 'cpf' => $cpf])->_real();
        VinculoPessoaObjetoFactory::createOne(['tenant' => $tenant, 'pessoa' => $pessoa, 'objeto' => $objeto]);

        return $pessoa;
    }

    private function boleto(string $unidade, string $sacado, string $nn = '9001'): BoletoImportavel
    {
        return new BoletoImportavel(
            nn: $nn,
            objetoIdentificacao: $unidade,
            unidadeMetadata: null,
            sacadoNome: $sacado,
            principalCentavos: 17000,
            jurosCentavos: 0,
            multaCentavos: 0,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: 0,
            vencimento: new \DateTimeImmutable('2026-02-10'),
            competencia: '02/2026',
            acordoTexto: null,
            acordo: null,
            somaColunaValorCentavos: 17000,
            linhas: [],
        );
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant DUP ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('dup_' . uniqid() . '@test.com');
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
