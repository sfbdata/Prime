<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\Exception\BatidaEmHorarioFuturoException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use App\Ponto\Service\TrilhaDoPonto;
use App\Ponto\UseCase\AnalisarJustificativaUseCase;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
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
        self::assertSame(0, $this->batidasDoColaborador(), 'nenhuma batida para um pedido que não existe');
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
        self::assertSame(0, $this->batidasDoColaborador());
    }

    #[TestDox('Caminho feliz: o abono e a batida do esquecimento comitam juntos')]
    public function testAbonarGravaAbonoEBatida(): void
    {
        $justificativa = $this->esquecimento('08:00');

        $this->analisar->abonar($justificativa, $this->tenant, $this->gestor, 'Conferido');

        self::assertSame('abonado', $this->statusNoBanco($justificativa));
        self::assertSame(1, $this->batidasDoColaborador());
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
        self::assertSame(0, $this->batidasDoColaborador());
    }

    #[TestDox('Trilha E9, atomicidade: a trilha falha ao gravar o evento: o abono e a batida não ficam')]
    public function testTrilhaQueFalhaNaoDeixaOFato(): void
    {
        $justificativa = $this->esquecimento('08:00');
        $container = static::getContainer();
        $falha = new class ($container->get(ManagerRegistry::class)) extends TrilhaDoPontoRepository {
            public function inserir(array $linha): void
            {
                throw new \RuntimeException('Falha simulada da trilha.');
            }
        };
        $trilha = new TrilhaDoPonto($falha, new ChavesDoHmacDoPonto(null), $container->get(ClockInterface::class), new TokenStorage());
        $analisar = new AnalisarJustificativaUseCase(
            $this->em,
            $container->get(JustificativaPontoRepository::class),
            $container->get(RegistrarBatidaDoEsquecimentoUseCase::class),
            $container->get(AnaliseDaPropriaJustificativa::class),
            $trilha,
        );

        try {
            $analisar->abonar($justificativa, $this->tenant, $this->gestor, '');
            self::fail('A falha da trilha deveria subir.');
        } catch (\RuntimeException $e) {
            self::assertSame('Falha simulada da trilha.', $e->getMessage());
        }

        self::assertSame('pendente', $this->statusNoBanco($justificativa), 'o abono voltou com a transação');
        self::assertSame(0, $this->batidasDoColaborador());
        self::assertSame([], $this->eventosDaTrilha());
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
        self::assertSame(1, $this->batidasDoColaborador(), 'só a batida da que continuou');
    }

    #[TestDox('abonarPorId (contrato da E5): abona pelo id no escritório e devolve a abonada, com a batida do esquecimento')]
    public function testAbonarPorIdCaminhoFeliz(): void
    {
        $justificativa = $this->esquecimento('08:00');

        $abonada = $this->analisar->abonarPorId((int) $justificativa->getId(), $this->gestor, $this->tenant, 'Conferido');

        self::assertSame($justificativa, $abonada);
        self::assertSame('abonado', $this->statusNoBanco($justificativa));
        self::assertSame(1, $this->batidasDoColaborador());
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
        self::assertSame(1, $this->batidasDoColaborador(), 'só a batida que já existia');
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

    private function esquecimento(string $hora, string $status = 'pendente', string $tipo = 'entrada'): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setTenant($this->tenant);
        $justificativa->setUser($this->colaborador);
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

    private function batidasDoColaborador(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$this->colaborador->getId(), $this->tenant->getId()],
        );
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
