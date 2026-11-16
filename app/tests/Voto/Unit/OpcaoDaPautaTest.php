<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Dados da candidatura na opção da pauta: texto vazio vira nulo e cada campo tem o seu limite. */
#[CoversClass(OpcaoDaPauta::class)]
final class OpcaoDaPautaTest extends TestCase
{
    private static function opcao(): OpcaoDaPauta
    {
        $pauta = new PautaDaAssembleia(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Fracao, 'Gestora'), 1, 'Síndico(a)');

        return new OpcaoDaPauta($pauta, 1, 'Ana');
    }

    #[TestDox('Nasce sem dados de candidatura')]
    public function testNasceVazia(): void
    {
        $opcao = self::opcao();

        self::assertSame([null, null, null, null], [$opcao->getDescricao(), $opcao->getPropostas(), $opcao->getComposicao(), $opcao->getUnidadeCandidato()]);
    }

    #[TestDox('Guarda sem espaços nas pontas, troca CRLF por LF e esvazia o que vier só com espaços')]
    public function testGuardaELimpa(): void
    {
        $opcao = self::opcao();
        $opcao->detalhar('  Moradora  ', 'Mais jardim', "Presidente: Ana\r\nVice: Bia", '   ');

        self::assertSame('Moradora', $opcao->getDescricao());
        self::assertSame("Presidente: Ana\nVice: Bia", $opcao->getComposicao());
        self::assertNull($opcao->getUnidadeCandidato());
    }

    #[TestDox('Corta no limite de cada campo: 280, 2000, 1000 e 60 caracteres')]
    public function testLimites(): void
    {
        $opcao = self::opcao();
        $opcao->detalhar(str_repeat('á', 400), str_repeat('p', 3000), str_repeat('c', 1500), str_repeat('u', 100));

        self::assertSame([280, 2000, 1000, 60], [
            mb_strlen((string) $opcao->getDescricao()), mb_strlen((string) $opcao->getPropostas()),
            mb_strlen((string) $opcao->getComposicao()), mb_strlen((string) $opcao->getUnidadeCandidato()),
        ]);
    }

    #[TestDox('Foto: nasce sem, guarda o nome, e vazio ou nulo tira')]
    public function testFoto(): void
    {
        $opcao = self::opcao();
        self::assertNull($opcao->getFoto());

        $opcao->definirFoto('a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        self::assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg', $opcao->getFoto());

        $opcao->definirFoto('');
        self::assertNull($opcao->getFoto());
    }
}
