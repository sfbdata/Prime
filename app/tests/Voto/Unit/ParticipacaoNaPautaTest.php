<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParticipacaoNaPauta::class)]
final class ParticipacaoNaPautaTest extends TestCase
{
    #[TestDox('Registra unidade, pauta e hora, com o escritório da unidade, e nada sobre a opção')]
    public function testRegistraSoQuemEQuando(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G');
        $unidade = new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        $quando = new \DateTimeImmutable('2026-11-10 19:05');

        $participacao = new ParticipacaoNaPauta($unidade, $pauta, $quando);

        self::assertSame($unidade, $participacao->getUnidade());
        self::assertSame($pauta, $participacao->getPauta());
        self::assertSame($quando, $participacao->getRegistradaEm());
        self::assertSame($assembleia->getTenant(), $participacao->getTenant());
        $propriedades = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(ParticipacaoNaPauta::class))->getProperties());
        self::assertSame([], array_values(array_filter($propriedades, static fn (string $nome): bool => str_contains(strtolower($nome), 'opcao'))), 'sigilo: a participação não guarda a opção');
    }

    #[TestDox('Unidade de outra assembleia não participa da pauta')]
    public function testUnidadeDeOutraAssembleia(): void
    {
        $tenant = new Tenant();
        $unidade = new UnidadeDaAssembleia(new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'G'), '101', str_repeat('a', 32), str_repeat('b', 64));
        $pauta = new PautaDaAssembleia(new Assembleia($tenant, 'AGE', PesoDoVoto::Unidade, 'G'), 1, 'Contas');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        new ParticipacaoNaPauta($unidade, $pauta);
    }
}
