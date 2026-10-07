<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\FonteDeContagensDaPasta;
use App\Inteligencia\DTO\OQueAIaSabeOutput;
use App\Inteligencia\UseCase\ContarOQueAIaSabeDaPastaUseCase;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContarOQueAIaSabeDaPastaUseCase::class)]
#[CoversClass(OQueAIaSabeOutput::class)]
final class ContarOQueAIaSabeDaPastaUseCaseTest extends TestCase
{
    #[TestDox('repassa o tenant explícito à fonte e devolve os quatro itens na ordem e com os rótulos do desenho')]
    public function testItensNaOrdemDoDesenho(): void
    {
        $tenant = new Tenant();
        $pasta = new Pasta();
        $fonte = $this->createMock(FonteDeContagensDaPasta::class);
        $fonte->expects(self::once())->method('contar')->with($tenant, $pasta)
            ->willReturn(new OQueAIaSabeOutput(documentos: 5, tarefas: 4, prazos: 3, anotacoes: 2));

        $saida = (new ContarOQueAIaSabeDaPastaUseCase($fonte))->executar($pasta, $tenant);

        self::assertSame(
            [
                ['chave' => 'documentos', 'valor' => 5, 'rotulo' => 'documentos'],
                ['chave' => 'tarefas', 'valor' => 4, 'rotulo' => 'tarefas'],
                ['chave' => 'prazos', 'valor' => 3, 'rotulo' => 'prazos'],
                ['chave' => 'anotacoes', 'valor' => 2, 'rotulo' => 'anotações'],
            ],
            $saida->itens(),
        );
    }
}
