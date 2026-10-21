<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\ListaInformada;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A leitura do CSV no servidor com a regra do dc (L.545) e a do Individual (L.534), com os limites da spec §8.5 e o nome
 * limpo de `;:()<>"'` (§8.8, item 12).
 */
#[CoversClass(ListaInformada::class)]
#[CoversClass(DestinatarioDaCampanha::class)]
final class ListaInformadaTest extends TestCase
{
    #[TestDox('CSV: separa por ; , ou tabulação; o e-mail é a coluna com @ e o nome a primeira outra, sem aspas; linhas sem e-mail válido saem')]
    public function testCsvComARegraDoDc(): void
    {
        $csv = "nome;email\n\"Ana Lima\";ANA@Exemplo.com \r\nbruno@exemplo.com,Bruno Reis\n\tCarla\tcarla@exemplo.com\nsem-email;Fulano\nx@y\n";

        $lista = ListaInformada::doCsv($csv);

        self::assertSame([
            ['email' => 'ana@exemplo.com', 'nome' => 'Ana Lima'],
            ['email' => 'bruno@exemplo.com', 'nome' => 'Bruno Reis'],
            ['email' => 'carla@exemplo.com', 'nome' => 'Carla'],
        ], array_map(static fn (DestinatarioDaCampanha $d): array => $d->paraArray(), $lista));
    }

    #[TestDox('CSV: o nome sai sem ; : ( ) < > " e apóstrofo')]
    public function testNomeSanitizado(): void
    {
        $lista = ListaInformada::doCsv("dia@exemplo.com\tO'Neil <b>(Dra.)</b>: x\n");

        self::assertSame('ONeil bDra./b x', $lista[0]->nome);
    }

    #[TestDox('CSV: mais de 5.000 linhas ou mais de 1 MB é recusado com a mensagem')]
    public function testLimitesDoCsv(): void
    {
        try {
            ListaInformada::doCsv(str_repeat("a@exemplo.com\n", 5001));
            self::fail('5.001 linhas deveriam ser recusadas');
        } catch (CampanhaRecusada $e) {
            self::assertSame('A lista passa de 5.000 linhas.', $e->getMessage());
        }
        self::assertCount(5000, ListaInformada::doCsv(str_repeat("a@exemplo.com\n", 5000)));

        $this->expectExceptionMessage('O arquivo passa de 1 MB.');
        ListaInformada::doCsv(str_repeat('x', ListaInformada::BYTES_DO_CSV + 1));
    }

    #[TestDox('Individual: separa por espaço, vírgula e ponto e vírgula, em minúsculas; mais de 50 é recusado')]
    public function testIndividual(): void
    {
        $lista = ListaInformada::doIndividual(" Ana@Exemplo.com, bruno@exemplo.com;nao-e-email\ncarla@exemplo.com ");

        self::assertSame(['ana@exemplo.com', 'bruno@exemplo.com', 'carla@exemplo.com'], array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $lista));
        self::assertCount(50, ListaInformada::doIndividual(implode(',', array_map(static fn (int $i): string => 'p' . $i . '@exemplo.com', range(1, 50)))));

        $this->expectExceptionMessage('Digite até 50 e-mails no envio individual.');
        ListaInformada::doIndividual(implode(',', array_map(static fn (int $i): string => 'p' . $i . '@exemplo.com', range(1, 51))));
    }
}
