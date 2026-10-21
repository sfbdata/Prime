<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\SeccionalOab;
use App\Master\Enum\StatusDaDivulgacao;
use App\Master\Repository\NotaDaCidadeOabRepository;
use App\Master\Repository\NotaDaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\Repository\SubsecaoOabRepository;
use App\Master\UseCase\ListarSeccionaisUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lista das seccionais do mapa (spec master-seccionais-oab, "Dados para a tela"; desenho 05 inteiro): uma linha por
 * seccional com a sede, as inscrições, a visibilidade, a marca da divulgação, as subseções e as notas do estado e das
 * cidades. A contagem por inscrição no SQL fica no teste funcional (SeccionaisOabContagemTest).
 */
#[CoversClass(ListarSeccionaisUseCase::class)]
#[CoversClass(StatusDaDivulgacao::class)]
final class ListarSeccionaisUseCaseTest extends TestCase
{
    #[TestDox('uma linha por seccional, na ordem do repositório, com sede, inscrições (0 quando não há), visibilidade e marca')]
    public function testMontaAsLinhas(): void
    {
        $sp = new SeccionalOab('SP', 'OAB Seccional São Paulo');
        $sp->editarSede('Praça da Sé', '(11) 0000-0000', 'https://exemplo.test', null, new \DateTimeImmutable('2026-10-20'));
        $sp->marcar(StatusDaDivulgacao::Negociacao, '#fca5a5', new \DateTimeImmutable('2026-10-20'));
        $ac = new SeccionalOab('AC', 'OAB Seccional Acre');
        $ac->alternarVisibilidade();

        $linhas = $this->useCase([$ac, $sp], ['SP' => 3])->executar();

        self::assertSame(['AC', 'SP'], array_column($linhas, 'uf'));
        self::assertSame(['uf' => 'AC', 'nome' => 'OAB Seccional Acre', 'endereco' => null, 'telefone' => null, 'site' => null, 'inscricoes' => 0, 'visivel' => false, 'marca' => null, 'subsecoes' => [], 'nota' => null, 'notasCidades' => []], $linhas[0]);
        self::assertSame(3, $linhas[1]['inscricoes']);
        self::assertTrue($linhas[1]['visivel']);
        self::assertSame('Praça da Sé', $linhas[1]['endereco']);
        self::assertSame(['status' => 'neg', 'rotulo' => 'Em negociação', 'cor' => '#fca5a5', 'data' => '2026-10-20'], $linhas[1]['marca']);
        self::assertArrayNotHasKey('ultimas', $linhas[1]);
    }

    #[TestDox('janela da OAB: subseções (com a cidade), nota do estado e notas das cidades de cada UF vêm junto')]
    public function testSubsecoesENotas(): void
    {
        $subsecoes = $this->createStub(SubsecaoOabRepository::class);
        $subsecoes->method('todasPorUf')->willReturn(['RR' => [['id' => 4, 'cidade' => 'Boa Vista', 'nome' => 'Subseção', 'endereco' => 'Rua', 'telefone' => null]]]);
        $notas = $this->createStub(NotaDaSeccionalRepository::class);
        $notas->method('todasPorUf')->willReturn(['RR' => ['texto' => 'Ligar em novembro', 'atualizadaEm' => '2026-10-21T14:30:00-03:00']]);
        $notasDasCidades = $this->createStub(NotaDaCidadeOabRepository::class);
        $notasDasCidades->method('todasPorUf')->willReturn(['RR' => ['Boa Vista' => ['texto' => 'Visitar', 'atualizadaEm' => '2026-10-21T15:00:00-03:00']]]);
        $seccionais = $this->createStub(SeccionalOabRepository::class);
        $seccionais->method('todasEmOrdem')->willReturn([new SeccionalOab('AP', 'OAB Seccional Amapá'), new SeccionalOab('RR', 'OAB Seccional Roraima')]);
        $seccionais->method('inscricoesPorUf')->willReturn([]);

        [$ap, $rr] = (new ListarSeccionaisUseCase($seccionais, $subsecoes, $notas, $notasDasCidades))->executar();

        self::assertSame([], $ap['subsecoes']);
        self::assertNull($ap['nota']);
        self::assertSame([], $ap['notasCidades']);
        self::assertSame('Boa Vista', $rr['subsecoes'][0]['cidade']);
        self::assertSame(['texto' => 'Ligar em novembro', 'atualizadaEm' => '2026-10-21T14:30:00-03:00'], $rr['nota']);
        self::assertSame(['Boa Vista' => ['texto' => 'Visitar', 'atualizadaEm' => '2026-10-21T15:00:00-03:00']], $rr['notasCidades']);
    }

    /**
     * @param list<SeccionalOab>  $seccionais
     * @param array<string, int> $inscricoes
     */
    private function useCase(array $seccionais, array $inscricoes): ListarSeccionaisUseCase
    {
        $repoSeccionais = $this->createStub(SeccionalOabRepository::class);
        $repoSeccionais->method('todasEmOrdem')->willReturn($seccionais);
        $repoSeccionais->method('inscricoesPorUf')->willReturn($inscricoes);

        return new ListarSeccionaisUseCase($repoSeccionais, $this->createStub(SubsecaoOabRepository::class), $this->createStub(NotaDaSeccionalRepository::class), $this->createStub(NotaDaCidadeOabRepository::class));
    }
}
