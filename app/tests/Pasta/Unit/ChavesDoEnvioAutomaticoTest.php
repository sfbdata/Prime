<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ChavesDoEnvioAutomatico;
use App\Pasta\DTO\ItemDoEnvioAutomaticoOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As chaves do envio automático (spec push-envio-automatico §1 e §2, D1): as três nascem desligadas
 * (sem linha e na linha nova); as linhas do bloco têm o rótulo e a dica do desenho, com o prefixo
 * "Cadastre um e-mail para ativar. " sem e-mail.
 */
#[CoversClass(ChavesDoEnvioAutomatico::class)]
#[CoversClass(ItemDoEnvioAutomaticoOutput::class)]
#[CoversClass(EnvioAutomaticoDoCliente::class)]
#[CoversClass(ChaveDoEnvioAutomatico::class)]
final class ChavesDoEnvioAutomaticoTest extends TestCase
{
    #[TestDox('D1: sem linha e na linha recém-criada, as três chaves estão desligadas')]
    public function testPadroesDesligados(): void
    {
        self::assertFalse(ChaveDoEnvioAutomatico::PADRAO);
        $semLinha = ChavesDoEnvioAutomatico::da(null);
        $linha = new EnvioAutomaticoDoCliente(new Tenant(), new ClientePF(), null, new \DateTimeImmutable());
        foreach (ChaveDoEnvioAutomatico::cases() as $chave) {
            self::assertFalse($semLinha->ligada($chave), $chave->value);
            self::assertFalse($linha->ligada($chave), $chave->value);
        }
        self::assertFalse($semLinha->avisaNaCaptacao());
    }

    #[TestDox('definir liga só a chave pedida e registra quem mexeu e quando')]
    public function testDefinir(): void
    {
        $linha = new EnvioAutomaticoDoCliente(new Tenant(), new ClientePF(), null, new \DateTimeImmutable('2026-10-01'));
        $quem = new User();
        $linha->definir(ChaveDoEnvioAutomatico::AudienciasIntimacoes, true, $quem, new \DateTimeImmutable('2026-10-19 10:00'));

        $chaves = ChavesDoEnvioAutomatico::da($linha);
        self::assertFalse($chaves->novasMovimentacoes);
        self::assertTrue($chaves->audienciasIntimacoes);
        self::assertFalse($chaves->resumoSemanal);
        self::assertTrue($chaves->avisaNaCaptacao());
        self::assertSame($quem, $linha->getAtualizadoPor());
        self::assertSame('2026-10-19 10:00', $linha->getAtualizadoEm()->format('Y-m-d H:i'));
    }

    #[TestDox('Bloco "Envio automático": rótulos e dicas do desenho na ordem; sem e-mail, o prefixo da dica')]
    public function testLinhasDoBloco(): void
    {
        $comEmail = ItemDoEnvioAutomaticoOutput::lista(new ChavesDoEnvioAutomatico(novasMovimentacoes: true), false);
        self::assertSame(['Novas movimentações', 'Audiências e intimações', 'Resumo semanal'], array_map(static fn ($i): string => $i->rotulo, $comEmail));
        self::assertSame(['novas_movimentacoes', 'audiencias_intimacoes', 'resumo_semanal'], array_map(static fn ($i): string => $i->chave, $comEmail));
        self::assertSame([true, false, false], array_map(static fn ($i): bool => $i->ligado, $comEmail));
        self::assertSame('Envia ao cliente um aviso com o texto oficial da movimentação, assim que ela chega', $comEmail[0]->dica());
        self::assertSame('Avisa quando chegar intimação ou designação de audiência', $comEmail[1]->dica());
        self::assertSame('Toda segunda-feira, um resumo das movimentações da semana anterior', $comEmail[2]->dica());

        $semEmail = ItemDoEnvioAutomaticoOutput::lista(new ChavesDoEnvioAutomatico(), true);
        self::assertSame('Cadastre um e-mail para ativar. Toda segunda-feira, um resumo das movimentações da semana anterior', $semEmail[2]->dica());
    }
}
