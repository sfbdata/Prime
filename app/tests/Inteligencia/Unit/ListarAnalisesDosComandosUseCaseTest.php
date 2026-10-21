<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\RegistroDeAuditoriaOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\ListarAnalisesDosComandosUseCase;
use App\Pasta\Entity\Pasta;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O painel do drawer por comando (spec inteligencia-drawer-da-pasta, F1/F2): uma consulta por
 * comando, todo comando com entrada, a última concluída para o rótulo do botão. A consulta em si
 * (comando + legados do agente, tenant explícito) se prova no teste funcional.
 */
#[CoversClass(ListarAnalisesDosComandosUseCase::class)]
#[CoversClass(RegistroDeAuditoriaOutput::class)]
final class ListarAnalisesDosComandosUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        DefineId::em($this->pasta, 7);
    }

    private function analise(int $id, Agente $agente, ?Comando $comando, bool $concluida): AnaliseDeInteligencia
    {
        $a = new AnaliseDeInteligencia($this->tenant, null, TipoDeAnalise::AnalisePasta, AnaliseDeInteligencia::ALVO_PASTA, 7, 'agente-v1', str_repeat('a', 64), ['total' => 1, 'financeiro' => false], $agente, $comando);
        DefineId::em($a, $id);
        if ($concluida) {
            $a->iniciarProcessamento();
            $a->concluir('Resumo.', [], null, '{}', 'falso', 'falso-1', 1, 1, 1, 'TEXTO');
        }

        return $a;
    }

    #[TestDox('uma consulta por comando e relatório (15), com tenant, alvo e limite com folga; todo comando tem entrada')]
    public function testPainelUmaConsultaPorComando(): void
    {
        $vistos = [];
        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        // 10 comandos, 5 relatórios e o Índice da aba Documentos (F4).
        $repo->expects(self::exactly(16))->method('listarPorComando')->willReturnCallback(
            function (Tenant $t, string $alvo, int $id, Comando $comando, int $limite) use (&$vistos): array {
                self::assertSame($this->tenant, $t);
                self::assertSame(AnaliseDeInteligencia::ALVO_PASTA, $alvo);
                self::assertSame(7, $id);
                self::assertSame(ListarAnalisesDosComandosUseCase::LIMITE_POR_COMANDO * ListarAnalisesDosComandosUseCase::FOLGA_DO_FILTRO, $limite);
                $vistos[] = $comando;

                return $comando === Comando::AnalisarPasta
                    ? [$this->analise(2, Agente::Gestor, null, false), $this->analise(1, Agente::Gestor, Comando::AnalisarPasta, true)]
                    : [];
            },
        );

        $painel = (new ListarAnalisesDosComandosUseCase($repo))->executar($this->pasta, $this->tenant, []);

        self::assertSame(Comando::cases(), $vistos);
        self::assertTrue($painel->temEmAndamento());
        self::assertSame(2, $painel->totalDeAnalises());
        $analisar = $painel->de(Comando::AnalisarPasta);
        self::assertTrue($analisar->temEmAndamento());
        self::assertSame(1, $analisar->ultimaConcluida?->id);
        self::assertNull($analisar->analises[0]->comando, 'a antiga, sem comando, aparece no comando do agente legado');
        self::assertSame('Agente Gestor', $analisar->analises[0]->agenteNome);
        self::assertSame('Analisar esta pasta', $analisar->analises[1]->comandoRotulo);
        self::assertFalse($painel->de(Comando::RelatorioRisco)->temAnalise());
    }

    #[TestDox('lista de UM comando: corta no limite')]
    public function testListaDeUmComandoCortaNoLimite(): void
    {
        $muitas = [];
        for ($i = 1; $i <= ListarAnalisesDosComandosUseCase::LIMITE_POR_COMANDO + 5; ++$i) {
            $muitas[] = $this->analise($i, Agente::Prazos, Comando::RelatorioPrazos, true);
        }
        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repo->method('listarPorComando')->willReturn($muitas);

        $saida = (new ListarAnalisesDosComandosUseCase($repo))->executarParaComando($this->pasta, $this->tenant, Comando::RelatorioPrazos, []);

        self::assertSame(Comando::RelatorioPrazos, $saida->comando);
        self::assertSame(ListarAnalisesDosComandosUseCase::LIMITE_POR_COMANDO, $saida->total());
        self::assertSame(1, $saida->ultimaConcluida?->id);
    }

    #[TestDox('registro de auditoria: o rótulo é o do comando quando há; sem comando, o nome do agente')]
    public function testRotuloDaAuditoria(): void
    {
        self::assertSame('Relatório de risco', RegistroDeAuditoriaOutput::fromEntity($this->analise(3, Agente::Gestor, Comando::RelatorioRisco, true))->oQue);
        self::assertSame('Agente Gestor', RegistroDeAuditoriaOutput::fromEntity($this->analise(4, Agente::Gestor, null, true))->oQue);
        self::assertSame('Raio-X jurídico', RegistroDeAuditoriaOutput::rotuloDaAnalise(Agente::Processual, TipoDeAnalise::AnalisePasta, Comando::RaioX));
    }
}
