<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\AjusteDaMarcacao;
use App\Ponto\Model\BatidaAjustada;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A escolha das batidas do dia com a batida EFETIVA (E10, plano-e10-ponto §1): a ordem no mesmo segundo é a do
 * repositório (registro, depois ajuste; a inclusão, sem registro, por último) e a batida ajustada pelo gestor nunca
 * é repetição do colaborador.
 *
 * A batida gravada tem id; aqui, sem banco, o id entra por reflexão (o `alvo()` recusa a batida sem id).
 */
#[CoversClass(EscolhaDasBatidasDoDia::class)]
#[CoversClass(BatidasEscolhidas::class)]
final class EscolhaComBatidaAjustadaTest extends TestCase
{
    private const DIA = '2026-10-21';

    private EscolhaDasBatidasDoDia $escolha;

    protected function setUp(): void
    {
        $this->escolha = new EscolhaDasBatidasDoDia();
    }

    #[TestDox('No mesmo segundo: pelo id do registro, depois pela cabeça; a inclusão vem depois dos registros')]
    public function testOrdemNoMesmoSegundo(): void
    {
        $lista = [
            $this->inclusao('entrada', '10:00:00', 15),
            $this->registro('saida', '10:00:00', 7),
            $this->inclusao('entrada', '10:00:00', 12),
            $this->alteracao('saida', '10:00:00', 5, 20),
            $this->registro('entrada', '09:59:59', 9),
        ];

        $ordenadas = $this->escolha->ordenar($lista);

        self::assertSame(['b9', 'b5', 'b7', 'a12', 'a15'], array_map(static fn (MarcacaoDoPonto $b): string => $b->alvo(), $ordenadas));
    }

    #[TestDox('Controle: duas entradas do colaborador a 2 min são o mesmo registro (sem marca)')]
    public function testRepeticaoDoColaboradorSemMarca(): void
    {
        $primeira = $this->registro('entrada', '08:00:00', 1);

        $escolha = $this->escolha->escolher([$primeira, $this->registro('entrada', '08:02:00', 2)], new \DateTimeImmutable(self::DIA));

        self::assertSame($primeira, $escolha->entrada);
        self::assertSame([], $escolha->aConferir);
    }

    #[TestDox('A entrada incluída pelo gestor a 2 min da do colaborador não é repetição: vira registro distinto, com a marca')]
    public function testAjustadaNaoERepeticao(): void
    {
        $primeira = $this->registro('entrada', '08:00:00', 1);
        $incluida = $this->inclusao('entrada', '08:02:00', 30);

        $escolha = $this->escolha->escolher([$incluida, $primeira], new \DateTimeImmutable(self::DIA));

        self::assertSame($primeira, $escolha->entrada);
        self::assertSame([$incluida], $escolha->desconsideradas);
        self::assertSame([BatidasEscolhidas::MARCA_ENTRADAS_DISTINTAS], $escolha->aConferir);
    }

    #[TestDox('A batida alterada pelo gestor é escolhida pela hora nova')]
    public function testAlteradaValePelaHoraNova(): void
    {
        $entrada = $this->registro('entrada', '08:00:00', 1);
        $saida = $this->alteracao('saida', '18:37:00', 2, 40);

        $escolha = $this->escolha->escolher([$entrada, $saida], new \DateTimeImmutable(self::DIA));

        self::assertSame([$entrada, $saida], [$escolha->entrada, $escolha->saida]);
        self::assertSame('18:37', $escolha->saida?->getDataHora()->format('H:i'));
    }

    private function registro(string $tipo, string $hora, int $id): RegistroPonto
    {
        $registro = (new RegistroPonto())->setTipo($tipo)->setDataHora(new \DateTimeImmutable(self::DIA . ' ' . $hora));
        (new \ReflectionProperty(RegistroPonto::class, 'id'))->setValue($registro, $id);

        return $registro;
    }

    private function inclusao(string $tipo, string $hora, int $id): BatidaAjustada
    {
        return new BatidaAjustada(null, $id, $id, 3, $tipo, new \DateTimeImmutable(self::DIA . ' ' . $hora), self::ajuste(TipoDoAjuste::Inclusao));
    }

    private function alteracao(string $tipo, string $hora, int $registroId, int $cabeca): BatidaAjustada
    {
        return new BatidaAjustada($registroId, null, $cabeca, 3, $tipo, new \DateTimeImmutable(self::DIA . ' ' . $hora), self::ajuste(TipoDoAjuste::Alteracao));
    }

    private static function ajuste(TipoDoAjuste $tipo): AjusteDaMarcacao
    {
        return new AjusteDaMarcacao($tipo, 'Gestora', new \DateTimeImmutable('2026-10-22 09:00:00'), null);
    }
}
