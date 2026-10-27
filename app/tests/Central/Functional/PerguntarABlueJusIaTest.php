<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\Conversa;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\UseCase\AbrirConversaComAIaUseCase;
use App\Central\UseCase\PerguntarABlueJusIaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A pergunta ao BlueJus IA na Central de ponta a ponta (F3, PR 2b): UseCase, fila em memória, handler do worker e
 * ProvedorFalso, contra o banco de verdade e SEM TenantFilter (como o worker).
 */
#[CoversClass(PerguntarABlueJusIaUseCase::class)]
final class PerguntarABlueJusIaTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function usecase(): PerguntarABlueJusIaUseCase
    {
        // Montado à mão, com a conexão e o relógio do teste.
        $c = static::getContainer();
        $acesso = $c->get(AcessoACentral::class);
        $conversas = $c->get(ConversaRepository::class);
        $relogio = $c->get(ClockInterface::class);

        return new PerguntarABlueJusIaUseCase(
            $acesso,
            $conversas,
            $c->get(MensagemRepository::class),
            $c->get(AnaliseDeInteligenciaRepository::class),
            new AbrirConversaComAIaUseCase($acesso, $conversas, $c->get(ParticipanteRepository::class), $this->em(), $relogio),
            $c->get(DisponibilidadeDeInteligencia::class),
            $c->get(EnfileiradorDeAnalise::class),
            $this->em(),
            $relogio,
        );
    }

    private static function resposta(string $texto): string
    {
        return (string) json_encode(['resposta' => $texto], JSON_UNESCAPED_UNICODE);
    }

    /** @return array{0: User, 1: Tenant} */
    private function cenario(int $limiteDiario = 50): array
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $filtros = $this->em()->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin, $limiteDiario);

        return [$this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana'), $tenant];
    }

    /** Roda no worker a(s) mensagem(ns) que o UseCase pôs na fila. */
    private function drenarFila(): void
    {
        $transporte = $this->transporteAsync();
        $handler = static::getContainer()->get(ProcessarAnaliseDeInteligenciaHandler::class);
        foreach ($transporte->getSent() as $envelope) {
            $mensagem = $envelope->getMessage();
            self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $mensagem);
            $handler($mensagem);
        }
        $transporte->reset();
    }

    /**
     * Por SQL cru: ler pelo ORM exigiria limpar o EntityManager e soltaria o escritório e a pessoa do cenário.
     *
     * @return list<array{id: int, conteudo: string, de_ia: bool, autor_id: int}>
     */
    private function mensagensDa(int $conversaId): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative('SELECT id, conteudo, de_ia, autor_id FROM central_mensagem WHERE conversa_id = ? ORDER BY id', [$conversaId]);

        return array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'conteudo' => (string) $l['conteudo'], 'de_ia' => (bool) $l['de_ia'], 'autor_id' => (int) $l['autor_id']], $linhas);
    }

    private function statusDa(int $analiseId): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM inteligencia_analise WHERE id = ?', [$analiseId]);
    }

    #[TestDox('ponta a ponta: a pergunta vira mensagem da pessoa, o worker responde e a fala da IA entra na MESMA conversa')]
    public function testPontaAPonta(): void
    {
        [$ana, $tenant] = $this->cenario();
        $this->provedorFalso()->responderCom(self::resposta('A réplica tem 15 dias.'));

        $saida = $this->usecase()->executar($ana, $tenant, 'Qual o prazo da réplica?');

        self::assertFalse($saida->emAndamento);
        self::assertSame('pendente', $saida->status);
        self::assertCount(1, $this->transporteAsync()->getSent(), 'uma mensagem na fila');
        self::assertCount(1, $this->mensagensDa($saida->conversaId), 'a fala da IA ainda não existe');

        $this->drenarFila();

        self::assertSame('concluida', $this->statusDa($saida->analiseId));
        $mensagens = $this->mensagensDa($saida->conversaId);
        self::assertCount(2, $mensagens);
        self::assertFalse($mensagens[0]['de_ia']);
        self::assertSame('Qual o prazo da réplica?', $mensagens[0]['conteudo']);
        self::assertSame($saida->mensagemId, $mensagens[0]['id']);
        self::assertTrue($mensagens[1]['de_ia']);
        self::assertSame('A réplica tem 15 dias.', $mensagens[1]['conteudo']);
        self::assertSame((int) $ana->getId(), $mensagens[1]['autor_id']);
        self::assertCount(1, $this->provedorFalso()->pedidos);

        $linha = $this->em()->getConnection()->fetchAssociative('SELECT tipo, alvo_tipo, alvo_id, contexto_resumo FROM inteligencia_analise WHERE id = ?', [$saida->analiseId]);
        self::assertSame('chat_central', $linha['tipo']);
        self::assertSame(AnaliseDeInteligencia::ALVO_CONVERSA_IA, $linha['alvo_tipo']);
        self::assertSame($saida->conversaId, (int) $linha['alvo_id']);
        self::assertSame($saida->mensagemId, json_decode($linha['contexto_resumo'], true)['mensagemId']);
        self::assertStringNotContainsString('réplica', $linha['contexto_resumo'], 'o texto nunca vai para a análise');
    }

    #[TestDox('pergunta presa: com 9 minutos ainda é a mesma em andamento; com 11 a próxima pergunta a encerra como falhou e cria uma nova (no banco)')]
    public function testPerguntaPresaPorIdade(): void
    {
        [$ana, $tenant] = $this->cenario();
        $primeira = $this->usecase()->executar($ana, $tenant, 'PRESA');
        $velha = $this->em()->getRepository(AnaliseDeInteligencia::class)->find($primeira->analiseId);
        self::assertNotNull($velha);
        $envelhecer = function (string $idade) use ($velha): void {
            (new \ReflectionProperty(AnaliseDeInteligencia::class, 'criadaEm'))->setValue($velha, new \DateTimeImmutable($idade));
            $this->em()->flush();
        };

        $envelhecer('-9 minutes');
        $mesma = $this->usecase()->executar($ana, $tenant, 'NAO-ENTRA');
        self::assertTrue($mesma->emAndamento);
        self::assertSame($primeira->analiseId, $mesma->analiseId);

        $envelhecer('-11 minutes');
        $nova = $this->usecase()->executar($ana, $tenant, 'ENTRA');

        self::assertFalse($nova->emAndamento);
        self::assertNotSame($primeira->analiseId, $nova->analiseId);
        self::assertSame('falhou', $this->statusDa($primeira->analiseId));
        self::assertSame(PerguntarABlueJusIaUseCase::MOTIVO_PRESA, $this->em()->getConnection()->fetchOne('SELECT erro_motivo FROM inteligencia_analise WHERE id = ?', [$primeira->analiseId]));
        self::assertSame('pendente', $this->statusDa($nova->analiseId));
        self::assertSame(['PRESA', 'ENTRA'], array_column($this->mensagensDa($nova->conversaId), 'conteudo'));
    }

    #[TestDox('duas perguntas seguidas: a segunda, ainda com a primeira em andamento, devolve a mesma; depois de concluir, a segunda entra com o histórico da primeira troca e sem a própria pergunta')]
    public function testDuasPerguntasEHistorico(): void
    {
        [$ana, $tenant] = $this->cenario();
        $this->provedorFalso()->responderCom(self::resposta('Resposta UM.'));
        $this->provedorFalso()->responderCom(self::resposta('Resposta DOIS.'));
        $chamadas = 0;
        $limitador = static function () use (&$chamadas): void {
            ++$chamadas;
        };

        $primeira = $this->usecase()->executar($ana, $tenant, 'PERGUNTA-UM', $limitador);
        $repetida = $this->usecase()->executar($ana, $tenant, 'PERGUNTA-DOIS-ANTECIPADA', $limitador);

        self::assertTrue($repetida->emAndamento);
        self::assertSame($primeira->analiseId, $repetida->analiseId);
        self::assertSame($primeira->mensagemId, $repetida->mensagemId);
        self::assertSame(1, $chamadas, 'o limitador só roda quando cria');
        self::assertCount(1, $this->mensagensDa($primeira->conversaId), 'sem mensagem nova');
        self::assertCount(1, $this->transporteAsync()->getSent());

        $this->drenarFila();
        $segunda = $this->usecase()->executar($ana, $tenant, 'PERGUNTA-DOIS', $limitador);
        $this->drenarFila();

        self::assertFalse($segunda->emAndamento);
        self::assertNotSame($primeira->analiseId, $segunda->analiseId);
        self::assertSame($primeira->conversaId, $segunda->conversaId);
        self::assertSame(2, $chamadas);
        self::assertSame('concluida', $this->statusDa($segunda->analiseId));
        self::assertSame(['PERGUNTA-UM', 'Resposta UM.', 'PERGUNTA-DOIS', 'Resposta DOIS.'], array_column($this->mensagensDa($primeira->conversaId), 'conteudo'));

        $pedido = $this->provedorFalso()->ultimoPedido()?->textoDoUsuario() ?? '';
        self::assertStringContainsString('PERGUNTA-UM', $pedido);
        self::assertStringContainsString('Resposta UM.', $pedido);
        self::assertSame(1, substr_count($pedido, 'PERGUNTA-DOIS'), 'a pergunta atual só em <pergunta>, não no histórico');
        self::assertStringNotContainsString('PERGUNTA-DOIS-ANTECIPADA', $pedido);
        self::assertStringContainsString("<pergunta>\nPERGUNTA-DOIS\n</pergunta>", $pedido);
    }

    #[TestDox('usuário de outro escritório e outro usuário do mesmo escritório não tocam a conversa alheia')]
    public function testIsolamento(): void
    {
        [$ana, $tenantA] = $this->cenario();
        [$bia, $tenantB] = $this->cenario();
        $bruno = $this->criarUsuarioComPermissoes($tenantA, ['modules.inteligencia.view'], 'bruno');

        $daAna = $this->usecase()->executar($ana, $tenantA, 'Pergunta da Ana');
        $daBia = $this->usecase()->executar($bia, $tenantB, 'Pergunta da Bia');
        $doBruno = $this->usecase()->executar($bruno, $tenantA, 'Pergunta do Bruno');

        self::assertCount(3, array_unique([$daAna->conversaId, $daBia->conversaId, $doBruno->conversaId]));
        foreach ([$daAna, $daBia, $doBruno] as $saida) {
            self::assertFalse($saida->emAndamento, 'cada pessoa tem a sua pergunta em andamento');
            self::assertCount(1, $this->mensagensDa($saida->conversaId));
        }
        self::assertSame('Pergunta da Ana', $this->mensagensDa($daAna->conversaId)[0]['conteudo']);

        // Quem não tem vínculo com o escritório pedido não alcança nada dele.
        try {
            $this->usecase()->executar($bia, $tenantA, 'Invasão');
            self::fail('Devia recusar.');
        } catch (SemAcessoACentralException) {
            self::assertCount(1, $this->mensagensDa($daAna->conversaId));
        }
        self::assertSame(3, $this->contarNoBanco("SELECT COUNT(*) FROM central_mensagem WHERE de_ia = false"));
    }

    #[TestDox('a cota conta a pergunta: com limite de 1 por dia, a segunda é recusada e nada é gravado')]
    public function testCotaConta(): void
    {
        [$ana, $tenant] = $this->cenario(limiteDiario: 1);
        $this->provedorFalso()->responderCom(self::resposta('Uma só.'));

        $primeira = $this->usecase()->executar($ana, $tenant, 'Primeira');
        $this->drenarFila();

        try {
            $this->usecase()->executar($ana, $tenant, 'Segunda');
            self::fail('Devia recusar pela cota.');
        } catch (InteligenciaIndisponivelException) {
            self::assertCount(2, $this->mensagensDa($primeira->conversaId), 'pergunta e resposta; a segunda não gravou');
            self::assertSame([], $this->transporteAsync()->getSent());
        }
    }

    #[TestDox('limitador recusando: nada gravado, e o EntityManager segue aberto para a próxima')]
    public function testLimitadorRecusa(): void
    {
        [$ana, $tenant] = $this->cenario();

        try {
            $this->usecase()->executar($ana, $tenant, 'Oi', static function (): void {
                throw new MuitasSolicitacoesException('limite');
            });
            self::fail('Devia recusar.');
        } catch (MuitasSolicitacoesException) {
            self::assertSame(0, $this->contarNoBanco('SELECT COUNT(*) FROM central_mensagem'));
            self::assertSame(0, $this->contarNoBanco('SELECT COUNT(*) FROM inteligencia_analise WHERE tipo = ?', ['chat_central']));
            self::assertSame([], $this->transporteAsync()->getSent());
        }

        $depois = $this->usecase()->executar($ana, $tenant, 'Agora vai');
        self::assertFalse($depois->emAndamento);
        self::assertSame(1, $this->contarNoBanco('SELECT COUNT(*) FROM central_mensagem'));
    }

    #[TestDox('uma conversa IA por pessoa: perguntas seguidas reusam a mesma e não criam conversa individual')]
    public function testUmaConversaIaPorPessoa(): void
    {
        [$ana, $tenant] = $this->cenario();
        $this->provedorFalso()->responderCom(self::resposta('Ok.'));

        $a = $this->usecase()->executar($ana, $tenant, 'Um');
        $this->drenarFila();
        $b = $this->usecase()->executar($ana, $tenant, 'Dois');

        self::assertSame($a->conversaId, $b->conversaId);
        self::assertSame(1, $this->contarNoBanco('SELECT COUNT(*) FROM central_conversa WHERE tipo = ?', ['ia']));
        $conversa = static::getContainer()->get(ConversaRepository::class)->daIaDe($tenant, $ana);
        self::assertInstanceOf(Conversa::class, $conversa);
        self::assertSame($a->conversaId, $conversa->getId());
    }
}
