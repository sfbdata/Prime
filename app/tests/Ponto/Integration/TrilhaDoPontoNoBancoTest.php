<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ConferenciaDaTrilhaDoPontoOutput;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\TrilhaDoPonto;
use App\Ponto\UseCase\ConferirTrilhaDoPontoUseCase;
use App\Ponto\UseCase\ListarTrilhaDoPontoUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * A trilha de auditoria do Ponto no banco (E9): a cadeia por escritório sem buraco nem repetição, a trava que faz dois
 * eventos simultâneos passarem um de cada vez (provada com DUAS conexões, como em TravaDoIndicadorNoBancoTest), a
 * adulteração por UPDATE apontada na sequência certa e os escritórios que não se misturam.
 *
 * O DAMA envolve a conexão do container numa transação que segura a trava até o fim do teste; a segunda conexão é
 * independente e só tenta a trava, sem gravar nada.
 */
#[CoversClass(TrilhaDoPonto::class)]
#[CoversClass(TrilhaDoPontoRepository::class)]
#[CoversClass(ConferirTrilhaDoPontoUseCase::class)]
#[CoversClass(ListarTrilhaDoPontoUseCase::class)]
final class TrilhaDoPontoNoBancoTest extends KernelTestCase
{
    private const SEGREDO = 'segredo-da-trilha-de-teste';

    private EntityManagerInterface $em;
    private TrilhaDoPontoRepository $repositorio;
    private Connection $outra;
    private Tenant $escritorio;
    private User $autor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em          = static::getContainer()->get(EntityManagerInterface::class);
        $this->repositorio = static::getContainer()->get(TrilhaDoPontoRepository::class);
        // Conexão própria: sem o DAMA, enxerga o banco como outro processo enxergaria.
        $this->outra = DriverManager::getConnection($this->em->getConnection()->getParams());

