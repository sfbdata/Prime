<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\LeituraDoAndamentoDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\UseCase\LerAndamentoDaPastaUseCase;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O andamento da pasta só para leitura (`docs/specs/mcp-remoto-oauth.md`, 6.4, `ler_pasta` parte `andamento`), com o
 * banco: a trilha estimada da pasta nova, a sugestão do Financeiro que some com a aba oculta, os registros por fase que
 * somem com a aba Dados oculta, as recusas (outro escritório, sem permissão) e nada gravado.
 */
#[CoversClass(LerAndamentoDaPastaUseCase::class)]
#[CoversClass(LeituraDoAndamentoDaPastaOutput::class)]
final class LerAndamentoDaPastaUseCaseTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private User $colega;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio('Escritório do Andamento');
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::LEITOR);
        $this->colega = $this->pessoa();
        $this->vinculo($this->colega, $this->escritorio, permissoes: self::LEITOR);
    }

    #[TestDox('pasta nova: trilha estimada na primeira fase, com número e nome, e a sugestão de contrato assinado; nada gravado')]
    public function testPastaNovaEstimada(): void
    {
        $pasta = $this->pasta();
        $pasta->setSituacaoContrato('REGULAR');
        $this->em()->flush();

        $leitura = $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio);

        self::assertTrue($leitura->estimada);
        self::assertSame('Pasta aberta, sem registros', $leitura->baseDaEstimativa);
        self::assertSame(['numero' => 1, 'nome' => 'Abertura'], $leitura->atual);
        self::assertFalse($leitura->finalizada);
        self::assertSame(1, $leitura->fases[0]['numero']);
        self::assertSame('Abertura', $leitura->fases[0]['nome']);
        self::assertContains('Contrato', array_column($leitura->sugestoes, 'nome'));
        self::assertSame(
            ['via', 'atual', 'finalizada', 'estimada', 'base_da_estimativa', 'duracao', 'fases', 'sugestoes'],
            array_keys($leitura->paraArray()),
        );

        $unidade = $this->em()->getUnitOfWork();
        $unidade->computeChangeSets();
        self::assertSame([], $unidade->getScheduledEntityInsertions(), 'ler não grava andamento');
        self::assertSame([], $unidade->getScheduledEntityUpdates());
    }

    #[TestDox('com o Financeiro oculto para quem lê, a sugestão de contrato assinado não sai')]
    public function testSugestaoDoFinanceiroSomeComAAbaOculta(): void
    {
        $pasta = $this->pasta();
        $pasta->setSituacaoContrato('REGULAR');
        $this->ocultar($pasta, AbaDaPasta::Financeiro);

        $leitura = $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio);

        self::assertNotContains('Contrato', array_column($leitura->sugestoes, 'nome'));
    }

    #[TestDox('trilha gravada: com a aba Dados oculta, a contagem de registros por fase não sai')]
    public function testRegistrosSomemComADadosOculta(): void
    {
        $pasta = $this->pasta();
        // Arranjo: a trilha gravada (só ela data as fases, e o registro conta na fase alcançada antes dele).
        static::getContainer()->get(AndamentoDaPasta::class)->marcar($pasta, $this->colega, $this->escritorio, 1);
        $this->em()->flush();
        $mensagem = (new PastaMensagem())->setPasta($pasta)->setTenant($this->escritorio)->setAutor($this->colega)->setConteudo('Registro');
        $this->em()->persist($mensagem);
        $pasta->getMensagens()->add($mensagem);
        $this->em()->flush();

        $comDados = $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio);
        self::assertFalse($comDados->estimada);
        self::assertSame(['numero' => 2, 'nome' => 'Documentação'], $comDados->atual);
        self::assertNotNull($comDados->fases[1]['data']);
        self::assertGreaterThan(0, array_sum(array_column($comDados->fases, 'registros')));

        $this->ocultar($pasta, AbaDaPasta::Dados);
        $semDados = $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio);
        self::assertSame(0, array_sum(array_column($semDados->fases, 'registros')));
    }

    #[TestDox('pasta de outro escritório: recusa como outro escritório')]
    public function testOutroEscritorio(): void
    {
        $pasta = $this->pasta($this->escritorio('Outro'));

        $this->expectException(PastaDeOutroEscritorioException::class);
        $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio);
    }

    #[TestDox('quem não pode ver a pasta: recusa sem permissão')]
    public function testSemPermissao(): void
    {
        $semRecurso = $this->pessoa();
        $this->vinculo($semRecurso, $this->escritorio, permissoes: ['modules.pastas.view']);
        $pasta = $this->pasta();

        $this->expectException(SemPermissaoParaVerPastaException::class);
        $this->useCase()->executar($pasta, $semRecurso, $this->escritorio);
    }

    private function useCase(): LerAndamentoDaPastaUseCase
    {
        return static::getContainer()->get(LerAndamentoDaPastaUseCase::class);
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('AND-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function ocultar(Pasta $pasta, AbaDaPasta $aba): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, $aba, $this->colega, new \DateTimeImmutable()));
        $this->em()->flush();
    }
}
