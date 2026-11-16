<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use App\Voto\Enum\OrigemDaOcorrencia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use App\Voto\UseCase\DecidirAvaliacaoUseCase;
use App\Voto\UseCase\RegistrarOcorrenciaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Zenstruck\Foundry\Test\Factories;

/**
 * Registrar ocorrência e decidir a avaliação no banco (spec voto-avaliacoes-do-conselho-1611, seções 2, 3 e 4): a apta só
 * registra e a não apta abre a avaliação pendente; a decisão pede justificativa, não se refaz e "tornar apto" só vale
 * antes de uma pauta encerrar; o papel é conferido no UseCase (conselho e Administração decidem, a comissão e a
 * Administração registram); outro escritório dá 404; a trilha só leva ids.
 */
#[CoversClass(RegistrarOcorrenciaUseCase::class)]
#[CoversClass(DecidirAvaliacaoUseCase::class)]
#[CoversClass(OcorrenciaDaUnidadeRepository::class)]
final class AvaliacoesDoConselhoNoBancoTest extends JusPrimeWebTestCase
{
    use ComPessoasDoEscritorioDoVoto;
    use Factories;

    protected function setUp(): void
    {
        static::createClient();
        $this->prepararEscritorioDoVoto();
    }

    #[TestDox('Pessoa apta só registra (sem avaliação); não apta abre a avaliação pendente; a trilha leva só ids')]
    public function testRegistrar(): void
    {
        $a = $this->assembleiaComUnidades();
        $admin = $this->primario();

        $apta = $this->registrar($a, '101', 'Cadastro desatualizado', $admin);
        $inapta = $this->registrar($a, '102', 'Quitou ontem', $admin);

        self::assertNull($apta->getAvaliacao());
        self::assertSame(AvaliacaoDaOcorrencia::Pendente, $inapta->getAvaliacao());
        self::assertSame(OrigemDaOcorrencia::Manual, $inapta->getOrigem());
        self::assertSame('Cadastro desatualizado', $apta->getTexto());
        $trilhas = $this->trilhas($a);
        self::assertCount(2, $trilhas);
        self::assertSame('Ocorrência registrada', $trilhas[0]['evento']);
        self::assertSame($inapta->getUnidade()->getId(), $trilhas[1]['unidade_id']);
        self::assertStringNotContainsString('Quitou', json_encode($trilhas, \JSON_THROW_ON_ERROR), 'o texto livre não vai para a trilha');
        self::assertStringNotContainsString('Beto', json_encode($trilhas, \JSON_THROW_ON_ERROR), 'nem o nome');
    }

    #[TestDox('Só acrescenta: duas ocorrências na pessoa apta ficam as duas; com avaliação pendente aberta a nova é recusada')]
    public function testSoAcrescentaERecusaComPendente(): void
    {
        $a = $this->assembleiaComUnidades();
        $admin = $this->primario();
        $this->registrar($a, '101', 'Primeira', $admin);
        $this->registrar($a, '101', 'Segunda', $admin);
        self::assertCount(2, $this->em->getRepository(OcorrenciaDaUnidade::class)->findBy(['unidade' => $this->unidadeDe($a, '101')]));

        $this->registrar($a, '102', 'Abre a avaliação', $admin);
        try {
            $this->registrar($a, '102', 'Outra', $admin);
            self::fail('deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Já há avaliação pendente para esta unidade.', $e->getMessage());
        }
        self::assertCount(1, $this->em->getRepository(OcorrenciaDaUnidade::class)->findBy(['unidade' => $this->unidadeDe($a, '102')]));
    }

    #[TestDox('O índice único parcial do banco barra duas avaliações pendentes da mesma unidade, mas aceita decididas')]
    public function testIndiceUnicoParcial(): void
    {
        $a = $this->assembleiaComUnidades();
        $unidade = $this->unidadeDe($a, '102');
        $banco = $this->em->getConnection();
        $inserir = static fn (string $avaliacao) => $banco->executeStatement(
            "INSERT INTO voto.ocorrencia_da_unidade (texto, avaliacao, origem, registrada_por_nome, registrada_em, tenant_id, assembleia_id, unidade_id) VALUES ('x', ?, 'manual', 'n', NOW(), ?, ?, ?)",
            [$avaliacao, $a->getTenant()->getId(), $a->getId(), $unidade->getId()],
        );

        $inserir('mantida');
        $inserir('mantida');
        $inserir('pendente');

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $inserir('pendente');
    }

