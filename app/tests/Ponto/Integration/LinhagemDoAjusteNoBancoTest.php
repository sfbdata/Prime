<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\TrilhaDoPonto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A leitura da linhagem dos ajustes no `RegistroPontoRepository` (E10, plano-e10-ponto §6): o alvo do ajuste com a
 * cabeça, as desconsideradas da competência, a inclusão do esquecimento e as duas consultas pela justificativa. Tudo
 * escopado pelo escritório (e pela pessoa, no alvo): o de outro escritório não existe para quem pede.
 *
 * As linhagens nascem pela `GravacaoDoAjusteDoPonto`; só a linha forjada entre escritórios entra por SQL cru, para
 * provar que a consulta não confia na gravação.
 */
#[CoversClass(RegistroPontoRepository::class)]
#[CoversClass(AlvoDoAjuste::class)]
final class LinhagemDoAjusteNoBancoTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private RegistroPontoRepository $registros;
    private TrilhaDoPonto $trilha;
    private GravacaoDoAjusteDoPonto $gravacao;
    private Tenant $a;
    private Tenant $b;
    private User $pessoa;
    private User $outra;
    private User $gestora;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone(date_default_timezone_get())));
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->registros = $c->get(RegistroPontoRepository::class);
        $this->trilha = $c->get(TrilhaDoPonto::class);
        $this->gravacao = new GravacaoDoAjusteDoPonto($this->em, $this->trilha, $c->get(HorarioFuturoDaBatida::class), $c->get(ClockInterface::class));

        $this->a = $this->escritorio('A');
        $this->b = $this->escritorio('B');
        $this->pessoa = $this->usuario('Ana Colaboradora');
        $this->outra = $this->usuario('Bia Colaboradora');
        $this->gestora = $this->usuario('Gestora');
        $this->em->flush();
    }

    #[TestDox('Registro nunca ajustado: alvo b{id} com a raiz, cabeça nula e a forma do próprio registro')]
    public function testAlvoDoRegistroSemAjuste(): void
    {
        $batida = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');

        $alvo = $this->registros->alvoDoAjuste($this->pessoa, $this->a, 'b' . $batida->getId());

        self::assertNotNull($alvo);
        self::assertSame(['b' . $batida->getId(), $batida, null, null], [$alvo->alvo, $alvo->raizBatida, $alvo->raizInclusao, $alvo->cabeca]);
        self::assertSame(['entrada', '2026-10-21 08:00:00'], [$alvo->tipoAtual, $alvo->dataHoraAtual?->format('Y-m-d H:i:s')]);
        self::assertSame([$this->a, $this->pessoa], [$alvo->escritorio, $alvo->pessoa]);
        self::assertFalse($alvo->desconsiderada());
    }

    #[TestDox('Inclusão ajustada: alvo a{id} com a inclusão como raiz e a última linha como cabeça')]
    public function testAlvoDaInclusaoAjustada(): void
    {
        $inclusao = $this->incluir($this->a, $this->pessoa, '2026-10-21 18:00:00');
        $alteracao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->alterar($this->alvo($this->a, 'a' . $inclusao->getId()), 'saida', new \DateTimeImmutable('2026-10-21 18:20:00'), 'Hora certa.', $this->gestora));

        $alvo = $this->alvo($this->a, 'a' . $inclusao->getId());

        self::assertSame([null, $inclusao, $alteracao], [$alvo->raizBatida, $alvo->raizInclusao, $alvo->cabeca]);
        self::assertSame('2026-10-21 18:20:00', $alvo->dataHoraAtual?->format('Y-m-d H:i:s'));
        self::assertSame([$inclusao, $inclusao], [$alteracao->getInclusao(), $alteracao->getAnterior()]);
        self::assertSame('a' . $inclusao->getId(), $alteracao->alvo());
    }

    #[TestDox('Isolamento: a batida e a inclusão de outro escritório, ou de outra pessoa, não existem para quem pede')]
    public function testAlvoDeOutroEscritorioOuPessoaENulo(): void
    {
        $batidaEmB = $this->batida($this->b, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $inclusaoEmB = $this->incluir($this->b, $this->pessoa, '2026-10-21 18:00:00');
        $batidaDaOutra = $this->batida($this->a, $this->outra, 'entrada', '2026-10-21 08:00:00');
        $inclusaoDaOutra = $this->incluir($this->a, $this->outra, '2026-10-21 18:00:00');

        self::assertNull($this->registros->alvoDoAjuste($this->pessoa, $this->a, 'b' . $batidaEmB->getId()), 'batida de B pedida em A');
        self::assertNull($this->registros->alvoDoAjuste($this->pessoa, $this->a, 'a' . $inclusaoEmB->getId()), 'inclusão de B pedida em A');
        self::assertNull($this->registros->alvoDoAjuste($this->pessoa, $this->a, 'b' . $batidaDaOutra->getId()), 'batida de outra pessoa');
        self::assertNull($this->registros->alvoDoAjuste($this->pessoa, $this->a, 'a' . $inclusaoDaOutra->getId()), 'inclusão de outra pessoa, no mesmo escritório');
        self::assertNotNull($this->registros->alvoDoAjuste($this->outra, $this->a, 'a' . $inclusaoDaOutra->getId()), 'controle: para a outra pessoa, existe');
        self::assertNotNull($this->registros->alvoDoAjuste($this->pessoa, $this->b, 'b' . $batidaEmB->getId()), 'controle: em B, existe');
        self::assertNotNull($this->registros->alvoDoAjuste($this->pessoa, $this->b, 'a' . $inclusaoEmB->getId()), 'controle: em B, existe');
    }

    #[TestDox('O alvo a{id} só aceita inclusão: o id de uma alteração não é alvo')]
    public function testAlvoAComIdDeAlteracaoENulo(): void
    {
        $batida = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $alteracao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->alterar($this->alvo($this->a, 'b' . $batida->getId()), 'entrada', new \DateTimeImmutable('2026-10-21 08:10:00'), 'Hora.', $this->gestora));

        self::assertNull($this->registros->alvoDoAjuste($this->pessoa, $this->a, 'a' . $alteracao->getId()));
    }

    /** @return iterable<string, array{string}> */
    public static function alvosMalformados(): iterable
    {
        foreach (['', 'b', 'x1', 'B1', ' b1', 'b1 ', 'b0', 'b01', 'b-1', 'b1.5', 'b2147483648', 'b99999999999', 'ab1'] as $alvo) {
            yield sprintf('"%s"', $alvo) => [$alvo];
        }
    }

    #[DataProvider('alvosMalformados')]
    #[TestDox('Alvo malformado ou acima do INT do banco: nulo, sem erro de banco ($_dataName)')]
    public function testAlvoMalformadoENulo(string $alvo): void
    {
        self::assertNull($this->registros->alvoDoAjuste($this->pessoa, $this->a, $alvo));
    }

    #[TestDox('Desconsideradas da competência: só as que ainda são cabeça, da pessoa, do escritório e do mês, em ordem da batida')]
    public function testDesconsideradasDaCompetencia(): void
    {
        $tarde = $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 18:00:00'));
        $cedo = $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-02 08:00:00'));
        $restaurada = $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-10 08:00:00'));
        $this->emTransacao(fn (): PontoAjuste => $this->gravacao->restaurar($restaurada, 'Volta.', $this->gestora));
        $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-11-02 08:00:00'));   // outro mês
        $this->desconsiderar($this->a, $this->batida($this->a, $this->outra, 'entrada', '2026-10-15 08:00:00'));    // outra pessoa
        $this->desconsiderar($this->b, $this->batida($this->b, $this->pessoa, 'entrada', '2026-10-15 08:00:00'));   // outro escritório

        $lista = $this->registros->desconsideradasDaCompetencia($this->pessoa, $this->a, 2026, 10);

        self::assertSame([$cedo->getId(), $tarde->getId()], array_map(static fn (PontoAjuste $d): ?int => $d->getId(), $lista));
        self::assertSame([], $this->registros->desconsideradasDaCompetencia($this->pessoa, $this->a, 2026, 9));
    }

    #[TestDox('Competências com desconsideração: os meses das que ainda são cabeça, da pessoa e do escritório, do mais novo ao mais antigo')]
    public function testCompetenciasComDesconsideracao(): void
    {
        $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-09-02 08:00:00'));
        $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 18:00:00'));
        $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-02 08:00:00'));
        $restaurada = $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-08-10 08:00:00'));
        $this->emTransacao(fn (): PontoAjuste => $this->gravacao->restaurar($restaurada, 'Volta.', $this->gestora));
        $this->desconsiderar($this->a, $this->batida($this->a, $this->outra, 'entrada', '2026-07-15 08:00:00'));    // outra pessoa
        $this->desconsiderar($this->b, $this->batida($this->b, $this->pessoa, 'entrada', '2026-06-15 08:00:00'));   // outro escritório

        self::assertSame(['2026-10', '2026-09'], $this->registros->competenciasComDesconsideracao($this->pessoa, $this->a));
        self::assertSame([], $this->registros->competenciasComDesconsideracao($this->gestora, $this->a));
    }

    #[TestDox('Inclusão do esquecimento: a mais nova da justificativa, como alvo; sem inclusão, nula')]
    public function testInclusaoDoEsquecimento(): void
    {
        $justificativa = $this->justificativa($this->a, $this->pessoa);
        $semInclusao = $this->justificativa($this->a, $this->pessoa);
        $primeira = $this->incluir($this->a, $this->pessoa, '2026-10-21 18:00:00', $justificativa);
        $this->emTransacao(fn (): PontoAjuste => $this->gravacao->desconsiderar($this->alvo($this->a, 'a' . $primeira->getId()), 'Voltou.', $this->gestora, $justificativa, $primeira));
        $segunda = $this->incluir($this->a, $this->pessoa, '2026-10-21 18:00:00', $justificativa);

        $alvo = $this->registros->inclusaoDoEsquecimento($justificativa);

        self::assertNotNull($alvo);
        self::assertSame(['a' . $segunda->getId(), $segunda, $segunda], [$alvo->alvo, $alvo->raizInclusao, $alvo->cabeca]);
        self::assertNull($this->registros->inclusaoDoEsquecimento($semInclusao));
    }

    #[TestDox('existeAjusteDaJustificativa: verdadeiro com a inclusão ou a desconsideração ligadas; falso para outra justificativa e para a linha forjada de outro escritório')]
    public function testExisteAjusteDaJustificativa(): void
    {
        $incluida = $this->justificativa($this->a, $this->pessoa);
        $desconsiderada = $this->justificativa($this->a, $this->pessoa);
        $semAjuste = $this->justificativa($this->a, $this->pessoa);
        $deB = $this->justificativa($this->b, $this->pessoa);
        $this->incluir($this->a, $this->pessoa, '2026-10-21 18:00:00', $incluida);
        $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00'), $desconsiderada);
        $this->forjarInclusaoEmALigadaA($deB);

        self::assertTrue($this->registros->existeAjusteDaJustificativa((int) $incluida->getId()));
        self::assertTrue($this->registros->existeAjusteDaJustificativa((int) $desconsiderada->getId()));
        self::assertFalse($this->registros->existeAjusteDaJustificativa((int) $semAjuste->getId()));
        self::assertFalse($this->registros->existeAjusteDaJustificativa((int) $deB->getId()), 'ajuste de A apontando justificativa de B não conta');
    }

    #[TestDox('desconsideracaoDaJustificativa: achada enquanto é a cabeça; restaurada, nula; de outra justificativa ou forjada em outro escritório, nula')]
    public function testDesconsideracaoDaJustificativa(): void
    {
        $justificativa = $this->justificativa($this->a, $this->pessoa);
        $outra = $this->justificativa($this->a, $this->pessoa);
        $deB = $this->justificativa($this->b, $this->pessoa);
        $desconsideracao = $this->desconsiderar($this->a, $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00'), $justificativa);
        $this->forjarDesconsideracaoEmALigadaA($deB);

        self::assertSame($desconsideracao, $this->registros->desconsideracaoDaJustificativa((int) $justificativa->getId()));
        self::assertNull($this->registros->desconsideracaoDaJustificativa((int) $outra->getId()));
        self::assertNull($this->registros->desconsideracaoDaJustificativa((int) $deB->getId()), 'linha de A apontando justificativa de B');

        $this->emTransacao(fn (): PontoAjuste => $this->gravacao->restaurar($desconsideracao, 'A recusa voltou para análise.', $this->gestora));

        self::assertNull($this->registros->desconsideracaoDaJustificativa((int) $justificativa->getId()), 'já restaurada');
    }

    /**
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    private function emTransacao(\Closure $trabalho): mixed
    {
        return $this->trilha->emTransacao($trabalho);
    }

    private function alvo(Tenant $escritorio, string $alvo): AlvoDoAjuste
    {
        return $this->registros->alvoDoAjuste($this->pessoa, $escritorio, $alvo) ?? throw new \LogicException('Alvo não achado: ' . $alvo);
    }

    private function incluir(Tenant $escritorio, User $pessoa, string $quando, ?JustificativaPonto $justificativa = null): PontoAjuste
    {
        return $this->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir($escritorio, $pessoa, 'saida', new \DateTimeImmutable($quando), 'Inclusão.', $this->gestora, $justificativa));
    }

    private function desconsiderar(Tenant $escritorio, RegistroPonto $batida, ?JustificativaPonto $justificativa = null): PontoAjuste
    {
        $pessoa = $batida->getUser() ?? throw new \LogicException('Batida sem pessoa.');
        $alvo = $this->registros->alvoDoAjuste($pessoa, $escritorio, 'b' . $batida->getId()) ?? throw new \LogicException('Alvo não achado.');

        return $this->emTransacao(fn (): PontoAjuste => $this->gravacao->desconsiderar($alvo, 'Fora da conta.', $this->gestora, $justificativa, null));
    }

    /** Uma inclusão em A presa à justificativa de B: a gravação recusaria; só por SQL cru. */
    private function forjarInclusaoEmALigadaA(JustificativaPonto $deOutroEscritorio): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO ponto_ajuste (tenant_id, user_id, tipo, tipo_marcacao_nova, data_hora_nova, motivo, autor_nome, criado_em, justificativa_id)
             VALUES (?, ?, 'inclusao', 'saida', '2026-10-21 18:00:00', 'Forjada.', 'Ninguém', '2026-10-22 09:00:00', ?)",
            [$this->a->getId(), $this->pessoa->getId(), $deOutroEscritorio->getId()],
        );
    }

    /** Uma desconsideração em A (de uma batida de A) presa à justificativa de B: só por SQL cru. */
    private function forjarDesconsideracaoEmALigadaA(JustificativaPonto $deOutroEscritorio): void
    {
        $batida = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 18:00:00');
        $this->em->getConnection()->executeStatement(
            "INSERT INTO ponto_ajuste (tenant_id, user_id, tipo, registro_ponto_id, tipo_marcacao_anterior, data_hora_anterior, motivo, autor_nome, criado_em, justificativa_id)
             VALUES (?, ?, 'desconsideracao', ?, 'saida', '2026-10-21 18:00:00', 'Forjada.', 'Ninguém', '2026-10-22 09:00:00', ?)",
            [$this->a->getId(), $this->pessoa->getId(), $batida->getId(), $deOutroEscritorio->getId()],
        );
    }

    private function batida(Tenant $escritorio, User $pessoa, string $tipo, string $quando): RegistroPonto
    {
        $batida = (new RegistroPonto())->setTenant($escritorio)->setUser($pessoa)->setTipo($tipo)->setDataHora(new \DateTime($quando));
        $this->em->persist($batida);
        $this->em->flush();

        return $batida;
    }

    private function justificativa(Tenant $escritorio, User $pessoa): JustificativaPonto
    {
        $justificativa = (new JustificativaPonto())
            ->setTenant($escritorio)
            ->setUser($pessoa)
            ->setData(new \DateTime('2026-10-21'))
            ->setStatus('abonado')
            ->setTipo('esquecimento_registro');
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }

    private function escritorio(string $nome): Tenant
    {
        $escritorio = (new Tenant())->setName('Escritório da linhagem ' . $nome . ' ' . uniqid());
        $this->em->persist($escritorio);

        return $escritorio;
    }

    private function usuario(string $nome): User
    {
        $usuario = (new User())->setEmail('linhagem_' . uniqid() . '@test.com')->setFullName($nome);
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setPassword('x');
        $this->em->persist($usuario);

        return $usuario;
    }
}
