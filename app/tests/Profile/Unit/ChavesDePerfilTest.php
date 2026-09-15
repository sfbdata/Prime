<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Armazenamento\ChavesDePerfil;
use App\Profile\Entity\UserProfile;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A foto de perfil é do `User`, não do escritório — por isso o escopo é GLOBAL (D1), e por isso a
 * purga de escritório a poupa. É a única categoria em que a fábrica não procura tenant nenhum.
 */
#[CoversClass(ChavesDePerfil::class)]
final class ChavesDePerfilTest extends TestCase
{
    #[TestDox('foto(): categoria FOTO_PERFIL com escopo global')]
    public function testFotoEhGlobal(): void
    {
        $chave = ChavesDePerfil::foto($this->perfil('abc.jpg'));

        self::assertSame(CategoriaDeArquivo::FOTO_PERFIL, $chave->categoria);
        self::assertTrue($chave->escopo->ehGlobal());
        self::assertSame('abc.jpg', $chave->nome);
    }

    #[TestDox('foto(): perfil sem foto não tem chave')]
    public function testPerfilSemFotoLanca(): void
    {
        $perfil = $this->perfil(null);

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePerfil::foto($perfil);
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegados(): iterable
    {
        yield 'espaço nas bordas' => [' abc.jpg '];
        yield 'termina em ponto'  => ['hash.'];
    }

    #[DataProvider('nomesLegados')]
    #[TestDox('foto(): nome preservado byte a byte')]
    public function testNomeEhPreservadoByteAByte(string $nome): void
    {
        self::assertSame($nome, ChavesDePerfil::foto($this->perfil($nome))->nome);
    }

    #[TestDox('foto(): recebe só a entidade')]
    public function testFabricaRecebeSoAEntidade(): void
    {
        $parametros = (new \ReflectionMethod(ChavesDePerfil::class, 'foto'))->getParameters();

        self::assertCount(1, $parametros);
        self::assertSame(UserProfile::class, (string) $parametros[0]->getType());
    }

    private function perfil(?string $fotoUrl): UserProfile
    {
        return (new UserProfile(new User()))->setFotoUrl($fotoUrl);
    }
}
