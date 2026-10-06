<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaPendenciasOutput;
use App\Pasta\DTO\PastaResumoImpressaoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PastaResumoImpressaoOutput::class)]
final class PastaResumoImpressaoOutputTest extends TestCase
{
    private const HOJE = '2026-10-05';

    private function hoje(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::HOJE);
    }

    private function meta(Pasta $pasta, string $titulo, string $status, ?string $prazo, ?User $responsavel = null): void
    {
        $m = new Tarefa();
        $m->setTitulo($titulo);
        $m->setStatus($status);
        if ($prazo !== null) {
            $m->setPrazo(new \DateTimeImmutable($prazo));
        }
        if ($responsavel !== null) {
            $m->addResponsavel($responsavel);
        }
        $pasta->getTarefas()->add($m);
    }

    private function pagamento(string $valor, ?string $pagoEm = null): PastaPagamento
    {
        $p = new PastaPagamento();
        $p->setDescricao('parcela');
        $p->setValor($valor);
        $p->setVencimento(new \DateTimeImmutable('2026-09-01'));
        if ($pagoEm !== null) {
            $p->alternarQuitacao(new \DateTimeImmutable($pagoEm));
        }

        return $p;
    }

    /** @param PastaPagamento[] $pagamentos */
    private function montar(Pasta $pasta, array $pagamentos = [], bool $financeiro = true): PastaResumoImpressaoOutput
    {
        return PastaResumoImpressaoOutput::montar(
            $pasta,
            PastaPendenciasOutput::montar($pasta, 0, $pagamentos, $this->hoje()),
            [],
            $pagamentos,
            $financeiro,
            $this->hoje(),
        );
    }

    #[TestDox('Dados da pasta saem do cadastro: cliente principal, documento, processo principal e a Ação do processo quando a pasta não tem')]
    public function testDadosDaPasta(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('1240');
        $pasta->setSituacao('arquivado');

        $cliente = new ClientePF();
        $cliente->setNomeCompleto('MARIA DA SILVA');
        $cliente->setCpf('12345678901');
        $pasta->addCliente($cliente);

        $processo = new Processo();
        $processo->setNumeroProcesso('07011345720258070007');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setOrgaoJulgador('1ª Vara Cível');
        $pasta->vincularProcesso($processo);

        $r = $this->montar($pasta);

        self::assertSame('1240', $r->numero);
        self::assertSame('MARIA DA SILVA', $r->titulo);
        self::assertSame('12345678901', $r->documento);
        self::assertSame('Arquivado', $r->situacao);
        self::assertSame('Procedimento Comum', $r->acao);
        self::assertCount(1, $r->clientes);
        self::assertTrue($r->clientes[0]['principal']);
        self::assertCount(1, $r->processos);
        self::assertTrue($r->processos[0]['principal']);
        self::assertSame('1ª VARA CÍVEL', $r->processos[0]['orgao']);
    }

    #[TestDox('Sem cliente cadastrado o título é o identificador da pasta, e não há documento para inventar')]
    public function testSemClienteUsaIdentificador(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('77');
        $pasta->setNomeCliente('CONDOMÍNIO TOP LIFE');

        $r = $this->montar($pasta);

        self::assertSame('CONDOMÍNIO TOP LIFE', $r->titulo);
        self::assertNull($r->documento);
        self::assertSame([], $r->clientes);
        // Sem processo vinculado: a pendência da aba Processo vira item da folha, com maiúscula.
        self::assertContains('Nenhum processo vinculado', $r->pendencias);
    }

    #[TestDox('Metas abertas: só as não concluídas, do prazo mais atrasado ao mais distante, sem prazo no fim, com o atraso em dias')]
    public function testMetasAbertas(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('1');
        $ana = new User();
        $ana->setFullName('Ana Souza');

        $this->meta($pasta, 'Sem prazo', Tarefa::STATUS_PENDENTE, null);
        $this->meta($pasta, 'Distante', Tarefa::STATUS_EM_REVISAO, '2026-11-01', $ana);
        $this->meta($pasta, 'Atrasada', Tarefa::STATUS_PENDENTE, '2026-10-02', $ana);
        $this->meta($pasta, 'Feita', Tarefa::STATUS_CONCLUIDA, '2026-09-01', $ana);

        $r = $this->montar($pasta);

        self::assertSame(3, $r->totalMetasAbertas);
        self::assertSame(['Atrasada', 'Distante', 'Sem prazo'], array_column($r->metasAbertas, 'titulo'));
        self::assertSame(3, $r->metasAbertas[0]['atrasoDias']);
        self::assertSame(0, $r->metasAbertas[1]['atrasoDias']);
        self::assertNull($r->metasAbertas[2]['prazo']);
        self::assertSame('Sem responsável', $r->metasAbertas[2]['responsaveis']);
        self::assertSame('Ana Souza', $r->metasAbertas[0]['responsaveis']);
    }

    #[TestDox('A folha lista no máximo 12 metas, mas o total diz quantas estão abertas')]
    public function testLimiteDeMetas(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('2');
        for ($i = 1; $i <= 15; ++$i) {
            $this->meta($pasta, 'Meta ' . $i, Tarefa::STATUS_PENDENTE, sprintf('2026-12-%02d', $i));
        }

        $r = $this->montar($pasta);

        self::assertSame(15, $r->totalMetasAbertas);
        self::assertCount(PastaResumoImpressaoOutput::LIMITE_METAS, $r->metasAbertas);
    }

    #[TestDox('Financeiro resumido: valor da causa, contrato, recebido de previsto e quantidade de lançamentos')]
    public function testFinanceiro(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('3');
        $pasta->setValorCausa('12860.00');

        $r = $this->montar($pasta, [$this->pagamento('1000.00', '2026-09-02'), $this->pagamento('500.00')]);

        self::assertNotNull($r->financeiro);
        self::assertSame('R$ 12.860,00', $r->financeiro['valorCausa']);
        self::assertSame('R$ 1.000,00', $r->financeiro['recebido']);
        self::assertSame('R$ 1.500,00', $r->financeiro['previsto']);
        self::assertSame(2, $r->financeiro['lancamentos']);
    }

    #[TestDox('Sem financeiro liberado a seção NÃO existe — nem vazia, nem com travessão')]
    public function testFinanceiroOmitido(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('4');
        $pasta->setValorCausa('99999.00');

        $r = $this->montar($pasta, [$this->pagamento('1000.00')], financeiro: false);

        self::assertNull($r->financeiro);
    }
}
