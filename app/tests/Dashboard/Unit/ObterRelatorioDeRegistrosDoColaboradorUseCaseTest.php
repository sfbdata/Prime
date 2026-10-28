<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\UseCase\ObterRelatorioDeRegistrosDoColaboradorUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObterRelatorioDeRegistrosDoColaboradorUseCase::class)]
final class ObterRelatorioDeRegistrosDoColaboradorUseCaseTest extends TestCase
{
    private function usuario(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    /** @param list<DashboardZeramento> $zeramentos */
    private function useCase(MetricasExtrasDoDashboardRepository $metricas, array $zeramentos = [], array $equipe = [], bool $veFinanceiro = true): ObterRelatorioDeRegistrosDoColaboradorUseCase
    {
        $pastas = $this->createStub(PastaRepository::class);
        $pasta = new Pasta();
        $pasta->setTenant(new Tenant());
        $pastas->method('find')->willReturn($pasta);
        // A classe é final: sem regra de aba oculta na pasta (o caso comum), ninguém tem aba oculta. As regras reais
        // (Dados e Detalhes ocultos) são provadas no teste funcional.
        $regras = $this->createStub(AbaOcultaDaPastaRepository::class);
        $regras->method('daAba')->willReturn(null);
        $abas = (new \ReflectionClass(VisibilidadeDasAbasDaPasta::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(VisibilidadeDasAbasDaPasta::class, 'regras'))->setValue($abas, $regras);
        $fin = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $fin->method('podeVer')->willReturn($veFinanceiro);

        $users = $this->createStub(UserRepository::class);
        $users->method('findColaboradoresAtivosPorTenant')->willReturn($equipe ?: [$this->usuario(5, 'Ana Equipe')]);
        $zer = $this->createStub(DashboardZeramentoRepository::class);
        $zer->method('listarAtivos')->willReturn($zeramentos);

        return new ObterRelatorioDeRegistrosDoColaboradorUseCase($users, $metricas, $zer, $pastas, $abas, $fin, new \App\Cobranca\Service\ResolvedorIdentificadorDaPasta($this->createStub(\App\Cobranca\Repository\CasoCobrancaRepository::class), new \App\Cobranca\Service\ComporNomeDaPastaJudicial()));
    }

    private function linha(int $id, int $pasta, string $quando, string $conteudo = 'texto', string $aba = 'dados'): array
    {
        return ['id' => $id, 'aba' => $aba, 'pastaId' => $pasta, 'pastaNup' => 'NUP' . $pasta, 'cliente' => 'CLIENTE ' . $pasta, 'criadaEm' => new \DateTimeImmutable($quando), 'conteudo' => $conteudo];
    }

    #[TestDox('agrupa por pasta (a de registro mais novo primeiro), ordena os itens do mais novo ao mais antigo e formata data e hora')]
    public function testAgrupaEOrdena(): void
    {
        $metricas = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $metricas->expects(self::once())->method('listarRegistrosDoAutor')
            ->with(self::isInstanceOf(Tenant::class), 5, ['data_de' => '2026-09-01', 'data_ate' => ''], [11], ObterRelatorioDeRegistrosDoColaboradorUseCase::LIMITE_DE_LINHAS)
            ->willReturn(['total' => 4, 'registros' => [
                $this->linha(1, 100, '2026-09-05 09:00'),
                $this->linha(2, 200, '2026-09-20 18:45', 'x', 'financeiro'),
                $this->linha(3, 100, '2026-09-10 10:00', 'y', 'detalhes'),
                $this->linha(4, 200, '2026-09-02 08:00'),
            ]]);

        $saida = $this->useCase($metricas)->executar(new Tenant(), 5, ['data_de' => '2026-09-01'], [11], $this->usuario(1, 'Quem olha'));

        self::assertSame(4, $saida->total);
        self::assertSame(0, $saida->restantes);
        self::assertSame([200, 100], array_column($saida->grupos, 'pastaId'));
        self::assertSame([2, 4], array_column($saida->grupos[0]['itens'], 'id'));
        self::assertSame('20/09/2026', $saida->grupos[0]['itens'][0]['data']);
        self::assertSame('18:45', $saida->grupos[0]['itens'][0]['hora']);
        self::assertSame('NUP200', $saida->grupos[0]['titulo']);
        self::assertSame('/p/200#dados', $saida->paraArray(static fn (int $id): string => '/p/' . $id)['grupos'][0]['url']);
    }

    #[TestDox('restantes = total exato menos as linhas devolvidas (o limite não tira do total)')]
    public function testRestantes(): void
    {
        $metricas = $this->createStub(MetricasExtrasDoDashboardRepository::class);
        $metricas->method('listarRegistrosDoAutor')->willReturn(['total' => 350, 'registros' => [$this->linha(1, 100, '2026-09-05 09:00')]]);

        $saida = $this->useCase($metricas)->executar(new Tenant(), 5, [], [], $this->usuario(1, 'Quem olha'));

        self::assertSame(350, $saida->total);
        self::assertSame(349, $saida->restantes);
    }

    #[TestDox('o corte do Zerar da coluna Relatório (o mais recente entre equipe e pessoa) vai para a consulta')]
    public function testCorteDoZerar(): void
    {
        $tenant = new Tenant();
        $por = $this->usuario(1, 'Primária');
        $ana = $this->usuario(5, 'Ana Equipe');
        $zeramentos = [
            new DashboardZeramento($tenant, 'relatorio', null, new \DateTimeImmutable('2026-09-05 00:00'), $por),
            new DashboardZeramento($tenant, 'relatorio', $ana, new \DateTimeImmutable('2026-09-12 10:00'), $por),
            new DashboardZeramento($tenant, 'metas', $ana, new \DateTimeImmutable('2026-09-20 10:00'), $por),
        ];

        $metricas = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $metricas->expects(self::once())->method('listarRegistrosDoAutor')
            ->with(self::anything(), 5, self::callback(static fn (array $f): bool => $f['contar_desde']->format('Y-m-d H:i') === '2026-09-12 10:00'), [], self::anything())
            ->willReturn(['total' => 0, 'registros' => []]);

        $saida = $this->useCase($metricas, $zeramentos)->executar($tenant, 5, [], [], $this->usuario(1, 'Quem olha'));

        self::assertNotNull($saida->corte);
    }

    #[TestDox('pessoa que não é colaboradora ativa do escritório: exceção (vira 404)')]
    public function testForaDoEscritorio(): void
    {
        $this->expectException(ColaboradorForaDoEscritorioException::class);
        $metricas = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $metricas->expects(self::never())->method('listarRegistrosDoAutor');

        $this->useCase($metricas)->executar(new Tenant(), 99, [], [], $this->usuario(1, 'Quem olha'));
    }

    #[TestDox('quem não vê a aba Financeiro: a linha e o total ficam, o trecho some, e o link do grupo cai em Dados')]
    public function testAbaFinanceiroNaoVisivel(): void
    {
        $metricas = $this->createStub(MetricasExtrasDoDashboardRepository::class);
        $metricas->method('listarRegistrosDoAutor')->willReturn(['total' => 1, 'registros' => [$this->linha(1, 100, '2026-09-05 09:00', 'segredo do financeiro', 'financeiro')]]);

        $saida = $this->useCase($metricas, veFinanceiro: false)->executar(new Tenant(), 5, [], [], $this->usuario(1, 'Quem olha'));

        self::assertSame(1, $saida->total);
        $item = $saida->grupos[0]['itens'][0];
        self::assertTrue($item['oculto']);
        self::assertSame('', $item['trecho']);
        self::assertStringNotContainsString('segredo', json_encode($saida->paraArray(static fn (int $id): string => '/p/' . $id), JSON_THROW_ON_ERROR));
        self::assertSame('/p/100#dados', $saida->paraArray(static fn (int $id): string => '/p/' . $id)['grupos'][0]['url']);
    }

    #[TestDox('quem vê a aba recebe o trecho e a âncora da aba quando o grupo é de uma aba só')]
    public function testAbaVisivelComAncora(): void
    {
        $metricas = $this->createStub(MetricasExtrasDoDashboardRepository::class);
        $metricas->method('listarRegistrosDoAutor')->willReturn(['total' => 2, 'registros' => [
            $this->linha(1, 100, '2026-09-05 09:00', 'texto fin', 'financeiro'),
            $this->linha(2, 200, '2026-09-05 08:00', 'texto det', 'detalhes'),
            $this->linha(3, 200, '2026-09-04 08:00', 'texto dados', 'dados'),
        ]]);

        $saida = $this->useCase($metricas)->executar(new Tenant(), 5, [], [], $this->usuario(1, 'Quem olha'));
        $arr = $saida->paraArray(static fn (int $id): string => '/p/' . $id);

        self::assertFalse($saida->grupos[0]['itens'][0]['oculto']);
        self::assertSame('texto fin', $saida->grupos[0]['itens'][0]['trecho']);
        self::assertSame('/p/100#financeiro', $arr['grupos'][0]['url']);
        self::assertSame('/p/200#dados', $arr['grupos'][1]['url'], 'abas misturadas: Dados');
    }

    #[TestDox('trecho: sem HTML, entidades decodificadas, espaços colapsados e truncado com reticências')]
    public function testTrecho(): void
    {
        self::assertSame('Olá mundo & fim', ObterRelatorioDeRegistrosDoColaboradorUseCase::trecho("<p>Olá</p><p>  <b>mundo</b>\n&amp; fim</p>"));
        self::assertSame('', ObterRelatorioDeRegistrosDoColaboradorUseCase::trecho('<p> </p>'));
        $longo = ObterRelatorioDeRegistrosDoColaboradorUseCase::trecho(str_repeat('palavra ', 40));
        self::assertLessThanOrEqual(121, mb_strlen($longo));
        self::assertStringEndsWith('…', $longo);
    }
}
