<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\ListaInformada;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\RelatorioDaImportacao;
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
#[CoversClass(RelatorioDaImportacao::class)]
final class ListaInformadaTest extends TestCase
{
    #[TestDox('CSV: separa por ; , ou tabulação; o e-mail é a coluna com @ e o nome a primeira outra, sem aspas; linha sem @ sai; e-mail com @ inválido segue para a contagem dos inválidos')]
    public function testCsvComARegraDoDc(): void
    {
        $csv = "nome;email\n\"Ana Lima\";ANA@Exemplo.com \r\nbruno@exemplo.com,Bruno Reis\n\tCarla\tcarla@exemplo.com\nsem-email;Fulano\nx@y\n";

        $lista = ListaInformada::doCsv($csv);

        self::assertSame([
            ['email' => 'ana@exemplo.com', 'nome' => 'Ana Lima'],
            ['email' => 'bruno@exemplo.com', 'nome' => 'Bruno Reis'],
            ['email' => 'carla@exemplo.com', 'nome' => 'Carla'],
            ['email' => 'x@y', 'nome' => ''],
        ], array_map(static fn (DestinatarioDaCampanha $d): array => $d->paraArray(), $lista));
        self::assertFalse($lista[3]->valido(), 'quem filtra e conta é o PublicosDaCampanha (dc 21.10 L665)');
    }

    #[TestDox('A2: o relatório conta lidas, válidas, repetidas (sem diferença de maiúsculas), inválidas e vazias, com o número da linha no arquivo e o motivo; o cabeçalho e a linha em branco não contam')]
    public function testRelatorioDaImportacao(): void
    {
        $csv = "email;nome\nana@exemplo.com;Ana\n\nANA@Exemplo.com;Ana de novo\nsem-email;Fulano\n;;\nx@y\nbruno@exemplo.com\n";

        $relatorio = ListaInformada::relatorioDoCsv($csv);

        self::assertSame([
            'lidas' => 6, 'validas' => 2, 'repetidas' => 1, 'invalidas' => 2, 'vazias' => 1,
            'recusadas' => [
                ['linha' => 4, 'conteudo' => 'ANA@Exemplo.com;Ana de novo', 'motivo' => 'repetido'],
                ['linha' => 5, 'conteudo' => 'sem-email;Fulano', 'motivo' => 'invalido'],
                ['linha' => 6, 'conteudo' => ';;', 'motivo' => 'vazia'],
                ['linha' => 7, 'conteudo' => 'x@y', 'motivo' => 'invalido'],
            ],
        ], $relatorio->paraArray());
    }

    #[TestDox('A2: sem cabeçalho, a primeira linha com e-mail conta; os limites de tamanho valem também para o relatório')]
    public function testRelatorioSemCabecalhoELimites(): void
    {
        self::assertSame(['lidas' => 1, 'validas' => 1, 'repetidas' => 0, 'invalidas' => 0, 'vazias' => 0, 'recusadas' => []], ListaInformada::relatorioDoCsv("ana@exemplo.com\n")->paraArray());
        self::assertSame(0, ListaInformada::relatorioDoCsv('')->lidas());
        $longa = ListaInformada::relatorioDoCsv("ana@exemplo.com\r\n" . str_repeat('x', 300) . "\r\n");
        self::assertSame([2, 200], [$longa->recusadas[0]['linha'], mb_strlen($longa->recusadas[0]['conteudo'])], 'linha com \\r\\n e texto cortado em 200');
        $this->expectException(CampanhaRecusada::class);
        ListaInformada::relatorioDoCsv(str_repeat("a@exemplo.com\n", 5001));
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

    #[TestDox('Individual: separa por espaço, vírgula e ponto e vírgula, em minúsculas; o inválido segue para a contagem; mais de 50 válidos é recusado')]
    public function testIndividual(): void
    {
        $lista = ListaInformada::doIndividual(" Ana@Exemplo.com, bruno@exemplo.com;nao-e-email\ncarla@exemplo.com ");

        self::assertSame(['ana@exemplo.com', 'bruno@exemplo.com', 'nao-e-email', 'carla@exemplo.com'], array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $lista));
        self::assertCount(51, ListaInformada::doIndividual(implode(',', [...array_map(static fn (int $i): string => 'p' . $i . '@exemplo.com', range(1, 49)), 'errado', 'p50@exemplo.com'])), 'o inválido segue na lista e não conta no limite de 50');
        self::assertCount(50, ListaInformada::doIndividual(implode(',', array_map(static fn (int $i): string => 'p' . $i . '@exemplo.com', range(1, 50)))));

        $this->expectExceptionMessage('Digite até 50 e-mails no envio individual.');
        ListaInformada::doIndividual(implode(',', array_map(static fn (int $i): string => 'p' . $i . '@exemplo.com', range(1, 51))));
    }
}
