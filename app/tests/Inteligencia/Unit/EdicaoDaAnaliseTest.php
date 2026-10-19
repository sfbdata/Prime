<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Pasta\DTO\AnaliseCompartilhadaOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * P4h: "Editar" a Análise IA (spec docs/specs/push-editar-analise-ia.md). A edição mora ao lado da
 * saída do modelo, que nunca muda; a tela e o link público mostram a versão exibida.
 */
#[CoversClass(AnaliseDeInteligencia::class)]
#[CoversClass(AnaliseOutput::class)]
#[CoversClass(AnaliseCompartilhadaOutput::class)]
final class EdicaoDaAnaliseTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    private function concluida(): AnaliseDeInteligencia
    {
        $a = new AnaliseDeInteligencia(
            tenant: new Tenant(),
            solicitante: $this->user,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: 7,
            versaoDoPrompt: 'push-v1',
            contextoHash: str_repeat('a', 64),
            contextoResumo: ['chaves' => ['pub:1'], 'total' => 1, 'novas' => 1],
        );
        $a->iniciarProcessamento();
        $a->concluir(
            'Sentença publicada.',
            [['tipo' => 'prazo', 'texto' => 'Apelação em 15 dias.'], ['tipo' => 'info', 'texto' => 'Custas pagas.']],
            'Advogado',
            '{"bruto":true}',
            'falso',
            'falso-1',
            10,
            5,
            3,
        );

        return $a;
    }

    #[TestDox('editar grava a versão exibida e NÃO toca resumo, pontos, quem age nem texto bruto do modelo')]
    public function testNaoTocaOOriginal(): void
    {
        $a = $this->concluida();

        self::assertTrue($a->editar('Sentença de procedência publicada.', [0 => 'Apelação até 27/10.', 1 => 'Custas pagas.'], $this->user));

        self::assertTrue($a->foiEditada());
        self::assertSame($this->user, $a->getEditadaPor());
        self::assertNotNull($a->getEditadaEm());
        self::assertSame('Sentença de procedência publicada.', $a->getResumoExibido());
        self::assertSame([['tipo' => 'prazo', 'texto' => 'Apelação até 27/10.'], ['tipo' => 'info', 'texto' => 'Custas pagas.']], $a->getPontosExibidos());
        // A saída do modelo continua a mesma (regras 1 e 6; o próximo prompt lê `getResumo()`).
        self::assertSame('Sentença publicada.', $a->getResumo());
        self::assertSame('Apelação em 15 dias.', $a->getPontos()[0]['texto'] ?? null);
        self::assertSame('Advogado', $a->getQuemAge());
        self::assertSame('{"bruto":true}', $a->getTextoBruto());
    }

    #[TestDox('resumo vazio mantém o anterior; ponto vazio ou ausente sai; o tipo dos que ficam não muda')]
    public function testRegrasDoDesenho(): void
    {
        $a = $this->concluida();

        $a->editar('   ', [1 => 'Custas complementares pagas.'], $this->user);

        self::assertSame('Sentença publicada.', $a->getResumoExibido());
        self::assertSame([['tipo' => 'info', 'texto' => 'Custas complementares pagas.']], $a->getPontosExibidos());

        // Editar de novo parte da versão exibida: o índice 0 agora é o ponto "info".
        $a->editar('Novo resumo.', [0 => ''], $this->user);
        self::assertSame([], $a->getPontosExibidos());
        self::assertSame('Novo resumo.', $a->getResumoExibido());
    }

    #[TestDox('salvar sem mudar nada não marca "editado"')]
    public function testSemMudancaENoOp(): void
    {
        $a = $this->concluida();

        self::assertFalse($a->editar('Sentença publicada.', [0 => 'Apelação em 15 dias.', 1 => 'Custas pagas.'], $this->user));
        self::assertFalse($a->foiEditada());
        self::assertNull($a->getEditadaEm());
    }

    #[TestDox('só a concluída e não excluída pode ser editada')]
    public function testRecusaForaDeConcluida(): void
    {
        $pendente = new AnaliseDeInteligencia(
            tenant: new Tenant(),
            solicitante: $this->user,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: 7,
            versaoDoPrompt: 'push-v1',
            contextoHash: str_repeat('a', 64),
            contextoResumo: [],
        );
        try {
            $pendente->editar('x', [], $this->user);
            self::fail('pendente não pode ser editada');
        } catch (\DomainException) {
            self::assertFalse($pendente->foiEditada());
        }

        $excluida = $this->concluida();
        $excluida->excluir($this->user);
        $this->expectException(\DomainException::class);
        $excluida->editar('x', [], $this->user);
    }

    #[TestDox('o cartão (AnaliseOutput) e o link público mostram a versão editada, com o selo')]
    public function testSaidasUsamAVersaoExibida(): void
    {
        $a = $this->concluida();
        $saida = AnaliseOutput::fromEntity($a);
        self::assertFalse($saida->editada);
        self::assertSame('Sentença publicada.', $saida->resumo);

        $a->editar('Resumo corrigido.', [0 => 'Apelação até 27/10.'], $this->user);

        $saida = AnaliseOutput::fromEntity($a);
        self::assertTrue($saida->editada);
        self::assertNotNull($saida->editadaEm);
        self::assertSame('Resumo corrigido.', $saida->resumo);
        self::assertSame([['tipo' => 'prazo', 'rotulo' => $saida->pontos[0]['rotulo'], 'texto' => 'Apelação até 27/10.']], $saida->pontos);

        $publica = AnaliseCompartilhadaOutput::fromEntity($a);
        self::assertSame('Resumo corrigido.', $publica->resumo);
        self::assertSame([['tipo' => 'prazo', 'texto' => 'Apelação até 27/10.']], $publica->pontos);
    }
}
