<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\FonteDeContagensDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\ContagensDaPasta;
use App\Inteligencia\DTO\ContextoDaPasta;
use App\Inteligencia\DTO\OQueAIaSabeOutput;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\SecaoDoContexto;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\UseCase\ContarOQueAIaSabeDaPastaUseCase;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * IA-006b: a grade mostra o que a IA RECEBE (teto de cada seção do montador), não o total da
 * pasta; quando o teto corta, diz de quanto.
 */
#[CoversClass(ContarOQueAIaSabeDaPastaUseCase::class)]
#[CoversClass(OQueAIaSabeOutput::class)]
#[CoversClass(ContagensDaPasta::class)]
final class ContarOQueAIaSabeDaPastaUseCaseTest extends TestCase
{
    #[TestDox('abaixo do teto: repassa o tenant explícito à fonte e mostra o número exato, sem frase de corte, na ordem do desenho')]
    public function testAbaixoDoTetoMostraONumeroExato(): void
    {
        $tenant = new Tenant();
        $pasta = new Pasta();
        $fonte = $this->createMock(FonteDeContagensDaPasta::class);
        $fonte->expects(self::once())->method('contar')->with($tenant, $pasta)
            ->willReturn(new ContagensDaPasta(documentos: 5, metas: 4, metasAbertasComPrazo: 3, anotacoes: 2, observacoes: 1));

        $saida = (new ContarOQueAIaSabeDaPastaUseCase($fonte))->executar($pasta, $tenant);

        self::assertSame(
            [
                ['chave' => 'documentos', 'valor' => 5, 'total' => 5, 'rotulo' => 'documentos', 'corte' => null],
                ['chave' => 'tarefas', 'valor' => 4, 'total' => 4, 'rotulo' => 'tarefas', 'corte' => null],
                ['chave' => 'prazos', 'valor' => 3, 'total' => 3, 'rotulo' => 'prazos de metas abertas', 'corte' => null],
                ['chave' => 'anotacoes', 'valor' => 3, 'total' => 3, 'rotulo' => 'anotações', 'corte' => null],
            ],
            $saida->itens(),
        );
    }

    #[TestDox('exatamente no teto: o número é o teto e não há frase de corte (nada ficou de fora)')]
    public function testNoTetoNaoHaCorte(): void
    {
        $saida = ContarOQueAIaSabeDaPastaUseCase::doQueAIaRecebe(new ContagensDaPasta(
            documentos: MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS,
            metas: MontadorDeContextoDaPasta::LIMITE_METAS,
            metasAbertasComPrazo: MontadorDeContextoDaPasta::LIMITE_METAS,
            anotacoes: MontadorDeContextoDaPasta::LIMITE_ANOTACOES,
            observacoes: MontadorDeContextoDaPasta::LIMITE_OBSERVACOES,
        ));

        self::assertSame([null, null, null, null], array_column($saida->itens(), 'corte'));
    }

    #[TestDox('acima do teto: mostra o teto do montador e diz quais entram "de N"')]
    public function testAcimaDoTetoMostraOTetoEDeN(): void
    {
        $saida = ContarOQueAIaSabeDaPastaUseCase::doQueAIaRecebe(new ContagensDaPasta(
            documentos: 112,
            metas: 45,
            metasAbertasComPrazo: 33,
            anotacoes: 26,
            observacoes: 4,
        ));
        $itens = array_column($saida->itens(), null, 'chave');

        self::assertSame(MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS, $itens['documentos']['valor']);
        self::assertSame(112, $itens['documentos']['total']);
        self::assertSame(sprintf('lê os %d mais recentes de 112', MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS), $itens['documentos']['corte']);

        self::assertSame(MontadorDeContextoDaPasta::LIMITE_METAS, $itens['tarefas']['valor']);
        self::assertSame(sprintf('lê %d de 45, abertas primeiro', MontadorDeContextoDaPasta::LIMITE_METAS), $itens['tarefas']['corte']);

        self::assertSame(MontadorDeContextoDaPasta::LIMITE_METAS, $itens['prazos']['valor']);
        self::assertSame(sprintf('lê os %d mais próximos de 33', MontadorDeContextoDaPasta::LIMITE_METAS), $itens['prazos']['corte']);

        // 26 anotações cortam em 20; as 4 observações entram todas → 24 de 30.
        $esperado = MontadorDeContextoDaPasta::LIMITE_ANOTACOES + 4;
        self::assertSame($esperado, $itens['anotacoes']['valor']);
        self::assertSame(30, $itens['anotacoes']['total']);
        self::assertSame(sprintf('lê %d de 30, as mais recentes de cada aba', $esperado), $itens['anotacoes']['corte']);
    }

    #[TestDox('pasta vazia: zero em tudo, sem frase de corte')]
    public function testPastaVazia(): void
    {
        $saida = ContarOQueAIaSabeDaPastaUseCase::doQueAIaRecebe(new ContagensDaPasta(0, 0, 0, 0, 0));

        self::assertSame([0, 0, 0, 0], array_column($saida->itens(), 'valor'));
        self::assertSame([null, null, null, null], array_column($saida->itens(), 'corte'));
    }

