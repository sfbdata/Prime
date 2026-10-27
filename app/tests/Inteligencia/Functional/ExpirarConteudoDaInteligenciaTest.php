<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Command\ExpirarConteudoDaInteligenciaCommand;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\UseCase\ExpirarConteudoDaInteligenciaUseCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retenção de 12 meses da BlueJus IA contra o banco de verdade: some o conteúdo, ficam os números.
 */
#[CoversClass(ExpirarConteudoDaInteligenciaUseCase::class)]
#[CoversClass(AnaliseDeInteligenciaRepository::class)]
#[CoversClass(ExpirarConteudoDaInteligenciaCommand::class)]
final class ExpirarConteudoDaInteligenciaTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function envelhecer(AnaliseDeInteligencia $analise, string $quando): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE inteligencia_analise SET criada_em = ? WHERE id = ?',
            [(new \DateTimeImmutable($quando))->format('Y-m-d H:i:s'), (int) $analise->getId()],
        );
    }

    /** @return array<string, mixed> */
    private function linha(AnaliseDeInteligencia $analise): array
    {
        return $this->em()->getConnection()->fetchAssociative(
            'SELECT status, resumo, pontos, texto_bruto, texto_da_analise, excluida_em, tokens_entrada, modelo FROM inteligencia_analise WHERE id = ?',
            [(int) $analise->getId()],
        ) ?: [];
    }

    #[TestDox('análise terminada há mais de 12 meses perde o conteúdo e sai das telas; ficam modelo e tokens')]
    public function testExpiraConteudoAntigo(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $antiga = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($antiga, 'Resumo antigo.', [['tipo' => 'prazo', 'texto' => 'x']], null, 'Texto antigo.');
        $this->envelhecer($antiga, '-13 months');

        $resultado = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class)->executar(new \DateTimeImmutable());

        self::assertSame(1, $resultado['analises']);
        $linha = $this->linha($antiga);
        self::assertSame('concluida', $linha['status']);
        self::assertNull($linha['resumo']);
        self::assertNull($linha['pontos']);
        self::assertNull($linha['texto_bruto']);
        self::assertNull($linha['texto_da_analise']);
        self::assertNotNull($linha['excluida_em'], 'sai das telas');
        self::assertSame(100, (int) $linha['tokens_entrada'], 'o custo continua contável');
        self::assertNotNull($linha['modelo']);
    }

    #[TestDox('análise recente e análise antiga ainda em andamento não são tocadas')]
    public function testNaoTocaRecenteNemEmAndamento(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $recente = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($recente, 'Resumo recente.');
        $this->envelhecer($recente, '-11 months');
        $pendente = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->envelhecer($pendente, '-13 months');

        $resultado = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class)->executar(new \DateTimeImmutable());

        self::assertSame(0, $resultado['analises']);
        self::assertSame('Resumo recente.', $this->linha($recente)['resumo']);
        self::assertNull($this->linha($recente)['excluida_em']);
        self::assertSame('pendente', $this->linha($pendente)['status']);
        self::assertNull($this->linha($pendente)['excluida_em']);
    }

    #[TestDox('a pergunta da conversa antiga perde o texto; rodar de novo não conta nada')]
    public function testPerguntaAntigaEIdempotente(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $pergunta = $this->criarPergunta($tenant, $user, $pasta, 'Qual o prazo do recurso?');
        $this->concluirAnalise($pergunta->getAnalise(), 'Resposta.');
        $this->envelhecer($pergunta->getAnalise(), '-2 years');
        $usecase = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class);

        $primeira = $usecase->executar(new \DateTimeImmutable());
        $segunda = $usecase->executar(new \DateTimeImmutable());

        self::assertSame(['analises' => 1, 'perguntas' => 1], ['analises' => $primeira['analises'], 'perguntas' => $primeira['perguntas']]);
        self::assertSame(['analises' => 0, 'perguntas' => 0], ['analises' => $segunda['analises'], 'perguntas' => $segunda['perguntas']]);
        self::assertSame(0, $this->contarNoBanco("SELECT COUNT(*) FROM inteligencia_pergunta WHERE texto <> ''"));
    }

    #[TestDox('o prazo escolhido pelo Master manda: com 3 meses, a análise de 4 meses atrás expira')]
    public function testPrazoDoMaster(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise, 'Resumo de quatro meses.');
        $this->envelhecer($analise, '-4 months');
        $plataforma = static::getContainer()->get(ConfiguracaoDaPlataformaDeIaRepository::class);
        $configuracao = $plataforma->obterParaAlterar();
        $configuracao->definirRetencao(3, $user, new \DateTimeImmutable());
        $plataforma->salvar($configuracao, flush: true);

        $resultado = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class)->executar(new \DateTimeImmutable());

        self::assertSame(3, $resultado['meses']);
        self::assertSame(1, $resultado['analises']);
        self::assertNull($this->linha($analise)['resumo']);
    }

    #[TestDox('o comando recusa sem --force e apaga com --force')]
    public function testComandoExigeForce(): void
    {
        $kernel = self::bootKernel();
        $comando = (new Application($kernel))->find('app:inteligencia:expirar-conteudo');

        $semForce = new CommandTester($comando);
        self::assertSame(1, $semForce->execute([]));

        $comForce = new CommandTester($comando);
        self::assertSame(0, $comForce->execute(['--force' => true]));
        self::assertStringContainsString('Conteúdo apagado', $comForce->getDisplay());
    }
}
