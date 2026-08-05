<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Desempate entre justificativas do MESMO dia.
 *
 * O sistema aceita várias justificativas por data (não há constraint única em user+data) e aceita de
 * propósito: em produção, 34 dias têm de 2 a 3 — o caso comum é esquecer duas batidas no mesmo dia e
 * lançar uma para cada. Mas o cálculo só honra uma, e desde que `abonaSaldo()` passou a distinguir
 * tipo técnico de abono legítimo, **qual delas vence mudou o saldo do dia**: em 02/06/2026 um
 * colaborador tem `esquecimento_registro (abonado)` e `atestado_medico (abonado)` na mesma data, com
 * efeitos opostos. Sem desempate explícito o resultado dependia da ordem que o SGBD devolvesse.
 *
 * @see docs/specs/ponto-abono-nao-perdoa-jornada.md
 */
#[CoversClass(JustificativaPontoRepository::class)]
final class JustificativaPontoIndexacaoTest extends TestCase
{
    private JustificativaPontoRepository $repositorio;

    protected function setUp(): void
    {
        // `indexarPorDia` é puro — não toca banco nem estado do repositório. Instanciar sem o
        // construtor evita arrastar um ManagerRegistry falso só para exercitar uma regra de decisão.
        $this->repositorio = (new \ReflectionClass(JustificativaPontoRepository::class))
            ->newInstanceWithoutConstructor();
    }

    private function justificativa(string $tipo, string $status, string $data = '2026-06-02'): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setTipo($tipo);
        $justificativa->setStatus($status);
        $justificativa->setData(new \DateTime($data));

        return $justificativa;
    }

    public function testEntreDuasAbonadasVenceAQueAbonaOSaldo(): void
    {
        // O caso real: SAMUEL FREITAS em 02/06/2026.
        $esquecimento = $this->justificativa('esquecimento_registro', 'abonado');
        $atestado     = $this->justificativa('atestado_medico', 'abonado');

        $indexado = $this->repositorio->indexarPorDia([$esquecimento, $atestado]);

        self::assertSame($atestado, $indexado['2026-06-02']);
    }

    public function testOResultadoNaoDependeDaOrdemDeEntrada(): void
    {
        // É esta asserção que fecha a porta: antes, inverter a ordem invertia o saldo do dia.
        $esquecimento = $this->justificativa('esquecimento_registro', 'abonado');
        $atestado     = $this->justificativa('atestado_medico', 'abonado');

        $indexadoA = $this->repositorio->indexarPorDia([$esquecimento, $atestado]);
        $indexadoB = $this->repositorio->indexarPorDia([$atestado, $esquecimento]);

        self::assertSame($atestado, $indexadoA['2026-06-02']);
        self::assertSame($atestado, $indexadoB['2026-06-02']);
    }

    public function testAbonadaVenceRejeitadaEPendenteEmQualquerOrdem(): void
    {
        $rejeitada = $this->justificativa('saida_antecipada_autorizada', 'rejeitado');
        $pendente  = $this->justificativa('atestado_medico', 'pendente');
        $abonada   = $this->justificativa('dispensa_abonada', 'abonado');

        self::assertSame($abonada, $this->repositorio->indexarPorDia([$rejeitada, $pendente, $abonada])['2026-06-02']);
        self::assertSame($abonada, $this->repositorio->indexarPorDia([$abonada, $pendente, $rejeitada])['2026-06-02']);
    }

    public function testTecnicaAbonadaVenceRejeitada(): void
    {
        // Técnica não abona saldo, mas ainda é a decisão do admin — precisa aparecer na folha na
        // frente de uma justificativa recusada.
        $rejeitada = $this->justificativa('atestado_medico', 'rejeitado');
        $tecnica   = $this->justificativa('esquecimento_registro', 'abonado');

        self::assertSame($tecnica, $this->repositorio->indexarPorDia([$rejeitada, $tecnica])['2026-06-02']);
        self::assertSame($tecnica, $this->repositorio->indexarPorDia([$tecnica, $rejeitada])['2026-06-02']);
    }

    public function testEntreIguaisVenceAUltimaDaOrdemRecebida(): void
    {
        // 28 dos 34 dias duplicados em produção são dois esquecimentos abonados. Como se comportam
        // igual, tanto faz quem vence para o saldo — mas a escolha precisa ser estável, e a consulta
        // ordena por id crescente, então a última é a decisão mais recente do admin.
        $primeiro = $this->justificativa('esquecimento_registro', 'abonado');
        $segundo  = $this->justificativa('esquecimento_registro', 'abonado');

        self::assertSame($segundo, $this->repositorio->indexarPorDia([$primeiro, $segundo])['2026-06-02']);
    }

    public function testJustificativaSemTipoContaComoAbonoParaODesempate(): void
    {
        // Mesmo default do FolhaPontoBuilder: tipo nulo abona. Os dois lugares não podem discordar
        // sobre o que uma justificativa sem tipo faz, senão a escolhida aqui é abonada lá e o dia
        // vira um número que nenhum dos dois pretendia.
        $semTipo = $this->justificativa('dispensa_abonada', 'abonado');
        $semTipo->setTipo(null);
        $tecnica = $this->justificativa('esquecimento_registro', 'abonado');

        self::assertSame($semTipo, $this->repositorio->indexarPorDia([$semTipo, $tecnica])['2026-06-02']);
        self::assertSame($semTipo, $this->repositorio->indexarPorDia([$tecnica, $semTipo])['2026-06-02']);
    }

    public function testDiasDiferentesNaoDisputamEntreSi(): void
    {
        $dia2 = $this->justificativa('esquecimento_registro', 'abonado', '2026-06-02');
        $dia3 = $this->justificativa('atestado_medico', 'abonado', '2026-06-03');

        $indexado = $this->repositorio->indexarPorDia([$dia2, $dia3]);

        self::assertCount(2, $indexado);
        self::assertSame($dia2, $indexado['2026-06-02']);
        self::assertSame($dia3, $indexado['2026-06-03']);
    }
}
