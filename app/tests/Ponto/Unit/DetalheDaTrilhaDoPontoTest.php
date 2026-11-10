<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O texto da coluna "Detalhe" da aba Auditoria no formato do desenho 16 (dc L544, L601, L768): partes separadas por
 * " · ", a batida com os segundos e a marca " · sem internet", a justificativa como "dd/mm/aaaa · Tipo · subtítulo".
 *
 * O "Abono em lote" tem dois detalhes (spec ponto-desenho-0811, E5 e E9): o do "Abonar selecionadas" da aba Equipe
 * (`daEquipe`, várias pessoas, desenho L818 "N justificativa(s) da equipe") e o do "aprovar todos" da ficha (`doLote`,
 * uma pessoa, com o texto dele). A chave `da_equipe` separa os dois.
 */
#[CoversClass(DetalheDaTrilhaDoPonto::class)]
#[Group('ponto')]
final class DetalheDaTrilhaDoPontoTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function textos(): iterable
    {
        $batida = ['colaborador' => 'Ana', 'tipo' => 'entrada', 'data_hora' => '2026-03-10 08:01:12', 'home_office' => false, 'sede' => 'Centro'];

        yield 'batida com segundos, sem a sede' => ['batida_registrada', $batida, 'Ana: Entrada às 08:01:12 de 10/03/2026'];
        yield 'batida da fila sem internet' => ['batida_registrada', ['sem_internet' => true] + $batida, 'Ana: Entrada às 08:01:12 de 10/03/2026 · sem internet'];
        yield 'batida em home office e sem internet' => ['batida_registrada', ['home_office' => true, 'sem_internet' => true] + $batida, 'Ana: Entrada às 08:01:12 de 10/03/2026 · home office · sem internet'];
        yield 'evento antigo, sem a chave sem_internet' => ['batida_registrada', ['tipo' => 'saida', 'data_hora' => '2026-03-10 18:00:00'] + $batida, 'Ana: Saída às 18:00:00 de 10/03/2026'];
        yield 'batida do esquecimento' => ['batida_do_esquecimento', ['tipo' => 'saida', 'data_hora' => '2026-03-09 18:00:00', 'justificativa_id' => 4] + $batida, 'Ana: Saída às 18:00:00 de 09/03/2026 · pedida no esquecimento'];

        $esquecimento = ['colaborador' => 'Ana', 'justificativa_id' => 7, 'tipo' => 'esquecimento_registro', 'data' => '2026-11-07', 'batida_esquecida' => 'saida', 'hora_esquecida' => '18:00'];
        $atestado     = ['colaborador' => 'Ana', 'justificativa_id' => 8, 'tipo' => 'atestado_medico', 'data' => '2026-11-07'];

        yield 'justificativa abonada com subtítulo' => ['justificativa_abonada', $esquecimento, 'Ana: 07/11/2026 · Esquecimento de Registro · Saída às 18:00'];
        yield 'justificativa recusada sem subtítulo' => ['justificativa_recusada', $atestado, 'Ana: 07/11/2026 · Atestado Médico'];
        yield 'justificativa que voltou para análise' => ['justificativa_reaberta', ['situacao_anterior' => 'abonado'] + $atestado, 'Ana: 07/11/2026 · Atestado Médico · estava abonada'];

        $lote = ['colaborador' => 'Ana', 'justificativa_ids' => [1], 'tipo' => 'atestado_medico', 'de' => '2026-11-07', 'ate' => '2026-11-07', 'quantidade' => 1];

        yield 'envio de um dia' => ['justificativa_enviada', $lote, 'Ana: 07/11/2026 · Atestado Médico'];
        yield 'envio de vários dias' => ['justificativa_enviada', ['de' => '2026-11-03', 'quantidade' => 5, 'justificativa_ids' => [1, 2, 3, 4, 5]] + $lote, 'Ana: 03/11/2026 a 07/11/2026 (5 dias) · Atestado Médico'];
        yield 'envio do esquecimento com subtítulo' => ['justificativa_enviada', ['tipo' => 'esquecimento_registro', 'batida_esquecida' => 'saida', 'hora_esquecida' => '18:00'] + $lote, 'Ana: 07/11/2026 · Esquecimento de Registro · Saída às 18:00'];
        yield 'detalhe sem data' => ['justificativa_excluida', ['colaborador' => 'Ana', 'tipo' => 'atestado_medico'], 'Ana: Atestado Médico'];
    }

    /** @param array<string, mixed> $detalhe */
    #[DataProvider('textos')]
    #[TestDox('Texto no formato do desenho: $_dataName')]
    public function testTexto(string $acao, array $detalhe, string $esperado): void
    {
        self::assertSame($esperado, DetalheDaTrilhaDoPonto::texto($acao, $detalhe));
    }

    #[TestDox('O detalhe gravado da batida leva a marca da fila sem internet (o texto da trilha lê dela)')]
    public function testDaBatidaGravaSemInternet(): void
    {
        $batida = (new RegistroPonto())
            ->setUser((new User())->setEmail('ana@teste.com')->setFullName('Ana'))
            ->setTipo('entrada')
            ->setDataHora(new \DateTimeImmutable('2026-03-10 08:01:12'))
            ->setHomeOffice(false)
            ->setEnviadaSemInternet(true);

        $detalhe = DetalheDaTrilhaDoPonto::daBatida($batida);

        self::assertTrue($detalhe['sem_internet']);
        self::assertSame('2026-03-10 08:01:12', $detalhe['data_hora']);
        self::assertFalse(DetalheDaTrilhaDoPonto::daBatida($batida->setEnviadaSemInternet(false))['sem_internet']);
    }

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
        self::assertSame('Ana Lima: 03/11/2026 a 04/11/2026 (2 dias) · Atestado Médico', DetalheDaTrilhaDoPonto::texto(AcaoDaTrilhaDoPonto::AbonoEmLote->value, $lote));
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
