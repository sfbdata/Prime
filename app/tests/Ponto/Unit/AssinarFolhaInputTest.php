<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * O pedido de assinatura da folha (spec ponto-desenho-0811, E16, P22; desenho 16 L290): competência, posição e a
 * ressalva com texto de até 1.000 caracteres, com as mensagens da entidade.
 */
#[Group('ponto')]
#[CoversClass(AssinarFolhaInput::class)]
final class AssinarFolhaInputTest extends TestCase
{
    private ValidatorInterface $validador;

    protected function setUp(): void
    {
        $this->validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[TestDox('Concordo e ressalva com texto passam; o texto do concordo é ignorado e a ressalva é aparada')]
    public function testPedidosValidos(): void
    {
        $concordo = new AssinarFolhaInput(2026, 10, 'concordo', 'texto esquecido no campo');
        $ressalva = new AssinarFolhaInput(2026, 10, 'ressalva', "  O dia 12 foi de folga.\n");

        self::assertSame([], $this->mensagens($concordo));
        self::assertSame([], $this->mensagens($ressalva));
        self::assertSame([SituacaoDoAceiteDaFolha::Concordo, null], [$concordo->situacao(), $concordo->ressalvaParaGravar()]);
        self::assertSame([SituacaoDoAceiteDaFolha::Ressalva, 'O dia 12 foi de folga.'], [$ressalva->situacao(), $ressalva->ressalvaParaGravar()]);
    }

    /** @param array<string, string> $esperado */
    #[DataProvider('pedidosRecusados')]
    #[TestDox('Recusa: $_dataName')]
    public function testPedidosRecusados(AssinarFolhaInput $pedido, array $esperado): void
    {
        self::assertSame($esperado, $this->mensagens($pedido));
    }

    /** @return iterable<string, array{AssinarFolhaInput, array<string, string>}> */
    public static function pedidosRecusados(): iterable
    {
        yield 'ressalva vazia' => [new AssinarFolhaInput(2026, 10, 'ressalva', '   '), ['ressalva' => 'Descreva a ressalva.']];
        yield 'ressalva com mais de 1.000 caracteres' => [new AssinarFolhaInput(2026, 10, 'ressalva', str_repeat('á', 1001)), ['ressalva' => 'A ressalva pode ter até 1.000 caracteres.']];
        yield 'posição desconhecida' => [new AssinarFolhaInput(2026, 10, 'talvez'), ['situacao' => 'Escolha a sua posição.']];
        yield 'mês 13' => [new AssinarFolhaInput(2026, 13, 'concordo'), ['mes' => 'Competência inválida.']];
        yield 'ano com dois dígitos' => [new AssinarFolhaInput(26, 10, 'concordo'), ['ano' => 'Competência inválida.']];
    }

    #[TestDox('A ressalva com exatamente 1.000 caracteres (contados como letras, não bytes) passa')]
    public function testRessalvaNoLimite(): void
    {
        self::assertSame([], $this->mensagens(new AssinarFolhaInput(2026, 10, 'ressalva', str_repeat('á', 1000))));
    }

    #[TestDox('Ressalva com UTF-8 inválido: o byte inválido vira "?" antes de contar e de gravar (o texto selado é JSON)')]
    public function testRessalvaComUtf8Invalido(): void
    {
        $pedido = new AssinarFolhaInput(2026, 10, 'ressalva', "O dia 6 foi \xFF de folga.");

        self::assertSame([], $this->mensagens($pedido));
        self::assertSame('O dia 6 foi ? de folga.', $pedido->ressalvaParaGravar());
    }

    /** @return array<string, string> caminho => mensagem */
    private function mensagens(AssinarFolhaInput $pedido): array
    {
        $mensagens = [];
        foreach ($this->validador->validate($pedido) as $violacao) {
            $mensagens[$violacao->getPropertyPath()] = (string) $violacao->getMessage();
        }

        return $mensagens;
    }
}
