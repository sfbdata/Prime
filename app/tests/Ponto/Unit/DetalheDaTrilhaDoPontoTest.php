<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O "Abono em lote" tem dois detalhes (spec ponto-desenho-0811, E5 e E9): o do "Abonar selecionadas" da aba Equipe
 * (`daEquipe`, várias pessoas, desenho L818 "N justificativa(s) da equipe") e o do "aprovar todos" da ficha (`doLote`,
 * uma pessoa, com o texto dele). A chave `da_equipe` separa os dois.
 */
#[CoversClass(DetalheDaTrilhaDoPonto::class)]
#[Group('ponto')]
final class DetalheDaTrilhaDoPontoTest extends TestCase
{
    #[TestDox('daEquipe guarda de cada abonada o id, a pessoa e o dia, e a quantidade')]
    public function testDetalheDaEquipe(): void
    {
        $ana = self::pessoa(7, 'Ana Lima');
        $bruno = self::pessoa(8, 'Bruno Reis');

        $detalhe = DetalheDaTrilhaDoPonto::daEquipe([self::justificativa(10, $ana, '2026-11-03'), self::justificativa(11, $bruno, '2026-11-04')]);

        self::assertSame([
            'da_equipe' => [
                ['justificativa_id' => 10, 'colaborador_id' => 7, 'colaborador' => 'Ana Lima', 'data' => '2026-11-03'],
                ['justificativa_id' => 11, 'colaborador_id' => 8, 'colaborador' => 'Bruno Reis', 'data' => '2026-11-04'],
            ],
            'quantidade' => 2,
        ], $detalhe);
    }

    #[TestDox('O texto da equipe é o do desenho, no plural certo e sem o nome na frente')]
    public function testTextoDaEquipe(): void
    {
        $ana = self::pessoa(7, 'Ana Lima');
        $uma = DetalheDaTrilhaDoPonto::daEquipe([self::justificativa(10, $ana, '2026-11-03')]);
        $tres = DetalheDaTrilhaDoPonto::daEquipe([
            self::justificativa(10, $ana, '2026-11-03'),
            self::justificativa(11, $ana, '2026-11-04'),
            self::justificativa(12, self::pessoa(8, 'Bruno Reis'), '2026-11-04'),
        ]);

        self::assertSame('1 justificativa da equipe', DetalheDaTrilhaDoPonto::texto(AcaoDaTrilhaDoPonto::AbonoEmLote->value, $uma));
        self::assertSame('3 justificativas da equipe', DetalheDaTrilhaDoPonto::texto(AcaoDaTrilhaDoPonto::AbonoEmLote->value, $tres));
    }

    #[TestDox('O "aprovar todos" da ficha continua com o texto dele: a pessoa, o tipo, o período e os dias')]
    public function testTextoDoAprovarTodosNaoMuda(): void
    {
        $ana = self::pessoa(7, 'Ana Lima');
        $lote = DetalheDaTrilhaDoPonto::doLote([self::justificativa(10, $ana, '2026-11-03'), self::justificativa(11, $ana, '2026-11-04')]);

        self::assertArrayNotHasKey('da_equipe', $lote);
        self::assertSame('Ana Lima: Atestado Médico de 03/11/2026 a 04/11/2026 (2 dias)', DetalheDaTrilhaDoPonto::texto(AcaoDaTrilhaDoPonto::AbonoEmLote->value, $lote));
    }

    #[TestDox('Lote da equipe sem nenhuma abonada não vira evento')]
    public function testEquipeVazia(): void
    {
        $this->expectException(\LogicException::class);

        DetalheDaTrilhaDoPonto::daEquipe([]);
    }

    private static function pessoa(int $id, string $nome): User
    {
        $pessoa = (new User())->setEmail(strtolower(str_replace(' ', '.', $nome)) . '@test.com')->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, $id);

        return $pessoa;
    }

    private static function justificativa(int $id, User $dono, string $dia): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setUser($dono);
        $justificativa->setData(new \DateTime($dia));
        $justificativa->setStatus('pendente');
        $justificativa->setTipo('atestado_medico');
        (new \ReflectionProperty(JustificativaPonto::class, 'id'))->setValue($justificativa, $id);

        return $justificativa;
    }
}