    #[TestDox('Texto vazio é recusado; assembleia que acabou não aceita mais ocorrência')]
    public function testTextoEFim(): void
    {
        $admin = $this->primario();
        $a = $this->assembleiaComUnidades();
        try {
            $this->registrar($a, '101', '  ', $admin);
            self::fail('texto vazio');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Escreva a ocorrência.', $e->getMessage());
        }

        $acabada = $this->assembleiaComUnidades('encerrada');
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->registrar($acabada, '101', 'Tarde demais', $admin);
    }

    #[TestDox('Quem registra: sem equipe quem tem o módulo; com equipe a comissão e a Administração, nunca o conselho, a auditoria nem o fiscal')]
    public function testQuemRegistra(): void
    {
        $semEquipe = $this->assembleiaComUnidades();
        $this->registrar($semEquipe, '101', 'Colega do módulo', $this->colega());

        $a = $this->assembleiaComUnidades();
        $comissao = $this->colega();
        $this->membro($a, PapelNaEquipe::Comissao, $comissao);
        $this->registrar($a, '101', 'Da comissão', $comissao);
        $this->registrar($a, '101', 'Da Administração', $this->primario());
        foreach ([PapelNaEquipe::Conselho, PapelNaEquipe::Auditoria, PapelNaEquipe::Fiscal] as $papel) {
            $pessoa = $this->colega();
            $this->membro($a, $papel, $pessoa);
            try {
                $this->registrar($a, '101', 'Não pode', $pessoa);
                self::fail($papel->value . ' não registra');
            } catch (SemPapelNaAvaliacaoException) {
                self::addToAssertionCount(1);
            }
        }
        $fora = $this->colega();
        $this->expectException(SemPapelNaAvaliacaoException::class);
        $this->registrar($a, '101', 'Fora da equipe', $fora);
    }

    #[TestDox('Decidir: justificativa obrigatória; manter inaptidão fica registrado e a unidade segue não apta; a decisão não se refaz')]
    public function testManter(): void
    {
        [$a, $ocorrencia] = $this->avaliacaoPendente();
        $admin = $this->primario();

        try {
            $this->decidir($a, $ocorrencia, AvaliacaoDaOcorrencia::Mantida, '   ', $admin);
            self::fail('sem justificativa');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Informe a justificativa.', $e->getMessage());
        }
        $decidida = $this->decidir($a, $ocorrencia, AvaliacaoDaOcorrencia::Mantida, 'Sem comprovante', $admin);

        self::assertSame(AvaliacaoDaOcorrencia::Mantida, $decidida->getAvaliacao());
        self::assertSame('Sem comprovante', $decidida->getJustificativa());
        self::assertNotNull($decidida->getDecididaEm());
        self::assertFalse($this->unidadeDe($a, '102')->isApta());
        $eventos = array_column($this->trilhas($a), 'evento');
        self::assertContains('Inaptidão mantida pelo conselho', $eventos);

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Não há avaliação pendente.');
        $this->decidir($a, $ocorrencia, AvaliacaoDaOcorrencia::TornadaApta, 'Mudei de ideia', $admin);
    }

    #[TestDox('Tornar apto antes de qualquer pauta encerrar: a unidade vira apta, a situação fica e o hash não muda')]
    public function testTornarAptoAntesDoEncerramento(): void
    {
        [$a, $ocorrencia] = $this->avaliacaoPendente('aberta');
        $hash = $a->getHashDaLista();

        $this->decidir($a, $ocorrencia, AvaliacaoDaOcorrencia::TornadaApta, 'Quitou', $this->primario());

        $this->em->clear();
        $unidade = $this->em->getRepository(\App\Voto\Entity\UnidadeDaAssembleia::class)->find($ocorrencia->getUnidade()->getId());
        self::assertTrue($unidade->isApta());
        self::assertSame('inadimplente', $unidade->getSituacao());
        self::assertSame($hash, $this->em->getRepository(Assembleia::class)->find($a->getId())->getHashDaLista());
        self::assertContains('Inaptidão alterada pelo conselho', array_column($this->trilhas($a), 'evento'));
    }