        $this->escritorio = $this->escritorio('A');
        $this->autor = (new User())->setEmail('autor_trilha_' . uniqid() . '@test.com')->setFullName('Gestora da Trilha');
        $this->autor->setRoles(['ROLE_USER']);
        $this->autor->setPassword('x');
        $this->em->persist($this->autor);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->outra->close();
        parent::tearDown();
    }

    #[TestDox('Três eventos: sequência 1, 2, 3 sem buraco, cada um selado com o anterior, e a conferência diz íntegra')]
    public function testCadeiaIntegra(): void
    {
        $trilha = $this->trilha(self::SEGREDO);
        foreach (['batida_registrada', 'justificativa_enviada', 'justificativa_abonada'] as $acao) {
            $trilha->registrar($this->escritorio, $this->autor, $acao, ['tipo' => 'entrada', 'colaborador' => 'Ana Ção']);
        }

        $linhas = $this->linhas($this->escritorio);
        self::assertSame([1, 2, 3], array_column($linhas, 'sequencia'));
        self::assertSame($linhas[0]['selo'], $linhas[1]['selo_anterior']);
        self::assertSame($linhas[1]['selo'], $linhas[2]['selo_anterior']);
        self::assertSame([1, 1, 1], array_map('intval', array_column($linhas, 'versao_da_chave')));
        self::assertSame((int) $this->autor->getId(), (int) $linhas[0]['autor_id']);
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::INTEGRA, $this->conferir(self::SEGREDO, $this->escritorio)->situacao);
    }

    #[TestDox('Com a trilha do escritório em uso, outra conexão não pega a trava dele; a de outro escritório continua livre')]
    public function testSegundaConexaoEspera(): void
    {
        $outro = $this->escritorio('B');
        $trilha = $this->trilha(self::SEGREDO);

        $resultado = $this->repositorio->emTransacao(function () use ($trilha, $outro): array {
            $trilha->registrar($this->escritorio, $this->autor, 'batida_registrada', []);

            $this->outra->beginTransaction();
            $mesmo = $this->conseguiuATrava((int) $this->escritorio->getId());
            $this->outra->rollBack();

            $this->outra->beginTransaction();
            $outroEscritorio = $this->conseguiuATrava((int) $outro->getId());
            $this->outra->rollBack();

            return [$mesmo, $outroEscritorio];
        });

        self::assertSame([false, true], $resultado, 'o mesmo escritório espera o COMMIT; outro escritório não');
    }

    #[TestDox('Sem ninguém segurando, a segunda conexão consegue a trava (o teste anterior não é falso positivo)')]
    public function testLivreQuandoNinguemSegura(): void
    {
        $this->outra->beginTransaction();
        $conseguiu = $this->conseguiuATrava((int) $this->escritorio->getId());
        $this->outra->rollBack();

        self::assertTrue($conseguiu);
    }

    #[TestDox('A sequência não repete: o índice único (escritório, sequência) recusa uma segunda linha 1')]
    public function testSequenciaNaoRepete(): void
    {
        $this->trilha(self::SEGREDO)->registrar($this->escritorio, null, 'batida_registrada', []);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->repositorio->inserir([
            'tenant_id' => (int) $this->escritorio->getId(), 'sequencia' => 1, 'em' => '2026-03-10 08:00:00', 'autor_id' => null,
            'autor_nome' => null, 'acao' => 'batida_registrada', 'detalhe' => [], 'selo_anterior' => null, 'selo' => null, 'versao_da_chave' => null,
        ]);
    }

    #[TestDox('UPDATE no detalhe da linha 2: a conferência aponta alterada no nº 2')]
    public function testUpdateApontaASequencia(): void
    {
        $trilha = $this->trilha(self::SEGREDO);
        for ($i = 0; $i < 3; ++$i) {
            $trilha->registrar($this->escritorio, $this->autor, 'batida_registrada', ['tipo' => 'entrada', 'n' => $i]);
        }

        $this->em->getConnection()->executeStatement(
            "UPDATE ponto_auditoria SET detalhe = '{\"tipo\":\"saida\",\"n\":1}' WHERE tenant_id = ? AND sequencia = 2",
            [$this->escritorio->getId()],
        );

        $resultado = $this->conferir(self::SEGREDO, $this->escritorio);
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::ALTERADA, $resultado->situacao);
        self::assertSame(2, $resultado->sequenciaAlterada);
    }

    #[TestDox('Apagar uma linha do meio também é apontado: alterada na sequência que sumiu')]
    public function testDeleteApontaASequencia(): void
    {
        $trilha = $this->trilha(self::SEGREDO);
        for ($i = 0; $i < 3; ++$i) {
            $trilha->registrar($this->escritorio, null, 'batida_registrada', ['n' => $i]);
        }

        $this->em->getConnection()->executeStatement('DELETE FROM ponto_auditoria WHERE tenant_id = ? AND sequencia = 2', [$this->escritorio->getId()]);

        self::assertSame(2, $this->conferir(self::SEGREDO, $this->escritorio)->sequenciaAlterada);
    }

    #[TestDox('Escritórios não se misturam: cada um começa no 1, e a adulteração de um não toca a conferência do outro')]
    public function testEscritoriosNaoSeMisturam(): void
    {
        $outro = $this->escritorio('C');
        $trilha = $this->trilha(self::SEGREDO);
        $trilha->registrar($this->escritorio, null, 'batida_registrada', []);
        $trilha->registrar($outro, null, 'batida_registrada', []);
        $trilha->registrar($this->escritorio, null, 'batida_registrada', []);

        self::assertSame([1, 2], array_column($this->linhas($this->escritorio), 'sequencia'));
        self::assertSame([1], array_column($this->linhas($outro), 'sequencia'));
        self::assertNull($this->linhas($outro)[0]['selo_anterior'], 'o elo não atravessa escritórios');

        $this->em->getConnection()->executeStatement("UPDATE ponto_auditoria SET acao = 'x' WHERE tenant_id = ?", [$outro->getId()]);

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::ALTERADA, $this->conferir(self::SEGREDO, $outro)->situacao);
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::INTEGRA, $this->conferir(self::SEGREDO, $this->escritorio)->situacao);
        self::assertSame(1, (new ListarTrilhaDoPontoUseCase($this->repositorio, $this->conferidor(self::SEGREDO)))->executar($outro, 1)->total);
    }

    #[TestDox('Segredo vazio: grava sem selo e sem versão; a conferência diz não selada, sem segredo')]
    public function testSemSegredo(): void
    {
        $this->trilha('')->registrar($this->escritorio, $this->autor, 'batida_registrada', []);

        $linha = $this->linhas($this->escritorio)[0];
        self::assertNull($linha['selo']);
        self::assertNull($linha['versao_da_chave']);
        $resultado = $this->conferir('', $this->escritorio);
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::NAO_SELADA, $resultado->situacao);
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::SEM_SEGREDO, $resultado->motivoSemSelo);
    }

    #[TestDox('Segredo configurado depois: as linhas de antes são o trecho inicial, as novas conferem')]
    public function testSegredoDepois(): void
    {
        $this->trilha('')->registrar($this->escritorio, null, 'batida_registrada', []);
        $this->trilha('')->registrar($this->escritorio, null, 'batida_registrada', []);
        $this->trilha(self::SEGREDO)->registrar($this->escritorio, null, 'batida_registrada', []);

        $resultado = $this->conferir(self::SEGREDO, $this->escritorio);
        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::TRECHO_INICIAL, $resultado->motivoSemSelo);
        self::assertSame(2, $resultado->ultimaSemSelo);
    }

    #[TestDox('A conferência lê a cadeia em blocos: mais de um bloco de 500 continua íntegra')]
    public function testCadeiaLongaEmBlocos(): void
    {
        $trilha = $this->trilha(self::SEGREDO);
        for ($i = 0; $i < 501; ++$i) {
            $trilha->registrar($this->escritorio, null, 'batida_registrada', ['n' => $i]);
        }

        self::assertSame(ConferenciaDaTrilhaDoPontoOutput::INTEGRA, $this->conferir(self::SEGREDO, $this->escritorio)->situacao);
        $pagina = (new ListarTrilhaDoPontoUseCase($this->repositorio, $this->conferidor(self::SEGREDO)))->executar($this->escritorio, 99);
        self::assertSame(11, $pagina->paginas);
        self::assertSame(11, $pagina->pagina, 'página além da última cai na última');
        self::assertSame([1], array_column($pagina->eventos, 'sequencia'), 'a última página tem o evento mais antigo');
    }

    private function trilha(string $segredo): TrilhaDoPonto
    {
        return new TrilhaDoPonto($this->repositorio, new ChavesDoHmacDoPonto($segredo), static::getContainer()->get(ClockInterface::class), new TokenStorage());
    }

    private function conferidor(string $segredo): ConferirTrilhaDoPontoUseCase
    {
        return new ConferirTrilhaDoPontoUseCase($this->repositorio, new ChavesDoHmacDoPonto($segredo));
    }

    private function conferir(string $segredo, Tenant $escritorio): ConferenciaDaTrilhaDoPontoOutput
    {
        return $this->conferidor($segredo)->executar($escritorio);
    }

    private function conseguiuATrava(int $tenantId): bool
    {
        return (bool) $this->outra->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [TrilhaDoPontoRepository::CLASSE_TRAVA, $tenantId]);
    }

    /** @return list<array<string, mixed>> */
    private function linhas(Tenant $escritorio): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT sequencia, selo_anterior, selo, versao_da_chave, autor_id FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$escritorio->getId()],
        );
    }

    private function escritorio(string $nome): Tenant
    {
        $escritorio = (new Tenant())->setName('Escritório da trilha ' . $nome . ' ' . uniqid());
        $this->em->persist($escritorio);
        $this->em->flush();

        return $escritorio;
    }
}
