<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaPendenciasOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras da linha vermelha sob as abas (desenho 1.2.3) — provadas nos dois
 * sentidos: o caso que acende e o caso em que o filtro remove tudo. A tela
 * (PastaAbasPendenciaTelaTest) só confere que o resultado chega ao lugar certo.
 */
#[CoversClass(PastaPendenciasOutput::class)]
final class PastaPendenciasOutputTest extends TestCase
{
    private const HOJE = '2026-10-05';

    private function pastaRegular(): Pasta
    {
        // Contrato regular + um processo vinculado: nenhuma regra acende sozinha.
        $pasta = new Pasta();
        $pasta->setNup('1');
        $pasta->setSituacaoContrato('REGULAR');
        $pasta->vincularProcesso(new Processo());

        return $pasta;
    }

    private function meta(Pasta $pasta, string $status): void
    {
        $meta = new Tarefa();
        $meta->setTitulo('x');
        $meta->setDescricao('x');
        $meta->setPasta($pasta);
        $meta->setStatus($status);
        $pasta->getTarefas()->add($meta);
    }

    private function pagamento(string $vencimento, bool $pago = false): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setVencimento(new \DateTimeImmutable($vencimento));
        if ($pago) {
            $pagamento->alternarQuitacao(new \DateTimeImmutable(self::HOJE));
        }

        return $pagamento;
    }

    #[TestDox('pasta regularizada e sem novidade: nenhuma aba pendente')]
    public function testSemPendencias(): void
    {
        $out = PastaPendenciasOutput::montar($this->pastaRegular(), 0, [], new \DateTimeImmutable(self::HOJE));

        self::assertSame([], $out->porAba);
    }

    #[TestDox('Metas: conta só as não concluídas (pendente e em revisão), com singular e plural certos')]
    public function testMetas(): void
    {
        $pasta = $this->pastaRegular();
        $this->meta($pasta, Tarefa::STATUS_CONCLUIDA);
        $this->meta($pasta, Tarefa::STATUS_PENDENTE);
        $out = PastaPendenciasOutput::montar($pasta, 0, [], new \DateTimeImmutable(self::HOJE));
        self::assertSame(['n' => 1, 'txt' => '1 meta exige atenção'], $out->porAba['tarefas']);

        $this->meta($pasta, Tarefa::STATUS_EM_REVISAO);
        $out = PastaPendenciasOutput::montar($pasta, 0, [], new \DateTimeImmutable(self::HOJE));
        self::assertSame(['n' => 2, 'txt' => '2 metas exigem atenção'], $out->porAba['tarefas']);

        // Só concluídas: o filtro remove tudo.
        $soConcluida = $this->pastaRegular();
        $this->meta($soConcluida, Tarefa::STATUS_CONCLUIDA);
        $out = PastaPendenciasOutput::montar($soConcluida, 0, [], new \DateTimeImmutable(self::HOJE));
        self::assertArrayNotHasKey('tarefas', $out->porAba);
    }

    #[TestDox('Processo: pasta sem nenhum processo vinculado')]
    public function testProcesso(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('1');
        $pasta->setSituacaoContrato('REGULAR');

        $out = PastaPendenciasOutput::montar($pasta, 0, [], new \DateTimeImmutable(self::HOJE));
        self::assertSame(['n' => 1, 'txt' => 'nenhum processo vinculado'], $out->porAba['processo']);

        $pasta->vincularProcesso(new Processo());
        $out = PastaPendenciasOutput::montar($pasta, 0, [], new \DateTimeImmutable(self::HOJE));
        self::assertArrayNotHasKey('processo', $out->porAba);
    }

    #[TestDox('Financeiro: contrato pendente acende; pagamentos vencidos somam ao texto; pró-bono ou REGULAR apagam tudo')]
    public function testFinanceiro(): void
    {
        $pasta = $this->pastaRegular();
        $pasta->setSituacaoContrato('PENDENTE');
        $hoje = new \DateTimeImmutable(self::HOJE);

        $out = PastaPendenciasOutput::montar($pasta, 0, [], $hoje);
        self::assertSame(['n' => 1, 'txt' => 'contrato de honorários pendente de assinatura'], $out->porAba['financeiro']);

        $pagamentos = [
            $this->pagamento('2026-09-01'),        // vencido
            $this->pagamento('2026-09-15'),        // vencido
            $this->pagamento('2026-09-01', true),  // pago com atraso: NÃO é vencido
            $this->pagamento('2026-10-05'),        // vence hoje: ainda não venceu
            $this->pagamento('2026-12-01'),        // futuro
        ];
        $out = PastaPendenciasOutput::montar($pasta, 0, $pagamentos, $hoje);
        self::assertSame(
            ['n' => 3, 'txt' => 'contrato de honorários pendente de assinatura e 2 pagamentos vencidos'],
            $out->porAba['financeiro']
        );

        $out = PastaPendenciasOutput::montar($pasta, 0, [$this->pagamento('2026-09-01')], $hoje);
        self::assertSame('contrato de honorários pendente de assinatura e 1 pagamento vencido', $out->porAba['financeiro']['txt']);

        // Regra primária do desenho: pró-bono regulariza mesmo com vencidos.
        $pasta->setProBono(true);
        $out = PastaPendenciasOutput::montar($pasta, 0, $pagamentos, $hoje);
        self::assertArrayNotHasKey('financeiro', $out->porAba);

        // Contrato regular, sem pró-bono, com vencidos: também não acende (o desenho só
        // conta vencidos enquanto o contrato está pendente).
        $pasta->setProBono(false);
        $pasta->setSituacaoContrato('REGULAR');
        $out = PastaPendenciasOutput::montar($pasta, 0, $pagamentos, $hoje);
        self::assertArrayNotHasKey('financeiro', $out->porAba);
    }

    #[TestDox('Push: publicações não lidas, singular e plural; zero não acende')]
    public function testPush(): void
    {
        $pasta = $this->pastaRegular();
        $hoje  = new \DateTimeImmutable(self::HOJE);

        self::assertArrayNotHasKey('push', PastaPendenciasOutput::montar($pasta, 0, [], $hoje)->porAba);
        self::assertSame(
            ['n' => 1, 'txt' => '1 movimentação nova sem leitura'],
            PastaPendenciasOutput::montar($pasta, 1, [], $hoje)->porAba['push']
        );
        self::assertSame(
            ['n' => 3, 'txt' => '3 movimentações novas sem leitura'],
            PastaPendenciasOutput::montar($pasta, 3, [], $hoje)->porAba['push']
        );
    }

    #[TestDox('só as abas pendentes entram no mapa; Dados, Detalhes e Documentos nunca')]
    public function testSoAbasPendentesEntram(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('1');
        $this->meta($pasta, Tarefa::STATUS_PENDENTE);

        $out = PastaPendenciasOutput::montar($pasta, 2, [], new \DateTimeImmutable(self::HOJE));

        self::assertSame(['tarefas', 'processo', 'financeiro', 'push'], array_keys($out->porAba));
    }
}
