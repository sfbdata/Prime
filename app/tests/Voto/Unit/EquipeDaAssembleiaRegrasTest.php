<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\NivelNaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\EventListener\BarreiraDaEquipeListener;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AcessoAAssembleia;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** As regras puras do teto da equipe (spec docs/specs/voto-equipe-e-master-1611.md, seção 1.2), sem banco. */
#[Group('voto')]
#[CoversClass(AcessoAAssembleia::class)]
#[CoversClass(BarreiraDaEquipeListener::class)]
#[CoversClass(PapelNaEquipe::class)]
#[CoversClass(MembroDaEquipe::class)]
#[CoversClass(CenarioDaAssembleia::class)]
final class EquipeDaAssembleiaRegrasTest extends TestCase
{
    /** @return array<string, array{list<array{assembleia: int, user: int|null, papel: string}>, int, NivelNaAssembleia}> */
    public static function equipes(): array
    {
        $linha = static fn (?int $user, string $papel): array => ['assembleia' => 1, 'user' => $user, 'papel' => $papel];

        return [
            'sem equipe: editar' => [[], 7, NivelNaAssembleia::Editar],
            'comissão: editar' => [[$linha(7, 'comissao')], 7, NivelNaAssembleia::Editar],
            'auditoria: visualizar' => [[$linha(7, 'auditoria')], 7, NivelNaAssembleia::Visualizar],
            'fiscal: visualizar' => [[$linha(7, 'fiscal')], 7, NivelNaAssembleia::Visualizar],
            'conselho: visualizar' => [[$linha(7, 'conselho')], 7, NivelNaAssembleia::Visualizar],
            'fora da lista: oculto' => [[$linha(8, 'comissao')], 7, NivelNaAssembleia::Oculto],
            'só linha sem conta: oculto' => [[$linha(null, 'comissao')], 7, NivelNaAssembleia::Oculto],
            'papel desconhecido: oculto' => [[$linha(7, 'guardiao')], 7, NivelNaAssembleia::Oculto],
        ];
    }

    /**
     * @param list<array{assembleia: int, user: int|null, papel: string}> $linhas
     */
    #[DataProvider('equipes')]
    public function testNivelPelaEquipe(array $linhas, int $pessoa, NivelNaAssembleia $esperado): void
    {
        self::assertSame($esperado, AcessoAAssembleia::nivelPelaEquipe($linhas, $pessoa));
    }

