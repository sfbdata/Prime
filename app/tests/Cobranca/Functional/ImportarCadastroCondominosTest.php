<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Service\Importacao\CadastroCondominosAdapter;
use App\Cobranca\UseCase\ImportarCadastroCondominosUseCase;
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
 * Importação do cadastro de condôminos contra o BANCO REAL (spec
 * `docs/specs/cobranca-importar-cadastro-condominos.md`), com a fixture anonimizada
 * `cadastro_condominos_amostra.xlsx`, que reproduz os casos-limite medidos no dado real:
 * telefone lixo `null () null`, dois telefones numa célula, CNPJ, pessoa sem documento,
 * "Pessoa relacionada" na mesma unidade do proprietário, papel desconhecido e rodapé.
 */
#[CoversClass(ImportarCadastroCondominosUseCase::class)]
#[CoversClass(CadastroCondominosAdapter::class)]
final class ImportarCadastroCondominosTest extends KernelTestCase
{
    use Factories;

    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/cadastro_condominos_amostra.xlsx';

    private EntityManagerInterface $em;
    private ImportarCadastroCondominosUseCase $importar;
    private CadastroCondominosAdapter $adapter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->importar = static::getContainer()->get(ImportarCadastroCondominosUseCase::class);
        $this->adapter = static::getContainer()->get(CadastroCondominosAdapter::class);
    }

    #[TestDox('Adapter lê o layout: separa papéis, descarta rodapé e rejeita linha sem nome')]
    public function testAdapterLeLayout(): void
    {
        $leitura = $this->adapter->ler(self::FIXTURE);

        self::assertCount(7, $leitura->importaveis, '6 proprietários + 1 pessoa relacionada');
        self::assertGreaterThanOrEqual(1, count($leitura->rejeitadas), 'a linha sem nome é rejeitada');
        self::assertGreaterThan(0, $leitura->linhasIgnoradas, 'cabeçalho, rodapé e papel desconhecido');

        $motivos = array_map(static fn ($r): string => $r->motivo, $leitura->rejeitadas);
        self::assertNotEmpty(array_filter($motivos, static fn (string $m): bool => str_contains($m, 'sem nome')));
    }

    #[TestDox('Telefone lixo "null () null" não vira telefone, mas a pessoa entra assim mesmo')]
    public function testTelefoneLixoNaoViraTelefone(): void
    {
        $leitura = $this->adapter->ler(self::FIXTURE);
        $bruno = $this->porNome($leitura->importaveis, 'BRUNO EXEMPLO SOUZA');

        self::assertSame([], $bruno->telefones, 'lixo descartado');
        $motivos = array_map(static fn ($r): string => $r->motivo, $leitura->rejeitadas);
        self::assertNotEmpty(
            array_filter($motivos, static fn (string $m): bool => str_contains($m, 'null () null')),
            'o descarte precisa aparecer no resumo — silêncio aqui é o defeito',
        );
    }

    #[TestDox('Dois telefones na mesma célula viram dois telefones')]
    public function testDoisTelefonesNaMesmaCelula(): void
    {
        $leitura = $this->adapter->ler(self::FIXTURE);
        $carla = $this->porNome($leitura->importaveis, 'CARLA EXEMPLO LIMA');

        self::assertCount(2, $carla->telefones);
    }

    #[TestDox('Lixo convivendo com telefone bom na mesma célula também é reportado')]
    public function testLixoJuntoComTelefoneBomEhReportado(): void
    {
        $leitura = $this->adapter->ler(self::FIXTURE);
        $gina = $this->porNome($leitura->importaveis, 'GINA EXEMPLO CASTRO');

        self::assertSame(['+55 (61) 900000007'], $gina->telefones, 'o número bom entra');

        $motivos = array_map(static fn ($r): string => $r->motivo, $leitura->rejeitadas);
        self::assertNotEmpty(
            array_filter($motivos, static fn (string $m): bool => str_contains($m, 'descartado')),
            'descartar em silêncio é o defeito — mesmo quando sobrou telefone bom',
        );
    }

    #[TestDox('Endereço é parseado de trás para frente (com e sem complemento)')]
    public function testEnderecoParseado(): void
    {
        $leitura = $this->adapter->ler(self::FIXTURE);

        $ana = $this->porNome($leitura->importaveis, 'ANA EXEMPLO DA SILVA');
        self::assertSame('Área Rural', $ana->endereco['logradouro']);
        self::assertSame('S/N', $ana->endereco['numero']);
        self::assertSame('QUADRA 01 CHACARA 01/01', $ana->endereco['complemento'] ?? null);
        self::assertSame('GO', $ana->endereco['uf']);
        self::assertSame('72900000', $ana->endereco['cep']);

        // A "Pessoa relacionada" tem endereço curto (6 segmentos): sem complemento, resto igual.
        $eva = $this->porNome($leitura->importaveis, 'EVA EXEMPLO PEREIRA');
        self::assertArrayNotHasKey('complemento', $eva->endereco);
        self::assertSame('LAGO EXEMPLO', $eva->endereco['bairro'], 'o bairro não pode escorregar por falta do complemento');
    }

    #[TestDox('"Pessoa relacionada" vira vínculo Outro, nunca Coproprietário (a fonte não afirma isso)')]
    public function testPessoaRelacionadaViraOutro(): void
    {
        $leitura = $this->adapter->ler(self::FIXTURE);

        self::assertSame(TipoVinculo::Proprietario, $this->porNome($leitura->importaveis, 'ANA EXEMPLO DA SILVA')->tipoVinculo);
        self::assertSame(TipoVinculo::Outro, $this->porNome($leitura->importaveis, 'EVA EXEMPLO PEREIRA')->tipoVinculo);
    }

    #[TestDox('Importa cria unidades, pessoas, vínculos e contatos')]
    public function testImportaCriaEstrutura(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser($tenant);
        $carteira = $this->criarCarteira($tenant);

        $resultado = $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        self::assertSame(6, $resultado->totalObjetosCriados(), '6 unidades distintas (a linha da relacionada repete a 01/01)');
        self::assertSame(7, $resultado->totalPessoasCriadas());
        self::assertSame(7, $resultado->totalVinculosCriados());

        self::assertSame(6, $this->em->getRepository(ObjetoCobranca::class)->count(['tenant' => $tenant]));
        self::assertSame(7, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));
        self::assertSame(7, $this->em->getRepository(VinculoPessoaObjeto::class)->count(['tenant' => $tenant]));

        // A unidade 01/01 tem DUAS pessoas: proprietária e relacionada, ambas com vínculo aberto.
        $objeto = $this->em->getRepository(ObjetoCobranca::class)->findOneBy(['tenant' => $tenant, 'identificacao' => 'QUADRA 01 CHACARA 01/01']);
        self::assertNotNull($objeto);
        self::assertSame(2, $this->em->getRepository(VinculoPessoaObjeto::class)->count(['tenant' => $tenant, 'objeto' => $objeto]));

        $carla = $this->em->getRepository(Pessoa::class)->findOneBy(['tenant' => $tenant, 'nome' => 'CARLA EXEMPLO LIMA']);
        self::assertNotNull($carla);
        self::assertCount(2, $carla->getTelefones(), 'os dois números da mesma célula');
    }

    #[TestDox('Reimportar não duplica nada (idempotência da rodada mensal)')]
    public function testReimportacaoEhIdempotente(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser($tenant);
        $carteira = $this->criarCarteira($tenant);

        $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);
        $segunda = $this->importar->confirmar($carteira, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        self::assertSame(0, $segunda->totalObjetosCriados(), 'unidade já existe');
        self::assertSame(0, $segunda->totalVinculosCriados(), 'vínculo já aberto');
        self::assertSame(0, $segunda->telefonesAcrescentados, 'telefone idêntico é no-op');
        self::assertSame(0, $segunda->emailsAcrescentados);
        self::assertSame(0, $segunda->enderecosAcrescentados);

        self::assertSame(6, $this->em->getRepository(ObjetoCobranca::class)->count(['tenant' => $tenant]));
        self::assertSame(7, $this->em->getRepository(VinculoPessoaObjeto::class)->count(['tenant' => $tenant]));

        $carla = $this->em->getRepository(Pessoa::class)->findOneBy(['tenant' => $tenant, 'nome' => 'CARLA EXEMPLO LIMA']);
        self::assertCount(2, $carla?->getTelefones(), 'continuam 2 telefones, não 4');
    }

    #[TestDox('Pessoa sem documento NÃO é casada por nome — cria nova (homônimo não funde)')]
    public function testSemDocumentoNaoCasaPorNome(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser($tenant);
        $carteiraUm = $this->criarCarteira($tenant);
        $carteiraDois = $this->criarCarteira($tenant);

        $this->importar->confirmar($carteiraUm, $this->adapter->ler(self::FIXTURE), $tenant, $user);
        $this->importar->confirmar($carteiraDois, $this->adapter->ler(self::FIXTURE), $tenant, $user);

        // DANIEL não tem CPF na fixture: em duas carteiras vira duas pessoas — de propósito.
        self::assertSame(2, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant, 'nome' => 'DANIEL EXEMPLO ROCHA']));
        // ANA tem CPF: é reaproveitada, não duplicada.
        self::assertSame(1, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant, 'nome' => 'ANA EXEMPLO DA SILVA']));
    }

    #[TestDox('Nunca importa numa carteira de outro escritório')]
    public function testNaoCruzaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA = $this->criarUser($tenantA);
        $carteiraB = $this->criarCarteira($tenantB);

        $this->expectException(CarteiraNaoEncontradaException::class);
        $this->importar->confirmar($carteiraB, $this->adapter->ler(self::FIXTURE), $tenantA, $userA);
    }

    #[TestDox('Dry-run não persiste nada')]
    public function testDryRunNaoPersiste(): void
    {
        $tenant = $this->criarTenant();
        $this->criarUser($tenant);
        $carteira = $this->criarCarteira($tenant);

        $previa = $this->importar->prever($carteira, $this->adapter->ler(self::FIXTURE), $tenant);

        self::assertSame(6, $previa->totalObjetosCriados());
        self::assertSame(7, $previa->totalPessoasCriadas());
        self::assertSame(0, $this->em->getRepository(ObjetoCobranca::class)->count(['tenant' => $tenant]), 'prévia não grava');
        self::assertSame(0, $this->em->getRepository(Pessoa::class)->count(['tenant' => $tenant]));
    }

    /** @param list<\App\Cobranca\Service\Importacao\CondominoImportavel> $lista */
    private function porNome(array $lista, string $nome): \App\Cobranca\Service\Importacao\CondominoImportavel
    {
        foreach ($lista as $condomino) {
            if ($condomino->nome === $nome) {
                return $condomino;
            }
        }

        self::fail(sprintf('Condômino "%s" não encontrado na leitura.', $nome));
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant CADASTRO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail('cadastro_' . uniqid() . '@test.com');
        $user->setFullName('User Cadastro');
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
