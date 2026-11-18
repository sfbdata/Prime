<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\LeituraDoAndamentoDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\UseCase\LerAndamentoDaPastaUseCase;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O andamento da pasta só para leitura (`docs/specs/mcp-remoto-oauth.md`, 6.4, `ler_pasta` parte `andamento`), com o
 * banco: a trilha estimada da pasta nova, a sugestão que some com a aba de origem oculta (Financeiro, Documentos,
 * Processo), a contagem de registros que some com a aba Dados oculta (por fase e na base da estimativa), as recusas (outro escritório, sem permissão) e nada gravado.
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

    #[TestDox('pasta nova com documento: a sugestão de Documentação sai com a aba Documentos oculta')]
    public function testSugestaoDosDocumentosSomeComAAbaOculta(): void
    {
        $pasta = $this->pasta();
        $documento = (new PastaDocumento())->setTitulo('Procuração')->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo('teste/' . uniqid() . '.pdf')->setNomeOriginal('procuracao.pdf')->setMimeType('application/pdf')
            ->setTamanhoBytes(1)->setPasta($pasta)->setTenant($this->escritorio);
        $this->em()->persist($documento);
        $this->em()->flush();
        self::assertContains('Documentação', $this->nomesDasSugestoes($pasta), 'premissa: com a aba visível, a sugestão sai');

        $this->ocultar($pasta, AbaDaPasta::Documentos);

        self::assertNotContains('Documentação', $this->nomesDasSugestoes($pasta));
    }

    #[TestDox('trilha gravada e processo vinculado: a sugestão de Em andamento sai com a aba Processo oculta')]
    public function testSugestaoDoProcessoSomeComAAbaOculta(): void
    {
        $pasta = $this->pasta();
        static::getContainer()->get(AndamentoDaPasta::class)->marcar($pasta, $this->colega, $this->escritorio, 1);
        $processo = new Processo();
        $processo->setNumeroProcesso('0701134572025807' . random_int(1000, 9999));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();
        self::assertContains('Em andamento', $this->nomesDasSugestoes($pasta), 'premissa: com a aba visível, a sugestão sai');

        $this->ocultar($pasta, AbaDaPasta::Processo);

        self::assertNotContains('Em andamento', $this->nomesDasSugestoes($pasta));
    }

    #[TestDox('trilha estimada pelos registros: com a aba Dados oculta, a base não traz a contagem')]
    public function testBaseEstimadaSemAContagemComADadosOculta(): void
    {
        $pasta = $this->pasta();
        for ($i = 0; $i < 3; ++$i) {
            $mensagem = (new PastaMensagem())->setPasta($pasta)->setTenant($this->escritorio)->setAutor($this->colega)->setConteudo('Registro ' . $i);
            $this->em()->persist($mensagem);
            $pasta->getMensagens()->add($mensagem);
        }
        $this->em()->flush();
        self::assertSame('3 registro(s) na pasta', $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio)->baseDaEstimativa, 'premissa');

        $this->ocultar($pasta, AbaDaPasta::Dados);
        $leitura = $this->useCase()->executar($pasta, $this->pessoa, $this->escritorio);

        self::assertTrue($leitura->estimada);
        self::assertSame('registros na pasta', $leitura->baseDaEstimativa);
        self::assertStringNotContainsString('3', (string) $leitura->baseDaEstimativa);
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

    /** @return list<string> */
    private function nomesDasSugestoes(Pasta $pasta): array
    {
        return array_column($this->useCase()->executar($pasta, $this->pessoa, $this->escritorio)->sugestoes, 'nome');
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