    #[TestDox('A barreira: Oculto recusa tudo, Visualizar recusa o que escreve (menos as minutas), Editar passa')]
    public function testRegraDaBarreira(): void
    {
        self::assertFalse(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Editar, 'POST', 'voto_assembleia_editar'));
        self::assertTrue(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Oculto, 'GET', 'voto_assembleia_pautas'));
        self::assertTrue(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Oculto, 'POST', 'voto_assembleia_minuta'));
        self::assertFalse(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Visualizar, 'GET', 'voto_assembleia_pautas'));
        self::assertTrue(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Visualizar, 'POST', 'voto_assembleia_pauta_abrir'));
        self::assertFalse(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Visualizar, 'POST', 'voto_assembleia_minuta'));
    }

    #[TestDox('Só a comissão edita; os outros papéis só leem')]
    public function testNivelDosPapeis(): void
    {
        self::assertSame(NivelNaAssembleia::Editar, PapelNaEquipe::Comissao->nivel());
        foreach ([PapelNaEquipe::Auditoria, PapelNaEquipe::Fiscal, PapelNaEquipe::Conselho] as $papel) {
            self::assertSame(NivelNaAssembleia::Visualizar, $papel->nivel());
        }
    }

    #[TestDox('Cargos da categoria: quatro por categoria, sem Guardião e Validação, na ordem do desenho')]
    public function testCargosDaEquipe(): void
    {
        foreach (CenarioDaAssembleia::cases() as $cenario) {
            $cargos = $cenario->cargosDaEquipe();
            self::assertSame([PapelNaEquipe::Comissao, PapelNaEquipe::Conselho, PapelNaEquipe::Auditoria, PapelNaEquipe::Fiscal], array_column($cargos, 'papel'), $cenario->value);
            foreach ($cargos as $cargo) {
                self::assertDoesNotMatchRegularExpression('/Guardi|Escrutinador|Valida|Secretaria/', $cargo['cargo'], $cenario->value);
            }
        }
        self::assertSame('Presidência da assembleia', CenarioDaAssembleia::Condominio->cargosDaEquipe()[0]['cargo']);
        self::assertSame('Fiscal de chapa', CenarioDaAssembleia::Classe->cargosDaEquipe()[3]['cargo']);
    }

    #[TestDox('A linha exige nome, e-mail válido e corta o cargo em 60; "A designar" não tem conta')]
    public function testLinhaDaEquipe(): void
    {
        $a = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Teste');
        $m = new MembroDaEquipe($a, PapelNaEquipe::Fiscal, ' Maria ', ' maria@teste.com.br ', str_repeat('x', 80));
        self::assertSame('Maria', $m->getNome());
        self::assertSame('maria@teste.com.br', $m->getEmail());
        self::assertSame(60, mb_strlen($m->getCargo()));
        self::assertSame('Fiscal', (new MembroDaEquipe($a, PapelNaEquipe::Fiscal, 'Z'))->rotulo());
        self::assertTrue((new MembroDaEquipe($a, PapelNaEquipe::Fiscal, MembroDaEquipe::NOME_A_DESIGNAR))->aDesignar());

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        new MembroDaEquipe($a, PapelNaEquipe::Fiscal, '  ');
    }

    public function testEmailInvalidoERecusado(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        new MembroDaEquipe(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Teste'), PapelNaEquipe::Fiscal, 'Maria', 'isto-nao-e-email');
    }

    #[TestDox('Decidir a avaliação (a única escrita do Visualizar): só o conselho; sem equipe ou fora dela, ninguém (a Administração é tratada antes)')]
    public function testQuemDecideAAvaliacao(): void
    {
        $linhas = [
            ['assembleia' => 1, 'user' => 10, 'papel' => 'comissao'],
            ['assembleia' => 1, 'user' => 11, 'papel' => 'conselho'],
            ['assembleia' => 1, 'user' => 12, 'papel' => 'auditoria'],
            ['assembleia' => 1, 'user' => 13, 'papel' => 'fiscal'],
            ['assembleia' => 1, 'user' => null, 'papel' => 'conselho'],
        ];

        self::assertTrue(AcessoAAssembleia::avaliacaoPelaEquipe($linhas, 11));
        foreach ([10, 12, 13, 99] as $id) {
            self::assertFalse(AcessoAAssembleia::avaliacaoPelaEquipe($linhas, $id), 'pessoa ' . $id);
        }
        self::assertFalse(AcessoAAssembleia::avaliacaoPelaEquipe([], 11), 'sem equipe própria só a Administração decide');
        // Registrar é o nível Editar: o conselho (Visualizar) não registra, a comissão sim.
        self::assertSame(NivelNaAssembleia::Visualizar, AcessoAAssembleia::nivelPelaEquipe($linhas, 11));
        self::assertSame(NivelNaAssembleia::Editar, AcessoAAssembleia::nivelPelaEquipe($linhas, 10));
        self::assertTrue(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Oculto, 'POST', 'voto_assembleia_avaliacao_decidir'), 'quem está Oculto nunca decide');
        self::assertFalse(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Visualizar, 'POST', 'voto_assembleia_avaliacao_decidir'));
        self::assertTrue(BarreiraDaEquipeListener::recusaria(NivelNaAssembleia::Visualizar, 'POST', 'voto_assembleia_unidade_ocorrencia'), 'registrar continua sendo Editar');
    }
}
