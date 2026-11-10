<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\DTO\DadosDoAceiteDaFolha;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\SeloDaFolhaHmac;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os dados que o HMAC do aceite da folha sela (E16, decisão 14): o tipo "aceite-folha", escritório, pessoa,
 * competência, número do aceite, código da folha vista, situação, ressalva, aceito em, IP e navegador. A forma canônica
 * é estável (ida e volta iguais), o leitor recusa o que não é o formato 1 do tipo "aceite-folha", e o texto da folha e o
 * do aceite (selados com a mesma chave `ponto-folha`) não passam no leitor um do outro.
 */
#[Group('ponto')]
#[CoversClass(DadosDoAceiteDaFolha::class)]
#[CoversClass(DadosDaFolhaEmitida::class)]
final class DadosDoAceiteDaFolhaTest extends TestCase
{
    private const CODIGO = 'BJF-ABCD-EFGH-JK23';

    #[TestDox('Da assinatura: escritório, pessoa e competência saem da folha vista; chaves em ordem, acentos e barras sem escape')]
    public function testCanonicoDaAssinatura(): void
    {
        $dados = DadosDoAceiteDaFolha::daAssinatura($this->folhaVista(), 2, SituacaoDoAceiteDaFolha::Ressalva, 'Dia 3/10: saída às 18h.', $this->aceitoEm(), '189.6.10.2', 'Mozilla/5.0');

        self::assertSame(
            '{"formato":1,"tipo":"aceite-folha","escritorio_id":7,"pessoa_id":11,"ano":2026,"mes":10,"numero":2,'
            . '"codigo_folha_vista":"BJF-ABCD-EFGH-JK23","situacao":"ressalva","ressalva":"Dia 3/10: saída às 18h.",'
            . '"aceito_em":"2026-11-02T18:30:00-03:00","ip":"189.6.10.2","navegador":"Mozilla/5.0"}',
            $dados->canonico(),
        );
    }

    #[TestDox('Ida e volta iguais, no concordo (ressalva, IP e navegador nulos) e na ressalva')]
    public function testIdaEVolta(): void
    {
        $concordo = new DadosDoAceiteDaFolha(7, 11, 2026, 10, 1, self::CODIGO, SituacaoDoAceiteDaFolha::Concordo, null, $this->aceitoEm(), null, null);
        $ressalva = new DadosDoAceiteDaFolha(7, 11, 2026, 10, 3, self::CODIGO, SituacaoDoAceiteDaFolha::Ressalva, 'Faltou a batida do dia 3.', $this->aceitoEm(), '2001:db8::1', 'Firefox/130.0');

        foreach ([$concordo, $ressalva] as $dados) {
            self::assertSame($dados->canonico(), DadosDoAceiteDaFolha::deCanonico($dados->canonico())->canonico());
        }
        $volta = DadosDoAceiteDaFolha::deCanonico($ressalva->canonico());
        self::assertSame([7, 11, 2026, 10, 3, self::CODIGO], [$volta->escritorioId, $volta->pessoaId, $volta->ano, $volta->mes, $volta->numero, $volta->codigoDaFolhaVista]);
        self::assertSame(SituacaoDoAceiteDaFolha::Ressalva, $volta->situacao);
        self::assertSame(['Faltou a batida do dia 3.', '2001:db8::1', 'Firefox/130.0'], [$volta->ressalva, $volta->ip, $volta->navegador]);
        self::assertSame('2026-11-02T18:30:00-03:00', $volta->aceitoEm->format(\DATE_ATOM));
        self::assertNull(DadosDoAceiteDaFolha::deCanonico($concordo->canonico())->ressalva);
    }

