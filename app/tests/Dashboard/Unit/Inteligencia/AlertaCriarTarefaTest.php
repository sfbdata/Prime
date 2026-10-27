<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\Inteligencia\Alerta;
use App\Dashboard\Inteligencia\NivelDeAlerta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Quando o alerta vira tarefa e o texto que ele entrega ao formulário da pasta (botão "Criar tarefa"). */
#[CoversClass(Alerta::class)]
#[Group('dashboard')]
final class AlertaCriarTarefaTest extends TestCase
{
    private function alerta(NivelDeAlerta $nivel, ?string $acao, ?int $pessoaId = null): Alerta
    {
        return new Alerta('vencidas', $nivel, '3 metas vencidas', '3 metas passaram do prazo.', 'Bruno tem 3.', null, null, $acao, pessoaId: $pessoaId);
    }

    #[TestDox('Só vira tarefa o alerta com ação e fora do nível Normal')]
    public function testQuandoPodeVirarTarefa(): void
    {
        self::assertTrue($this->alerta(NivelDeAlerta::Critico, 'Priorizar as vencidas.')->podeVirarTarefa());
        self::assertTrue($this->alerta(NivelDeAlerta::Oportunidade, 'Direcionar metas.')->podeVirarTarefa());
        self::assertFalse($this->alerta(NivelDeAlerta::Normal, 'Manter.')->podeVirarTarefa());
        self::assertFalse($this->alerta(NivelDeAlerta::Critico, null)->podeVirarTarefa());
        self::assertFalse($this->alerta(NivelDeAlerta::Critico, '  ')->podeVirarTarefa());
    }

    #[TestDox('Título é a ação do alerta; descrição junta problema, evidência e ação, dentro dos limites')]
    public function testTextoDaTarefa(): void
    {
        $a = $this->alerta(NivelDeAlerta::Critico, 'Priorizar as vencidas.');

        self::assertSame('Priorizar as vencidas.', $a->tituloDaTarefa());
        self::assertSame("3 metas vencidas: 3 metas passaram do prazo.\nBruno tem 3.\nPriorizar as vencidas.", $a->descricaoDaTarefa());

        $longo = $this->alerta(NivelDeAlerta::Critico, str_repeat('a', 400));
        self::assertSame(255, mb_strlen($longo->tituloDaTarefa()));
        self::assertLessThanOrEqual(1500, mb_strlen($longo->descricaoDaTarefa()));
    }
}
