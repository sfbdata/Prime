<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Profile\DTO\CompletudeDoCadastro;
use App\Profile\DTO\PerfilOutput;
use App\Profile\Enum\VinculoColaborador;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletudeDoCadastro::class)]
#[CoversClass(CatalogoDePreferenciasDoPerfil::class)]
#[CoversClass(VinculoColaborador::class)]
final class CompletudeDoCadastroTest extends TestCase
{
    #[TestDox('o print do desenho: falta só foto e telefone com CLT dá 78%')]
    public function testExemploDoDesenho(): void
    {
        $c = CompletudeDoCadastro::de($this->perfil(foto: null, telefone: null));

        self::assertSame(78, $c->percentual);
        self::assertSame(['foto', 'telefone'], $c->faltando);
        self::assertFalse($c->completo());
    }

    #[TestDox('tudo preenchido dá 100% e some o alerta')]
    public function testCompleto(): void
    {
        self::assertTrue(CompletudeDoCadastro::de($this->perfil())->completo());
    }

    #[TestDox('fora do CLT, conta o início do contrato no lugar da CTPS')]
    public function testForaDoCltContaInicioDoContrato(): void
    {
        $c = CompletudeDoCadastro::de($this->perfil(vinculo: VinculoColaborador::Socio));

        self::assertSame(['início do contrato'], $c->faltando);
    }

    #[TestDox('preferências: padrão ligado; valor gravado que não é booleano também vale ligado')]
    public function testPreferenciasEfetivas(): void
    {
        $efetivas = CatalogoDePreferenciasDoPerfil::efetivas(['perfil.sons' => false, 'perfil.notificacoes' => 'x']);

        self::assertSame(['perfil.notificacoes' => true, 'perfil.sons' => false, 'perfil.resumo_email' => true], $efetivas);
        self::assertFalse(CatalogoDePreferenciasDoPerfil::existe('dashboard.sons'));
    }

    #[TestDox('vínculo: CLT e estágio batem ponto; os demais não')]
    public function testBatePonto(): void
    {
        $batem = array_values(array_map(static fn (VinculoColaborador $v): string => $v->value, array_filter(VinculoColaborador::cases(), static fn (VinculoColaborador $v): bool => $v->batePonto())));

        self::assertSame(['clt', 'estagio'], $batem);
    }

    private function perfil(?string $foto = 'f.jpg', ?string $telefone = '82999991234', VinculoColaborador $vinculo = VinculoColaborador::Clt): PerfilOutput
    {
        return new PerfilOutput(
            nomeCompleto: 'Farlei Assis da Rocha',
            nomeExibido: 'Farlei Assis da Rocha',
            iniciais: 'FR',
            email: 'f@x.com',
            cargo: null,
            lotacao: null,
            fotoUrl: $foto,
            status: 'Frase',
            cpf: '02254772546',
            dataNascimento: new \DateTimeImmutable('1986-08-25'),
            ctps: '6562329',
            serie: '0060',
            membroDesde: new \DateTimeImmutable('2026-04-07'),
            oabNumero: '49613',
            oabUf: 'DF',
            oabStatus: null,
            oabNomeOficial: null,
            oabVerificadaEm: null,
            telefone: $telefone,
            vinculo: $vinculo,
        );
    }
}
