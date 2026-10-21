<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\SeccionalOab;
use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Repository\DivulgacaoNaSeccionalRepository;
use App\Master\Repository\NotaDaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\Repository\SubsecaoOabRepository;
use App\Master\UseCase\ListarSeccionaisUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lista das seccionais do mapa (spec master-seccionais-oab, "Dados para a tela"): uma linha por seccional com a
 * sede, os inscritos e as 5 últimas divulgações. A contagem distinta e o corte por seccional no SQL ficam no
 * teste funcional (SeccionaisOabContagemTest).
 */
#[CoversClass(ListarSeccionaisUseCase::class)]
#[CoversClass(TipoDeDivulgacao::class)]
final class ListarSeccionaisUseCaseTest extends TestCase
{
    #[TestDox('uma linha por seccional, na ordem do repositório, com sede, inscritos (0 quando não há) e última divulgação')]
    public function testMontaAsLinhas(): void
    {
        $sp = new SeccionalOab('SP', 'OAB Seccional São Paulo');
        $sp->editarSede('Praça da Sé', '(11) 0000-0000', 'https://exemplo.test', null, new \DateTimeImmutable('2026-10-20'));
        $ac = new SeccionalOab('AC', 'OAB Seccional Acre');

        $linhas = $this->useCase([$ac, $sp], ['SP' => 3], ['SP' => [self::div('reuniao', '2026-10-20', 'Ana', 'Café'), self::div('material', '2026-10-01', null, null)]])->executar();

        self::assertSame(['AC', 'SP'], array_column($linhas, 'uf'));
        self::assertSame(['uf' => 'AC', 'nome' => 'OAB Seccional Acre', 'endereco' => null, 'telefone' => null, 'site' => null, 'usuarios' => 0, 'ultima' => null, 'ultimas' => [], 'subsecoes' => [], 'nota' => null], $linhas[0]);
        self::assertSame(3, $linhas[1]['usuarios']);
        self::assertSame('Praça da Sé', $linhas[1]['endereco']);
        self::assertSame('https://exemplo.test', $linhas[1]['site']);
        self::assertSame(['tipo' => 'reuniao', 'rotulo' => 'Reunião', 'data' => '2026-10-20'], $linhas[1]['ultima']);
        self::assertSame(['tipo' => 'material', 'rotulo' => 'Material enviado', 'data' => '2026-10-01', 'autor' => null, 'nota' => null], $linhas[1]['ultimas'][1]);
        self::assertSame('Café', $linhas[1]['ultimas'][0]['nota']);
        self::assertSame('Ana', $linhas[1]['ultimas'][0]['autor']);
    }

    #[TestDox('pede ao repositório as 5 últimas e nunca devolve mais que 5 por seccional')]
    public function testCincoUltimas(): void
    {
        $seis = array_map(static fn (int $d): array => self::div('parceria', sprintf('2026-10-%02d', 20 - $d), 'Ana', null), range(0, 5));
        $divulgacoes = $this->createMock(DivulgacaoNaSeccionalRepository::class);
        $divulgacoes->expects($this->once())->method('ultimasPorUf')->with(5)->willReturn(['DF' => $seis]);
        $seccionais = $this->createStub(SeccionalOabRepository::class);
        $seccionais->method('todasEmOrdem')->willReturn([new SeccionalOab('DF', 'OAB Seccional Distrito Federal')]);
        $seccionais->method('usuariosPorUf')->willReturn([]);

        $linha = (new ListarSeccionaisUseCase($seccionais, $divulgacoes, $this->createStub(SubsecaoOabRepository::class), $this->createStub(NotaDaSeccionalRepository::class)))->executar()[0];

        self::assertCount(5, $linha['ultimas']);
        self::assertSame('2026-10-20', $linha['ultima']['data']);
        self::assertSame('Parceria', $linha['ultima']['rotulo']);
    }

    #[TestDox('janela da OAB: subseções e nota de cada UF vêm junto (lista vazia e nota nula quando não há)')]
    public function testSubsecoesENota(): void
    {
        $subsecoes = $this->createStub(SubsecaoOabRepository::class);
        $subsecoes->method('todasPorUf')->willReturn(['RR' => [['id' => 4, 'nome' => 'Subseção', 'endereco' => 'Rua', 'telefone' => null]]]);
        $notas = $this->createStub(NotaDaSeccionalRepository::class);
        $notas->method('todasPorUf')->willReturn(['RR' => ['texto' => 'Ligar em novembro', 'atualizadaEm' => '2026-10-21', 'autor' => 'Ana']]);
        $seccionais = $this->createStub(SeccionalOabRepository::class);
        $seccionais->method('todasEmOrdem')->willReturn([new SeccionalOab('AP', 'OAB Seccional Amapá'), new SeccionalOab('RR', 'OAB Seccional Roraima')]);
        $seccionais->method('usuariosPorUf')->willReturn([]);
        $divulgacoes = $this->createStub(DivulgacaoNaSeccionalRepository::class);
        $divulgacoes->method('ultimasPorUf')->willReturn([]);

        [$ap, $rr] = (new ListarSeccionaisUseCase($seccionais, $divulgacoes, $subsecoes, $notas))->executar();

        self::assertSame([], $ap['subsecoes']);
        self::assertNull($ap['nota']);
        self::assertSame([['id' => 4, 'nome' => 'Subseção', 'endereco' => 'Rua', 'telefone' => null]], $rr['subsecoes']);
        self::assertSame(['texto' => 'Ligar em novembro', 'atualizadaEm' => '2026-10-21', 'autor' => 'Ana'], $rr['nota']);
    }

    /**
     * @param list<SeccionalOab>                                                                   $seccionais
     * @param array<string, int>                                                                   $usuarios
     * @param array<string, list<array{tipo: string, data: string, nota: ?string, autor: ?string}>> $ultimas
     */
    private function useCase(array $seccionais, array $usuarios, array $ultimas): ListarSeccionaisUseCase
    {
        $repoSeccionais = $this->createStub(SeccionalOabRepository::class);
        $repoSeccionais->method('todasEmOrdem')->willReturn($seccionais);
        $repoSeccionais->method('usuariosPorUf')->willReturn($usuarios);
        $repoDivulgacoes = $this->createStub(DivulgacaoNaSeccionalRepository::class);
        $repoDivulgacoes->method('ultimasPorUf')->willReturn($ultimas);

        return new ListarSeccionaisUseCase($repoSeccionais, $repoDivulgacoes, $this->createStub(SubsecaoOabRepository::class), $this->createStub(NotaDaSeccionalRepository::class));
    }

    /** @return array{tipo: string, data: string, nota: ?string, autor: ?string} */
    private static function div(string $tipo, string $data, ?string $autor, ?string $nota): array
    {
        return ['tipo' => $tipo, 'data' => $data, 'nota' => $nota, 'autor' => $autor];
    }
}