    #[TestDox('Folha vista de escritório ou pessoa ainda sem id: não monta o aceite')]
    public function testFolhaVistaSemIdRecusada(): void
    {
        foreach (['escritório sem id' => $this->folhaVista(escritorioId: null), 'pessoa sem id' => $this->folhaVista(pessoaId: null)] as $caso => $folha) {
            try {
                DadosDoAceiteDaFolha::daAssinatura($folha, 1, SituacaoDoAceiteDaFolha::Concordo, null, $this->aceitoEm(), null, null);
                self::fail('deveria recusar: ' . $caso);
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function textosForaDoFormato(): iterable
    {
        $trocar = static fn (array $troca): array => [(string) json_encode(array_replace(self::canonicoComoMapa(), $troca), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)];
        $dados = self::canonicoComoMapa();

        yield 'não é JSON' => ['xyz'];
        yield 'lista' => ['[1,2,3]'];
        yield 'chave a mais' => [(string) json_encode($dados + ['cpf' => '123'])];
        yield 'faltando o navegador' => [(string) json_encode(array_diff_key($dados, ['navegador' => 0]))];
        yield 'fora de ordem' => [(string) json_encode(['tipo' => $dados['tipo']] + $dados)];
        yield 'outro formato' => $trocar(['formato' => 2]);
        yield 'formato em texto' => $trocar(['formato' => '1']);
        yield 'tipo da folha' => $trocar(['tipo' => 'folha']);
        yield 'tipo nulo' => $trocar(['tipo' => null]);
        yield 'escritorio_id em texto' => $trocar(['escritorio_id' => '7']);
        yield 'escritorio_id zero' => $trocar(['escritorio_id' => 0]);
        yield 'pessoa_id fracionado' => $trocar(['pessoa_id' => 11.5]);
        yield 'ano com 2 dígitos' => $trocar(['ano' => 26]);
        yield 'mês 13' => $trocar(['mes' => 13]);
        yield 'número zero' => $trocar(['numero' => 0]);
        yield 'código fora do formato BJF' => $trocar(['codigo_folha_vista' => 'BJP-ABCD-EFGH-JK23']);
        yield 'código em minúsculas' => $trocar(['codigo_folha_vista' => strtolower(self::CODIGO)]);
        yield 'situação desconhecida' => $trocar(['situacao' => 'talvez']);
        yield 'situação em número' => $trocar(['situacao' => 1]);
        yield 'concordo com ressalva' => $trocar(['situacao' => 'concordo']);
        yield 'ressalva sem texto' => $trocar(['ressalva' => null]);
        yield 'ressalva só com espaços' => $trocar(['ressalva' => '   ']);
        yield 'ressalva com 1.001 caracteres' => $trocar(['ressalva' => str_repeat('a', 1001)]);
        yield 'data em outro formato' => $trocar(['aceito_em' => '02/11/2026 18:30']);
        yield 'data que não existe' => $trocar(['aceito_em' => '2026-02-30T18:30:00-03:00']);
        yield 'IP em número' => $trocar(['ip' => 189]);
        yield 'navegador em lista' => $trocar(['navegador' => ['Mozilla']]);
    }

    #[DataProvider('textosForaDoFormato')]
    #[TestDox('Recusado: $_dataName')]
    public function testRecusaForaDoFormato(string $texto): void
    {
        $this->expectException(\UnexpectedValueException::class);

        DadosDoAceiteDaFolha::deCanonico($texto);
    }

    #[TestDox('Folha e aceite selados com a mesma chave "ponto-folha": os dois conferem, mas o texto de um não passa no leitor do outro')]
    public function testTipoSeparaFolhaEAceite(): void
    {
        $selo = new SeloDaFolhaHmac(new ChavesDoHmacDoPonto('segredo-de-teste'));
        $folha = (new DadosDaFolhaEmitida(self::CODIGO, 2026, 10, 7, 11, 'Maria', 'Escritório', null, $this->aceitoEm(), 11, 'Maria', hash('sha256', 'resumo'), []))->canonico();
        $aceite = (new DadosDoAceiteDaFolha(7, 11, 2026, 10, 1, self::CODIGO, SituacaoDoAceiteDaFolha::Concordo, null, $this->aceitoEm(), null, null))->canonico();

        foreach ([$folha, $aceite] as $texto) {
            $selado = $selo->selar($texto);
            self::assertTrue($selo->conferir($texto, $selado['hmac'], $selado['versao']), 'a chave sozinha não separa os dois');
        }
        self::assertSame(self::CODIGO, DadosDaFolhaEmitida::deCanonico($folha)->codigo);
        self::assertSame(self::CODIGO, DadosDoAceiteDaFolha::deCanonico($aceite)->codigoDaFolhaVista);
        $this->assertRecusa(static fn () => DadosDaFolhaEmitida::deCanonico($aceite), 'o aceite no leitor da folha');
        $this->assertRecusa(static fn () => DadosDoAceiteDaFolha::deCanonico($folha), 'a folha no leitor do aceite');
    }

    private function assertRecusa(\Closure $ler, string $caso): void
    {
        try {
            $ler();
            self::fail('deveria recusar ' . $caso);
        } catch (\UnexpectedValueException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, mixed> */
    private static function canonicoComoMapa(): array
    {
        $dados = new DadosDoAceiteDaFolha(7, 11, 2026, 10, 2, self::CODIGO, SituacaoDoAceiteDaFolha::Ressalva, 'Faltou a batida do dia 3.', new \DateTimeImmutable('2026-11-02T18:30:00-03:00'), '189.6.10.2', 'Mozilla/5.0');

        return json_decode($dados->canonico(), true);
    }

    private function folhaVista(?int $escritorioId = 7, ?int $pessoaId = 11): FolhaEmitidaDoPonto
    {
        $escritorio = new Tenant();
        $escritorio->setName('Escritório Modelo');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, $escritorioId);
        $pessoa = new User();
        $pessoa->setEmail('maria@teste.com');
        $pessoa->setFullName('Maria da Silva Pereira');
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, $pessoaId);

        return new FolhaEmitidaDoPonto($escritorio, $pessoa, $pessoa, 2026, 10, self::CODIGO, str_repeat('f', 64), 1, new \DateTimeImmutable('2026-11-02 18:29:00'), hash('sha256', 'resumo'), '{}');
    }

    private function aceitoEm(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-02 18:30:00', new \DateTimeZone('America/Sao_Paulo'));
    }
}
