<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\BatidaAjustada;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Ponto\Service\TrilhaDoPonto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A leitura da batida EFETIVA no `RegistroPontoRepository` (E10, plano-e10-ponto §1 e §7, bloco A): os registros sem
 * ajuste mais a cabeça de cada linhagem que ainda está na conta. A alteração aparece com a hora nova e a original fica
 * gravada; a desconsiderada some de todo leitor; a inclusão entra sem id de registro, pelo alvo `a{id}`.
 *
 * Os ajustes nascem pela `GravacaoDoAjusteDoPonto`, dentro da transação; só as alterações forjadas entre escritórios
 * e entre pessoas entram por SQL cru, para provar que a leitura não confia na gravação.
 */
#[CoversClass(RegistroPontoRepository::class)]
#[CoversClass(BatidaAjustada::class)]
#[CoversClass(SaidaDeEsquecimento::class)]
final class LeituraDaBatidaEfetivaNoBancoTest extends KernelTestCase
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

    #[TestDox('Sem ajuste, os leitores devolvem os próprios registros, na ordem de sempre')]
    public function testSemAjusteDevolveOsRegistros(): void
    {
        $entrada = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $saida = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 17:00:00');

        self::assertSame([$entrada, $saida], $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21')));
        self::assertSame([$entrada, $saida], $this->registros->findByUserAndCompetencia($this->pessoa, $this->a, 2026, 10));
        self::assertSame($saida, $this->registros->ultimaBatidaNoTenant($this->pessoa, $this->a, $this->dia('2026-10-21'), new \DateTimeImmutable('2026-10-21 23:59:59')));
        self::assertNull($entrada->cabeca());
        self::assertNull($entrada->ajuste());
    }

    #[TestDox('Alteração: a batida vem com a hora nova e o ajuste da cabeça, e a original fica gravada no banco')]
    public function testAlteracaoMudaAHoraEGuardaAOriginal(): void
    {
        $entrada = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $saida = (new RegistroPonto())->setTenant($this->a)->setUser($this->pessoa)->setTipo('saida')
            ->setDataHora(new \DateTime('2026-10-21 17:05:00'))->setHomeOffice(true)->setObservacao('Saí pelo celular.');
        $this->em->persist($saida);
        $this->em->flush();

        $alteracao = $this->alterar($this->a, $this->pessoa, 'b' . $saida->getId(), 'saida', '2026-10-21 18:37:00');

        $dia = $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21'));
        self::assertCount(2, $dia);
        self::assertSame($entrada, $dia[0]);
        $ajustada = $dia[1];
        self::assertInstanceOf(BatidaAjustada::class, $ajustada);
        self::assertSame([$saida->getId(), 'b' . $saida->getId(), $alteracao->getId(), $this->pessoa->getId()], [$ajustada->getId(), $ajustada->alvo(), $ajustada->cabeca(), $ajustada->pessoaId()]);
        self::assertSame(['saida', '2026-10-21 18:37:00'], [$ajustada->getTipo(), $ajustada->getDataHora()->format('Y-m-d H:i:s')]);
        self::assertSame(
            [TipoDoAjuste::Alteracao, 'Gestora', '2026-11-20 12:00:00', null],
            [$ajustada->ajuste()->tipo, $ajustada->ajuste()->autorNome, $ajustada->ajuste()->em->format('Y-m-d H:i:s'), $ajustada->ajuste()->justificativaId],
        );
        self::assertSame([true, 'Saí pelo celular.', false, false], [$ajustada->isHomeOffice(), $ajustada->getObservacao(), $ajustada->feitaPeloColaborador(), $ajustada->isEnviadaSemInternet()]);
        self::assertSame('2026-10-21 17:05:00', $this->horaGravada($saida), 'a batida original continua gravada como foi batida');
    }

    #[TestDox('Alteração para outro dia e outro mês: a batida muda de dia, de competência e de seletor de competência')]
    public function testAlteracaoParaOutroMes(): void
    {
        $entrada = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-31 22:00:00');
        $saida = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-31 23:50:00');

        $this->alterar($this->a, $this->pessoa, 'b' . $saida->getId(), 'saida', '2026-11-01 00:10:00');

        self::assertSame([$entrada], $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-31')));
        self::assertSame([$entrada], $this->registros->findByUserAndCompetencia($this->pessoa, $this->a, 2026, 10));
        $novembro = $this->registros->findByUserAndCompetencia($this->pessoa, $this->a, 2026, 11);
        self::assertSame([$saida->getId()], array_map(static fn (MarcacaoDoPonto $b): ?int => $b->getId(), $novembro));
        self::assertSame(['2026-11', '2026-10'], array_column($this->registros->findCompetenciasComRegistroPorUsuario($this->pessoa, $this->a), 'valor'));
    }

    #[TestDox('Desconsideração: a batida sai do dia, do período, da competência e da primeira batida; a original fica no banco')]
    public function testDesconsideracaoTiraABatidaDeTodoLeitor(): void
    {
        $setembro = $this->batida($this->a, $this->pessoa, 'entrada', '2026-09-30 08:00:00');
        $extra = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-05 07:11:00');
        $outubro = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-05 08:04:00');

        $this->desconsiderar($this->a, $setembro);
        $this->desconsiderar($this->a, $extra);

        self::assertSame([], $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-09-30')));
        self::assertSame([$outubro], $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-05')));
        self::assertSame([$outubro], $this->registros->batidasDoDiaNoEscritorio($this->pessoa, $this->a, $this->dia('2026-10-05')));
        self::assertSame([$outubro], $this->registros->findByUserAndCompetencia($this->pessoa, $this->a, 2026, 10));
        self::assertSame([], $this->registros->batidasDoPeriodoNoEscritorio($this->pessoa, $this->a, $this->dia('2026-09-01'), new \DateTimeImmutable('2026-09-30 23:59:59')));
        self::assertSame('2026-10-05 08:04:00', $this->registros->findDataPrimeiraBatida($this->pessoa, $this->a)?->format('Y-m-d H:i:s'));
        self::assertSame(['2026-10'], array_column($this->registros->findCompetenciasComRegistroPorUsuario($this->pessoa, $this->a), 'valor'));
        self::assertSame('2026-09-30 08:00:00', $this->horaGravada($setembro));
    }

    #[TestDox('Inclusão: entra na conta sem id de registro, pelo alvo a{id}, e abre a competência e a primeira batida')]
    public function testInclusaoEntraNaConta(): void
    {
        $inclusao = $this->incluir($this->a, $this->pessoa, 'entrada', '2026-09-15 08:00:00');

        $dia = $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-09-15'));
        self::assertCount(1, $dia);
        $incluida = $dia[0];
        self::assertInstanceOf(BatidaAjustada::class, $incluida);
        self::assertSame([null, 'a' . $inclusao->getId(), $inclusao->getId(), TipoDoAjuste::Inclusao], [$incluida->getId(), $incluida->alvo(), $incluida->cabeca(), $incluida->ajuste()->tipo]);
        self::assertSame([false, null], [$incluida->isHomeOffice(), $incluida->getObservacao()]);
        self::assertSame('2026-09-15 08:00:00', $this->registros->findDataPrimeiraBatida($this->pessoa, $this->a)?->format('Y-m-d H:i:s'));
        self::assertSame(['2026-09'], array_column($this->registros->findCompetenciasComRegistroPorUsuario($this->pessoa, $this->a), 'valor'));
    }

    #[TestDox('Inclusão alterada depois: o alvo continua a{inclusão}, a cabeça é a alteração e só a hora nova aparece')]
    public function testInclusaoAlteradaGuardaOAlvo(): void
    {
        $inclusao = $this->incluir($this->a, $this->pessoa, 'retorno', '2026-10-21 13:00:00');
        $alteracao = $this->alterar($this->a, $this->pessoa, 'a' . $inclusao->getId(), 'retorno', '2026-10-21 13:03:00');

        $dia = $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21'));

        self::assertCount(1, $dia);
        self::assertSame(
            [null, 'a' . $inclusao->getId(), $alteracao->getId(), TipoDoAjuste::Alteracao, '13:03'],
            [$dia[0]->getId(), $dia[0]->alvo(), $dia[0]->cabeca(), $dia[0]->ajuste()?->tipo, $dia[0]->getDataHora()->format('H:i')],
        );
    }

    #[TestDox('Restauração: a batida volta com a hora de antes, pela cabeça da restauração')]
    public function testRestauracaoDevolveABatida(): void
    {
        $saida = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 17:00:00');
        $desconsideracao = $this->desconsiderar($this->a, $saida);
        $foraDaConta = $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21'));
        self::assertSame([], $foraDaConta);

        $restauracao = $this->emTransacao(fn (): PontoAjuste => $this->gravacao->restaurar($desconsideracao, 'Voltou para a conta.', $this->gestora));

        $dia = $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21'));
        self::assertCount(1, $dia);
        self::assertSame(
            [$saida->getId(), $restauracao->getId(), TipoDoAjuste::Restauracao, 'saida', '17:00'],
            [$dia[0]->getId(), $dia[0]->cabeca(), $dia[0]->ajuste()?->tipo, $dia[0]->getTipo(), $dia[0]->getDataHora()->format('H:i')],
        );
    }

    #[TestDox('No mesmo segundo, o registro vem antes da inclusão (e a última batida é a inclusão)')]
    public function testOrdemNoMesmoSegundo(): void
    {
        $inclusao = $this->incluir($this->a, $this->pessoa, 'entrada', '2026-10-21 10:00:00');
        $registro = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 10:00:00');

        $dia = $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21'));
        $ultima = $this->registros->ultimaBatidaNoTenant($this->pessoa, $this->a, $this->dia('2026-10-21'), new \DateTimeImmutable('2026-10-21 23:59:59'));

        self::assertSame(['b' . $registro->getId(), 'a' . $inclusao->getId()], array_map(static fn (MarcacaoDoPonto $b): string => $b->alvo(), $dia));
        self::assertSame('a' . $inclusao->getId(), $ultima?->alvo());
    }

    #[TestDox('Repouso do dia com limite: vale a hora efetiva, e a original ajustada não volta')]
    public function testRepousoDoDiaComLimite(): void
    {
        $repouso = $this->batida($this->a, $this->pessoa, 'repouso', '2026-10-21 12:00:00');
        $this->alterar($this->a, $this->pessoa, 'b' . $repouso->getId(), 'repouso', '2026-10-21 12:30:00');
        $dia = $this->dia('2026-10-21');

        self::assertNull($this->registros->findRepousoDoDia($this->pessoa, $this->a, $dia, new \DateTimeImmutable('2026-10-21 12:15:00')));
        $efetivo = $this->registros->findRepousoDoDia($this->pessoa, $this->a, $dia, new \DateTimeImmutable('2026-10-21 12:45:00'));
        self::assertSame([$repouso->getId(), '12:30'], [$efetivo?->getId(), $efetivo?->getDataHora()->format('H:i')]);
    }

    #[TestDox('Equipe do dia: registro e inclusão de cada pessoa, pela pessoa, só do escritório pedido')]
    public function testEquipeDoDia(): void
    {
        $daPessoa = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $daOutra = $this->incluir($this->a, $this->outra, 'entrada', '2026-10-21 09:00:00');
        $this->batida($this->b, $this->pessoa, 'entrada', '2026-10-21 07:00:00');
        $this->incluir($this->b, $this->outra, 'entrada', '2026-10-21 07:30:00');

        $equipe = $this->registros->batidasDoDiaDaEquipe($this->a, $this->dia('2026-10-21'));

        self::assertSame(
            [[$this->pessoa->getId(), 'b' . $daPessoa->getId()], [$this->outra->getId(), 'a' . $daOutra->getId()]],
            array_map(static fn (MarcacaoDoPonto $b): array => [$b->pessoaId(), $b->alvo()], $equipe),
        );
    }

    #[TestDox('Interjornada: a saída incluída pelo esquecimento abonado fecha o turno e é reconhecida como esquecimento')]
    public function testSaidaDoEsquecimentoFechaOTurno(): void
    {
        $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $justificativa = $this->justificativa($this->a, $this->pessoa);
        $inclusao = $this->incluir($this->a, $this->pessoa, 'saida', '2026-10-21 18:00:00', $justificativa);

        self::assertNull($this->registros->ultimaSaidaQueFechouTurno($this->pessoa, $this->a, 16, new \DateTimeImmutable('2026-10-21 17:00:00')));
        $saida = $this->registros->ultimaSaidaQueFechouTurno($this->pessoa, $this->a, 16, new \DateTimeImmutable('2026-10-22 07:00:00'));

        self::assertNotNull($saida);
        self::assertSame(['a' . $inclusao->getId(), $justificativa->getId()], [$saida->alvo(), $saida->ajuste()?->justificativaId]);
        self::assertTrue($this->saidaDeEsquecimento()->veioDeEsquecimento($saida));
    }

    #[TestDox('Interjornada: a entrada desconsiderada não fecha turno, e a saída alterada pelo gestor não é esquecimento')]
    public function testTurnoPelasEfetivas(): void
    {
        $entrada = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $saida = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 17:00:00');
        $this->alterar($this->a, $this->pessoa, 'b' . $saida->getId(), 'saida', '2026-10-21 18:00:00');
        $ate = new \DateTimeImmutable('2026-10-22 07:00:00');

        $alterada = $this->registros->ultimaSaidaQueFechouTurno($this->pessoa, $this->a, 16, $ate);
        self::assertSame(['b' . $saida->getId(), '18:00'], [$alterada?->alvo(), $alterada?->getDataHora()->format('H:i')]);
        self::assertNotNull($alterada);
        self::assertFalse($this->saidaDeEsquecimento()->veioDeEsquecimento($alterada));

        $this->desconsiderar($this->a, $entrada);

        self::assertNull($this->registros->ultimaSaidaQueFechouTurno($this->pessoa, $this->a, 16, $ate));
    }

    #[TestDox('Outros escritórios: os horários e o ponto aberto seguem as efetivas do outro escritório')]
    public function testOutrosEscritoriosPelasEfetivas(): void
    {
        $this->em->persist(new UserTenant($this->pessoa, $this->b));
        $this->em->flush();
        $this->batida($this->b, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $saida = $this->batida($this->b, $this->pessoa, 'saida', '2026-10-21 12:00:00');
        $inicio = $this->dia('2026-10-21');
        $agora = new \DateTimeImmutable('2026-10-21 15:00:00');

        self::assertSame([], $this->registros->pontoAbertoHojeEmOutrosEscritorios($this->pessoa, $this->a, [(int) $this->b->getId()], $inicio, $agora));

        $this->desconsiderar($this->b, $saida);
        $this->incluir($this->b, $this->pessoa, 'repouso', '2026-10-21 13:00:00');

        self::assertSame(
            [['tenantId' => (int) $this->b->getId(), 'nome' => (string) $this->b->getName()]],
            $this->registros->pontoAbertoHojeEmOutrosEscritorios($this->pessoa, $this->a, [(int) $this->b->getId()], $inicio, $agora),
        );
        $horarios = $this->registros->horariosEmOutrosEscritorios($this->pessoa, $this->a, $inicio, new \DateTimeImmutable('2026-10-21 23:59:59'));
        self::assertSame(['08:00', '13:00'], array_map(static fn (\DateTimeImmutable $h): string => $h->format('H:i'), $horarios));
    }

    #[TestDox('Isolamento: o ajuste de B e o de outra pessoa não aparecem em A, e a alteração forjada em A não esconde a batida de B')]
    public function testIsolamentoDoEscritorioEDaPessoa(): void
    {
        $deA = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 17:30:00');
        $deB = $this->batida($this->b, $this->pessoa, 'saida', '2026-10-21 17:00:00');
        $this->alterar($this->b, $this->pessoa, 'b' . $deB->getId(), 'saida', '2026-10-21 19:00:00');
        $this->incluir($this->a, $this->outra, 'entrada', '2026-10-21 09:00:00');
        $entradaDeB = $this->batida($this->b, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $this->forjarAlteracaoEmADe($entradaDeB);

        self::assertSame([$deA], $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21')));
        self::assertSame([$deA], $this->registros->findByUserAndCompetencia($this->pessoa, $this->a, 2026, 10));
        self::assertSame(
            ['b' . $deA->getId()],
            array_map(static fn (MarcacaoDoPonto $b): string => $b->alvo(), array_values(array_filter(
                $this->registros->batidasDoDiaDaEquipe($this->a, $this->dia('2026-10-21')),
                fn (MarcacaoDoPonto $b): bool => $b->pessoaId() === $this->pessoa->getId(),
            ))),
        );

        $emB = $this->registros->findBatidasDoDia($this->pessoa, $this->b, $this->dia('2026-10-21'));
        self::assertCount(2, $emB);
        self::assertSame($entradaDeB, $emB[0], 'a alteração forjada em A não esconde a batida de B');
        self::assertSame(['b' . $deB->getId(), '19:00'], [$emB[1]->alvo(), $emB[1]->getDataHora()->format('H:i')]);
    }

    #[TestDox('Ajuste forjado de outra pessoa, preso ao registro de alguém: não esconde o registro nem entra na conta de ninguém')]
    public function testAjusteDeOutraPessoaNoRegistroNaoVale(): void
    {
        $daPessoa = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $this->forjarAjuste($this->a, $this->outra, $daPessoa, null);

        self::assertSame([$daPessoa], $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21')), 'o registro continua na conta da pessoa');
        self::assertSame([], $this->registros->findBatidasDoDia($this->outra, $this->a, $this->dia('2026-10-21')), 'o ajuste forjado não entra na conta da outra');
        self::assertSame(
            ['b' . $daPessoa->getId()],
            array_map(static fn (MarcacaoDoPonto $b): string => $b->alvo(), $this->registros->batidasDoDiaDaEquipe($this->a, $this->dia('2026-10-21'))),
        );
    }

    #[TestDox('Sucessor forjado em outro escritório ou de outra pessoa: não tira a cabeça da conta nem aparece')]
    public function testSucessorDeOutroEscritorioOuDeOutraPessoaNaoTiraACabeca(): void
    {
        $entrada = $this->batida($this->a, $this->pessoa, 'entrada', '2026-10-21 08:00:00');
        $saida = $this->batida($this->a, $this->pessoa, 'saida', '2026-10-21 17:00:00');
        $daEntrada = $this->alterar($this->a, $this->pessoa, 'b' . $entrada->getId(), 'entrada', '2026-10-21 08:10:00');
        $daSaida = $this->alterar($this->a, $this->pessoa, 'b' . $saida->getId(), 'saida', '2026-10-21 17:10:00');
        $this->forjarAjuste($this->b, $this->pessoa, $entrada, $daEntrada);   // outro escritório
        $this->forjarAjuste($this->a, $this->outra, $saida, $daSaida);        // outra pessoa

        self::assertSame(
            [['b' . $entrada->getId(), $daEntrada->getId(), '08:10'], ['b' . $saida->getId(), $daSaida->getId(), '17:10']],
            array_map(
                static fn (MarcacaoDoPonto $b): array => [$b->alvo(), $b->cabeca(), $b->getDataHora()?->format('H:i')],
                $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21')),
            ),
        );
        self::assertSame([], $this->registros->findBatidasDoDia($this->pessoa, $this->b, $this->dia('2026-10-21')));
        self::assertSame([], $this->registros->findBatidasDoDia($this->outra, $this->a, $this->dia('2026-10-21')));
    }

    #[TestDox('O envio da batida sem internet acha a original, mesmo desconsiderada (é o recibo do que foi batido)')]
    public function testDoEnvioDevolveAOriginal(): void
    {
        $enviada = (new RegistroPonto())->setTenant($this->a)->setUser($this->pessoa)->setTipo('entrada')
            ->setDataHora(new \DateTime('2026-10-21 08:00:00'))->setIdEnvio('0d6c2a52-6d0e-4f43-9a52-3f0b1d2e9e10');
        $this->em->persist($enviada);
        $this->em->flush();

        $this->desconsiderar($this->a, $enviada);

        self::assertSame([], $this->registros->findBatidasDoDia($this->pessoa, $this->a, $this->dia('2026-10-21')));
        self::assertSame($enviada, $this->registros->doEnvio($this->pessoa, $this->a, '0d6c2a52-6d0e-4f43-9a52-3f0b1d2e9e10'));
    }

    private function emTransacao(\Closure $trabalho): mixed
    {
        return $this->trilha->emTransacao($trabalho);
    }

    private function incluir(Tenant $escritorio, User $pessoa, string $tipo, string $quando, ?JustificativaPonto $justificativa = null): PontoAjuste
    {
        return $this->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir($escritorio, $pessoa, $tipo, new \DateTimeImmutable($quando), 'Inclusão.', $this->gestora, $justificativa));
    }

    private function alterar(Tenant $escritorio, User $pessoa, string $alvo, string $tipo, string $quando): PontoAjuste
    {
        $doAlvo = $this->registros->alvoDoAjuste($pessoa, $escritorio, $alvo) ?? throw new \LogicException('Alvo não achado: ' . $alvo);

        return $this->emTransacao(fn (): PontoAjuste => $this->gravacao->alterar($doAlvo, $tipo, new \DateTimeImmutable($quando), 'Hora corrigida.', $this->gestora));
    }

    private function desconsiderar(Tenant $escritorio, RegistroPonto $batida): PontoAjuste
    {
        $pessoa = $batida->getUser() ?? throw new \LogicException('Batida sem pessoa.');
        $alvo = $this->registros->alvoDoAjuste($pessoa, $escritorio, 'b' . $batida->getId()) ?? throw new \LogicException('Alvo não achado.');

        return $this->emTransacao(fn (): PontoAjuste => $this->gravacao->desconsiderar($alvo, 'Fora da conta.', $this->gestora, null, null));
    }

    /** Uma alteração gravada em A que aponta para a batida de B: a gravação recusaria; só por SQL cru. */
    private function forjarAlteracaoEmADe(RegistroPonto $deB): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO ponto_ajuste (tenant_id, user_id, tipo, registro_ponto_id, tipo_marcacao_anterior, data_hora_anterior, tipo_marcacao_nova, data_hora_nova, motivo, autor_nome, criado_em)
             VALUES (?, ?, 'alteracao', ?, 'entrada', '2026-10-21 08:00:00', 'entrada', '2026-10-21 06:00:00', 'Forjada.', 'Ninguém', '2026-10-22 09:00:00')",
            [$this->a->getId(), $this->pessoa->getId(), $deB->getId()],
        );
    }

    /**
     * Uma alteração forjada por SQL cru (a gravação recusaria): no escritório e na pessoa dados, presa ao registro dado
     * e, com `$anterior`, sucessora dele. A hora nova é 06:00 do dia do registro.
     */
    private function forjarAjuste(Tenant $escritorio, User $pessoa, RegistroPonto $registro, ?PontoAjuste $anterior): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO ponto_ajuste (tenant_id, user_id, tipo, registro_ponto_id, anterior_id, tipo_marcacao_anterior, data_hora_anterior, tipo_marcacao_nova, data_hora_nova, motivo, autor_nome, criado_em)
             VALUES (?, ?, 'alteracao', ?, ?, ?, ?, ?, ?, 'Forjada.', 'Ninguém', '2026-10-22 09:00:00')",
            [
                $escritorio->getId(),
                $pessoa->getId(),
                $registro->getId(),
                $anterior?->getId(),
                $registro->getTipo(),
                $registro->getDataHora()?->format('Y-m-d H:i:s'),
                $registro->getTipo(),
                $registro->getDataHora()?->format('Y-m-d') . ' 06:00:00',
            ],
        );
    }

    private function horaGravada(RegistroPonto $batida): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT data_hora FROM registro_ponto WHERE id = ?', [$batida->getId()]);
    }

    private function saidaDeEsquecimento(): SaidaDeEsquecimento
    {
        return new SaidaDeEsquecimento(static::getContainer()->get(JustificativaPontoRepository::class));
    }

    private function dia(string $data): \DateTimeImmutable
    {
        return new \DateTimeImmutable($data . ' 00:00:00');
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
        $escritorio = (new Tenant())->setName('Escritório da efetiva ' . $nome . ' ' . uniqid());
        $this->em->persist($escritorio);

        return $escritorio;
    }

    private function usuario(string $nome): User
    {
        $usuario = (new User())->setEmail('efetiva_' . uniqid() . '@test.com')->setFullName($nome);
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setPassword('x');
        $this->em->persist($usuario);

        return $usuario;
    }
}