    #[TestDox('Depois de uma pauta encerrada: "tornar apto" é recusado (e a unidade segue inapta), "manter" ainda vale')]
    public function testDepoisDeUmaPautaEncerrada(): void
    {
        [$a, $ocorrencia] = $this->avaliacaoPendente('encerrada');
        $admin = $this->primario();
        try {
            $this->decidir($a, $ocorrencia, AvaliacaoDaOcorrencia::TornadaApta, 'Quitou', $admin);
            self::fail('deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Votação encerrada: a aptidão não pode mais ser alterada.', $e->getMessage());
        }
        self::assertFalse($this->unidadeDe($a, '102')->isApta());
        self::assertTrue($this->em->getRepository(OcorrenciaDaUnidade::class)->find($ocorrencia->getId())->pendente());

        $decidida = $this->decidir($a, $ocorrencia, AvaliacaoDaOcorrencia::Mantida, 'Segue inapta', $admin);
        self::assertSame(AvaliacaoDaOcorrencia::Mantida, $decidida->getAvaliacao());
    }

    #[TestDox('Quem decide: sem equipe só a Administração; com equipe o conselho e a Administração; comissão, auditoria, fiscal e quem está fora, não')]
    public function testQuemDecide(): void
    {
        $semEquipe = $this->assembleiaComUnidades();
        $o1 = $this->registrar($semEquipe, '102', 'Sem equipe', $this->primario());
        try {
            $this->decidir($semEquipe, $o1, AvaliacaoDaOcorrencia::Mantida, 'x', $this->colega());
            self::fail('colega do módulo não decide sem equipe');
        } catch (SemPapelNaAvaliacaoException) {
            self::addToAssertionCount(1);
        }
        $this->decidir($semEquipe, $o1, AvaliacaoDaOcorrencia::Mantida, 'Administração decide', $this->primario());

        [$a, $o2] = $this->avaliacaoPendente();
        foreach ([PapelNaEquipe::Comissao, PapelNaEquipe::Auditoria, PapelNaEquipe::Fiscal] as $papel) {
            $pessoa = $this->colega();
            $this->membro($a, $papel, $pessoa);
            try {
                $this->decidir($a, $o2, AvaliacaoDaOcorrencia::Mantida, 'Não pode', $pessoa);
                self::fail($papel->value . ' não decide');
            } catch (SemPapelNaAvaliacaoException) {
                self::addToAssertionCount(1);
            }
        }
        try {
            $this->decidir($a, $o2, AvaliacaoDaOcorrencia::Mantida, 'Fora', $this->colega());
            self::fail('quem está fora da equipe não decide');
        } catch (SemPapelNaAvaliacaoException) {
            self::addToAssertionCount(1);
        }
        $conselho = $this->colega();
        $this->membro($a, PapelNaEquipe::Conselho, $conselho);
        $semModulo = $this->colega(comModulo: false);
        $this->membro($a, PapelNaEquipe::Conselho, $semModulo);

        $decidida = $this->decidir($a, $o2, AvaliacaoDaOcorrencia::Mantida, 'O conselho decide', $conselho);

        self::assertSame(AvaliacaoDaOcorrencia::Mantida, $decidida->getAvaliacao());
        self::assertStringContainsString('Pessoa', (string) $decidida->getDecididaPorNome());
    }

