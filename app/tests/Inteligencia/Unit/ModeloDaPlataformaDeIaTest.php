<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Service\ModeloDaPlataformaDeIa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O modelo vem do banco (escolhido pelo Master); o do ambiente só vale sem a linha. */
#[CoversClass(ModeloDaPlataformaDeIa::class)]
final class ModeloDaPlataformaDeIaTest extends TestCase
{
    private function fonte(?ConfiguracaoDaPlataformaDeIa $gravada, string $doAmbiente = ' modelo-do-ambiente '): ModeloDaPlataformaDeIa
    {
        $repositorio = $this->createStub(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('gravada')->willReturn($gravada);

        return new ModeloDaPlataformaDeIa($repositorio, $doAmbiente);
    }

    #[TestDox('com a linha gravada, vale o modelo do Master (o do ambiente é ignorado)')]
    public function testModeloDoBanco(): void
    {
        $linha = new ConfiguracaoDaPlataformaDeIa();
        $linha->escolherModelo(ModeloDeIa::Sonnet55, new User(), new \DateTimeImmutable());

        self::assertSame('claude-sonnet-5-5', $this->fonte($linha)->modelo());
        self::assertSame('claude-haiku-5-5', $this->fonte(new ConfiguracaoDaPlataformaDeIa())->modelo(), 'a linha nasce com o Haiku 5.5');
    }

    #[TestDox('sem a linha (banco sem a migration), vale o IA_MODELO do ambiente, aparado')]
    public function testSemLinhaValeOAmbiente(): void
    {
        self::assertSame('modelo-do-ambiente', $this->fonte(null)->modelo());
    }

    #[TestDox('valor gravado fora da lista do painel (mexido à mão, ex. um modelo com retenção de 30 dias): volta vazio, o adaptador fica dormente')]
    public function testValorForaDaListaEhVazio(): void
    {
        $linha = new ConfiguracaoDaPlataformaDeIa();
        (new \ReflectionProperty($linha, 'modelo'))->setValue($linha, 'claude-fable-5');

        self::assertSame('', $this->fonte($linha)->modelo());
    }
}
