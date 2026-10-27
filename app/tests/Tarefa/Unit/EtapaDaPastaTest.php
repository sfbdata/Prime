<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\Enum\EtapaDaPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EtapaDaPasta::class)]
#[Group('tarefa')]
final class EtapaDaPastaTest extends TestCase
{
    /** @return iterable<string, array{string, EtapaDaPasta}> */
    public static function titulos(): iterable
    {
        yield 'contrato'          => ['Assinar contrato com o cliente', EtapaDaPasta::Comercial];
        yield 'honorários'        => ['Cobrar HONORÁRIOS iniciais', EtapaDaPasta::Comercial];
        yield 'cadastro'          => ['Cadastro do cliente no sistema', EtapaDaPasta::Abertura];
        yield 'abertura'          => ['Abertura da pasta', EtapaDaPasta::Abertura];
        yield 'relatório'         => ['Relatório inicial', EtapaDaPasta::Abertura];
        yield 'análise de pasta'  => ['Análise de pasta nova', EtapaDaPasta::Controladoria];
        yield 'conferir'          => ['Conferir dados', EtapaDaPasta::Controladoria];
        yield 'pendência'         => ['Resolver pendências', EtapaDaPasta::Controladoria];
        yield 'documentos'        => ['Juntar documentos', EtapaDaPasta::Documentacao];
        yield 'procuração'        => ['Colher procuração', EtapaDaPasta::Documentacao];
        yield 'protocolar'        => ['Protocolar petição', EtapaDaPasta::Protocolo];
        yield 'ajuizar'           => ['Ajuizar a ação', EtapaDaPasta::Protocolo];
        yield 'distribuir'        => ['Distribuir o processo', EtapaDaPasta::Protocolo];
        yield 'audiência'         => ['Audiência de conciliação', EtapaDaPasta::Acompanhamento];
        yield 'prazo'             => ['Cumprir prazo', EtapaDaPasta::Acompanhamento];
        yield 'publicação'        => ['Ler publicação', EtapaDaPasta::Acompanhamento];
        yield 'manifestação'      => ['Manifestação sobre laudo', EtapaDaPasta::Acompanhamento];
        yield 'arquivar'          => ['Arquivar a pasta', EtapaDaPasta::Encerramento];
        yield 'encerrar'          => ['Encerrar o caso', EtapaDaPasta::Encerramento];
        yield 'sem regra: padrão' => ['Elaborar petição inicial', EtapaDaPasta::Juridico];
        yield 'vazio: padrão'     => ['', EtapaDaPasta::Juridico];
    }

    #[DataProvider('titulos')]
    #[TestDox('deduzirDoTitulo: $titulo')]
    public function testDeduzDoTitulo(string $titulo, EtapaDaPasta $esperada): void
    {
        self::assertSame($esperada, EtapaDaPasta::deduzirDoTitulo($titulo));
    }

    #[TestDox('A primeira regra que casa vence (contrato antes de protocolo)')]
    public function testPrimeiraRegraVence(): void
    {
        self::assertSame(EtapaDaPasta::Comercial, EtapaDaPasta::deduzirDoTitulo('Protocolar contrato'));
    }

    #[TestDox('posicao vai de 1 a 8 na ordem da esteira')]
    public function testPosicao(): void
    {
        $posicoes = array_map(static fn (EtapaDaPasta $e): int => $e->posicao(), EtapaDaPasta::cases());

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], $posicoes);
        self::assertSame(1, EtapaDaPasta::Comercial->posicao());
        self::assertSame(5, EtapaDaPasta::Juridico->posicao());
        self::assertSame(8, EtapaDaPasta::Encerramento->posicao());
    }
}
