<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AlertaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\UseCase\LerPontosDoRelatorioInicialUseCase;
use App\Pasta\UseCase\ListarAlertasDaPastaUseCase;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Os UseCases da Controladoria da pasta, extraídos do controller para a tela e para a parte `controladoria` da `ler_pasta`
 * do conector de I.A.: os alertas (com a contagem de urgentes) e os pontos do relatório inicial, com a posse do escritório
 * e o `podeVer` com o cadeado.
 */
#[CoversClass(ListarAlertasDaPastaUseCase::class)]
#[CoversClass(LerPontosDoRelatorioInicialUseCase::class)]
final class ControladoriaDaPastaUseCasesTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio('Escritório da Controladoria');
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::LEITOR);
    }

    #[TestDox('alertas: o prazo vencido vira alerta alto e conta como urgente')]
    public function testAlertaDePrazoVencido(): void
    {
        $pasta = $this->pasta();
        $prazo = new PastaPrazo($this->escritorio, $pasta, $this->pessoa);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, 'Contestação', new \DateTimeImmutable('-3 days'), null, null);
        $this->em()->persist($prazo);
        $this->em()->flush();

        $saida = static::getContainer()->get(ListarAlertasDaPastaUseCase::class)->executar($pasta, $this->pessoa, $this->escritorio);

        $porChave = array_column(array_map(static fn (AlertaDaPasta $a): array => ['chave' => $a->chave, 'nivel' => $a->nivel], $saida->alertas), 'nivel', 'chave');
        self::assertSame(AlertaDaPasta::ALTA, $porChave['prazos']);
        self::assertGreaterThanOrEqual(1, $saida->urgentes);
    }

    #[TestDox('alertas e pontos: pasta de outro escritório e pasta trancada para quem lê são recusadas')]
    public function testRecusas(): void
    {
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();
        $alertas = static::getContainer()->get(ListarAlertasDaPastaUseCase::class);
        $pontos = static::getContainer()->get(LerPontosDoRelatorioInicialUseCase::class);

        foreach ([$alertas, $pontos] as $useCase) {
            try {
                $useCase->executar($deOutro, $this->pessoa, $this->escritorio);
                self::fail('deveria recusar a pasta de outro escritório');
            } catch (PastaDeOutroEscritorioException) {
                self::assertTrue(true);
            }
            try {
                $useCase->executar($trancada, $this->pessoa, $this->escritorio);
                self::fail('deveria recusar a pasta trancada');
            } catch (SemPermissaoParaVerPastaException) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('pontos: o que aparece no relatório inicial sai como registrado; sem observação, vazio e com os três pendentes')]
    public function testPontosDoRelatorioInicial(): void
    {
        $vazia = $this->pasta();
        $comRelatorio = $this->pasta();
        $observacao = (new PastaObservacaoDetalhes())->setTenant($this->escritorio)->setPasta($comRelatorio)->setAutor($this->pessoa)
            ->setConteudo('<p>O objetivo do cliente é revisar o contrato. Ficou combinado o envio dos documentos.</p>');
        $this->em()->persist($observacao);
        $this->em()->flush();
        $useCase = static::getContainer()->get(LerPontosDoRelatorioInicialUseCase::class);

        $semNada = $useCase->executar($vazia, $this->pessoa, $this->escritorio);
        $parcial = $useCase->executar($comRelatorio, $this->pessoa, $this->escritorio);

        self::assertTrue($semNada->vazio);
        self::assertCount(3, $semNada->faltam());
        self::assertFalse($parcial->vazio);
        self::assertSame(['objetivos' => true, 'acordos' => true, 'resultado' => false], array_column($parcial->itens, 'ok', 'k'));
        self::assertFalse($parcial->completo());
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('CTL-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
