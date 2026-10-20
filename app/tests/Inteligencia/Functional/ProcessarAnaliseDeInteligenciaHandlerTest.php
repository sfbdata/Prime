<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Notificacao;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Entity\Tenant\TenantRolePermission;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Fluxo\FluxoDaAnaliseDaPasta;
use App\Inteligencia\Fluxo\FluxoDaPerguntaLivre;
use App\Inteligencia\Fluxo\FluxoDoResumoDoPush;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Inteligencia\Prompt\PromptDaPergunta;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\PerguntaDaConversaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDePush;
use App\Inteligencia\Service\InterpretadorDeRespostaDoAgente;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
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

    private function handler(ProvedorDeLinguagem $provedor, ?LoggerInterface $logger = null, bool $iaHabilitada = true): ProcessarAnaliseDeInteligenciaHandler
    {
        $c = static::getContainer();

        $repositorio = $c->get(AnaliseDeInteligenciaRepository::class);
        $disponibilidade = new DisponibilidadeDeInteligencia(
            $provedor,
            $c->get(ConfiguracaoDeInteligenciaRepository::class),
            $repositorio,
            $c->get(PermissionChecker::class),
            $iaHabilitada,
        );

        return new ProcessarAnaliseDeInteligenciaHandler(
            $this->em(),
            $repositorio,
            new FluxoDoResumoDoPush($c->get(MontadorDeContextoDoPush::class), new PromptResumoDoPush(), new InterpretadorDeRespostaDePush(), $repositorio),
            new FluxoDaAnaliseDaPasta($c->get(MontadorDeContextoDaPasta::class), new PromptDoAgente(), new InterpretadorDeRespostaDoAgente(), $repositorio),
            new FluxoDaPerguntaLivre($c->get(MontadorDeContextoDaPasta::class), new PromptDaPergunta(new PromptDoAgente()), new InterpretadorDeRespostaDoAgente(), $c->get(PerguntaDaConversaRepository::class)),
            $provedor,
            $c->get(NotificacaoService::class),
            $c->get('router'),
            $disponibilidade,
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

    #[TestDox('ProvedorNaoConfigurado → indisponivel antes do provedor, sem re-lançar (repetir não muda nada)')]
    public function testProvedorNaoConfigurado(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);

        $this->handler(new ProvedorNaoConfigurado())($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Indisponivel, $relida->getStatus());
        self::assertSame('nao_configurado', $relida->getProvedor());
        self::assertStringContainsString('não configurada', (string) $relida->getErroMotivo());
        self::assertSame(1, $relida->getTentativas());
    }

    #[TestDox('IA-034L: IA_HABILITADA desligada com a análise na fila → indisponivel com o motivo e o provedor NÃO é chamado')]
    public function testPlataformaDesligadaCortaAFila(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->responderComResumo('não devia sair', []);

        $this->handler($falso, iaHabilitada: false)($this->mensagem($analise));

        self::assertFalse($falso->foiChamado());
        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Indisponivel, $relida->getStatus());
        self::assertSame('cancelada antes do provedor: IA não configurada nesta instalação', $relida->getErroMotivo());
        self::assertNull($relida->getResumo());
    }

    #[TestDox('IA-034L: escritório desligou a IA com a análise na fila → falhou com o motivo, provedor não chamado')]
    public function testEscritorioDesligadoCortaAFila(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $configuracao = $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $configuracao->atualizar(false, 50, 500, true, $user);
        $this->em()->flush();
        $falso = new ProvedorFalso();
        $falso->responderComResumo('não devia sair', []);

        $this->handler($falso)($this->mensagem($analise));

        self::assertFalse($falso->foiChamado());
        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertStringContainsString('desligada neste escritório', (string) $relida->getErroMotivo());
    }

    #[TestDox('IA-034L: a permissão do módulo foi retirada de quem pediu com a análise na fila → falhou com o motivo, provedor não chamado')]
    public function testPermissaoRetiradaCortaAFila(): void
    {
        self::bootKernel();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $solicitante = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view']);
        $analise = $this->criarAnalisePendente($tenant, $solicitante, $pasta);

        // Retira o módulo do papel DEPOIS de a análise entrar na fila.
        $vinculos = $this->em()->getRepository(TenantRolePermission::class)->findAll();
        $retirados = 0;
        foreach ($vinculos as $vinculo) {
            if ($vinculo->getPermission()?->getCode() === 'modules.inteligencia.view'
                && $vinculo->getTenantRole()?->getTenant()?->getId() === $tenant->getId()) {
                $vinculo->getTenantRole()->getTenantRolePermissions()->removeElement($vinculo);
                $this->em()->remove($vinculo);
                ++$retirados;
            }
        }
        $this->em()->flush();
        self::assertSame(1, $retirados, 'só o papel do solicitante tinha o módulo');

        $falso = new ProvedorFalso();
        $falso->responderComResumo('não devia sair', []);

        $this->handler($falso)($this->mensagem($analise));

        self::assertFalse($falso->foiChamado());
        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertSame('cancelada antes do provedor: Sem permissão para usar a BlueJus IA', $relida->getErroMotivo());
        self::assertNull($relida->getResumo());
    }

    #[TestDox('IA-034L: quem pediu não existe mais (solicitante nulo) → falhou com o motivo, provedor não chamado')]
    public function testSolicitanteNuloCortaAFila(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, null, $pasta);
        $falso = new ProvedorFalso();
        $falso->responderComResumo('não devia sair', []);

        $this->handler($falso)($this->mensagem($analise));

        self::assertFalse($falso->foiChamado());
        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertSame('cancelada antes do provedor: quem pediu a análise não existe mais', $relida->getErroMotivo());
    }

    #[TestDox('IA-034L: N análises canceladas antes do provedor (indisponivel e falhou) não tomam a cota; as outras falhas continuam contando')]
    public function testCanceladaAntesDoProvedorNaoTomaACota(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $configuracao = $this->ligarIaNoTenant($tenant, $user, limiteDiario: 2);

        // Três canceladas pelo escritório desligado (falhou) e duas pela plataforma (indisponivel).
        $configuracao->atualizar(false, 2, 500, true, $user);
        $this->em()->flush();
        for ($i = 0; $i < 3; ++$i) {
            $this->handler(new ProvedorFalso())($this->mensagem($this->criarAnalisePendente($tenant, $user, $pasta)));
        }
        for ($i = 0; $i < 2; ++$i) {
            $this->handler(new ProvedorFalso(), iaHabilitada: false)($this->mensagem($this->criarAnalisePendente($tenant, $user, $pasta)));
        }
        // E uma que falhou por outro motivo (não é cancelamento): essa conta.
        $outra = $this->criarAnalisePendente($tenant, $user, $pasta);
        $outra->falhar('resposta inválida do provedor');
        $this->em()->flush();

        $configuracao = $this->em()->find(\get_class($configuracao), $configuracao->getId());
        $configuracao->atualizar(true, 2, 500, true, $user);
        $this->em()->flush();
        $this->em()->clear();

        $repo = static::getContainer()->get(AnaliseDeInteligenciaRepository::class);
        $tenant = $this->em()->find(\get_class($tenant), $tenant->getId());
        $user   = $this->em()->find(\get_class($user), $user->getId());
        self::assertSame(6, (int) $this->em()->createQuery('SELECT COUNT(a.id) FROM ' . AnaliseDeInteligencia::class . ' a WHERE a.tenant = :t')
            ->setParameter('t', $tenant)->getSingleScalarResult(), 'as seis linhas existem');
        self::assertSame(1, $repo->contarDesde($tenant, new \DateTimeImmutable('today')), 'só a falha que não é cancelamento conta');
        self::assertSame(1, $repo->contarDesde($tenant, new \DateTimeImmutable('first day of this month midnight')));

        $disponibilidade = new DisponibilidadeDeInteligencia(
            new ProvedorFalso(),
            static::getContainer()->get(ConfiguracaoDeInteligenciaRepository::class),
            $repo,
            static::getContainer()->get(PermissionChecker::class),
            true,
        );
        self::assertSame(Disponibilidade::Disponivel, $disponibilidade->para($user, $tenant), 'limite 2, uma contada: ainda dá para pedir');
    }

    #[TestDox('IA-034L: a cota NÃO é reconferida no worker — a análise no limite exato (contada na solicitação) conclui')]
    public function testCotaNaoEhReconferidaNoWorker(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user, limiteDiario: 1);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->responderComResumo('Resumo no limite.', []);

        $this->handler($falso)($this->mensagem($analise));

        self::assertTrue($falso->foiChamado());
        self::assertSame(StatusDaAnalise::Concluida, $this->reler($analise)->getStatus());
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

    #[TestDox('falha transitória com retentativa à frente → volta a PENDENTE (segue em andamento, sem 2ª análise) + re-lança para o Messenger retentar')]
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
        self::assertSame(StatusDaAnalise::Pendente, $relida->getStatus());
        self::assertTrue($relida->estaEmAndamento());
        self::assertStringContainsString('transitória', (string) $relida->getErroMotivo());
        self::assertNull($relida->getConcluidaEm());
        self::assertSame(1, $relida->getTentativas());

        // É isto que fecha o gasto duplo: um novo clique encontra a mesma análise em andamento.
        $pendente = static::getContainer()->get(AnaliseDeInteligenciaRepository::class)
            ->findPendenteDoAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, (int) $pasta->getId());
        self::assertSame($relida->getId(), $pendente?->getId());
    }

    #[TestDox('na ÚLTIMA tentativa (max_retries do async), a falha transitória vira falhou e re-lança (vai para failed)')]
    public function testUltimaTentativaTransitoriaViraFalhou(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $max = ProcessarAnaliseDeInteligenciaHandler::TENTATIVAS_MAXIMAS;

        for ($tentativa = 1; $tentativa <= $max; ++$tentativa) {
            $falso->falharTransitoriamente('timeout ' . $tentativa);
            try {
                $this->handler($falso)($this->mensagem($analise));
                self::fail('deveria re-lançar');
            } catch (FalhaDoProvedorException) {
            }

            $relida = $this->reler($analise);
            self::assertSame($tentativa, $relida->getTentativas());
            self::assertSame(
                $tentativa < $max ? StatusDaAnalise::Pendente : StatusDaAnalise::Falhou,
                $relida->getStatus(),
                "tentativa {$tentativa} de {$max}",
            );
        }

        self::assertNotNull($relida->getConcluidaEm(), 'a última marca o fim');
        self::assertFalse($relida->estaEmAndamento(), 'só agora um novo clique pode abrir outra análise');
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
        self::assertStringContainsString("<analise_anterior>\nA contestação foi recebida.\n</analise_anterior>", $texto);
        self::assertStringContainsString('[NOVA] 03/10/2026', $texto);
        self::assertStringNotContainsString('[NOVA] 01/10/2026', $texto);

        $relida = $this->reler($analise);
        self::assertSame(2, $relida->getContextoResumo()['total']);
        self::assertSame(1, $relida->getContextoResumo()['novas']);
    }

    // =========================================================================
    // Agentes da pasta (fatia 2) — mesmo handler, outro fluxo
    // =========================================================================

    #[TestDox('agente: ProvedorFalso com JSON válido → concluida com resumo, pontos, quem, texto_da_analise e notificação para #ia')]
    public function testAgenteConclui(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant, texto: 'Intimação do autor, CPF 123.456.789-09, para réplica em 15 dias.');
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarClientePF($tenant, $pasta, 'João da Silva', '987.654.321-00');
        $this->criarMeta($tenant, $pasta, $user, 'Protocolar réplica', '+3 days', 'Ligar para (61) 99999-1234.');
        $this->criarAnotacao($tenant, $pasta, $user, '<p>Cliente ligou, CPF 123.456.789-09.</p>');
        $this->criarDocumento($tenant, $pasta, 'Procuração');
        $this->criarPagamento($tenant, $pasta, 'Honorários iniciais');
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor, financeiro: true);

        $falso = new ProvedorFalso();
        $falso->responderCom((string) json_encode([
            'resumo' => 'Pasta ativa — réplica pendente.',
            'pontos' => [['tipo' => 'prazo', 'texto' => 'Réplica em 15 dias, termo inicial a conferir.'], ['tipo' => 'inventado', 'texto' => 'vira info']],
            'quem' => 'Dra. Ana',
            'texto' => "CONCLUSÃO\n• Réplica pendente, FATO CONFIRMADO (DJEN, 01/10/2026).\n\nNecessita de conferência do advogado.",
        ], JSON_UNESCAPED_UNICODE));

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Concluida, $relida->getStatus());
        self::assertSame(Agente::Gestor, $relida->getAgente());
        self::assertSame('Pasta ativa, réplica pendente.', $relida->getResumo(), 'travessão vira vírgula');
        self::assertSame('prazo', $relida->getPontos()[0]['tipo']);
        self::assertSame('info', $relida->getPontos()[1]['tipo']);
        self::assertSame('Dra. Ana', $relida->getQuemAge());
        self::assertSame("CONCLUSÃO\n• Réplica pendente, FATO CONFIRMADO (DJEN, 01/10/2026).\n\nNecessita de conferência do advogado.", $relida->getTextoDaAnalise());
        self::assertSame(ProvedorFalso::NOME, $relida->getProvedor());
        self::assertSame('agente-v1', $relida->getVersaoDoPrompt());
        $resumo = $relida->getContextoResumo();
        self::assertSame('gestor', $resumo['agente']);
        self::assertTrue($resumo['financeiro']);
        self::assertSame(1, $resumo['secoes']['clientes']);
        self::assertSame(1, $resumo['secoes']['movimentacoes']);
        self::assertSame(1, $resumo['secoes']['metas']);
        self::assertSame(1, $resumo['secoes']['anotacoes']);
        self::assertSame(1, $resumo['secoes']['documentos']);
        self::assertSame(2, $resumo['secoes']['financeiro']);

        // O pedido que saiu: blocos delimitados, PII mascarada, documento/contato do cliente ausentes.
        $pedido = $falso->ultimoPedido();
        self::assertNotNull($pedido);
        $texto = $pedido->textoDoUsuario();
        self::assertStringStartsWith('PEDIDO: ' . Agente::Gestor->pedido(), $texto);
        foreach (['<pasta>', '<processos_vinculados>', '<clientes>', '<movimentacoes>', '<metas>', '<anotacoes>', '<documentos>', '<checklist>', '<financeiro>'] as $tag) {
            self::assertStringContainsString($tag, $texto, "bloco $tag presente");
        }
        self::assertStringContainsString('para réplica em 15 dias', $texto);
        self::assertStringContainsString('[CPF]', $texto);
        self::assertStringContainsString('[TEL]', $texto);
        self::assertStringNotContainsString('123.456.789-09', $texto, 'o CPF da publicação/anotação não pode sair');
        self::assertStringNotContainsString('987.654.321-00', $texto, 'o CPF do cadastro do cliente não pode sair');
        self::assertStringNotContainsString('99999-1234', $texto);
        self::assertStringNotContainsString('@test.com', $texto, 'e-mail do cliente não sai');
        self::assertStringContainsString('Honorários iniciais', $texto, 'financeiro liberado entra');
        self::assertStringContainsString('conteúdo não lido', $texto);
        self::assertStringEndsWith('Papel: ' . Agente::Gestor->papel(), $pedido->sistema);
        self::assertSame('analise_pasta/gestor/agente-v1', $pedido->rotuloDeUso);

        $notificacoes = $this->em()->getRepository(Notificacao::class)->findBy([
            'usuario' => $user->getId(),
            'tipo' => ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO,
        ]);
        self::assertCount(1, $notificacoes);
        self::assertStringContainsString('#ia', (string) $notificacoes[0]->getUrl());
        self::assertStringContainsString('Agente Gestor', (string) $notificacoes[0]->getMensagem());
    }

    #[TestDox('comando: o worker monta o PEDIDO do comando, usa a anterior do MESMO comando e conclui; a de outro comando do agente não vira anterior')]
    public function testComandoConcluiComAnteriorDoMesmoComando(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant, texto: 'Intimação para réplica em 15 dias.');
        $this->ligarIaNoTenant($tenant, $user);
        $resposta = (string) json_encode(['resumo' => 'Riscos mapeados.', 'pontos' => [], 'quem' => 'Dra. Ana', 'texto' => "CONCLUSÃO\nRiscos.\n\nNecessita de conferência do advogado."], JSON_UNESCAPED_UNICODE);

        // Uma concluída de OUTRO comando do mesmo agente (Gestor): não pode entrar como anterior.
        $outro = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::AnalisarPasta);
        $falsoOutro = new ProvedorFalso();
        $falsoOutro->responderCom((string) json_encode(['resumo' => 'Visão geral SEGREDO-DO-OUTRO.', 'pontos' => [], 'quem' => '', 'texto' => 'Texto SEGREDO-DO-OUTRO.'], JSON_UNESCAPED_UNICODE));
        $this->handler($falsoOutro)($this->mensagem($outro));

        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, comando: Comando::IdentificarRiscos);
        $falso = new ProvedorFalso();
        $falso->responderCom($resposta);
        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Concluida, $relida->getStatus());
        self::assertSame(Comando::IdentificarRiscos, $relida->getComando());
        $pedido = $falso->ultimoPedido();
        self::assertNotNull($pedido);
        self::assertStringStartsWith('PEDIDO: ' . Comando::IdentificarRiscos->pedido(), $pedido->textoDoUsuario());
        self::assertStringNotContainsString('SEGREDO-DO-OUTRO', $pedido->textoDoUsuario(), 'a anterior é só do mesmo comando');
        self::assertStringContainsString('identificar_riscos', $pedido->rotuloDeUso);
    }

    #[TestDox('agente: financeiro gravado como false → o worker obedece e nada financeiro vai ao provedor')]
    public function testAgenteObedeceADecisaoSobreOFinanceiro(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarPagamento($tenant, $pasta, 'Honorários secretos');
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Relatorios, financeiro: false);
        $falso = new ProvedorFalso();
        $falso->responderCom('{"resumo":"ok","pontos":[],"quem":"","texto":"CONCLUSÃO\n• ok"}');

        $this->handler($falso)($this->mensagem($analise));

        self::assertSame(StatusDaAnalise::Concluida, $this->reler($analise)->getStatus());
        $texto = (string) $falso->ultimoPedido()?->textoDoUsuario();
        self::assertStringNotContainsString('<financeiro>', $texto);
        self::assertStringNotContainsString('Honorários secretos', $texto);
        self::assertStringContainsString('não fazem parte desta análise', $texto);
        self::assertFalse($this->reler($analise)->getContextoResumo()['financeiro']);
    }

    #[TestDox('agente: abas ocultas gravadas na solicitação (ocultar aba) → o worker obedece: o Push não vai ao provedor')]
    public function testAgenteObedeceAsAbasOcultas(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarPagamento($tenant, $pasta, 'Honorários visíveis');
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor, financeiro: true, abasOcultas: ['push']);
        $falso = new ProvedorFalso();
        $falso->responderCom('{"resumo":"ok","pontos":[],"quem":"","texto":"CONCLUSÃO\n• ok"}');

        $this->handler($falso)($this->mensagem($analise));

        self::assertSame(StatusDaAnalise::Concluida, $this->reler($analise)->getStatus());
        $texto = (string) $falso->ultimoPedido()?->textoDoUsuario();
        self::assertStringNotContainsString('<movimentacoes>', $texto);
        self::assertStringContainsString('Honorários visíveis', $texto, 'o resto segue');
        self::assertSame(['push'], $this->reler($analise)->getContextoResumo()['abasOcultas']);
    }

    #[TestDox('agente: processo com nivelSigilo > 0 → falhou "contexto bloqueado", provedor NÃO chamado (mesmo para o Documental)')]
    public function testAgenteSigiloBloqueia(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarDocumento($tenant, $pasta, 'Procuração');
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Documental);
        $processo->setNivelSigilo(1);
        $this->em()->flush();
        $falso = new ProvedorFalso();
        $falso->responderCom('{"resumo":"não deveria"}');

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertStringContainsString('contexto bloqueado', (string) $relida->getErroMotivo());
        self::assertFalse($falso->foiChamado());
    }

    #[TestDox('agente sem dado nenhum nas suas seções → falhou "sem dados para o agente", provedor não chamado')]
    public function testAgenteSemDados(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Documental);
        $falso = new ProvedorFalso();

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertSame('sem dados para o agente', $relida->getErroMotivo());
        self::assertFalse($falso->foiChamado());
    }

    #[TestDox('agente: a segunda análise do MESMO agente recebe a anterior; a de outro agente não')]
    public function testAgenteUsaAAnteriorDoMesmoAgente(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $anteriorDoGestor = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($anteriorDoGestor, 'Visão anterior do gestor.');
        $anteriorDePrazos = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);
        $this->concluirAnalise($anteriorDePrazos, 'Prazos anteriores.');
        $falso = new ProvedorFalso();
        $falso->responderCom('{"resumo":"nova","texto":"x"}');

        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->handler($falso)($this->mensagem($analise));

        $texto = (string) $falso->ultimoPedido()?->textoDoUsuario();
        self::assertStringContainsString("<analise_anterior>\nVisão anterior do gestor.\n</analise_anterior>", $texto);
        self::assertStringNotContainsString('Prazos anteriores.', $texto);
    }

    #[TestDox('IA-033: a análise anterior do Push volta ao provedor mascarada (CPF vira [CPF], CNJ intacto)')]
    public function testAnaliseAnteriorDoPushVoltaMascarada(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, , $primeiraPub] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $anterior = $this->criarAnalisePendente($tenant, $user, $pasta, ['pub:' . $primeiraPub->getId()]);
        // Resposta gerada quando a máscara estava desligada (ou por outro provedor): carrega o CPF.
        $this->concluirAnalise($anterior, 'Autor CPF 123.456.789-09 no processo 0701134-57.2025.8.07.0007.');

        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falso = new ProvedorFalso();
        $falso->responderComResumo('Nada novo.');

        $this->handler($falso)($this->mensagem($analise));

        $texto = (string) $falso->ultimoPedido()?->textoDoUsuario();
        self::assertStringContainsString("<analise_anterior>\nAutor CPF [CPF] no processo 0701134-57.2025.8.07.0007.\n</analise_anterior>", $texto);
        self::assertStringNotContainsString('123.456.789-09', $texto);
    }

    #[TestDox('IA-033: a análise anterior do agente volta ao provedor mascarada (CPF e telefone)')]
    public function testAnaliseAnteriorDoAgenteVoltaMascarada(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $anterior = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($anterior, 'Cliente ligou do (61) 99999-1234, CPF 529.982.247-25.');

        $falso = new ProvedorFalso();
        $falso->responderCom('{"resumo":"nova","texto":"x"}');
        $this->handler($falso)($this->mensagem($this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor)));

        $texto = (string) $falso->ultimoPedido()?->textoDoUsuario();
        self::assertStringContainsString("<analise_anterior>\nCliente ligou do [TEL], CPF [CPF].\n</analise_anterior>", $texto);
        self::assertStringNotContainsString('529.982.247-25', $texto);
    }

    #[TestDox('agente: resposta sem JSON → falhou "resposta inválida" com o texto bruto guardado')]
    public function testAgenteRespostaInvalida(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Processual);
        $falso = new ProvedorFalso();
        $falso->responderCom('CONCLUSÃO em texto solto, sem JSON.');

        $this->handler($falso)($this->mensagem($analise));

        $relida = $this->reler($analise);
        self::assertSame(StatusDaAnalise::Falhou, $relida->getStatus());
        self::assertStringContainsString('resposta inválida', (string) $relida->getErroMotivo());
        self::assertSame('CONCLUSÃO em texto solto, sem JSON.', $relida->getTextoBruto());
        self::assertNull($relida->getTextoDaAnalise());
    }
}
