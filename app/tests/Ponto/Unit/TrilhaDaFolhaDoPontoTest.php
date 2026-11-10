<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os três eventos da folha na trilha do Ponto (E16, decisões 14 e 18; desenho 16 L727 e L809): "Folha emitida" com a
 * competência e o código, "Folha assinada" e "Folha assinada com ressalva" com a competência. O detalhe gravado nunca
 * leva o texto da ressalva.
 */
#[Group('ponto')]
#[CoversClass(DetalheDaTrilhaDoPonto::class)]
#[CoversClass(AcaoDaTrilhaDoPonto::class)]
final class TrilhaDaFolhaDoPontoTest extends TestCase
{
    #[TestDox('Os rótulos da coluna "Ação" são os do desenho; os códigos gravados são estáveis')]
    public function testRotulos(): void
    {
        self::assertSame('Folha emitida', AcaoDaTrilhaDoPonto::rotuloDe('folha_emitida'));
        self::assertSame('Folha assinada', AcaoDaTrilhaDoPonto::rotuloDe('folha_assinada'));
        self::assertSame('Folha assinada com ressalva', AcaoDaTrilhaDoPonto::rotuloDe('folha_assinada_com_ressalva'));
        self::assertSame(
            ['folha_emitida', 'folha_assinada', 'folha_assinada_com_ressalva'],
            [AcaoDaTrilhaDoPonto::FolhaEmitida->value, AcaoDaTrilhaDoPonto::FolhaAssinada->value, AcaoDaTrilhaDoPonto::FolhaAssinadaComRessalva->value],
        );
    }

    #[TestDox('Folha emitida: o detalhe guarda pessoa, competência e código; o texto é "11/2026 · BJF-..." (L727)')]
    public function testFolhaEmitida(): void
    {
        $detalhe = DetalheDaTrilhaDoPonto::daFolhaEmitida($this->folha());

        self::assertSame(
            ['colaborador_id' => 11, 'colaborador' => 'Maria da Silva Pereira', 'folha_emitida_id' => 31, 'ano' => 2026, 'mes' => 11, 'codigo' => 'BJF-ABCD-EFGH-JK23'],
            $detalhe,
        );
        self::assertSame('Maria da Silva Pereira: 11/2026 · BJF-ABCD-EFGH-JK23', $this->textoGravado('folha_emitida', $detalhe));
    }

    #[TestDox('Folha assinada e assinada com ressalva: o texto é a competência (L809); a ressalva não entra no detalhe')]
    public function testFolhaAssinada(): void
    {
        $folha = $this->folha();
        $concordo = DetalheDaTrilhaDoPonto::doAceiteDaFolha(new AceiteDaFolhaDoPonto($folha, 1, SituacaoDoAceiteDaFolha::Concordo, null, new \DateTimeImmutable(), null, null, str_repeat('e', 64), 1));
        $ressalva = DetalheDaTrilhaDoPonto::doAceiteDaFolha(new AceiteDaFolhaDoPonto($folha, 2, SituacaoDoAceiteDaFolha::Ressalva, 'Texto sigiloso da ressalva', new \DateTimeImmutable(), null, null, str_repeat('e', 64), 1));

        self::assertSame(
            ['colaborador_id' => 11, 'colaborador' => 'Maria da Silva Pereira', 'aceite_id' => null, 'ano' => 2026, 'mes' => 11, 'numero' => 2, 'codigo' => 'BJF-ABCD-EFGH-JK23'],
            $ressalva,
        );
        self::assertStringNotContainsString('sigiloso', (string) json_encode($ressalva), 'a trilha não guarda o texto da ressalva (decisão 14)');
        self::assertSame('Maria da Silva Pereira: 11/2026', $this->textoGravado('folha_assinada', $concordo));
        self::assertSame('Maria da Silva Pereira: 11/2026', $this->textoGravado('folha_assinada_com_ressalva', $ressalva));
    }

    #[TestDox('Detalhe sem competência ou sem código (evento estranho): o texto não quebra')]
    public function testDetalheIncompleto(): void
    {
        self::assertSame('Colaborador: competência não informada', DetalheDaTrilhaDoPonto::texto('folha_emitida', []));
        self::assertSame('Ana: 03/2026', DetalheDaTrilhaDoPonto::texto('folha_assinada', ['colaborador' => 'Ana', 'ano' => 2026, 'mes' => 3]));
    }

    /**
     * O texto como a aba Auditoria monta: do detalhe que voltou do banco (JSON).
     *
     * @param array<string, mixed> $detalhe
     */
    private function textoGravado(string $acao, array $detalhe): string
    {
        $doBanco = json_decode((string) json_encode($detalhe), true);
        self::assertIsArray($doBanco);

        return DetalheDaTrilhaDoPonto::texto($acao, $doBanco);
    }

    private function folha(): FolhaEmitidaDoPonto
    {
        $pessoa = new User();
        $pessoa->setEmail('maria@teste.com');
        $pessoa->setFullName('Maria da Silva Pereira');
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, 11);
        $folha = new FolhaEmitidaDoPonto(new Tenant(), $pessoa, $pessoa, 2026, 11, 'BJF-ABCD-EFGH-JK23', str_repeat('f', 64), 1, new \DateTimeImmutable(), hash('sha256', 'x'), '{}');
        (new \ReflectionProperty(FolhaEmitidaDoPonto::class, 'id'))->setValue($folha, 31);

        return $folha;
    }
}
