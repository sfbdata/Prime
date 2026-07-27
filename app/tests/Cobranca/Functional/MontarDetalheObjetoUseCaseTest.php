<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\CasoDetalheOutput;
use App\Cobranca\DTO\ObjetoDetalheOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Cobranca\UseCase\MontarDetalheObjetoUseCase;
use App\Cobranca\UseCase\MontarFichaPessoaUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Fatia 1 do ajuste 2 (objeto=caso unificado): a leitura da página do objeto contra o BANCO REAL.
 * Prova que `MontarDetalheObjetoUseCase` embrulha o `CasoDetalheOutput` do caso âncora e agrega os
 * vínculos do objeto, marcando exatamente quem é a pessoa cobrada atual. E que o resolvedor
 * `casoAncoraDoObjeto` devolve o caso ativo (ou null quando o objeto ainda não tem caso).
 */
#[CoversClass(MontarDetalheObjetoUseCase::class)]
#[CoversClass(CasoCobrancaRepository::class)]
#[CoversClass(VinculoPessoaObjetoRepository::class)]
final class MontarDetalheObjetoUseCaseTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private MontarDetalheObjetoUseCase $sut;
    private CasoCobrancaRepository $casoRepo;
    private ObjetoCobrancaRepository $objetoRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var MontarDetalheCasoUseCase $montarDetalheCaso */
        $montarDetalheCaso = static::getContainer()->get(MontarDetalheCasoUseCase::class);
        /** @var VinculoPessoaObjetoRepository $vinculoRepo */
        $vinculoRepo = $this->em->getRepository(VinculoPessoaObjeto::class);
        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        $this->casoRepo = $casoRepo;

        /** @var MontarFichaPessoaUseCase $montarFichaPessoa */
        $montarFichaPessoa = static::getContainer()->get(MontarFichaPessoaUseCase::class);
        /** @var ObjetoCobrancaRepository $objetoRepo */
        $objetoRepo = $this->em->getRepository(ObjetoCobranca::class);
        $this->objetoRepo = $objetoRepo;

        $this->sut = new MontarDetalheObjetoUseCase($montarDetalheCaso, $vinculoRepo, $montarFichaPessoa, $objetoRepo);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant DO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function objeto(Tenant $tenant, string $identificacao, ?string $descricao, ?Carteira $carteira = null): ObjetoCobranca
    {
        return ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $carteira ?? $this->carteira($tenant),
            'identificacao' => $identificacao,
            'descricao' => $descricao,
        ])->_real();
    }

    private function carteira(Tenant $tenant): Carteira
    {
        return CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
        ])->_real();
    }

    #[TestDox('embrulha o CasoDetalheOutput e agrega os vínculos marcando a cobrada atual')]
    public function testMontaDetalheDoObjetoComVinculosEMarcaCobradaAtual(): void
    {
        $tenant = $this->tenant();
        $objeto = $this->objeto($tenant, 'Apto 302', 'Bloco B');

        $joao = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'João Silva'])->_real();
        $maria = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Maria Souza'])->_real();

        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => $joao,
            'status' => StatusCaso::Ativo,
        ])->_real();

        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto, 'pessoa' => $joao,
            'tipoVinculo' => TipoVinculo::Proprietario,
        ]);
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto, 'pessoa' => $maria,
            'tipoVinculo' => TipoVinculo::Representante,
        ]);

        $out = $this->sut->executar($objeto, $caso);

        self::assertInstanceOf(ObjetoDetalheOutput::class, $out);
        self::assertSame('Apto 302', $out->identificacao);
        self::assertSame('Bloco B', $out->descricao);
        self::assertInstanceOf(CasoDetalheOutput::class, $out->caso);
        self::assertSame($caso->getId(), $out->caso->id);
        self::assertTrue($out->temCobradaAtual);

        self::assertCount(2, $out->vinculos);
        $cobradas = array_values(array_filter($out->vinculos, static fn ($v) => $v->ehCobradaAtual));
        self::assertCount(1, $cobradas);
        self::assertSame('João Silva', $cobradas[0]->nome);
        self::assertSame('Proprietário', $cobradas[0]->papelLabel);
    }

    #[TestDox('a ficha da pessoa cobrada atual vem completa, com a lista de telefones da ficha')]
    public function testFichaDaCobradaVemCompletaComOsTelefones(): void
    {
        $tenant = $this->tenant();
        $objeto = $this->objeto($tenant, 'Apto 302', null);

        $joao = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'João Silva', 'cpf' => '11144477735'])->_real();
        $this->telefone($tenant, $joao, '21988887777', atual: true);
        $this->telefone($tenant, $joao, '2133334444', atual: false);

        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoaCobradaAtual' => $joao, 'status' => StatusCaso::Ativo,
        ])->_real();

        $out = $this->sut->executar($objeto, $caso);

        self::assertNotNull($out->fichaCobrada);
        self::assertSame($joao->getId(), $out->fichaCobrada->id);
        self::assertSame('João Silva', $out->fichaCobrada->nome);
        // É a LISTA da ficha (§2.3), não o telefone único derivado que a aba mostrava antes.
        self::assertCount(2, $out->fichaCobrada->telefones);
        self::assertSame(
            ['21988887777', '2133334444'],
            array_map(static fn ($t) => $t->numero, $out->fichaCobrada->telefones),
        );
        self::assertTrue($out->fichaCobrada->telefones[0]->atual);
    }

    #[TestDox('sem pessoa cobrada atual a ficha vem nula, sem consulta perdida')]
    public function testSemCobradaAtualAFichaEhNula(): void
    {
        $tenant = $this->tenant();
        $objeto = $this->objeto($tenant, 'Apto 404', null);

        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => StatusCaso::Ativo,
        ])->_real();

        // `setPessoaCobradaAtual` não aceita null (o caminho normal sempre tem cobrada), mas a coluna é
        // nulável e o dado legado chega assim — é o cenário que `temCobradaAtual` já contemplava antes
        // desta etapa. Zerar em memória reproduz exatamente isso, sem persistir um estado inválido.
        (new \ReflectionProperty(CasoCobranca::class, 'pessoaCobradaAtual'))->setValue($caso, null);

        $out = $this->sut->executar($objeto, $caso);

        self::assertFalse($out->temCobradaAtual);
        self::assertNull($out->fichaCobrada);
    }

    /**
     * A vizinhança das setas `‹ ›` (spec §1.5). Ordem `identificacao ASC, id ASC` — estável entre
     * visitas, ao contrário da listagem da carteira (`atualizadoEm DESC`), que muda sozinha a cada
     * registro e faria a mesma seta levar a lugares diferentes.
     */
    #[TestDox('as setas apontam para o vizinho por identificacao ASC e ficam nulas nas pontas')]
    public function testVizinhosNaCarteiraSeguemIdentificacaoAsc(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        // Criados FORA de ordem de propósito: a ordem é da identificação, não da inserção.
        $meio = $this->objeto($tenant, 'Apto 202', null, $carteira);
        $ultimo = $this->objeto($tenant, 'Apto 303', null, $carteira);
        $primeiro = $this->objeto($tenant, 'Apto 101', null, $carteira);

        self::assertSame(
            ['anteriorId' => null, 'proximoId' => $meio->getId()],
            $this->objetoRepo->vizinhosNaCarteira($primeiro),
        );
        self::assertSame(
            ['anteriorId' => $primeiro->getId(), 'proximoId' => $ultimo->getId()],
            $this->objetoRepo->vizinhosNaCarteira($meio),
        );
        self::assertSame(
            ['anteriorId' => $meio->getId(), 'proximoId' => null],
            $this->objetoRepo->vizinhosNaCarteira($ultimo),
        );

        // E é isso que chega ao DTO da página.
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $meio,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => StatusCaso::Ativo,
        ])->_real();

        $out = $this->sut->executar($meio, $caso);
        self::assertSame($primeiro->getId(), $out->objetoAnteriorId);
        self::assertSame($ultimo->getId(), $out->objetoProximoId);
    }

    /**
     * Duas unidades podem ter a MESMA identificação na mesma carteira (nada no banco impede). Sem o
     * desempate por `id`, a comparação `>`/`<` pularia a gêmea — ou entraria em laço entre as duas.
     */
    #[TestDox('identificações repetidas desempatam por id, sem pular nem repetir a gêmea')]
    public function testVizinhosDesempatamPorIdQuandoAIdentificacaoRepete(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        $gemeaA = $this->objeto($tenant, 'Casa 1', null, $carteira);
        $gemeaB = $this->objeto($tenant, 'Casa 1', null, $carteira);
        $seguinte = $this->objeto($tenant, 'Casa 2', null, $carteira);

        self::assertGreaterThan($gemeaA->getId(), $gemeaB->getId());

        self::assertSame(
            ['anteriorId' => null, 'proximoId' => $gemeaB->getId()],
            $this->objetoRepo->vizinhosNaCarteira($gemeaA),
            'a gêmea de id maior é o próximo, não a Casa 2',
        );
        self::assertSame(
            ['anteriorId' => $gemeaA->getId(), 'proximoId' => $seguinte->getId()],
            $this->objetoRepo->vizinhosNaCarteira($gemeaB),
        );
    }

    #[TestDox('a seta nunca atravessa carteira nem tenant')]
    public function testVizinhosNaoAtravessamCarteiraNemTenant(): void
    {
        $tenant = $this->tenant();
        $outroTenant = $this->tenant();

        $carteira = $this->carteira($tenant);
        $atual = $this->objeto($tenant, 'Apto 100', null, $carteira);
        $vizinhoLegitimo = $this->objeto($tenant, 'Apto 300', null, $carteira);

        // Ambos ficariam ENTRE 100 e 300 se a consulta vazasse — e roubariam a seta do legítimo.
        $this->objeto($tenant, 'Apto 200', null, $this->carteira($tenant));
        $this->objeto($outroTenant, 'Apto 200', null, $this->carteira($outroTenant));

        self::assertSame(
            ['anteriorId' => null, 'proximoId' => $vizinhoLegitimo->getId()],
            $this->objetoRepo->vizinhosNaCarteira($atual),
        );
    }

    private function telefone(Tenant $tenant, Pessoa $pessoa, string $numero, bool $atual): void
    {
        $telefone = (new PessoaTelefone())
            ->setTenant($tenant)
            ->setPessoa($pessoa)
            ->setNumero($numero)
            ->setAtual($atual);

        $this->em->persist($telefone);
        $this->em->flush();
    }

    #[TestDox('casoAncoraDoObjeto retorna o caso ativo do objeto e null quando não há caso')]
    public function testCasoAncoraDoObjeto(): void
    {
        $tenant = $this->tenant();

        $comCaso = $this->objeto($tenant, 'Com caso', null);
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $comCaso,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => StatusCaso::Ativo,
        ])->_real();

        $semCaso = $this->objeto($tenant, 'Sem caso', null);

        self::assertSame($caso->getId(), $this->casoRepo->casoAncoraDoObjeto($comCaso)?->getId());
        self::assertNull($this->casoRepo->casoAncoraDoObjeto($semCaso));
    }

    #[TestDox('casoAncoraDoObjeto com vários ativos (legado modo B) escolhe o mais recente sem explodir')]
    public function testCasoAncoraDoObjetoComVariosAtivosEscolheOMaisRecente(): void
    {
        $tenant = $this->tenant();
        $objeto = $this->objeto($tenant, 'Dois ativos', null);

        // Dois casos ativos no mesmo objeto (cenário legado). A ordenação determinística
        // (criadoEm DESC, id DESC) deve devolver o criado por último (id maior), sem lançar.
        $antigo = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => StatusCaso::Ativo,
        ])->_real();
        $recente = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => StatusCaso::Ativo,
        ])->_real();

        self::assertGreaterThan($antigo->getId(), $recente->getId());
        self::assertSame($recente->getId(), $this->casoRepo->casoAncoraDoObjeto($objeto)?->getId());
    }

    #[TestDox('casoAncoraDoObjeto sem caso ativo cai para o caso encerrado (deep-link)')]
    public function testCasoAncoraDoObjetoSemAtivoUsaOEncerrado(): void
    {
        $tenant = $this->tenant();
        $objeto = $this->objeto($tenant, 'Só encerrado', null);

        $encerrado = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => StatusCaso::Encerrado,
        ])->_real();

        self::assertSame($encerrado->getId(), $this->casoRepo->casoAncoraDoObjeto($objeto)?->getId());
    }
}
