<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As invariantes do aceite da folha e da folha emitida (E16, decisões 1, 14 e 19): ressalva obrigatória e com até
 * 1.000 caracteres quando a pessoa assina com ressalva, nula quando concorda; o aceite herda escritório, pessoa e
 * competência da folha vista; número a partir de 1; competência e impressão válidas na folha.
 */
#[Group('ponto')]
#[CoversClass(AceiteDaFolhaDoPonto::class)]
#[CoversClass(FolhaEmitidaDoPonto::class)]
final class AceiteDaFolhaDoPontoTest extends TestCase
{
    #[TestDox('Concordo sem ressalva: o aceite herda escritório, pessoa e competência da folha vista')]
    public function testConcordoHerdaDaFolhaVista(): void
    {
        $folha = $this->folha();
        $aceite = $this->aceite($folha, SituacaoDoAceiteDaFolha::Concordo, null);

        self::assertSame($folha->getTenant(), $aceite->getTenant());
        self::assertSame($folha->getUser(), $aceite->getUser());
        self::assertSame([2026, 10], [$aceite->getAno(), $aceite->getMes()]);
        self::assertSame($folha, $aceite->getFolhaVista());
        self::assertSame(SituacaoDoAceiteDaFolha::Concordo, $aceite->getSituacao());
        self::assertNull($aceite->getRessalva());
        self::assertSame(1, $aceite->getNumero());
        self::assertSame(['189.6.10.2', 'Mozilla/5.0'], [$aceite->getIp(), $aceite->getNavegador()]);
    }

    #[TestDox('Ressalva com texto (até 1.000 caracteres, contados como letras e não bytes): aceita, guardada como veio')]
    public function testRessalvaComTexto(): void
    {
        $aceite = $this->aceite($this->folha(), SituacaoDoAceiteDaFolha::Ressalva, ' Faltou a batida do dia 3. ');
        self::assertSame(' Faltou a batida do dia 3. ', $aceite->getRessalva(), 'sem aparar: o selo feito antes confere com o gravado');

        $limite = str_repeat('ç', AceiteDaFolhaDoPonto::LIMITE_DA_RESSALVA);
        self::assertSame($limite, $this->aceite($this->folha(), SituacaoDoAceiteDaFolha::Ressalva, $limite)->getRessalva());
    }

    /** @return iterable<string, array{SituacaoDoAceiteDaFolha, ?string, string}> */
    public static function ressalvasRecusadas(): iterable
    {
        yield 'ressalva sem texto (nulo)' => [SituacaoDoAceiteDaFolha::Ressalva, null, 'Descreva a ressalva.'];
        yield 'ressalva vazia' => [SituacaoDoAceiteDaFolha::Ressalva, '', 'Descreva a ressalva.'];
        yield 'ressalva só com espaços' => [SituacaoDoAceiteDaFolha::Ressalva, " \n\t ", 'Descreva a ressalva.'];
        yield 'ressalva com 1.001 caracteres' => [SituacaoDoAceiteDaFolha::Ressalva, str_repeat('a', 1001), 'A ressalva pode ter até 1.000 caracteres.'];
        yield 'concordo com texto' => [SituacaoDoAceiteDaFolha::Concordo, 'texto', 'Quem concorda com a folha não registra ressalva.'];
        yield 'concordo com texto vazio' => [SituacaoDoAceiteDaFolha::Concordo, '', 'Quem concorda com a folha não registra ressalva.'];
    }

    #[DataProvider('ressalvasRecusadas')]
    #[TestDox('Recusado: $_dataName')]
    public function testRessalvaIncoerenteRecusada(SituacaoDoAceiteDaFolha $situacao, ?string $ressalva, string $mensagem): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($mensagem);

        $this->aceite($this->folha(), $situacao, $ressalva);
    }

    #[TestDox('Número do aceite começa em 1; IP acima de 45 e navegador acima de 255 caracteres são recusados (quem grava corta antes de selar)')]
    public function testNumeroIpENavegador(): void
    {
        $recusas = [
            'número zero'      => fn () => $this->aceite($this->folha(), SituacaoDoAceiteDaFolha::Concordo, null, numero: 0),
            'IP longo'         => fn () => $this->aceite($this->folha(), SituacaoDoAceiteDaFolha::Concordo, null, ip: str_repeat('1', 46)),
            'navegador longo'  => fn () => $this->aceite($this->folha(), SituacaoDoAceiteDaFolha::Concordo, null, navegador: str_repeat('é', 256)),
        ];
        foreach ($recusas as $caso => $criar) {
            try {
                $criar();
                self::fail('deveria recusar: ' . $caso);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $semIp = $this->aceite($this->folha(), SituacaoDoAceiteDaFolha::Concordo, null, ip: null, navegador: str_repeat('é', 255));
        self::assertNull($semIp->getIp());
        self::assertSame(255, mb_strlen((string) $semIp->getNavegador()));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function folhasRecusadas(): iterable
    {
        yield 'mês 0' => [2026, 0, str_repeat('a', 64)];
        yield 'mês 13' => [2026, 13, str_repeat('a', 64)];
        yield 'ano com 2 dígitos' => [26, 10, str_repeat('a', 64)];
        yield 'impressão curta' => [2026, 10, str_repeat('a', 63)];
        yield 'impressão em maiúsculas' => [2026, 10, str_repeat('A', 64)];
    }

    #[DataProvider('folhasRecusadas')]
    #[TestDox('Folha emitida recusada: $_dataName')]
    public function testFolhaComCompetenciaOuImpressaoInvalida(int $ano, int $mes, string $impressao): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->folha($ano, $mes, $impressao);
    }

    private function folha(int $ano = 2026, int $mes = 10, ?string $impressao = null): FolhaEmitidaDoPonto
    {
        $escritorio = new Tenant();
        $escritorio->setName('Escritório Modelo');
        $pessoa = new User();
        $pessoa->setEmail('maria@teste.com');
        $pessoa->setFullName('Maria da Silva Pereira');

        return new FolhaEmitidaDoPonto(
            $escritorio,
            $pessoa,
            $pessoa,
            $ano,
            $mes,
            'BJF-ABCD-EFGH-JK23',
            str_repeat('f', 64),
            1,
            new \DateTimeImmutable('2026-11-03 09:00:00'),
            $impressao ?? hash('sha256', 'resumo'),
            '{"formato":1}',
        );
    }

    private function aceite(FolhaEmitidaDoPonto $folha, SituacaoDoAceiteDaFolha $situacao, ?string $ressalva, int $numero = 1, ?string $ip = '189.6.10.2', ?string $navegador = 'Mozilla/5.0'): AceiteDaFolhaDoPonto
    {
        return new AceiteDaFolhaDoPonto($folha, $numero, $situacao, $ressalva, new \DateTimeImmutable('2026-11-03 09:05:00'), $ip, $navegador, str_repeat('e', 64), 1);
    }
}
