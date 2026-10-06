<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Notificacao;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDePush;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Service\NotificacaoService;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * O worker da BlueJus IA contra o banco de verdade (sem TenantFilter, como em produção). O
 * provedor é sempre explícito aqui — `ProvedorNaoConfigurado` ou um `ProvedorFalso` novo — para
 * cada caso dizer exatamente o que o modelo "respondeu".
 */
#[CoversClass(ProcessarAnaliseDeInteligenciaHandler::class)]
final class ProcessarAnaliseDeInteligenciaHandlerTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function handler(ProvedorDeLinguagem $provedor, ?LoggerInterface $logger = null): ProcessarAnaliseDeInteligenciaHandler
    {
        $c = static::getContainer();

        return new ProcessarAnaliseDeInteligenciaHandler(
            $this->em(),
            $c->get(AnaliseDeInteligenciaRepository::class),
            $c->get(MontadorDeContextoDoPush::class),
            new PromptResumoDoPush(),
            $provedor,
            new InterpretadorDeRespostaDePush(),
            $c->get(NotificacaoService::class),
            $c->get('router'),
            $logger ?? new NullLogger(),
        );
    }

    private function mensagem(AnaliseDeInteligencia $analise, ?int $tenantId = null): ProcessarAnaliseDeInteligencia
    {
        return new ProcessarAnaliseDeInteligencia((int) $analise->getId(), $tenantId ?? (int) $analise->getTenant()?->getId());
    }

    private function reler(AnaliseDeInteligencia $analise): AnaliseDeInteligencia
    {
        $id = (int) $analise->getId();
        $this->em()->clear();

        $relida = $this->em()->find(AnaliseDeInteligencia::class, $id);
        self::assertNotNull($relida);

        return $relida;
    }

    #[TestDox('tenant da mensagem diferente do da linha → no-op com warning, status intacto')]
    public function testTenantDivergente(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [, $tenantB] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $logger = new LoggerEmMemoria();

        $this->handler($falso, $logger)($this->mensagem($analise, (int) $tenantB->getId()));

        self::assertSame(StatusDaAnalise::Pendente, $this->reler($analise)->getStatus());
        self::assertFalse($falso->foiChamado());
        self::assertCount(1, $logger->doNivel('warning'));
        self::assertStringContainsString('ignorada', $logger->doNivel('warning')[0]['mensagem']);
    }

    #[TestDox('ProvedorNaoConfigurado → indisponivel e UnrecoverableMessageHandlingException (sem retry)')]
    public function testProvedorNaoConfigurado(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);

        try {
            $this->handler(new ProvedorNaoConfigurado())($this->mensagem($analise));
            self::fail('deveria lançar Unrecoverable');
        } catch (UnrecoverableMessageHandlingException) {
        }

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Indisponivel, $relida->getStatus());
        self::assertSame('nao_configurado', $relida->getProvedor());
        self::assertStringContainsString('não configurado', (string) $relida->getErroMotivo());
        self::assertSame(1, $relida->getTentativas());
    }

    #[TestDox('ProvedorFalso com JSON válido → concluida com resumo, pontos, quem, tokens, modelo e notificação')]
    public function testConcluiComProvedorFalso(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, , $publicacao] = $this->criarPastaComPublicacao(
            $tenant,
            texto: 'Intimação do autor, CPF 123.456.789-09, para réplica em 15 dias.',
        );
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);

        $falso = new ProvedorFalso();
        $falso->responderComResumo(
            'Réplica aberta — prazo corre.',
            [
                ['tipo' => 'prazo', 'texto' => 'Réplica em 15 dias, termo inicial a conferir.'],
                ['tipo' => 'inventado', 'texto' => 'vira info'],
            ],
            'Dra. Ana',
        );

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Concluida, $relida->getStatus());
        self::assertSame('Réplica aberta, prazo corre.', $relida->getResumo(), 'travessão vira vírgula');
        self::assertSame('prazo', $relida->getPontos()[0]['tipo']);
        self::assertSame('info', $relida->getPontos()[1]['tipo']);
        self::assertSame('Dra. Ana', $relida->getQuemAge());
        self::assertSame(ProvedorFalso::NOME, $relida->getProvedor());
        self::assertSame(ProvedorFalso::MODELO, $relida->getModelo());
        self::assertSame(120, $relida->getTokensEntrada());
        self::assertSame(40, $relida->getTokensSaida());
        self::assertSame(15, $relida->getDuracaoMs());
        self::assertNotNull($relida->getConcluidaEm());
        self::assertStringContainsString('"resumo"', (string) $relida->getTextoBruto());
        self::assertSame(['pub:' . $publicacao->getId()], $relida->getChavesAnalisadas());
        self::assertSame(1, $relida->getContextoResumo()['total']);

        // O pedido que saiu: movimentação dentro da tag, PII mascarada, versão do prompt no rótulo.
        $pedido = $falso->ultimoPedido();
        self::assertNotNull($pedido);
        $texto = $pedido->textoDoUsuario();
        self::assertStringContainsString('<movimentacoes>', $texto);
        self::assertStringContainsString('para réplica em 15 dias', $texto);
        self::assertStringContainsString('[CPF]', $texto);
        self::assertStringNotContainsString('123.456.789-09', $texto, 'o CPF não pode sair do escritório');
        self::assertStringContainsString('push-v1', $pedido->rotuloDeUso);

        // Quem pediu é avisado no sino.
        $notificacoes = $this->em()->getRepository(Notificacao::class)->findBy([
            'usuario' => $user->getId(),
            'tipo' => ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO,
        ]);
        self::assertCount(1, $notificacoes);
        self::assertStringContainsString('#push', (string) $notificacoes[0]->getUrl());
    }

    #[TestDox('falha transitória do provedor → falhou + re-lança a própria exceção (o Messenger faz o retry)')]
    public function testFalhaTransitoria(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->falharTransitoriamente('timeout');

        try {
            $this->handler($falso)($this->mensagem($analise));
            self::fail('deveria re-lançar');
        } catch (FalhaDoProvedorException $e) {
            self::assertTrue($e->transitoria);
        }

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertStringContainsString('transitória', (string) $relida->getErroMotivo());
        self::assertSame(1, $relida->getTentativas());
    }

    #[TestDox('falha definitiva do provedor (401) → falhou + Unrecoverable (sem retry)')]
    public function testFalhaDefinitiva(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->falharDefinitivamente('chave recusada (401)');

        try {
            $this->handler($falso)($this->mensagem($analise));
            self::fail('deveria lançar Unrecoverable');
        } catch (UnrecoverableMessageHandlingException) {
        }

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertStringContainsString('definitiva', (string) $relida->getErroMotivo());
    }

    #[TestDox('retry depois de falha transitória: a análise volta a processando e conclui, com tentativas 2')]
    public function testRetryDepoisDeFalha(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->falharTransitoriamente();
        $falso->responderComResumo('Agora foi.');

        try {
            $this->handler($falso)($this->mensagem($analise));
        } catch (FalhaDoProvedorException) {
        }
        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Concluida, $relida->getStatus());
        self::assertSame('Agora foi.', $relida->getResumo());
        self::assertSame(2, $relida->getTentativas());
    }

    #[TestDox('resposta sem JSON → falhou com "resposta inválida" e o texto bruto guardado')]
    public function testRespostaInvalida(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->responderCom('Desculpe, não consigo ajudar com isso.');

        $this->handler($falso)($this->mensagem($analise)); // não re-lança

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertStringContainsString('resposta inválida', (string) $relida->getErroMotivo());
        self::assertSame('Desculpe, não consigo ajudar com isso.', $relida->getTextoBruto());
    }

    #[TestDox('processo com nivelSigilo > 0 → falhou "contexto bloqueado" e o provedor NÃO foi chamado')]
    public function testSigiloBloqueiaAntesDoProvedor(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $processo->setNivelSigilo(1);
        $this->em()->flush();
        $falso = new ProvedorFalso();
        $falso->responderComResumo('não deveria ser usado');

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertStringContainsString('contexto bloqueado', (string) $relida->getErroMotivo());
        self::assertFalse($falso->foiChamado(), 'conteúdo sigiloso nunca pode chegar ao provedor');
        self::assertNull($relida->getResumo());
    }

    #[TestDox('pasta sem movimentação → falhou "sem movimentações", provedor não chamado')]
    public function testSemMovimentacoes(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, '07088888888888888888'));
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertSame('sem movimentações', $relida->getErroMotivo());
        self::assertFalse($falso->foiChamado());
    }

    #[TestDox('análise já concluída (mensagem duplicada) → no-op, provedor não chamado')]
    public function testJaConcluidaEhNoOp(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise, 'Já estava pronta.');
        $falso = new ProvedorFalso();

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame('Já estava pronta.', $relida->getResumo());
        self::assertSame(1, $relida->getTentativas());
        self::assertFalse($falso->foiChamado());
    }

    #[TestDox('a segunda análise recebe a anterior como "ANÁLISE ANTERIOR" e marca [NOVA] só no que mudou')]
    public function testSegundaAnaliseUsaAAnterior(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo, $primeiraPub] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $anterior = $this->criarAnalisePendente($tenant, $user, $pasta, ['pub:' . $primeiraPub->getId()]);
        $this->concluirAnalise($anterior, 'A contestação foi recebida.');

        $novaPub = $this->criarPublicacao($tenant, $this->proximoDjenId(), '07011345720258070007', '2026-10-03', $processo);
        $novaPub->setTexto('Intime-se para réplica.');
        $this->em()->flush();

        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->responderComResumo('Réplica aberta.');

        $this->handler($falso)($this->mensagem($analise));

        $texto = (string) $falso->ultimoPedido()?->textoDoUsuario();
        self::assertStringContainsString('ANÁLISE ANTERIOR (não repetir): A contestação foi recebida.', $texto);
        self::assertStringContainsString('[NOVA] 03/10/2026', $texto);
        self::assertStringNotContainsString('[NOVA] 01/10/2026', $texto);

        $relida = $this->reler($analise);
        self::assertSame(2, $relida->getContextoResumo()['total']);
        self::assertSame(1, $relida->getContextoResumo()['novas']);
    }
}
