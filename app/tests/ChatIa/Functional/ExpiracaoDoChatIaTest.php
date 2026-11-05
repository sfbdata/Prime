<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\ExpiracaoDoChatIa;
use App\ChatIa\UseCase\ImportarHistoricoDaCentralUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Command\ExpirarConteudoDaInteligenciaCommand;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\UseCase\ExpirarConteudoDaInteligenciaUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retenção do Chat I.A (auditoria 1.19) contra o banco de verdade, no console (sem o TenantFilter): as falas de
 * `chat_ia_mensagem` mais antigas que o prazo do Master saem na rotina diária, com os anexos (linha e arquivo) e a
 * conversa que ficou sem mensagem nenhuma. Vale para todos os escritórios na mesma rodada e para a cópia que a
 * importação trouxe da Central com a data original.
 */
#[CoversClass(ExpiracaoDoChatIa::class)]
#[CoversClass(MensagemDeChatRepository::class)]
#[CoversClass(AnexoDeChatRepository::class)]
#[CoversClass(ConversaDeChatRepository::class)]
#[CoversClass(ExpirarConteudoDaInteligenciaUseCase::class)]
#[CoversClass(ExpirarConteudoDaInteligenciaCommand::class)]
final class ExpiracaoDoChatIaTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    /** @var list<ChaveDeArquivo> arquivos que o teste gravou e que podem ter ficado (limpos no fim) */
    private array $gravados = [];

    protected function tearDown(): void
    {
        if ($this->gravados !== []) {
            $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
            foreach ($this->gravados as $chave) {
                if ($armazenamento->existe($chave)) {
                    $armazenamento->excluir($chave);
                }
            }
        }
        parent::tearDown();
    }

    private function armazenamento(): ArmazenamentoDeArquivos
    {
        return static::getContainer()->get(ArmazenamentoDeArquivos::class);
    }

    private function conversa(Tenant $tenant, User $dono, string $criada = 'now'): ConversaDeChat
    {
        $conversa = new ConversaDeChat($tenant, $dono, ModeloDeIa::PADRAO, new \DateTimeImmutable($criada));
        $this->em()->persist($conversa);
        $this->em()->flush();

        return $conversa;
    }

    private function pergunta(ConversaDeChat $conversa, string $quando, string $texto = 'SEGREDO-PERGUNTA'): MensagemDeChat
    {
        $mensagem = MensagemDeChat::doUsuario($conversa, $texto, null, new \DateTimeImmutable($quando));
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $mensagem;
    }

    private function resposta(ConversaDeChat $conversa, string $quando): MensagemDeChat
    {
        $mensagem = MensagemDeChat::doAssistente($conversa, 'SEGREDO-RESPOSTA', 'claude-haiku-5-5', 10, 20, false, new \DateTimeImmutable($quando));
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $mensagem;
    }

    /** @return array{AnexoDeChat, ChaveDeArquivo} o anexo (amarrado à `$mensagem`, ou solto) com o arquivo gravado */
    private function anexo(ConversaDeChat $conversa, User $dono, ?MensagemDeChat $mensagem, string $quando): array
    {
        $armazenado = $this->armazenamento()->gravar(ChavesDeChatIa::novoAnexo($conversa, 'png'), FonteDeConteudo::deTexto('conteudo'));
        $anexo = new AnexoDeChat($conversa->getTenant() ?? throw new \LogicException(), $dono, $conversa, $armazenado->chave->nome, 'foto.png', 'image/png', 8, new \DateTimeImmutable($quando));
        if ($mensagem !== null) {
            $anexo->anexarA($mensagem);
        }
        $this->em()->persist($anexo);
        $this->em()->flush();
        $chave = ChavesDeChatIa::anexo($anexo);
        $this->gravados[] = $chave;

        return [$anexo, $chave];
    }

    private function existe(string $tabela, ?int $id): bool
    {
        return $this->contarNoBanco("SELECT COUNT(*) FROM $tabela WHERE id = ?", [(int) $id]) === 1;
    }

    /**
     * Dois escritórios. A: conversa mista (pergunta e resposta antigas, com anexo, e uma pergunta recente), conversa só
     * antiga (com um anexo solto) e conversa nova sem fala. B: conversa só antiga e conversa recente.
     *
     * @return array<string, mixed>
     */
    private function montar(): array
    {
        [$donoA, $tenantA] = $this->criarAdmin();
        [$donoB, $tenantB] = $this->criarAdmin();

        $mista = $this->conversa($tenantA, $donoA, '-14 months');
        $perguntaAntiga = $this->pergunta($mista, '-13 months');
        $respostaAntiga = $this->resposta($mista, '-13 months');
        [$anexoDaFala, $chaveDaFala] = $this->anexo($mista, $donoA, $perguntaAntiga, '-13 months');
        $perguntaRecente = $this->pergunta($mista, '-1 month');
        [$anexoRecente, $chaveRecente] = $this->anexo($mista, $donoA, $perguntaRecente, '-1 month');

        $soAntigaA = $this->conversa($tenantA, $donoA, '-14 months');
        $this->pergunta($soAntigaA, '-13 months');
        $this->resposta($soAntigaA, '-13 months');
        [$anexoSolto, $chaveSolta] = $this->anexo($soAntigaA, $donoA, null, '-13 months');

        $novaSemFala = $this->conversa($tenantA, $donoA);

        $soAntigaB = $this->conversa($tenantB, $donoB, '-2 years');
        $this->pergunta($soAntigaB, '-2 years');
        $recenteB = $this->conversa($tenantB, $donoB, '-11 months');
        $falaRecenteB = $this->pergunta($recenteB, '-11 months');

        return compact(
            'mista',
            'perguntaAntiga',
            'respostaAntiga',
            'anexoDaFala',
            'chaveDaFala',
            'perguntaRecente',
            'anexoRecente',
            'chaveRecente',
            'soAntigaA',
            'anexoSolto',
            'chaveSolta',
            'novaSemFala',
            'soAntigaB',
            'recenteB',
            'falaRecenteB',
        );
    }

    #[TestDox('a fala antes do limite sai e a de depois fica, nos dois escritórios na mesma rodada, sem o TenantFilter')]
    public function testApagaAsFalasAntigasDeTodosOsEscritorios(): void
    {
        self::bootKernel();
        $c = $this->montar();
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'a rotina roda no console, com o filtro desligado');

        $resultado = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class)->executar(new \DateTimeImmutable());

        self::assertSame(5, $resultado['mensagensDoChatIa'], '2 da mista, 2 da só antiga de A e 1 de B');
        self::assertFalse($this->existe('chat_ia_mensagem', $c['perguntaAntiga']->getId()));
        self::assertFalse($this->existe('chat_ia_mensagem', $c['respostaAntiga']->getId()));
        self::assertTrue($this->existe('chat_ia_mensagem', $c['perguntaRecente']->getId()), 'a fala depois do limite fica');
        self::assertTrue($this->existe('chat_ia_mensagem', $c['falaRecenteB']->getId()), 'a fala de 11 meses de B fica');
        self::assertSame(0, $this->contarNoBanco('SELECT COUNT(*) FROM chat_ia_mensagem WHERE conversa_id = ?', [(int) $c['soAntigaB']->getId()]), 'o escritório B também foi varrido');
    }

    #[TestDox('o anexo da fala antiga sai do banco e do armazenamento; o anexo da fala recente fica com o arquivo')]
    public function testAnexosDaFalaAntigaSaemComOArquivo(): void
    {
        self::bootKernel();
        $c = $this->montar();
        self::assertTrue($this->armazenamento()->existe($c['chaveDaFala']));

        $resultado = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class)->executar(new \DateTimeImmutable());

        self::assertSame(2, $resultado['anexosDoChatIa'], 'o da fala antiga e o solto da conversa que ficou vazia');
        self::assertFalse($this->existe('chat_ia_anexo', $c['anexoDaFala']->getId()));
        self::assertFalse($this->armazenamento()->existe($c['chaveDaFala']), 'o arquivo do anexo antigo saiu');
        self::assertFalse($this->existe('chat_ia_anexo', $c['anexoSolto']->getId()));
        self::assertFalse($this->armazenamento()->existe($c['chaveSolta']), 'o arquivo do solto da conversa removida saiu');
        self::assertTrue($this->existe('chat_ia_anexo', $c['anexoRecente']->getId()));
        self::assertTrue($this->armazenamento()->existe($c['chaveRecente']), 'o arquivo do anexo recente fica');
    }

    #[TestDox('a conversa que ficou sem fala é removida; a que ainda tem fala e a nova que nunca teve fala ficam; rodar de novo não conta nada')]
    public function testConversaQueFicouVaziaERemovida(): void
    {
        self::bootKernel();
        $c = $this->montar();
        $usecase = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class);

        $primeira = $usecase->executar(new \DateTimeImmutable());
        $segunda = $usecase->executar(new \DateTimeImmutable());

        self::assertSame(2, $primeira['conversasDoChatIa'], 'a só antiga de A e a só antiga de B');
        self::assertFalse($this->existe('chat_ia_conversa', $c['soAntigaA']->getId()));
        self::assertFalse($this->existe('chat_ia_conversa', $c['soAntigaB']->getId()));
        self::assertTrue($this->existe('chat_ia_conversa', $c['mista']->getId()), 'ainda tem a pergunta recente');
        self::assertTrue($this->existe('chat_ia_conversa', $c['recenteB']->getId()));
        self::assertTrue($this->existe('chat_ia_conversa', $c['novaSemFala']->getId()), 'conversa nova sem fala não é tocada');
        self::assertSame(
            ['mensagensDoChatIa' => 0, 'anexosDoChatIa' => 0, 'conversasDoChatIa' => 0],
            ['mensagensDoChatIa' => $segunda['mensagensDoChatIa'], 'anexosDoChatIa' => $segunda['anexosDoChatIa'], 'conversasDoChatIa' => $segunda['conversasDoChatIa']],
            'idempotente',
        );
    }

    #[TestDox('a cópia que a importação trouxe da Central com a data original sai na mesma rodada que o original')]
    public function testCopiaImportadaDaCentralSaiJunto(): void
    {
        self::bootKernel();
        [$dono, $tenant] = $this->criarAdmin();
        $antes = new \DateTimeImmutable('-13 months');
        $daCentral = Conversa::comAIa($tenant, $dono, $antes);
        $this->em()->persist($daCentral);
        $this->em()->persist(new Participante($tenant, $daCentral, $dono, $antes));
        $this->em()->flush();
        $this->em()->persist(new Mensagem($tenant, $daCentral, $dono, 'SEGREDO-PERGUNTA-ANTIGA', $antes));
        $this->em()->persist(Mensagem::daIA($tenant, $daCentral, $dono, 'SEGREDO-RESPOSTA-ANTIGA', $antes->modify('+1 minute')));
        $this->em()->persist(new Mensagem($tenant, $daCentral, $dono, 'Pergunta recente', new \DateTimeImmutable('-1 month')));
        $this->em()->flush();
        $importada = static::getContainer()->get(ImportarHistoricoDaCentralUseCase::class)->executar($dono, $tenant);
        self::assertNotNull($importada);
        self::assertSame(3, $this->contarNoBanco('SELECT COUNT(*) FROM chat_ia_mensagem WHERE conversa_id = ?', [$importada]));

        $resultado = static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class)->executar(new \DateTimeImmutable());

        self::assertSame(2, $resultado['mensagens'], 'o original na Central');
        self::assertSame(2, $resultado['mensagensDoChatIa'], 'a cópia no Chat I.A');
        self::assertSame(0, $this->contarNoBanco("SELECT COUNT(*) FROM chat_ia_mensagem WHERE conteudo LIKE 'SEGREDO%'"));
        self::assertSame(1, $this->contarNoBanco('SELECT COUNT(*) FROM chat_ia_mensagem WHERE conversa_id = ?', [$importada]), 'a fala recente importada fica');
        self::assertTrue($this->existe('chat_ia_conversa', $importada));
    }

    #[TestDox('a saída do comando e o log da Inteligência mostram quantas falas do Chat I.A saíram, sem texto nem travessão')]
    public function testComandoELogMostramAsFalasDoChatIa(): void
    {
        self::bootKernel();
        $this->montar();
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $registros = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->registros[] = [(string) $message, $context];
            }
        };
        $comando = new CommandTester(new ExpirarConteudoDaInteligenciaCommand(
            static::getContainer()->get(ExpirarConteudoDaInteligenciaUseCase::class),
            $logger,
        ));

        self::assertSame(0, $comando->execute(['--force' => true]));

        $saida = (string) preg_replace('/\s+/', ' ', $comando->getDisplay());
        self::assertMatchesRegularExpression('/5 mensagem\(ns\) do Chat I\.A/', $saida);
        self::assertMatchesRegularExpression('/2 anexo\(s\)/', $saida);
        self::assertMatchesRegularExpression('/2 conversa\(s\)/', $saida);
        self::assertStringNotContainsString('SEGREDO', $saida);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $saida);

        self::assertCount(1, $logger->registros);
        [$mensagem, $contexto] = $logger->registros[0];
        self::assertStringContainsString('{mensagensDoChatIa} mensagem(ns) do Chat I.A', $mensagem);
        self::assertSame(5, $contexto['mensagensDoChatIa']);
        self::assertSame(2, $contexto['anexosDoChatIa']);
        self::assertSame(2, $contexto['conversasDoChatIa']);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $mensagem);
        self::assertStringNotContainsString('SEGREDO', $mensagem . json_encode($contexto));
    }
}
