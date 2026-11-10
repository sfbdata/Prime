<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\DadosDaFolhaExportada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os dados que o XLSX imprime (spec ponto-desenho-0811, E16; Coordenador, 10/11/2026): o código da pessoa sem vínculo
 * ativo sai vazio, como no PDF (decisão 9), e o "Saldo mês" da linha de hoje é o acumulado até ontem, como a tela e o
 * PDF (E17, desenho L157).
 */
#[CoversClass(DadosDaFolhaExportada::class)]
#[Group('ponto')]
final class DadosDaFolhaExportadaTest extends TestCase
{
    #[TestDox('Sem vínculo ativo, o código da pessoa sai vazio (decisão 9), nunca o próximo código livre do escritório')]
    public function testCodigoVazioSemVinculoAtivo(): void
    {
        $dados = $this->montar(null, []);

        self::assertSame(['', ''], [$dados['codigoFuncionario'], $dados['numeroTrabalhador']]);
    }

    #[TestDox('Com vínculo ativo, o código gravado no vínculo')]
    public function testCodigoDoVinculo(): void
    {
        $vinculo = $this->createStub(UserTenant::class);
        $vinculo->method('getCodigoFuncionario')->willReturn('42');

        $dados = $this->montar($vinculo, []);

        self::assertSame(['42', '42'], [$dados['codigoFuncionario'], $dados['numeroTrabalhador']]);
    }

    #[TestDox('Com vínculo ativo e sem código gravado, a célula fica vazia')]
    public function testVinculoSemCodigo(): void
    {
        $vinculo = $this->createStub(UserTenant::class);
        $vinculo->method('getCodigoFuncionario')->willReturn(null);

        self::assertSame('', $this->montar($vinculo, [])['codigoFuncionario']);
    }

    #[TestDox('Dia em andamento (hoje sem saída): o "Saldo mês" é o acumulado até ontem; com o dia fechado, o do dia; no dia 1º, "Sem dado"')]
    public function testSaldoDoMesNoDiaEmAndamento(): void
    {
        $dados = $this->montar(null, [
            self::dia('2026-11-07', ['saida' => '18:00', 'saldoAcumulado' => 30]),
            self::dia('2026-11-09', ['saldoAcumuladoAteOntem' => -45]),
            self::dia('2026-11-01', []),
        ]);

        self::assertSame(['+0:30', '-0:45', 'Sem dado'], array_column($dados['folhaRows'], 'bancoHoras'));
    }

    /** @param list<array<string, mixed>> $linhas */
    private function montar(?UserTenant $vinculo, array $linhas): array
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $motor = $this->createStub(FolhaPontoBuilder::class);
        $motor->method('calcularSaldoAteMes')->willReturn(0);
        $motor->method('somarHorasPagasDaCompetencia')->willReturn(0);

        return (new DadosDaFolhaExportada($vinculos, $motor))->montar((new User())->setEmail('pessoa@teste.local'), new Tenant(), 2026, 11, $linhas, [], null, null, null);
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private static function dia(string $data, array $campos): array
    {
        return $campos + [
            'chaveDia' => $data, 'diaMes' => substr($data, 8, 2), 'entrada' => '08:00', 'saida' => '', 'fimSemana' => false,
            'isFeriado' => false, 'minutosIntervalo' => null, 'minutosTrabalhadosDia' => null, 'saldoDia' => null,
            'saldoAcumulado' => null, 'saldoAcumuladoAteOntem' => null,
        ];
    }
}
