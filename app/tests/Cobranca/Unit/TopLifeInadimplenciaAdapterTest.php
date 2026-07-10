<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TopLifeInadimplenciaAdapter::class)]
final class TopLifeInadimplenciaAdapterTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/toplife_amostra.xlsx';

    private TopLifeInadimplenciaAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new TopLifeInadimplenciaAdapter();
    }

    /**
     * @return array<string, BoletoImportavel>
     */
    private function porNn(): array
    {
        $r = $this->adapter->ler(self::FIXTURE);
        $mapa = [];
        foreach ($r->importaveis as $b) {
            $mapa[$b->nn] = $b;
        }

        return $mapa;
    }

    #[Test]
    public function classificaImportaveisRejeitadasEIgnoradas(): void
    {
        $r = $this->adapter->ler(self::FIXTURE);

        self::assertCount(6, $r->importaveis, 'esperados 6 boletos importáveis');
        self::assertCount(4, $r->rejeitadas, 'esperados 4 boletos rejeitados');
        self::assertSame(8, $r->linhasIgnoradas, 'esperadas 8 linhas ignoradas (rodapé/totais/metadados)');
    }

    #[Test]
    public function agregaBoletoDeUmaSoLinha(): void
    {
        $b = $this->porNn()['1001'];

        self::assertSame('01-01', $b->objetoIdentificacao);
        self::assertSame('DEVEDOR UM EXEMPLO', $b->sacadoNome);
        self::assertSame('02/2026', $b->competencia);
        self::assertSame('10/02/2026', $b->vencimento->format('d/m/Y'));
        self::assertSame(19000, $b->principalCentavos);
        self::assertSame(1317, $b->encargosCentavos, 'juros 9,37 + multa 3,80');
        self::assertSame(4063, $b->honorariosInformadosCentavos);
        self::assertNull($b->unidadeMetadata);
        self::assertNull($b->acordoTexto);
    }

    #[Test]
    public function agregaComponentesDoMesmoNnNumUnicoBoleto(): void
    {
        // NN=1002: Taxa (100) + Energia (45) = principal 145; encargos (5+2)+(2+0,90); honorários 21+10.
        $b = $this->porNn()['1002'];

        self::assertSame(14500, $b->principalCentavos);
        self::assertSame(990, $b->encargosCentavos);
        self::assertSame(3100, $b->honorariosInformadosCentavos);
        self::assertCount(2, $b->linhas, 'detalhamento das 2 linhas-componente preservado');
    }

    #[Test]
    public function separaUnidadePrincipalEGuardaParentesesEAcordoComoObservacao(): void
    {
        $b = $this->porNn()['1003'];

        self::assertSame('01-03A', $b->objetoIdentificacao);
        self::assertSame('05-03,06-01', $b->unidadeMetadata);
        self::assertSame('Acordo 396 - Parc. 2/11', $b->acordoTexto);
        self::assertStringContainsString('Unidades associadas: 05-03,06-01', (string) $b->observacao());
        self::assertStringContainsString('Acordo 396', (string) $b->observacao());
    }

    #[Test]
    public function encargosSomamLinhasJurosMultaEHonorarioClasse115NaoEntraNoPrincipal(): void
    {
        // NN=1004: Taxa 170 (principal); linhas 1.4 Juros 12,50 + 1.5 Multas 3,40 → encargos; 1.15 → honorário.
        $b = $this->porNn()['1004'];

        self::assertSame(17000, $b->principalCentavos, 'só a Taxa entra no principal');
        self::assertSame(2089, $b->encargosCentavos, '(1,59+3,40) + (12,50+3,40)');
        self::assertSame(5000, $b->honorariosInformadosCentavos, 'linha 1.15 (50,00), sem dupla contagem com coluna L=0');
    }

    #[Test]
    public function descontoReduzOPrincipal(): void
    {
        // NN=1005: Taxa 170 + Desconto -10 → principal 160.
        $b = $this->porNn()['1005'];

        self::assertSame(16000, $b->principalCentavos);
        self::assertSame(500, $b->encargosCentavos);
        self::assertSame(2751, $b->honorariosInformadosCentavos);
    }

    #[Test]
    public function rejeitaComMotivoClaro(): void
    {
        $r = $this->adapter->ler(self::FIXTURE);
        $motivos = [];
        foreach ($r->rejeitadas as $rej) {
            $motivos[$rej->referencia] = $rej->motivo;
        }

        self::assertArrayHasKey('1006', $motivos);
        self::assertStringContainsString('Sacado', $motivos['1006']);
        self::assertArrayHasKey('1007', $motivos);
        self::assertStringContainsString('Competência', $motivos['1007']);
        self::assertArrayHasKey('1008', $motivos);
        self::assertStringContainsString('não numérico', $motivos['1008']);
        self::assertArrayHasKey('1010', $motivos);
        self::assertStringContainsString('sem principal', $motivos['1010']);
    }
}
