<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ReversaoDaJustificativaOutput;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Exception\BatidaEmHorarioFuturoException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use App\Ponto\Service\TrilhaDoPonto;
use App\Ponto\UseCase\AnalisarJustificativaUseCase;
use App\Ponto\UseCase\DesconsiderarBatidaUseCase;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use App\Tests\Ponto\Support\TrilhaQueAnotaAsTravasDasPessoas;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * A corrida da E3 (docs/specs/ponto-desenho-0811.md): o gestor leu a justificativa, o colaborador a excluiu e comitou,
 * e só então a análise grava. A exclusão comitada é simulada com um DELETE direto na mesma conexão, depois de a
 * entidade estar carregada no EntityManager, como a ficha a deixa: a releitura sob a trava não pode confiar nela.
 *
 * E10 (D18): a batida do esquecimento abonado é uma INCLUSÃO em `ponto_ajuste` ligada à justificativa (contada no
 * banco, não pela folha); voltar a abonada para análise a desconsidera. A ordem das travas é conferida em `pg_locks`:
 * a linhagem da pessoa (4210) já está segura quando a trilha trava o escritório (4203).
 */
#[CoversClass(AnalisarJustificativaUseCase::class)]
final class AnalisarJustificativaUseCaseTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private AnalisarJustificativaUseCase $analisar;
    private Tenant $tenant;
    private User $colaborador;
    private User $gestor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em       = static::getContainer()->get(EntityManagerInterface::class);
        $this->analisar = static::getContainer()->get(AnalisarJustificativaUseCase::class);

        $this->tenant = (new Tenant())->setName('Escritório da corrida ' . uniqid());
        $this->em->persist($this->tenant);
        $this->colaborador = $this->pessoa('colaborador');
        $this->gestor      = $this->pessoa('gestor');
        $this->em->flush();
    }

    #[TestDox('Abonar a que o colaborador excluiu: recusa com a mensagem, sem batida do esquecimento e com o EntityManager aberto')]
    public function testAbonarExcluidaNaoGravaBatida(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $this->excluirPorFora($justificativa);

        $recusa = $this->recusaDe(fn () => $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, ''));

        self::assertSame(AnaliseDeJustificativaRecusadaException::EXCLUIDA, $recusa->motivo);
        self::assertSame('Esta justificativa foi excluída pelo colaborador.', $recusa->getMessage());
        self::assertSame(0, $this->inclusoesDoColaborador(), 'nenhuma batida para um pedido que não existe');
        self::assertTrue($this->em->isOpen(), 'a recusa não fecha o EntityManager');
    }

    #[TestDox('Recusar e voltar para análise a que sumiu também recusam com a mesma mensagem')]
    public function testRecusarEReverterExcluida(): void
    {
        $pendente = $this->esquecimento('08:00');
        $this->excluirPorFora($pendente);
        $abonada = $this->esquecimento('12:00', 'abonado');
        $this->excluirPorFora($abonada);

        $naRecusa   = $this->recusaDe(fn () => $this->analisar->recusar($pendente, $this->tenant, $this->gestor, 'Sem prova'));
        $naReversao = $this->recusaDe(fn () => $this->analisar->reverter($abonada, $this->tenant, $this->gestor));

        self::assertSame(AnaliseDeJustificativaRecusadaException::MENSAGEM_EXCLUIDA, $naRecusa->getMessage());
        self::assertSame(AnaliseDeJustificativaRecusadaException::MENSAGEM_EXCLUIDA, $naReversao->getMessage());
    }

    #[TestDox('Outra análise comitou antes (situação mudou sob a trava): recusa com o texto da ficha e não grava a segunda batida')]
    public function testSituacaoMudouSobATrava(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $this->em->getConnection()->executeStatement("UPDATE justificativa_ponto SET status = 'abonado' WHERE id = ?", [$justificativa->getId()]);

        $recusa = $this->recusaDe(fn () => $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, ''));

        self::assertSame(AnaliseDeJustificativaRecusadaException::SITUACAO_MUDOU, $recusa->motivo);
        self::assertSame(NomesDasSituacoesDaJustificativa::texto('analise.so_para_abonar'), $recusa->getMessage());
        self::assertSame(0, $this->inclusoesDoColaborador());
    }

    #[TestDox('Caminho feliz: o abono e a inclusão do esquecimento (ligada à justificativa, autor quem abonou) comitam juntos; registro_ponto não ganha linha')]
    public function testAbonarGravaAbonoEBatida(): void
    {
        $justificativa = $this->esquecimento('08:00');

        $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, 'Conferido');

        self::assertSame('abonado', $this->statusNoBanco($justificativa));
        self::assertSame(1, $this->inclusoesDoColaborador());
        self::assertSame(
            [['2026-03-10 08:00:00', 'entrada', (int) $justificativa->getId(), (int) $this->gestor->getId()]],
            $this->inclusoesDaJustificativa($justificativa),
        );
        self::assertSame(0, $this->registrosDoColaborador(), 'a batida do esquecimento não entra em registro_ponto (D18)');
        self::assertSame(['justificativa_abonada', 'batida_do_esquecimento'], $this->eventosDaTrilha(), 'os eventos comitam com o abono');
    }

    #[TestDox('Trilha E9, atomicidade: o fato falha DEPOIS do evento (horário futuro na batida): o evento "abonada" sai junto')]
    public function testFatoQueFalhaDepoisDoEventoLevaOEvento(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-03-10 07:00:00', new \DateTimeZone(date_default_timezone_get())));
        $justificativa = $this->esquecimento('08:00');

        try {
            $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, '');
            self::fail('O horário futuro deveria barrar a batida do esquecimento.');
        } catch (BatidaEmHorarioFuturoException) {
        }

        self::assertSame([], $this->eventosDaTrilha(), 'o "abonada" gravado antes da batida voltou com a transação');
        self::assertSame('pendente', $this->statusNoBanco($justificativa));
        self::assertSame(0, $this->inclusoesDoColaborador());
    }

    #[TestDox('Trilha E9, atomicidade: a trilha falha ao gravar o evento: o abono e a batida não ficam')]
    public function testTrilhaQueFalhaNaoDeixaOFato(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $analisar = $this->analisarComTrilhaQueFalha();

        try {
            $analisar->abonar($justificativa, $this->tenant, $this->gestor, '');
            self::fail('A falha da trilha deveria subir.');
        } catch (\RuntimeException $e) {
            self::assertSame('Falha simulada da trilha.', $e->getMessage());
        }

        self::assertSame('pendente', $this->statusNoBanco($justificativa), 'o abono voltou com a transação');
        self::assertSame(0, $this->inclusoesDoColaborador());
        self::assertSame([], $this->eventosDaTrilha());
    }

    #[TestDox('E10, atomicidade: a trilha falha no evento da INCLUSÃO (depois do "abonada"): nem o abono, nem a inclusão, nem o evento ficam')]
    public function testTrilhaQueFalhaNaInclusaoNaoDeixaNada(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $container = static::getContainer();
        $falha = new class ($container->get(ManagerRegistry::class)) extends TrilhaDoPontoRepository {
            public function inserir(array $linha): void
            {
                if ($linha['acao'] === 'batida_do_esquecimento') {
                    throw new \RuntimeException('Falha simulada na trilha da inclusão.');
                }
                parent::inserir($linha);
            }
        };
        $trilhaQueFalha = new TrilhaDoPonto($falha, new ChavesDoHmacDoPonto(null), $container->get(ClockInterface::class), new TokenStorage());
        $analisar = $this->analisarCom($trilhaQueFalha);

        try {
            $analisar->abonar($justificativa, $this->tenant, $this->gestor, '');
            self::fail('A falha da trilha na inclusão deveria subir.');
        } catch (\RuntimeException $e) {
            self::assertSame('Falha simulada na trilha da inclusão.', $e->getMessage());
        }

        self::assertSame('pendente', $this->statusNoBanco($justificativa), 'o abono voltou com a transação');
        self::assertSame(0, $this->inclusoesDoColaborador(), 'a inclusão voltou com a transação');
        self::assertSame([], $this->eventosDaTrilha(), 'o "abonada", gravado antes, voltou junto');
    }

    #[TestDox('E10: voltar para análise a abonada desconsidera a inclusão do abono (ligada à justificativa, desfazendo a inclusão), com a trilha "ajuste_desfeito" e a inclusão intacta')]
    public function testReverterAbonadaDesconsideraAInclusao(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, '');
        $inclusao = $this->inclusoesDaJustificativa($justificativa);

        $reversao = $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);

        self::assertSame('abonado', $reversao->situacaoAnterior);
        self::assertSame(ReversaoDaJustificativaOutput::BATIDA_DESCONSIDERADA, $reversao->batidaDoAbono);
        self::assertSame('reverter.feito_desconsiderada', $reversao->chaveDoTexto());
        self::assertFalse($reversao->pedeAtencao());
        self::assertSame('pendente', $this->statusNoBanco($justificativa));
        self::assertSame($inclusao, $this->inclusoesDaJustificativa($justificativa), 'a inclusão fica como foi gravada');

        $ajustes = $this->ajustesDoColaborador();
        self::assertCount(2, $ajustes);
        [$raiz, $desconsideracao] = $ajustes;
        self::assertSame('inclusao', $raiz['tipo']);
        self::assertSame('desconsideracao', $desconsideracao['tipo']);
        self::assertSame($raiz['id'], $desconsideracao['inclusao_id'], 'presa à linhagem da inclusão');
        self::assertSame($raiz['id'], $desconsideracao['anterior_id']);
        self::assertSame($raiz['id'], $desconsideracao['desfaz_id'], 'desfaz a inclusão do abono');
        self::assertSame((int) $justificativa->getId(), $desconsideracao['justificativa_id'], 'ligada à análise que a fez');
        self::assertSame((int) $this->gestor->getId(), $desconsideracao['autor_id']);
        self::assertSame(AnalisarJustificativaUseCase::MOTIVO_DA_VOLTA_PARA_ANALISE, $desconsideracao['motivo']);
        self::assertSame(['2026-03-10 08:00:00', 'entrada'], [$desconsideracao['data_hora_anterior'], $desconsideracao['tipo_marcacao_anterior']]);
        self::assertTrue($this->repositorioDasBatidas()->inclusaoDoEsquecimento($justificativa)?->desconsiderada(), 'a batida do abono está fora da conta');
        self::assertSame(0, $this->registrosDoColaborador());
        self::assertSame(
            ['justificativa_abonada', 'batida_do_esquecimento', 'justificativa_reaberta', 'ajuste_desfeito'],
            $this->eventosDaTrilha(),
        );
    }

    #[TestDox('E12: recusar o trabalho externo tira a batida ligada da conta (Desconsiderar da E10, pela análise e ligado à justificativa), na mesma transação')]
    public function testRecusarTrabalhoExternoTiraABatidaDaConta(): void
    {
        [$batida, $justificativa] = $this->trabalhoExterno();

        $saiu = $this->analisar->recusar($justificativa, $this->tenant, $this->gestor, 'Sem prova do deslocamento');

        self::assertTrue($saiu);
        self::assertSame('rejeitado', $this->statusNoBanco($justificativa));
        $ajustes = $this->ajustesDoColaborador();
        self::assertCount(1, $ajustes);
        self::assertSame(
            ['desconsideracao', (int) $justificativa->getId(), (int) $this->gestor->getId(), AnalisarJustificativaUseCase::MOTIVO_DA_RECUSA_DO_TRABALHO_EXTERNO],
            [$ajustes[0]['tipo'], $ajustes[0]['justificativa_id'], $ajustes[0]['autor_id'], $ajustes[0]['motivo']],
        );
        self::assertSame(['2026-03-10 08:12:00', 'entrada'], [$ajustes[0]['data_hora_anterior'], $ajustes[0]['tipo_marcacao_anterior']]);
        self::assertTrue($this->alvoDa($batida)->desconsiderada(), 'a batida externa está fora da conta');
        self::assertSame(1, $this->registrosDoColaborador(), 'o registro original fica guardado');
        self::assertSame(['justificativa_recusada', 'batida_desconsiderada'], $this->eventosDaTrilha());
    }

    #[TestDox('E12: voltar para análise a recusada do trabalho externo restaura a batida; recusar de novo a tira outra vez')]
    public function testReverterRecusadaDoTrabalhoExternoRestauraABatida(): void
    {
        [$batida, $justificativa] = $this->trabalhoExterno();
        $this->analisar->recusar($justificativa, $this->tenant, $this->gestor, 'Sem prova');

        $reversao = $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);

        self::assertSame('rejeitado', $reversao->situacaoAnterior);
        self::assertSame(ReversaoDaJustificativaOutput::BATIDA_RESTAURADA, $reversao->batidaDoAbono);
        self::assertSame('reverter.feito_restaurada', $reversao->chaveDoTexto());
        self::assertFalse($reversao->pedeAtencao());
        self::assertSame('pendente', $this->statusNoBanco($justificativa));
        self::assertFalse($this->alvoDa($batida)->desconsiderada(), 'a batida voltou para a conta');
        $ajustes = $this->ajustesDoColaborador();
        self::assertSame(['desconsideracao', 'restauracao'], array_column($ajustes, 'tipo'));
        self::assertSame(AnalisarJustificativaUseCase::MOTIVO_DA_VOLTA_DO_TRABALHO_EXTERNO, $ajustes[1]['motivo']);
        self::assertSame($ajustes[0]['id'], $ajustes[1]['desfaz_id'], 'desfaz a desconsideração da recusa');
        self::assertSame(['justificativa_recusada', 'batida_desconsiderada', 'justificativa_reaberta', 'ajuste_desfeito'], $this->eventosDaTrilha());

        self::assertTrue($this->analisar->recusar($justificativa, $this->tenant, $this->gestor, 'Sem prova de novo'));
        self::assertTrue($this->alvoDa($batida)->desconsiderada());
        self::assertCount(3, $this->ajustesDoColaborador());
    }

    #[TestDox('E12: batida externa que já saiu da conta pela ficha: a recusa só recusa, e voltar para análise não a devolve')]
    public function testRecusarTrabalhoExternoJaForaDaConta(): void
    {
        [$batida, $justificativa] = $this->trabalhoExterno();
        $this->em->wrapInTransaction(function () use ($batida): void {
            $gravacao = static::getContainer()->get(GravacaoDoAjusteDoPonto::class);
            $gravacao->travar($this->colaborador);
            $gravacao->desconsiderar($this->alvoDa($batida), 'Tirada pela ficha', $this->gestor, null, null);
        });

        self::assertFalse($this->analisar->recusar($justificativa, $this->tenant, $this->gestor, 'Sem prova'));
        self::assertSame('rejeitado', $this->statusNoBanco($justificativa));
        self::assertCount(1, $this->ajustesDoColaborador(), 'nenhum ajuste a mais');

        $reversao = $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);
        self::assertNull($reversao->batidaDoAbono);
        self::assertSame('reverter.feito', $reversao->chaveDoTexto());
        self::assertTrue($this->alvoDa($batida)->desconsiderada(), 'a desconsideração da ficha não é desfeita pela análise');
        self::assertCount(1, $this->ajustesDoColaborador());
    }

    #[TestDox('E12: recusar uma justificativa sem batida ligada não mexe em batida nenhuma')]
    public function testRecusarSemBatidaLigada(): void
    {
        $justificativa = $this->esquecimento('08:00');

        self::assertFalse($this->analisar->recusar($justificativa, $this->tenant, $this->gestor, 'Sem prova'));
        self::assertSame([], $this->ajustesDoColaborador());
        self::assertSame('reverter.feito', $this->analisar->reverter($justificativa, $this->tenant, $this->gestor)->chaveDoTexto());
    }

    #[TestDox('E10: abonar de novo depois de voltar grava outra inclusão, e a nova volta desconsidera a mais nova')]
    public function testAbonarDeNovoEVoltarDesconsideraAInclusaoMaisNova(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, '');
        $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);
        $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, '');

        $reversao = $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);

        self::assertSame(ReversaoDaJustificativaOutput::BATIDA_DESCONSIDERADA, $reversao->batidaDoAbono);
        $tipos = array_column($this->ajustesDoColaborador(), 'tipo');
        self::assertSame(['inclusao', 'desconsideracao', 'inclusao', 'desconsideracao'], $tipos);
        $ajustes = $this->ajustesDoColaborador();
        self::assertSame($ajustes[2]['id'], $ajustes[3]['desfaz_id'], 'a segunda volta desfaz a segunda inclusão');
    }

    #[TestDox('E10, decisão 3: abono de antes da E10 (batida em registro_ponto, sem inclusão): a batida fica, nada vai para ponto_ajuste e a tela avisa')]
    public function testReverterAbonoDeAntesDaE10MantemABatida(): void
    {
        $justificativa = $this->esquecimento('08:00', 'abonado');
        $antiga = new RegistroPonto();
        $antiga->setUser($this->colaborador);
        $antiga->setTenant($this->tenant);
        $antiga->setTipo('entrada');
        $antiga->setDataHora(new \DateTime('2026-03-10 08:00:00'));
        $antiga->setObservacao(RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO);
        $this->em->persist($antiga);
        $this->em->flush();

        $reversao = $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);

        self::assertSame(ReversaoDaJustificativaOutput::BATIDA_DE_ANTES, $reversao->batidaDoAbono);
        self::assertSame('reverter.feito_com_batida', $reversao->chaveDoTexto());
        self::assertTrue($reversao->pedeAtencao());
        self::assertSame('pendente', $this->statusNoBanco($justificativa));
        self::assertSame(1, $this->registrosDoColaborador(), 'a batida de antes da E10 fica');
        self::assertSame([], $this->ajustesDoColaborador());
        self::assertSame(['justificativa_reaberta'], $this->eventosDaTrilha());
    }

    #[TestDox('E10: a inclusão do abono que o gestor já desconsiderou não ganha outra desconsideração; a volta só reabre')]
    public function testReverterComInclusaoJaForaDaContaSoReabre(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, '');
        $gravacao = static::getContainer()->get(GravacaoDoAjusteDoPonto::class);
        $this->em->wrapInTransaction(function () use ($gravacao, $justificativa): void {
            $gravacao->travar($this->colaborador);
            $alvo = $this->repositorioDasBatidas()->inclusaoDoEsquecimento($justificativa);
            self::assertNotNull($alvo);
            $gravacao->desconsiderar($alvo, 'Batida errada.', $this->gestor, null, null);
        });

        $reversao = $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);

        self::assertNull($reversao->batidaDoAbono);
        self::assertSame('reverter.feito', $reversao->chaveDoTexto());
        self::assertSame(['inclusao', 'desconsideracao'], array_column($this->ajustesDoColaborador(), 'tipo'));
    }

    #[TestDox('Voltar para análise uma abonada que não é esquecimento não mexe na folha')]
    public function testReverterAbonadaSemEsquecimento(): void
    {
        $justificativa = $this->esquecimento('08:00', 'abonado');
        $justificativa->setTipo('atestado_medico');
        $justificativa->setTipoRegistroEsquecido(null);
        $justificativa->setHoraRegistroEsquecido(null);
        $this->em->flush();

        $reversao = $this->analisar->reverter($justificativa, $this->tenant, $this->gestor);

        self::assertNull($reversao->batidaDoAbono);
        self::assertSame('reverter.feito', $reversao->chaveDoTexto());
        self::assertSame([], $this->ajustesDoColaborador());
    }

    #[TestDox('E10, ordem das travas, abonar: a linhagem da pessoa, ainda livre antes, já está travada (4210) no primeiro evento da trilha (4203)')]
    public function testAbonarTravaAPessoaAntesDaTrilha(): void
    {
        $justificativa = $this->esquecimento('08:00');
        [$espiao, $analisar] = $this->analisarEspiando([$this->colaborador]);
        self::assertSame([], $this->travadas([$this->colaborador]), 'a pessoa ainda não foi travada neste teste');

        $analisar->abonar($justificativa, $this->tenant, $this->gestor, '');

        self::assertSame(['justificativa_abonada', 'batida_do_esquecimento'], $this->eventosDaTrilha());
        self::assertSame([(int) $this->colaborador->getId()], $espiao->travadasPorEvento[0], 'no primeiro evento a pessoa já estava travada');
        self::assertCount(2, $espiao->travadasPorEvento);
    }

    #[TestDox('E10, ordem das travas, voltar para análise: a linhagem da pessoa, ainda livre antes, já está travada (4210) no primeiro evento da trilha (4203)')]
    public function testReverterTravaAPessoaAntesDaTrilha(): void
    {
        $justificativa = $this->esquecimento('08:00', 'abonado');
        [$espiao, $analisar] = $this->analisarEspiando([$this->colaborador]);
        self::assertSame([], $this->travadas([$this->colaborador]), 'a pessoa ainda não foi travada neste teste');

        $analisar->reverter($justificativa, $this->tenant, $this->gestor);

        self::assertSame(['justificativa_reaberta'], $this->eventosDaTrilha());
        self::assertSame([[(int) $this->colaborador->getId()]], $espiao->travadasPorEvento, 'no evento a pessoa já estava travada');
    }

    #[TestDox('E12, ordem das travas, recusar o trabalho externo: a linhagem da pessoa já está travada (4210) nos dois eventos (a recusa e a batida desconsiderada)')]
    public function testRecusarTrabalhoExternoTravaAPessoaAntesDaTrilha(): void
    {
        [, $justificativa] = $this->trabalhoExterno();
        [$espiao, $analisar] = $this->analisarEspiando([$this->colaborador]);
        self::assertSame([], $this->travadas([$this->colaborador]), 'a pessoa ainda não foi travada neste teste');

        self::assertTrue($analisar->recusar($justificativa, $this->tenant, $this->gestor, 'Sem prova'));

        self::assertSame(['justificativa_recusada', 'batida_desconsiderada'], $this->eventosDaTrilha());
        $pessoa = [(int) $this->colaborador->getId()];
        self::assertSame([$pessoa, $pessoa], $espiao->travadasPorEvento, 'nos dois eventos a pessoa já estava travada');
    }

    #[TestDox('E10, ordem das travas, "aprovar todos" com duas pessoas: as duas, ainda livres antes, já estão travadas (4210) no evento do lote, o primeiro da trilha (4203)')]
    public function testAbonarLoteTravaTodasAsPessoasAntesDaTrilha(): void
    {
        $outra = $this->pessoa('outra');
        $this->em->flush();
        $lote = [$this->esquecimento('08:00', 'pendente', 'entrada', $outra), $this->esquecimento('12:00', 'pendente', 'repouso')];
        [$espiao, $analisar] = $this->analisarEspiando([$this->colaborador, $outra]);
        self::assertSame([], $this->travadas([$this->colaborador, $outra]), 'nenhuma das duas foi travada neste teste');

        $abono = $analisar->abonarLote($lote, $this->tenant, $this->gestor);

        self::assertCount(2, $abono->abonadas);
        self::assertSame(['abono_em_lote', 'batida_do_esquecimento', 'batida_do_esquecimento'], $this->eventosDaTrilha());
        $ids = [(int) $this->colaborador->getId(), (int) $outra->getId()];
        sort($ids);
        self::assertSame($ids, $espiao->travadasPorEvento[0], 'no evento do lote as duas pessoas já estavam travadas, não só a primeira');
    }

    #[TestDox('E10, ordem das travas, "Abonar selecionadas" com duas pessoas: as duas, ainda livres antes, já estão travadas (4210) no primeiro evento da trilha (4203)')]
    public function testAbonarDaEquipeTravaTodasAsPessoasAntesDaTrilha(): void
    {
        $outra = $this->pessoa('outra');
        $this->em->flush();
        $daEquipe = [$this->esquecimento('08:00', 'pendente', 'entrada', $outra), $this->esquecimento('12:00', 'pendente', 'repouso')];
        [$espiao, $analisar] = $this->analisarEspiando([$this->colaborador, $outra]);
        self::assertSame([], $this->travadas([$this->colaborador, $outra]), 'nenhuma das duas foi travada neste teste');

        $resultado = $analisar->abonarDaEquipe($daEquipe, $this->tenant, $this->gestor);

        self::assertCount(2, $resultado->abonadas);
        self::assertSame(['batida_do_esquecimento', 'batida_do_esquecimento', 'abono_em_lote'], $this->eventosDaTrilha());
        $ids = [(int) $this->colaborador->getId(), (int) $outra->getId()];
        sort($ids);
        self::assertSame($ids, $espiao->travadasPorEvento[0], 'no primeiro evento as duas pessoas já estavam travadas, não só a primeira');
    }

    #[TestDox('"Aprovar todos": a excluída fica de fora e entra no aviso; as outras são abonadas com a batida delas')]
    public function testAbonarLoteDeixaDeForaAExcluida(): void
    {
        $fica   = $this->esquecimento('08:00');
        $sumiu  = $this->esquecimento('18:00', 'pendente', 'saida');
        $this->excluirPorFora($sumiu);

        $abono = $this->analisar->abonarLote([$sumiu, $fica], $this->tenant, $this->gestor);

        self::assertSame([$fica], $abono->abonadas);
        self::assertSame(1, $abono->excluidas);
        self::assertSame(AnaliseDeJustificativaRecusadaException::MENSAGEM_EXCLUIDA, $abono->avisoDasExcluidas());
        self::assertSame('abonado', $this->statusNoBanco($fica));
        self::assertSame(1, $this->inclusoesDoColaborador(), 'só a inclusão da que continuou');
        self::assertCount(1, $this->inclusoesDaJustificativa($fica));
    }

    #[TestDox('abonarPorId (contrato da E5): abona pelo id no escritório e devolve a abonada, com a batida do esquecimento')]
    public function testAbonarPorIdCaminhoFeliz(): void
    {
        $justificativa = $this->esquecimento('08:00');

        $abonada = $this->analisar->abonarPorId((int) $justificativa->getId(), $this->gestor, $this->tenant, 'Conferido');

        self::assertSame($justificativa, $abonada);
        self::assertSame('abonado', $this->statusNoBanco($justificativa));
        self::assertCount(1, $this->inclusoesDaJustificativa($justificativa));
    }

    #[TestDox('abonarPorId: id de outro escritório é "não encontrada" e não muda nada lá')]
    public function testAbonarPorIdDeOutroEscritorio(): void
    {
        $deFora = $this->esquecimento('08:00');
        $outro  = (new Tenant())->setName('Outro escritório ' . uniqid());
        $this->em->persist($outro);
        $this->em->flush();

        $recusa = $this->recusaDe(fn () => $this->analisar->abonarPorId((int) $deFora->getId(), $this->gestor, $outro));

        self::assertSame(AnaliseDeJustificativaRecusadaException::NAO_ENCONTRADA, $recusa->motivo);
        self::assertSame('pendente', $this->statusNoBanco($deFora));
    }

    #[TestDox('abonarPorId: a própria, de quem não é primário nem Master, é recusada (P43 a)')]
    public function testAbonarPorIdAPropria(): void
    {
        $justificativa = $this->esquecimento('08:00');

        $recusa = $this->recusaDe(fn () => $this->analisar->abonarPorId((int) $justificativa->getId(), $this->colaborador, $this->tenant));

        self::assertSame(AnaliseDeJustificativaRecusadaException::PROPRIA, $recusa->motivo);
        self::assertSame('pendente', $this->statusNoBanco($justificativa));
    }

    #[TestDox('abonarPorId: fora de análise e batida já registrada recusam como a ficha, sem gravar')]
    public function testAbonarPorIdRecusasDaFicha(): void
    {
        $abonada = $this->esquecimento('08:00', 'abonado');
        $foraDeAnalise = $this->recusaDe(fn () => $this->analisar->abonarPorId((int) $abonada->getId(), $this->gestor, $this->tenant));

        $jaBatida = $this->esquecimento('12:00', 'pendente', 'repouso');
        $existente = new RegistroPonto();
        $existente->setUser($this->colaborador);
        $existente->setTenant($this->tenant);
        $existente->setTipo('repouso');
        $existente->setDataHora(new \DateTime('2026-03-10 12:05:00'));
        $this->em->persist($existente);
        $this->em->flush();
        $naBatida = $this->recusaDe(fn () => $this->analisar->abonarPorId((int) $jaBatida->getId(), $this->gestor, $this->tenant));

        self::assertSame(AnaliseDeJustificativaRecusadaException::SITUACAO_MUDOU, $foraDeAnalise->motivo);
        self::assertSame(AnaliseDeJustificativaRecusadaException::BATIDA_JA_REGISTRADA, $naBatida->motivo);
        self::assertSame('pendente', $this->statusNoBanco($jaBatida));
        self::assertSame(1, $this->registrosDoColaborador(), 'só a batida que já existia');
        self::assertSame(0, $this->inclusoesDoColaborador());
    }

    #[TestDox('abonarDaEquipe (E5, desenho L818): abona as da equipe com a batida de cada esquecimento e UM "abono_em_lote" no mesmo COMMIT')]
    public function testAbonarDaEquipeComUmEventoSo(): void
    {
        $entrada = $this->esquecimento('08:00');
        $saida   = $this->esquecimento('18:00', 'pendente', 'saida');

        $resultado = $this->analisar->abonarDaEquipe([$entrada, $saida], $this->tenant, $this->gestor);

        self::assertSame([$entrada, $saida], $resultado->abonadas);
        self::assertSame([], $resultado->recusas);
        self::assertSame(['abonado', 'abonado'], [$this->statusNoBanco($entrada), $this->statusNoBanco($saida)]);
        self::assertSame(2, $this->inclusoesDoColaborador());
        self::assertSame(['batida_do_esquecimento', 'batida_do_esquecimento', 'abono_em_lote'], $this->eventosDaTrilha());
        $lote = $this->detalheDoUltimoEvento();
        self::assertSame(2, $lote['quantidade']);
        self::assertSame([$entrada->getId(), $saida->getId()], array_column($lote['da_equipe'], 'justificativa_id'));
    }

    #[TestDox('abonarDaEquipe: excluída, já analisada e batida já registrada (R1, a da primeira do lote) viram o motivo de cada uma; o evento conta só a abonada')]
    public function testAbonarDaEquipeRecusasNaoDesfazemAsOutras(): void
    {
        $fica      = $this->esquecimento('08:00');
        $repetida  = $this->esquecimento('08:30');
        $jaAbonada = $this->esquecimento('12:00', 'abonado', 'repouso');
        $sumiu     = $this->esquecimento('18:00', 'pendente', 'saida');
        $this->excluirPorFora($sumiu);

        $resultado = $this->analisar->abonarDaEquipe([$fica, $repetida, $jaAbonada, $sumiu], $this->tenant, $this->gestor);

        self::assertSame([$fica], $resultado->abonadas);
        self::assertSame([
            (int) $repetida->getId()  => sprintf(BatidaJaRegistrada::MENSAGEM, 'entrada'),
            (int) $jaAbonada->getId() => NomesDasSituacoesDaJustificativa::texto('analise.so_para_abonar'),
            (int) $sumiu->getId()     => AnaliseDeJustificativaRecusadaException::MENSAGEM_EXCLUIDA,
        ], $resultado->recusas);
        self::assertSame(['abonado', 'pendente'], [$this->statusNoBanco($fica), $this->statusNoBanco($repetida)]);
        self::assertSame(1, $this->inclusoesDoColaborador(), 'só a inclusão da abonada');
        self::assertSame(['batida_do_esquecimento', 'abono_em_lote'], $this->eventosDaTrilha());
        self::assertSame([$fica->getId()], array_column($this->detalheDoUltimoEvento()['da_equipe'], 'justificativa_id'));
    }

    #[TestDox('abonarDaEquipe: de outro escritório e a própria sem ser primário nem Master são recusadas; nada abonado, nenhum evento')]
    public function testAbonarDaEquipeGuardasSemEvento(): void
    {
        $daqui = $this->esquecimento('08:00');
        $outro = (new Tenant())->setName('Outro escritório ' . uniqid());
        $this->em->persist($outro);
        $this->em->flush();

        $deFora = $this->analisar->abonarDaEquipe([$daqui], $outro, $this->gestor);
        $propria = $this->analisar->abonarDaEquipe([$daqui], $this->tenant, $this->colaborador);

        self::assertSame([(int) $daqui->getId() => 'Justificativa não encontrada.'], $deFora->recusas);
        self::assertSame([(int) $daqui->getId() => AnaliseDaPropriaJustificativa::RECUSA], $propria->recusas);
        self::assertSame('pendente', $this->statusNoBanco($daqui));
        self::assertSame([], $this->eventosDaTrilha(), 'nada abonado, nenhum evento');
    }

    #[TestDox('abonarDaEquipe, atomicidade: a trilha falha no evento do lote: nenhum abono nem batida fica gravado sem ele')]
    public function testAbonarDaEquipeTrilhaQueFalhaNaoDeixaOsAbonos(): void
    {
        $entrada = $this->esquecimento('08:00');
        $saida   = $this->esquecimento('18:00', 'pendente', 'saida');

        try {
            $this->analisarComTrilhaQueFalha()->abonarDaEquipe([$entrada, $saida], $this->tenant, $this->gestor);
            self::fail('A falha da trilha deveria subir.');
        } catch (\RuntimeException $e) {
            self::assertSame('Falha simulada da trilha.', $e->getMessage());
        }

        self::assertSame(['pendente', 'pendente'], [$this->statusNoBanco($entrada), $this->statusNoBanco($saida)], 'os abonos voltaram com a transação');
        self::assertSame(0, $this->inclusoesDoColaborador());
        self::assertSame([], $this->eventosDaTrilha());
    }

    /** A análise com a trilha que falha ao gravar o evento dela; a batida do esquecimento usa a trilha de verdade. */
    private function analisarComTrilhaQueFalha(): AnalisarJustificativaUseCase
    {
        $container = static::getContainer();
        $falha = new class ($container->get(ManagerRegistry::class)) extends TrilhaDoPontoRepository {
            public function inserir(array $linha): void
            {
                throw new \RuntimeException('Falha simulada da trilha.');
            }
        };

        return new AnalisarJustificativaUseCase(
            $this->em,
            $container->get(JustificativaPontoRepository::class),
            $container->get(RegistrarBatidaDoEsquecimentoUseCase::class),
            $container->get(AnaliseDaPropriaJustificativa::class),
            new TrilhaDoPonto($falha, new ChavesDoHmacDoPonto(null), $container->get(ClockInterface::class), new TokenStorage()),
            $container->get(GravacaoDoAjusteDoPonto::class),
            $container->get(RegistroPontoRepository::class),
            $container->get(DesconsiderarBatidaUseCase::class),
        );
    }

    /** @return array<string, mixed> o detalhe do último evento da trilha do escritório */
    private function detalheDoUltimoEvento(): array
    {
        $detalhe = json_decode((string) $this->em->getConnection()->fetchOne(
            'SELECT detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia DESC LIMIT 1',
            [$this->tenant->getId()],
        ), true);
        self::assertIsArray($detalhe);

        return $detalhe;
    }

    private function recusaDe(callable $acao): AnaliseDeJustificativaRecusadaException
    {
        try {
            $acao();
        } catch (AnaliseDeJustificativaRecusadaException $e) {
            return $e;
        }

        self::fail('A análise deveria ter sido recusada.');
    }

    private function excluirPorFora(JustificativaPonto $justificativa): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM justificativa_ponto WHERE id = ?', [$justificativa->getId()]);
    }

    private function esquecimento(string $hora, string $status = 'pendente', string $tipo = 'entrada', ?User $pessoa = null): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setTenant($this->tenant);
        $justificativa->setUser($pessoa ?? $this->colaborador);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus($status);
        $justificativa->setTipo('esquecimento_registro');
        $justificativa->setTipoRegistroEsquecido($tipo);
        $justificativa->setHoraRegistroEsquecido(new \DateTime('1970-01-01 ' . $hora . ':00'));
        $justificativa->setBatchId('lote-corrida');
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }

    /**
     * A batida de trabalho externo (E12) e a justificativa em análise ligada a ela, como a batida as grava.
     *
     * @return array{RegistroPonto, JustificativaPonto}
     */
    private function trabalhoExterno(): array
    {
        $batida = new RegistroPonto();
        $batida->setUser($this->colaborador);
        $batida->setTenant($this->tenant);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime('2026-03-10 08:12:00'));
        $batida->setTrabalhoExterno(true);
        $batida->setMotivoTrabalhoExterno('Audiência no fórum');
        $this->em->persist($batida);

        $justificativa = new JustificativaPonto();
        $justificativa->setTenant($this->tenant);
        $justificativa->setUser($this->colaborador);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus('pendente');
        $justificativa->setTipo('trabalho_externo');
        $justificativa->setRegistroPonto($batida);
        $justificativa->setBatchId('lote-externo');
        $this->em->persist($justificativa);
        $this->em->flush();

        return [$batida, $justificativa];
    }

    private function alvoDa(RegistroPonto $batida): AlvoDoAjuste
    {
        return $this->repositorioDasBatidas()->alvoDoAjuste($this->colaborador, $this->tenant, 'b' . $batida->getId())
            ?? throw new \LogicException('Batida fora da folha.');
    }

    private function statusNoBanco(JustificativaPonto $justificativa): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM justificativa_ponto WHERE id = ?', [$justificativa->getId()]);
    }

    /** @return list<string> as ações da trilha do escritório, em ordem */
    private function eventosDaTrilha(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT acao FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$this->tenant->getId()],
        ));
    }

    /** O que a pessoa bateu (e as batidas do abono de antes da E10). */
    private function registrosDoColaborador(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$this->colaborador->getId(), $this->tenant->getId()],
        );
    }

    /** As inclusões ligadas a uma justificativa (o esquecimento abonado, E10), contadas no banco. */
    private function inclusoesDoColaborador(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM ponto_ajuste WHERE user_id = ? AND tenant_id = ? AND tipo = 'inclusao' AND justificativa_id IS NOT NULL",
            [$this->colaborador->getId(), $this->tenant->getId()],
        );
    }

    /** @return list<array{string, string, int, int}> data e hora, tipo, justificativa e autor de cada inclusão dela */
    private function inclusoesDaJustificativa(JustificativaPonto $justificativa): array
    {
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            "SELECT data_hora_nova, tipo_marcacao_nova, justificativa_id, autor_id FROM ponto_ajuste WHERE tipo = 'inclusao' AND justificativa_id = ? ORDER BY id",
            [$justificativa->getId()],
        );

        return array_map(static fn (array $l): array => [(string) $l['data_hora_nova'], (string) $l['tipo_marcacao_nova'], (int) $l['justificativa_id'], (int) $l['autor_id']], $linhas);
    }

    /** @return list<array<string, mixed>> os ajustes do colaborador no escritório, em ordem de gravação */
    private function ajustesDoColaborador(): array
    {
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, tipo, inclusao_id, anterior_id, desfaz_id, justificativa_id, autor_id, motivo, tipo_marcacao_anterior, data_hora_anterior'
            . ' FROM ponto_ajuste WHERE user_id = ? AND tenant_id = ? ORDER BY id',
            [$this->colaborador->getId(), $this->tenant->getId()],
        );

        return array_map(static function (array $l): array {
            foreach (['id', 'inclusao_id', 'anterior_id', 'desfaz_id', 'justificativa_id', 'autor_id'] as $coluna) {
                $l[$coluna] = $l[$coluna] === null ? null : (int) $l[$coluna];
            }

            return $l;
        }, $linhas);
    }

    private function repositorioDasBatidas(): RegistroPontoRepository
    {
        return static::getContainer()->get(RegistroPontoRepository::class);
    }

    /** A análise com outra trilha em tudo: nos eventos da análise e nos da gravação do ajuste. */
    private function analisarCom(TrilhaDoPonto $trilha): AnalisarJustificativaUseCase
    {
        $container = static::getContainer();
        $gravacao  = new GravacaoDoAjusteDoPonto($this->em, $trilha, $container->get(HorarioFuturoDaBatida::class), $container->get(ClockInterface::class));

        return new AnalisarJustificativaUseCase(
            $this->em,
            $container->get(JustificativaPontoRepository::class),
            new RegistrarBatidaDoEsquecimentoUseCase($container->get(HorarioFuturoDaBatida::class), $container->get(BatidaJaRegistrada::class), $gravacao, $trilha),
            $container->get(AnaliseDaPropriaJustificativa::class),
            $trilha,
            $gravacao,
            $this->repositorioDasBatidas(),
            new DesconsiderarBatidaUseCase($trilha, $gravacao, $this->repositorioDasBatidas(), $container->get(AnaliseDaPropriaJustificativa::class)),
        );
    }

    /**
     * A análise com a trilha que anota as travas das pessoas a cada evento ({@see TrilhaQueAnotaAsTravasDasPessoas}).
     *
     * @param list<User> $pessoas
     *
     * @return array{TrilhaQueAnotaAsTravasDasPessoas, AnalisarJustificativaUseCase}
     */
    private function analisarEspiando(array $pessoas): array
    {
        $container = static::getContainer();
        $espiao    = new TrilhaQueAnotaAsTravasDasPessoas($container->get(ManagerRegistry::class), self::ids($pessoas));

        return [$espiao, $this->analisarCom(new TrilhaDoPonto($espiao, new ChavesDoHmacDoPonto(null), $container->get(ClockInterface::class), new TokenStorage()))];
    }

    /**
     * @param list<User> $pessoas
     *
     * @return list<int> as que estão com a linhagem travada (4210) nesta conexão agora
     */
    private function travadas(array $pessoas): array
    {
        return TrilhaQueAnotaAsTravasDasPessoas::travadas($this->em->getConnection(), self::ids($pessoas));
    }

    /**
     * @param list<User> $pessoas
     *
     * @return list<int>
     */
    private static function ids(array $pessoas): array
    {
        return array_map(static fn (User $pessoa): int => (int) $pessoa->getId(), $pessoas);
    }

    private function pessoa(string $papel): User
    {
        $pessoa = new User();
        $pessoa->setEmail($papel . '_corrida_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa ' . $papel);
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);

        return $pessoa;
    }
}