    #[TestDox('Outro escritório dá 404: assembleia alheia, unidade de outra assembleia e ocorrência de outra assembleia')]
    public function testOutroEscritorio(): void
    {
        $admin = $this->primario();
        $alheia = AssembleiaFactory::createOne()->_real();
        $alheiaUnidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $alheia, 'identificacao' => '900'])->_real();
        $a = $this->assembleiaComUnidades();

        foreach ([[$alheia->getId(), $alheiaUnidade->getId()], [$a->getId(), $alheiaUnidade->getId()]] as [$assembleiaId, $unidadeId]) {
            try {
                static::getContainer()->get(RegistrarOcorrenciaUseCase::class)->executar($this->escritorio, (int) $assembleiaId, (int) $unidadeId, 'Invasão', $admin, 'X');
                self::fail('deveria dar 404');
            } catch (AssembleiaNaoEncontradaException) {
                self::addToAssertionCount(1);
            }
        }

        [$outra, $ocorrencia] = $this->avaliacaoPendente();
        foreach ([$alheia->getId(), $a->getId()] as $assembleiaId) {
            try {
                static::getContainer()->get(DecidirAvaliacaoUseCase::class)->executar($this->escritorio, (int) $assembleiaId, (int) $ocorrencia->getId(), AvaliacaoDaOcorrencia::Mantida, 'x', $admin, 'X');
                self::fail('deveria dar 404');
            } catch (AssembleiaNaoEncontradaException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertTrue($this->em->getRepository(OcorrenciaDaUnidade::class)->find($ocorrencia->getId())->pendente());
        self::assertNotNull($outra);
    }

    #[TestDox('O contador conta só as "tornada apta" decididas depois da trava da lista')]
    public function testContadorDeAlteracoes(): void
    {
        $a = $this->assembleiaComUnidades();
        $repositorio = static::getContainer()->get(OcorrenciaDaUnidadeRepository::class);
        self::assertSame(0, $repositorio->contarAlteracoesDepoisDaTrava($a), 'lista ainda não travada');

        $admin = $this->primario();
        $antes = $this->registrar($a, '102', 'Antes da trava', $admin);
        $this->decidir($a, $antes, AvaliacaoDaOcorrencia::TornadaApta, 'Quitou', $admin);
        $pauta = $a->getPautas()->first();
        $pauta->abrir('Mesa', 1, null, new \DateTimeImmutable('+1 minute'));
        $this->em->flush();
        self::assertNotNull($a->getHashDaLista());
        self::assertSame(0, $repositorio->contarAlteracoesDepoisDaTrava($a), 'a decisão de antes da trava não conta');

        UnidadeDaAssembleiaFactory::new(['assembleia' => $a, 'identificacao' => '103'])->inapta()->create();
        $this->em->refresh($a);
        $depois = $this->registrar($a, '103', 'Depois da trava', $admin, new \DateTimeImmutable('+2 minutes'));
        $this->decidir($a, $depois, AvaliacaoDaOcorrencia::TornadaApta, 'Quitou depois', $admin, new \DateTimeImmutable('+3 minutes'));

        self::assertSame(1, $repositorio->contarAlteracoesDepoisDaTrava($a));
    }

    /** @return array{0: Assembleia, 1: OcorrenciaDaUnidade} a unidade 102 (inapta) com a avaliação pendente */
    private function avaliacaoPendente(string $pauta = 'aguardando'): array
    {
        $a = $this->assembleiaComUnidades('aguardando');
        $ocorrencia = $this->registrar($a, '102', 'Quitou ontem', $this->primario());
        if ('aguardando' !== $pauta) {
            $primeira = $a->getPautas()->first();
            $primeira->abrir('Mesa', 1, null);
            if ('encerrada' === $pauta) {
                $primeira->encerrar('Mesa', 'Fim');
            }
            $this->em->flush();
        }

        return [$a, $ocorrencia];
    }

    private function registrar(Assembleia $a, string $identificacao, string $texto, User $ator, ?\DateTimeImmutable $agora = null): OcorrenciaDaUnidade
    {
        return static::getContainer()->get(RegistrarOcorrenciaUseCase::class)->executar(
            $this->escritorio,
            (int) $a->getId(),
            (int) $this->unidadeDe($a, $identificacao)->getId(),
            $texto,
            $ator,
            (string) $ator->getFullName(),
            $agora,
        );
    }

    private function decidir(Assembleia $a, OcorrenciaDaUnidade $ocorrencia, AvaliacaoDaOcorrencia $decisao, string $justificativa, User $ator, ?\DateTimeImmutable $agora = null): OcorrenciaDaUnidade
    {
        return static::getContainer()->get(DecidirAvaliacaoUseCase::class)->executar(
            $this->escritorio,
            (int) $a->getId(),
            (int) $ocorrencia->getId(),
            $decisao,
            $justificativa,
            $ator,
            (string) $ator->getFullName(),
            $agora,
        );
    }

    /** @return list<array<string, mixed>> o `changes` das trilhas das ocorrências e decisões da assembleia, em ordem */
    private function trilhas(Assembleia $a): array
    {
        $linhas = $this->em->getConnection()->fetchFirstColumn(
            "SELECT changes FROM audit_log WHERE tenant_id = ? AND entity_id = ? AND action IN ('voto_ocorr', 'voto_avali') ORDER BY id",
            [$this->escritorio->getId(), (string) $a->getId()],
        );

        return array_map(static fn (string $json): array => json_decode($json, true, 512, \JSON_THROW_ON_ERROR), $linhas);
    }
}
