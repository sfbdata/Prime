<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Service\ConversaDaIaDaCentral;
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
#[CoversClass(ConversaDaIaDaCentral::class)]
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

    /**
     * @return array{ia: list<Mensagem>, individual: Mensagem, grupo: Mensagem}
     *             IA: [pergunta antiga, resposta antiga, mensagem nova]; as antigas têm 13 meses
     */
    private function montarMensagens(): array
    {
        [$admin, $tenant] = $this->criarAdmin();
        $outro = $this->criarUsuarioComPermissoes($tenant, [], 'outro');
        $agora = new \DateTimeImmutable();
        $conversaIa = Conversa::comAIa($tenant, $admin, $agora);
        $individual = Conversa::individual($tenant, $admin, $outro, $agora);
        $grupo = Conversa::grupo($tenant, $admin, 'Equipe', $agora);
        foreach ([$conversaIa, $individual, $grupo] as $conversa) {
            $this->em()->persist($conversa);
            $this->em()->persist(new Participante($tenant, $conversa, $admin, $agora));
        }
        $mensagens = [
            'pergunta' => new Mensagem($tenant, $conversaIa, $admin, 'SEGREDO-PERGUNTA', $agora),
            'resposta' => Mensagem::daIA($tenant, $conversaIa, $admin, 'SEGREDO-RESPOSTA', $agora),
            'nova' => new Mensagem($tenant, $conversaIa, $admin, 'SEGREDO-NOVA', $agora),
            'individual' => new Mensagem($tenant, $individual, $admin, 'Oi, colega', $agora),
            'grupo' => new Mensagem($tenant, $grupo, $admin, 'Bom dia, grupo', $agora),
        ];
        foreach ($mensagens as $mensagem) {
            $this->em()->persist($mensagem);
        }
        $this->em()->flush();
        foreach (['pergunta', 'resposta', 'individual', 'grupo'] as $antiga) {
            $this->em()->getConnection()->executeStatement(
                'UPDATE central_mensagem SET criada_em = ? WHERE id = ?',
                [(new \DateTimeImmutable('-13 months'))->format('Y-m-d H:i:s'), (int) $mensagens[$antiga]->getId()],
            );
        }

        return ['ia' => [$mensagens['pergunta'], $mensagens['resposta'], $mensagens['nova']], 'individual' => $mensagens['individual'], 'grupo' => $mensagens['grupo']];
    }

    private function existeMensagem(Mensagem $mensagem): bool
    {
        return $this->contarNoBanco('SELECT COUNT(*) FROM central_mensagem WHERE id = ?', [(int) $mensagem->getId()]) === 1;
    }

    #[TestDox('a retenção apaga as DUAS pontas do chat com a IA (pergunta e resposta) e preserva a mais nova, a individual e o grupo')]
    public function testExpiraMensagensDaIa(): void
    {
        self::bootKernel();
        $m = $this->montarMensagens();
        $usecase = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class);

        $primeira = $usecase->executar(new \DateTimeImmutable());
        $segunda = $usecase->executar(new \DateTimeImmutable());

        self::assertSame(2, $primeira['mensagens']);
        self::assertSame(0, $segunda['mensagens'], 'idempotente');
        self::assertFalse($this->existeMensagem($m['ia'][0]), 'pergunta');
        self::assertFalse($this->existeMensagem($m['ia'][1]), 'resposta');
        self::assertTrue($this->existeMensagem($m['ia'][2]), 'a mais nova fica');
        self::assertTrue($this->existeMensagem($m['individual']));
        self::assertTrue($this->existeMensagem($m['grupo']));
    }

    #[TestDox('o prazo do Master vale para as mensagens: com 3 meses, a de 4 meses atrás sai')]
    public function testPrazoDoMasterNasMensagens(): void
    {
        self::bootKernel();
        $m = $this->montarMensagens();
        $this->em()->getConnection()->executeStatement('UPDATE central_mensagem SET criada_em = ? WHERE id = ?', [(new \DateTimeImmutable('-4 months'))->format('Y-m-d H:i:s'), (int) $m['ia'][2]->getId()]);
        $plataforma = static::getContainer()->get(ConfiguracaoDaPlataformaDeIaRepository::class);
        $configuracao = $plataforma->obterParaAlterar();
        $configuracao->definirRetencao(3, $this->em()->find(\App\Entity\Auth\User::class, (int) $m['ia'][0]->getAutor()->getId()), new \DateTimeImmutable());
        $plataforma->salvar($configuracao, flush: true);

        $resultado = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class)->executar(new \DateTimeImmutable());

        self::assertSame(3, $resultado['mensagens']);
        self::assertFalse($this->existeMensagem($m['ia'][2]));
        self::assertTrue($this->existeMensagem($m['grupo']));
    }

    #[TestDox('o comando imprime e registra só números, nunca o texto das mensagens apagadas')]
    public function testComandoSoNumeros(): void
    {
        $kernel = self::bootKernel();
        $this->montarMensagens();
        $comando = new CommandTester((new Application($kernel))->find('app:inteligencia:expirar-conteudo'));

        self::assertSame(0, $comando->execute(['--force' => true]));

        $saida = (string) preg_replace('/\s+/', ' ', $comando->getDisplay());
        self::assertMatchesRegularExpression('/2 mensagem\(ns\) do chat/', $saida);
        self::assertStringNotContainsString('SEGREDO', $saida);
    }
}
