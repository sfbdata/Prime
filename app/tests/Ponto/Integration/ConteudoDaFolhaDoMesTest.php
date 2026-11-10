<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\LinhaDaFolhaImpressa;
use App\Ponto\DTO\ResumoDoMesDaFolha;
use App\Ponto\Entity\Feriado;
use App\Ponto\Service\ConteudoDaFolhaDoMes;
use App\Ponto\Service\LinhasDaFolhaImpressa;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A folha do mês montada com o motor de verdade (spec ponto-desenho-0811, E16, P35 e seção 8; desenho 16 L612, L616,
 * L618, L622, L626 e L678): o feriado em todo dia de feriado (também onde o motor não apura o dia), sem mudar as horas,
 * e a linha "Dias incompletos · a revisar" contando o que cada linha mostra.
 */
#[Group('ponto')]
#[CoversClass(ConteudoDaFolhaDoMes::class)]
#[CoversClass(LinhasDaFolhaImpressa::class)]
#[CoversClass(ResumoDoMesDaFolha::class)]
final class ConteudoDaFolhaDoMesTest extends KernelTestCase
{
    use CenarioDaFolhaDoMesTrait;

    private Tenant $escritorio;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio('Escritório da folha do mês');
    }

    #[TestDox('Feriado antes do início da contagem e feriado futuro: "Feriado: nome" e "FERIADO"; o de outra sede não; as horas não mudam')]
    public function testFeriadoOndeOMotorNaoApura(): void
    {
        $maria = $this->pessoaComOutubro($this->escritorio);   // a contagem começa em 05/10
        $hoje = new \DateTimeImmutable('2026-10-20 12:00:00');
        $antes = $this->montar($maria, $hoje);
        $this->feriado('Dia antes da contagem', '2026-10-02');
        $this->feriado('Dia do Servidor Público', '2026-10-28');
        $this->feriado('Feriado de outra sede', '2026-10-29', $this->lotacao('Sede Norte'));

        $depois = $this->montar($maria, $hoje);

        self::assertSame(['Dia antes da contagem', 'FERIADO', true, 'Feriado: Dia antes da contagem'], self::feriadoDa($depois->linhas[1]));
        self::assertSame(['Dia do Servidor Público', 'FERIADO', true, 'Feriado: Dia do Servidor Público'], self::feriadoDa($depois->linhas[27]));
        self::assertSame([null, 'Quinta', false, ''], self::feriadoDa($depois->linhas[28]), 'o feriado de uma lotação que a pessoa não tem');
        self::assertSame(array_map(self::horasDa(...), $antes->linhas), array_map(self::horasDa(...), $depois->linhas));
        self::assertEquals($antes->resumo, $depois->resumo);
        self::assertEquals($antes->intervalos, $depois->intervalos);
    }

    #[TestDox('Pessoa sem jornada e sem batida (nenhum dia apurado): o feriado aparece igual, com as horas vazias')]
    public function testFeriadoSemJornada(): void
    {
        $joao = $this->pessoa('João Sem Jornada');
        $this->vincular($joao, $this->escritorio);
        $this->feriado('Nossa Senhora Aparecida', '2026-10-12');

        $linha = $this->montar($joao, new \DateTimeImmutable('2026-11-09 10:00:00'))->linhas[11];

        self::assertSame(['Nossa Senhora Aparecida', 'FERIADO', true, 'Feriado: Nossa Senhora Aparecida'], self::feriadoDa($linha));
        self::assertSame(['', '', ''], self::horasDa($linha));
    }

    #[TestDox('Dias incompletos · a revisar: 08:00, 12:00, 13:00, 07:30 é só "a revisar" (0 · 1); entrada e saída sem intervalo é dia apurado')]
    public function testIncompletosEARevisar(): void
    {
        $maria = $this->pessoaComOutubro($this->escritorio);
        $this->batidas($maria, '2026-10-26', ['entrada' => '08:00', 'repouso' => '12:00', 'retorno' => '13:00', 'saida' => '07:30']);
        $this->batidas($maria, '2026-10-27', ['entrada' => '08:00', 'saida' => '17:00']);

        $conteudo = $this->montar($maria, new \DateTimeImmutable('2026-11-09 10:00:00'));
        [$invertido, $semIntervalo] = [$conteudo->linhas[25], $conteudo->linhas[26]];

        self::assertSame([LinhasDaFolhaImpressa::FORA_DE_SEQUENCIA, true, false], [$invertido->observacao, $invertido->foraDeSequencia, $invertido->incompleto]);
        self::assertSame(['', false, false, '9:00'], [$semIntervalo->observacao, $semIntervalo->foraDeSequencia, $semIntervalo->incompleto, $semIntervalo->trabalhadas]);
        self::assertSame('0 · 1', $conteudo->resumo->textoDosIncompletos());

        $this->batidas($maria, '2026-10-28', ['entrada' => '08:00']);
        $comIncompleto = $this->montar($maria, new \DateTimeImmutable('2026-11-09 10:00:00'));
        self::assertSame(LinhasDaFolhaImpressa::INCOMPLETO, $comIncompleto->linhas[27]->observacao);
        self::assertSame('1 · 1', $comIncompleto->resumo->textoDosIncompletos());
    }

    private function montar(User $pessoa, \DateTimeImmutable $hoje): ConteudoDaFolha
    {
        return $this->conteudoDaFolhaDoMes()->montar($this->escritorio, $pessoa, 2026, 10, $hoje);
    }

    /** @param array<string, string> $horarios tipo => HH:MM */
    private function batidas(User $pessoa, string $dia, array $horarios): void
    {
        foreach ($horarios as $tipo => $hora) {
            $this->registrarBatida($pessoa, $this->escritorio, $tipo, new \DateTimeImmutable($dia . ' ' . $hora . ':00'));
        }
    }

    private function feriado(string $nome, string $dia, ?Lotacao $lotacao = null): void
    {
        $this->em()->persist((new Feriado())->setNome($nome)->setData(new \DateTime($dia))->setRecorrente(false)->setTenant($this->escritorio)->setLotacao($lotacao));
        $this->em()->flush();
    }

    private function lotacao(string $nome): Lotacao
    {
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($this->escritorio);
        $this->em()->persist($lotacao);
        $this->em()->flush();

        return $lotacao;
    }

    /** @return array{?string, string, bool, string} o feriado, a semana, o "FERIADO" no lugar das batidas e as observações */
    private static function feriadoDa(LinhaDaFolhaImpressa $linha): array
    {
        return [$linha->feriado, $linha->semana, $linha->feriadoSemBatida, $linha->observacao];
    }

    /** @return array{string, string, string} as horas, as extras e o saldo do mês */
    private static function horasDa(LinhaDaFolhaImpressa $linha): array
    {
        return [$linha->trabalhadas, $linha->extras, $linha->saldo];
    }
}
