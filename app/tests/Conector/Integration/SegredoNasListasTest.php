<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Service\SegredoNasListas;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\DTO\DadosManuaisDoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Segredo de justiça nas listas do conector, com o banco (spec mcp-remoto-oauth 6.2, regra 3): a pasta ligada a processo
 * em segredo (sigilo do tribunal ou "Sim" manual do cartão) sai e é contada; o escritório é o explícito, sem filtro de
 * sessão (nenhuma requisição aberta).
 */
#[CoversClass(SegredoNasListas::class)]
final class SegredoNasListasTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private int $numero = 0;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('tira a pasta com sigilo do tribunal e a com segredo manual, conta as duas e mantém a ordem das outras')]
    public function testTiraEConta(): void
    {
        $escritorio = $this->escritorio();
        $comum = $this->pasta($escritorio);
        $doTribunal = $this->pasta($escritorio, nivelSigilo: 1);
        $semProcesso = $this->pasta($escritorio, comProcesso: false);
        $manual = $this->pasta($escritorio, manual: true);
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'premissa: sem o filtro de sessão');

        $resultado = $this->segredo()->tirar([$comum, $doTribunal, $semProcesso, $manual], $escritorio);

        self::assertSame(['ids' => [$comum, $semProcesso], 'em_segredo' => 2], $resultado);
    }

    #[TestDox('basta um processo em segredo na pasta: o público ao lado não a libera')]
    public function testUmProcessoBasta(): void
    {
        $escritorio = $this->escritorio();
        $pasta = $this->pasta($escritorio, nivelSigilo: 1);
        $this->vincular($this->em()->find(Pasta::class, $pasta), $this->processo($escritorio));

        self::assertSame(['ids' => [], 'em_segredo' => 1], $this->segredo()->tirar([$pasta], $escritorio));
    }

    #[TestDox('cross-tenant: com o escritório A, a pasta em segredo do B não é lida; com o B, sai e é contada')]
    public function testEscritorioExplicito(): void
    {
        $a = $this->escritorio('Escritório A');
        $b = $this->escritorio('Escritório B');
        $doA = $this->pasta($a);
        $secretaDoB = $this->pasta($b, nivelSigilo: 2);

        self::assertSame(['ids' => [$doA, $secretaDoB], 'em_segredo' => 0], $this->segredo()->tirar([$doA, $secretaDoB], $a), 'o segredo de outro escritório não é consultado; quem tira a pasta alheia é a consulta do próprio escritório');
        self::assertSame(['ids' => [], 'em_segredo' => 1], $this->segredo()->tirar([$secretaDoB], $b));
    }

    #[TestDox('tirarDe: itens com a mesma pasta em segredo saem todos e contam um a um; item sem pasta fica; lista vazia não consulta')]
    public function testTirarDeItens(): void
    {
        $escritorio = $this->escritorio();
        $comum = $this->pasta($escritorio);
        $secreta = $this->pasta($escritorio, manual: true);
        $itens = [
            ['id' => 1, 'pasta' => $secreta],
            ['id' => 2, 'pasta' => $comum],
            ['id' => 3, 'pasta' => null],
            ['id' => 4, 'pasta' => $secreta],
        ];

        $resultado = $this->segredo()->tirarDe($itens, static fn (array $item): ?int => $item['pasta'], $escritorio);

        self::assertSame([2, 3], array_column($resultado['itens'], 'id'));
        self::assertSame(2, $resultado['em_segredo']);
        self::assertSame(['ids' => [], 'em_segredo' => 0], $this->segredo()->tirar([], $escritorio));
    }

    private function segredo(): SegredoNasListas
    {
        return new SegredoNasListas(static::getContainer()->get(ProcessoRepository::class));
    }

    /** Uma pasta do escritório, por padrão com um processo público; devolve o id. */
    private function pasta(Tenant $escritorio, int $nivelSigilo = 0, bool $manual = false, bool $comProcesso = true): int
    {
        $pasta = (new Pasta())->setNup('SEG-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        if ($comProcesso) {
            $processo = $this->processo($escritorio);
            $processo->setNivelSigilo($nivelSigilo);
            if ($manual) {
                $processo->definirDadosManuais(new DadosManuaisDoProcesso(segredo: true), $this->pessoa());
            }
            $this->vincular($pasta, $processo);
        }
        $this->em()->flush();

        return (int) $pasta->getId();
    }

    private function processo(Tenant $escritorio): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', ++$this->numero) . uniqid());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($escritorio);
        $this->em()->persist($processo);

        return $processo;
    }

    private function vincular(?Pasta $pasta, Processo $processo): void
    {
        self::assertNotNull($pasta);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();
    }
}
