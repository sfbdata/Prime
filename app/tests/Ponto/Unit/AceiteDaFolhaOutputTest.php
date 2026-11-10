<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AceiteDaFolhaOutput;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Exception\AssinaturaDaFolhaRecusadaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O estado do aceite de uma competência (spec ponto-desenho-0811, E16, decisões 1 e 3; desenho 16 L269 a L318): o selo
 * da lista, o carimbo e o "pode assinar".
 */
#[Group('ponto')]
#[CoversClass(AceiteDaFolhaOutput::class)]
#[CoversClass(AssinaturaDaFolhaRecusadaException::class)]
final class AceiteDaFolhaOutputTest extends TestCase
{
    #[TestDox('Sem aceite e sem impedimento: pode assinar, sem selo e sem carimbo')]
    public function testSemAceite(): void
    {
        $estado = AceiteDaFolhaOutput::daCompetencia(2026, 10, null, true, null);

        self::assertTrue($estado->podeAssinar);
        self::assertFalse($estado->temAceite());
        self::assertFalse($estado->alterada, 'sem aceite não há o que ter sido alterado');
        self::assertFalse($estado->carimbaAFolha());
        self::assertNull($estado->textoDoSelo());
    }

    #[TestDox('Aceite que vale: "Assinada em" ou "Ressalva em" com a data, carimba a folha e não pode assinar de novo')]
    public function testAceiteQueVale(): void
    {
        $concordo = AceiteDaFolhaOutput::daCompetencia(2026, 10, $this->aceite(SituacaoDoAceiteDaFolha::Concordo, null), false, AssinaturaDaFolhaRecusadaException::JA_ASSINADA);
        $ressalva = AceiteDaFolhaOutput::daCompetencia(2026, 10, $this->aceite(SituacaoDoAceiteDaFolha::Ressalva, 'O dia 12 foi de folga.'), false, AssinaturaDaFolhaRecusadaException::JA_ASSINADA);

        self::assertSame(['Assinada em 03/11/2026', true, false], [$concordo->textoDoSelo(), $concordo->carimbaAFolha(), $concordo->podeAssinar]);
        self::assertSame(['Ressalva em 03/11/2026', 'O dia 12 foi de folga.'], [$ressalva->textoDoSelo(), $ressalva->ressalva]);
        self::assertSame([2, 'BJF-ABCD-EFGH-JK23'], [$concordo->numero, $concordo->codigoDaFolhaVista]);
    }

    #[TestDox('Decisão 1: a folha alterada depois do aceite mostra "Alterada depois do aceite", não carimba e libera assinar de novo')]
    public function testAlteradaDepoisDoAceite(): void
    {
        $estado = AceiteDaFolhaOutput::daCompetencia(2026, 10, $this->aceite(SituacaoDoAceiteDaFolha::Concordo, null), true, null);

        self::assertSame('Alterada depois do aceite', $estado->textoDoSelo());
        self::assertTrue($estado->alterada);
        self::assertFalse($estado->carimbaAFolha());
        self::assertTrue($estado->podeAssinar);
    }

    #[TestDox('O aceite recém-gravado já é o que vale: carimba e não pode assinar de novo')]
    public function testAceiteGravado(): void
    {
        $estado = AceiteDaFolhaOutput::doAceiteGravado($this->aceite(SituacaoDoAceiteDaFolha::Concordo, null));

        self::assertSame([2026, 10, false, true], [$estado->ano, $estado->mes, $estado->podeAssinar, $estado->carimbaAFolha()]);
        self::assertSame(AssinaturaDaFolhaRecusadaException::JA_ASSINADA, $estado->impedimento);
    }

    #[TestDox('Cada impedimento tem a sua mensagem; a ressalva inválida repete a da entidade')]
    public function testMensagensDasRecusas(): void
    {
        self::assertSame('O aceite da folha não está disponível neste escritório.', AssinaturaDaFolhaRecusadaException::por(AssinaturaDaFolhaRecusadaException::FUNCAO_DESLIGADA)->getMessage());
        self::assertSame('Só dá para assinar a folha de um mês já fechado.', AssinaturaDaFolhaRecusadaException::por(AssinaturaDaFolhaRecusadaException::MES_ABERTO)->getMessage());
        self::assertSame('Este mês não está na sua folha de ponto.', AssinaturaDaFolhaRecusadaException::por(AssinaturaDaFolhaRecusadaException::FORA_DA_FOLHA)->getMessage());
        self::assertSame('Esta folha já foi assinada.', AssinaturaDaFolhaRecusadaException::jaAssinada()->getMessage());

        $ressalva = AssinaturaDaFolhaRecusadaException::ressalvaInvalida(new \DomainException('Descreva a ressalva.'));
        self::assertSame(['Descreva a ressalva.', AssinaturaDaFolhaRecusadaException::RESSALVA_INVALIDA], [$ressalva->getMessage(), $ressalva->motivo]);
    }

    private function aceite(SituacaoDoAceiteDaFolha $situacao, ?string $ressalva): AceiteDaFolhaDoPonto
    {
        $escritorio = new Tenant();
        $escritorio->setName('Escritório Modelo');
        $pessoa = new User();
        $pessoa->setEmail('maria@teste.com');
        $folha = new FolhaEmitidaDoPonto($escritorio, $pessoa, $pessoa, 2026, 10, 'BJF-ABCD-EFGH-JK23', str_repeat('f', 64), 1, new \DateTimeImmutable('2026-11-03 09:05:00'), hash('sha256', 'resumo'), '{"formato":1}');

        return new AceiteDaFolhaDoPonto($folha, 2, $situacao, $ressalva, new \DateTimeImmutable('2026-11-03 09:05:00'), null, null, str_repeat('e', 64), 1, '{"formato":1}');
    }
}
