<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O detalhe e o texto dos eventos de ajuste na trilha do Ponto (E10, plano-e10-ponto §5): o que se grava (sem o
 * motivo) e o que a coluna "Detalhe" da aba Auditoria mostra para cada um dos quatro eventos novos e para a inclusão do
 * esquecimento abonado.
 */
#[CoversClass(DetalheDaTrilhaDoPonto::class)]
#[CoversClass(AcaoDaTrilhaDoPonto::class)]
final class DetalheDaTrilhaDoAjusteTest extends TestCase
{
    private Tenant $escritorio;
    private User $pessoa;
    private User $gestora;

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setName('Escritório');
        $this->pessoa = $this->comId((new User())->setEmail('ana@test.com')->setFullName('Ana Colaboradora'), 5);
        $this->gestora = $this->comId((new User())->setEmail('gestora@test.com')->setFullName('Gestora'), 6);
    }

    #[TestDox('Os quatro eventos novos têm código e rótulo do plano')]
    public function testRotulosDosEventosNovos(): void
    {
        self::assertSame(
            ['batida_incluida' => 'Batida incluída', 'batida_ajustada' => 'Batida ajustada', 'batida_desconsiderada' => 'Batida desconsiderada', 'ajuste_desfeito' => 'Ajuste desfeito'],
            [
                AcaoDaTrilhaDoPonto::BatidaIncluida->value       => AcaoDaTrilhaDoPonto::BatidaIncluida->rotulo(),
                AcaoDaTrilhaDoPonto::BatidaAjustada->value       => AcaoDaTrilhaDoPonto::BatidaAjustada->rotulo(),
                AcaoDaTrilhaDoPonto::BatidaDesconsiderada->value => AcaoDaTrilhaDoPonto::BatidaDesconsiderada->rotulo(),
                AcaoDaTrilhaDoPonto::AjusteDesfeito->value       => AcaoDaTrilhaDoPonto::AjusteDesfeito->rotulo(),
            ],
        );
    }

    #[TestDox('daAjuste da alteração: colaborador, ajuste, alvo, anterior, novo, justificativa e o que desfaz; sem o motivo')]
    public function testDetalheDaAlteracao(): void
    {
        $alteracao = $this->comId(PontoAjuste::alteracao($this->alvoNaBatida(31, 'saida', '2026-10-21 18:00:00'), 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), 'Motivo livre.', $this->gestora, 'Gestora', new \DateTimeImmutable()), 40);

        self::assertSame([
            'colaborador_id'   => 5,
            'colaborador'      => 'Ana Colaboradora',
            'ajuste_id'        => 40,
            'alvo'             => 'b31',
            'anterior'         => ['tipo' => 'saida', 'data_hora' => '2026-10-21 18:00:00'],
            'novo'             => ['tipo' => 'saida', 'data_hora' => '2026-10-21 18:30:00'],
            'justificativa_id' => null,
            'desfaz_id'        => null,
        ], DetalheDaTrilhaDoPonto::daAjuste($alteracao));
    }

    #[TestDox('Textos: incluída, passou para, desconsiderada, restaurada e a desconsideração do esquecimento ao voltar para análise')]
    public function testTextosDosEventosNovos(): void
    {
        $inclusao = $this->comId(PontoAjuste::inclusao($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), 'm', $this->gestora, 'Gestora', new \DateTimeImmutable()), 50);
        $alteracao = $this->comId(PontoAjuste::alteracao($this->alvoNaBatida(31, 'saida', '2026-10-21 18:00:00'), 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), 'm', $this->gestora, 'Gestora', new \DateTimeImmutable()), 51);
        $desconsideracao = $this->comId(PontoAjuste::desconsideracao($this->alvoNaBatida(31, 'saida', '2026-10-21 18:00:00'), 'm', $this->gestora, 'Gestora', new \DateTimeImmutable()), 52);
        $restauracao = $this->comId(PontoAjuste::restauracao($desconsideracao, 'm', $this->gestora, 'Gestora', new \DateTimeImmutable()), 53);
        $reverter = $this->comId(PontoAjuste::desconsideracao($this->alvoNaInclusao($inclusao), 'm', $this->gestora, 'Gestora', new \DateTimeImmutable(), $this->justificativa(), $inclusao), 54);

        self::assertSame('Ana Colaboradora: Saída às 18:00 de 21/10/2026 incluída', $this->texto(AcaoDaTrilhaDoPonto::BatidaIncluida, $inclusao));
        self::assertSame('Ana Colaboradora: Saída às 18:00 de 21/10/2026 passou para Saída às 18:30 de 21/10/2026', $this->texto(AcaoDaTrilhaDoPonto::BatidaAjustada, $alteracao));
        self::assertSame('Ana Colaboradora: Saída às 18:00 de 21/10/2026 desconsiderada', $this->texto(AcaoDaTrilhaDoPonto::BatidaDesconsiderada, $desconsideracao));
        self::assertSame('Ana Colaboradora: Saída às 18:00 de 21/10/2026 restaurada', $this->texto(AcaoDaTrilhaDoPonto::AjusteDesfeito, $restauracao));
        self::assertSame(
            'Ana Colaboradora: Saída às 18:00 de 21/10/2026, do esquecimento abonado, desconsiderada ao voltar a justificativa para análise',
            $this->texto(AcaoDaTrilhaDoPonto::AjusteDesfeito, $reverter),
        );
        self::assertSame(['justificativa_id' => 70, 'desfaz_id' => 50], array_intersect_key(DetalheDaTrilhaDoPonto::daAjuste($reverter), ['desfaz_id' => 0, 'justificativa_id' => 0]));
    }

    #[TestDox('Inclusão do esquecimento: o detalhe do ajuste mais o tipo e a data/hora no primeiro nível; o texto é o de sempre do esquecimento')]
    public function testInclusaoDoEsquecimento(): void
    {
        $inclusao = $this->comId(PontoAjuste::inclusao($this->escritorio, $this->pessoa, 'retorno', new \DateTimeImmutable('2026-10-21 13:15:00'), 'm', $this->gestora, 'Gestora', new \DateTimeImmutable(), $this->justificativa()), 60);

        $detalhe = DetalheDaTrilhaDoPonto::daInclusaoDoEsquecimento($inclusao);

        self::assertSame(['a60', 60, 70, 'retorno', '2026-10-21 13:15:00'], [$detalhe['alvo'], $detalhe['ajuste_id'], $detalhe['justificativa_id'], $detalhe['tipo'], $detalhe['data_hora']]);
        self::assertSame(
            'Ana Colaboradora: Retorno às 13:15 de 21/10/2026, pedida no esquecimento',
            DetalheDaTrilhaDoPonto::texto(AcaoDaTrilhaDoPonto::BatidaDoEsquecimento->value, $detalhe),
        );
    }

    #[TestDox('daInclusaoDoEsquecimento recusa a inclusão sem justificativa (é o evento do gestor, batida_incluida)')]
    public function testEsquecimentoSemJustificativaRecusado(): void
    {
        $inclusao = $this->comId(PontoAjuste::inclusao($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), 'm', $this->gestora, 'Gestora', new \DateTimeImmutable()), 61);

        $this->expectException(\LogicException::class);
        DetalheDaTrilhaDoPonto::daInclusaoDoEsquecimento($inclusao);
    }

    #[TestDox('Detalhe sem as marcações (evento antigo ou incompleto): o texto não quebra')]
    public function testDetalheIncompletoNaoQuebra(): void
    {
        self::assertSame('Colaborador: Batida incluída', DetalheDaTrilhaDoPonto::texto('batida_incluida', ['novo' => null]));
        self::assertSame('Colaborador: Batida passou para Batida', DetalheDaTrilhaDoPonto::texto('batida_ajustada', ['anterior' => 'x']));
        self::assertSame('Colaborador: Batida, do esquecimento abonado, desconsiderada ao voltar a justificativa para análise', DetalheDaTrilhaDoPonto::texto('ajuste_desfeito', []));
    }

    private function texto(AcaoDaTrilhaDoPonto $acao, PontoAjuste $ajuste): string
    {
        // Como a trilha: o detalhe vai para o JSON e volta.
        $detalhe = json_decode((string) json_encode(DetalheDaTrilhaDoPonto::daAjuste($ajuste)), true);

        return DetalheDaTrilhaDoPonto::texto($acao->value, $detalhe);
    }

    private function alvoNaBatida(int $registroId, string $tipo, string $quando): AlvoDoAjuste
    {
        $batida = (new RegistroPonto())->setTenant($this->escritorio)->setUser($this->pessoa)->setTipo($tipo)->setDataHora(new \DateTime($quando));
        $this->comId($batida, $registroId);

        return new AlvoDoAjuste($this->escritorio, $this->pessoa, 'b' . $registroId, $batida, null, null, $tipo, new \DateTimeImmutable($quando));
    }

    private function alvoNaInclusao(PontoAjuste $inclusao): AlvoDoAjuste
    {
        return new AlvoDoAjuste($this->escritorio, $this->pessoa, $inclusao->alvo(), null, $inclusao, $inclusao, $inclusao->getTipoMarcacaoNova(), $inclusao->getDataHoraNova());
    }

    private function justificativa(): JustificativaPonto
    {
        return $this->comId((new JustificativaPonto())->setTenant($this->escritorio)->setUser($this->pessoa), 70);
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade::class, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
