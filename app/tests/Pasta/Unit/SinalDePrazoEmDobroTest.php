<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\SinalDePrazoEmDobro;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Sinal conservador de possível prazo em dobro (Fazenda Pública, MP, Defensoria): só a expressão inteira casa. */
#[CoversClass(SinalDePrazoEmDobro::class)]
final class SinalDePrazoEmDobroTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function positivos(): iterable
    {
        foreach ([
            'União Federal', 'UNIÃO', 'Fazenda Pública do Estado de São Paulo', 'Fazenda Nacional', 'Fazenda Municipal de Recife',
            'Estado de Minas Gerais', 'Estado do Rio Grande do Sul', 'Estado da Bahia', 'Município de Brasília', 'Município do Recife',
            'Distrito Federal', 'INSS', 'Instituto Nacional do Seguro Social - INSS', 'Autarquia municipal de trânsito',
            'Fundação Pública de Saúde', 'Ministério Público do Estado de Goiás', 'Defensoria Pública da União',
            'Procuradoria-Geral do Estado', 'Procuradoria Geral da Fazenda Nacional', 'Procuradoria do Município',
            'Intime-se a Fazenda Pública para se manifestar no prazo de 15 dias.',
        ] as $texto) {
            yield $texto => [$texto];
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function negativos(): iterable
    {
        foreach ([
            'Construtora Estado Novo Ltda', 'Estado de saúde do autor', 'em estado de necessidade', 'Reconhecimento de união estável',
            'Município de residência do autor', 'Fazendas Reunidas Boa Vista S.A.', 'Procuradoria jurídica da empresa',
            'Maria da Silva', 'Intime-se a parte autora para se manifestar no prazo de 15 dias.', 'Ministério da Saúde Comércio Ltda',
            'Defensoria privada', 'Unionense Transportes',
        ] as $texto) {
            yield $texto => [$texto];
        }
    }

    #[DataProvider('positivos')]
    #[TestDox('"$texto" parece ente com prazo em dobro')]
    public function testPositivo(string $texto): void
    {
        self::assertTrue(SinalDePrazoEmDobro::em($texto));
    }

    #[DataProvider('negativos')]
    #[TestDox('"$texto" não casa')]
    public function testNegativo(string $texto): void
    {
        self::assertFalse(SinalDePrazoEmDobro::em($texto));
    }

    #[TestDox('emAlgum: basta um dos textos casar')]
    public function testEmAlgum(): void
    {
        self::assertTrue(SinalDePrazoEmDobro::emAlgum(['Maria da Silva', 'INSS']));
        self::assertFalse(SinalDePrazoEmDobro::emAlgum(['Maria da Silva', 'João Souza']));
        self::assertFalse(SinalDePrazoEmDobro::emAlgum([]));
    }
}
