<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\CasoDetalheOutput;
use App\Cobranca\DTO\ObjetoDetalheOutput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Cobranca\UseCase\MontarDetalheObjetoUseCase;
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

        $this->sut = new MontarDetalheObjetoUseCase($montarDetalheCaso, $vinculoRepo);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant DO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function objeto(Tenant $tenant, string $identificacao, ?string $descricao): ObjetoCobranca
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
        ]);

        return ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $carteira,
            'identificacao' => $identificacao,
            'descricao' => $descricao,
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