    /**
     * A amarra: a MESMA pasta (acima de todos os tetos) passa pelo montador de verdade e pela
     * grade; o número de cada quadro é o número de linhas que a seção correspondente levou. Se
     * alguém mudar um teto no montador sem que a grade acompanhe — ou trocar a constante na grade
     * por um literal —, este teste cai.
     */
    #[TestDox('amarra: com a mesma pasta acima dos tetos, cada número da grade é o número de linhas que o montador de verdade envia')]
    public function testGradeAcompanhaOMontador(): void
    {
        $tenant = new Tenant();
        DefineId::em($tenant, 42);
        $user = new User();
        $user->setFullName('Dra. Ana');
        DefineId::em($user, 9);
        $pasta = new Pasta();
        $pasta->setTenant($tenant);
        $pasta->setNup('1234');
        $pasta->setNomeAcao('Cobrança');
        $pasta->setResponsavel($user);
        DefineId::em($pasta, 7);

        $extra = 7;
        $dados = new FonteDeDadosDaPastaFalsa();
        $nDocumentos = MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS + $extra;
        for ($i = 1; $i <= $nDocumentos; ++$i) {
            $d = new PastaDocumento();
            $d->setTenant($tenant);
            $d->setPasta($pasta);
            $d->setTitulo('Doc ' . $i);
            $d->setCategoria('DEMAIS');
            $d->setCaminhoArquivo('x/doc' . $i . '.pdf');
            $d->setNomeOriginal('doc' . $i . '.pdf');
            $d->setMimeType('application/pdf');
            $d->setTamanhoBytes(10);
            $dados->documentos[] = $d;
        }
        // Metas na ordem da fonte real: abertas com prazo primeiro (todas acima do teto), depois
        // abertas sem prazo e concluídas.
        $nAbertasComPrazo = MontadorDeContextoDaPasta::LIMITE_METAS + 2;
        $nMetas = $nAbertasComPrazo + $extra;
        for ($i = 1; $i <= $nMetas; ++$i) {
            $t = new Tarefa();
            $t->setTenant($tenant);
            $t->setPasta($pasta);
            $t->setTitulo('Meta ' . $i);
            $t->setDescricao('');
            $t->setPrazo($i <= $nAbertasComPrazo ? new \DateTimeImmutable('+' . $i . ' days') : null);
            $t->setStatus($i <= $nAbertasComPrazo + 3 ? Tarefa::STATUS_PENDENTE : Tarefa::STATUS_CONCLUIDA);
            $dados->tarefas[] = $t;
        }
        $nAnotacoes = MontadorDeContextoDaPasta::LIMITE_ANOTACOES + $extra;
        for ($i = 1; $i <= $nAnotacoes; ++$i) {
            $m = new PastaMensagem();
            $m->setTenant($tenant);
            $m->setPasta($pasta);
            $m->setAutor($user);
            $m->setConteudo('Anotação ' . $i);
            $dados->anotacoes[] = $m;
        }
        $nObservacoes = MontadorDeContextoDaPasta::LIMITE_OBSERVACOES + $extra;
        for ($i = 1; $i <= $nObservacoes; ++$i) {
            $o = new PastaObservacaoDetalhes();
            $o->setTenant($tenant);
            $o->setPasta($pasta);
            $o->setAutor($user);
            $o->setConteudo('Observação ' . $i);
            $dados->observacoes[] = $o;
        }

        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->atualizar(true, 50, 500, true, $user);
        $configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $configuracoes->method('findDoTenant')->willReturn($configuracao);
        $movimentacoes = new FonteDeMovimentacoesFalsa();
        $mascarador = new MascaradorDeDadosPessoais();
        $montador = new MontadorDeContextoDaPasta(
            $dados,
            $movimentacoes,
            new MontadorDeContextoDoPush($movimentacoes, $configuracoes, $mascarador),
            $configuracoes,
            $mascarador,
            new MockClock(new \DateTimeImmutable('today')),
        );
        $contexto = $montador->para($tenant, $pasta, Agente::Gestor, false);

        $grade = array_column(ContarOQueAIaSabeDaPastaUseCase::doQueAIaRecebe(new ContagensDaPasta(
            documentos: $nDocumentos,
            metas: $nMetas,
            metasAbertasComPrazo: $nAbertasComPrazo,
            anotacoes: $nAnotacoes,
            observacoes: $nObservacoes,
        ))->itens(), null, 'chave');

        $linhasDeMetas = self::linhas($contexto, SecaoDoContexto::Metas);
        self::assertSame(count(self::linhas($contexto, SecaoDoContexto::Documentos)), $grade['documentos']['valor']);
        self::assertSame(count($linhasDeMetas), $grade['tarefas']['valor']);
        // Prazo de meta aberta = linha de meta com "vence"/"ATRASADA" (texto do montador).
        $comPrazoAberto = array_filter($linhasDeMetas, static fn (string $l): bool => preg_match('/vence|ATRASADA/', $l) === 1);
        self::assertSame(count($comPrazoAberto), $grade['prazos']['valor']);
        self::assertSame(
            count(self::linhas($contexto, SecaoDoContexto::Anotacoes)) + count(self::linhas($contexto, SecaoDoContexto::Observacoes)),
            $grade['anotacoes']['valor'],
        );
        // E todos os quatro acima do teto declaram o corte.
        self::assertNotContains(null, array_column($grade, 'corte'));
    }

    /** @return list<string> */
    private static function linhas(ContextoDaPasta $contexto, SecaoDoContexto $qual): array
    {
        foreach ($contexto->secoes as $secao) {
            if ($secao->secao === $qual) {
                return $secao->linhas;
            }
        }
        self::fail('seção ' . $qual->value . ' não montada');
    }
}
