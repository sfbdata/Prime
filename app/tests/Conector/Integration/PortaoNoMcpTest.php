<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ConcessaoRecusada;
use App\Conector\Mcp\LimiteDeChamadasMiddleware;
use App\Conector\Mcp\PortaoNoMcpMiddleware;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Service\FiltroDoEscritorio;
use App\Conector\Service\ImpressaoDaSenha;
use App\Conector\Service\ModoDoConector;
use App\Conector\Service\PortaoDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\AvisoDeContaSuspensa;
use App\Tenant\Service\BarreirasDoEscritorio;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Tests\Conector\Support\CadeiaDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * O `/mcp` pela cadeia PSR-15, sem o controller (bloco A do PR 5): o `AuthorizationMiddleware` do SDK com o validador
 * do token, o portão e o limite. Cada barreira recusa o token na requisição seguinte com 401 `invalid_token` e a
 * mensagem fixa (spec mcp-remoto-oauth 12.3, 12.6, 12.16, 12.18 e 12.24); o portão que não abre dá 503 sem desafio e
 * nada é despachado (12.2 e decisão 4 do PR 1).
 */
#[CoversClass(PortaoNoMcpMiddleware::class)]
#[CoversClass(ValidadorDoTokenDoConector::class)]
#[CoversClass(LimiteDeChamadasMiddleware::class)]
final class PortaoNoMcpTest extends KernelTestCase
{
    use CadeiaDoMcpTrait;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('token de acesso em ordem: despacha uma vez, com a requisição aberta no escritório do token e o último uso gravado')]
    public function testTudoEmOrdemDespacha(): void
    {
        $conexao = $this->conexaoAutorizada();
        $escritorioId = (int) $conexao['escritorio']->getId();

        $resposta = $this->chamarMcp($this->tokenDeAcesso($conexao['concessao']));

        self::assertSame(200, $resposta->getStatusCode());
        self::assertSame(1, $this->despachos);
        $chamada = $this->requisicao()->atual();
        self::assertInstanceOf(ChamadaDoConector::class, $chamada);
        self::assertSame($conexao['pessoa']->getId(), $chamada->contexto->pessoa->getId());
        self::assertSame($escritorioId, $chamada->contexto->escritorio->getId());
        self::assertSame($conexao['concessao']->getId(), $chamada->concessaoId);
        self::assertSame($conexao['consentimento']->getId(), $chamada->consentimentoId);
        self::assertSame('claude.ai', $chamada->hostDoCliente);
        self::assertSame(['mcp:leitura', 'offline_access'], $chamada->escopos);
        self::assertEquals($conexao['concessao']->getExpiraEm(), $chamada->conexaoValeAte);
        self::assertTrue($this->filtro()->estaLigadoEm($escritorioId), 'o filtro de escritório ligado no escritório do token');
        self::assertSame($escritorioId, static::getContainer()->get(TenantContext::class)->getCurrentTenant()?->getId());
        self::assertTrue(static::getContainer()->get(ModoDoConector::class)->estaLigado());
        self::assertNotNull($this->ultimoUso($conexao['consentimento']), 'o último uso da conexão foi gravado');
        self::assertNull($this->validador()->motivoDaRecusa());
    }

    #[TestDox('sem bearer: 401 do SDK, sem despacho')]
    public function testSemBearer(): void
    {
        $resposta = $this->chamarMcp(null);

        self::assertSame(401, $resposta->getStatusCode());
        self::assertSame(0, $this->despachos);
        self::assertFalse($this->requisicao()->estaAberta());
    }

    /** @return iterable<string, array{string, string}> barreira e motivo esperado */
    public static function barreiras(): iterable
    {
        // 12.3
        yield 'pessoa desativada' => ['pessoa_desativada', AcessoDoConectorRecusado::MOTIVO_PESSOA];
        yield 'escritório suspenso' => ['escritorio_suspenso', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO];
        yield 'escritório em quarentena' => ['escritorio_em_quarentena', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO];
        yield 'vínculo suspenso pela equipe' => ['vinculo_suspenso', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO];
        yield 'vínculo removido' => ['vinculo_removido', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO];
        yield 'pedido de exclusão (equipe)' => ['pedido_de_exclusao', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO];
        yield 'primário de escritório suspenso a pedido' => ['primario_suspenso_a_pedido', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO];
        yield 'Master com vínculo em análise' => ['master_em_analise', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO];
        // 12.18
        yield 'termos de uso não aceitos' => ['termos', AcessoDoConectorRecusado::MOTIVO_TERMOS];
        // 12.24
        yield 'escritório bloqueado pelo Master' => ['liberacao_bloqueada', AcessoDoConectorRecusado::MOTIVO_LIBERACAO];
        yield 'conector desligado no escritório' => ['liberacao_desligada', AcessoDoConectorRecusado::MOTIVO_LIBERACAO];
        // 12.6 e 12.16
        yield 'consentimento revogado' => ['consentimento_revogado', ConcessaoRecusada::MOTIVO_CONCESSAO_INVALIDA];
        yield 'app de I.A. desativado' => ['cliente_inativo', ConcessaoRecusada::MOTIVO_CLIENTE_INATIVO];
        yield 'Sair de todos os aparelhos' => ['sessoes_encerradas', ConcessaoRecusada::MOTIVO_SESSOES_ENCERRADAS];
        yield 'senha trocada' => ['senha_trocada', ConcessaoRecusada::MOTIVO_SENHA_TROCADA];
    }

    #[DataProvider('barreiras')]
    #[TestDox('barreira $barreira depois de conectar: 401 invalid_token com a mensagem fixa, sem despacho e sem abrir o escritório')]
    public function testBarreiraRecusaNaRequisicaoSeguinte(string $barreira, string $motivo): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);
        self::assertSame(200, $this->chamarMcp($bearer)->getStatusCode(), 'antes da barreira, o token vale');
        $this->fimDaRequisicao();
        $this->despachos = 0;

        $mensagem = $this->aplicar($barreira, $this->recarregar($conexao));
        $resposta = $this->chamarMcp($bearer);

        $this->assertRecusaComDesafio($resposta, $mensagem);
        self::assertSame($motivo, $this->validador()->motivoDaRecusa());
        self::assertFalse(static::getContainer()->get(TenantContext::class)->hasCurrentTenant());
    }

    /** @return iterable<string, array{string, string}> */
    public static function tokensQueNaoValem(): iterable
    {
        yield 'renovação usada como bearer' => ['renovacao', ValidadorDoTokenDoConector::MOTIVO_NAO_E_ACESSO];
        yield 'token de outro resource' => ['outro_resource', ValidadorDoTokenDoConector::MOTIVO_RESOURCE];
        yield 'token vencido' => ['vencido', ValidadorDoTokenDoConector::MOTIVO_TOKEN_VENCIDO_OU_REVOGADO];
        yield 'token revogado' => ['revogado', ValidadorDoTokenDoConector::MOTIVO_TOKEN_VENCIDO_OU_REVOGADO];
        yield 'token desconhecido' => ['desconhecido', ValidadorDoTokenDoConector::MOTIVO_TOKEN_DESCONHECIDO];
    }

    #[DataProvider('tokensQueNaoValem')]
    #[TestDox('$caso: 401 invalid_token, sem despacho')]
    public function testTokenQueNaoVale(string $caso, string $motivo): void
    {
        self::mockTime('2026-11-16 10:00:00');
        $conexao = $this->conexaoAutorizada();
        $bearer = match ($caso) {
            'renovacao' => $this->tokenDeRenovacao($conexao['concessao']),
            'outro_resource' => $this->tokenDeAcesso($this->concessaoParaOutroRecurso($conexao['consentimento'])),
            'desconhecido' => 'token-que-ninguem-emitiu',
            default => $this->tokenDeAcesso($conexao['concessao']),
        };
        if ($caso === 'vencido') {
            self::mockTime('2026-11-16 11:00:00');
        }
        if ($caso === 'revogado') {
            foreach ($this->em()->getRepository(TokenDoConector::class)->findBy(['concessao' => $conexao['concessao']]) as $token) {
                $token->revogar($this->agora());
            }
            $this->em()->flush();
        }

        $resposta = $this->chamarMcp($bearer);

        $this->assertRecusaComDesafio($resposta, ValidadorDoTokenDoConector::TOKEN_NAO_VALE);
        self::assertSame($motivo, $this->validador()->motivoDaRecusa());
    }

    #[TestDox('12.2: o filtro de escritório não liga, a resposta é 503 sem desafio e nada é despachado')]
    public function testFiltroQueNaoLiga(): void
    {
        $conexao = $this->conexaoAutorizada();
        $filtro = $this->createStub(FiltroDoEscritorio::class);
        $filtro->method('estaLigadoEm')->willReturn(false);

        $resposta = $this->chamarMcp($this->tokenDeAcesso($conexao['concessao']), $this->middlewareDoPortao($this->portaoCom(filtro: $filtro)));

        $this->assertIndisponivelSemDespacho($resposta, $conexao['consentimento'], AcessoDoConectorRecusado::MOTIVO_FILTRO);
    }

    #[TestDox('12.2: o filtro de escritório lança, a resposta é 503 sem desafio e nada é despachado')]
    public function testFiltroQueLanca(): void
    {
        $conexao = $this->conexaoAutorizada();
        $filtro = $this->createStub(FiltroDoEscritorio::class);
        $filtro->method('ligar')->willThrowException(new \RuntimeException('banco fora do ar'));

        $resposta = $this->chamarMcp($this->tokenDeAcesso($conexao['concessao']), $this->middlewareDoPortao($this->portaoCom(filtro: $filtro)));

        $this->assertIndisponivelSemDespacho($resposta, $conexao['consentimento'], AcessoDoConectorRecusado::MOTIVO_FILTRO);
    }

    #[TestDox('decisão 4: o abrir lança LogicException no meio (filtro já ligado), a requisição acaba em 503 e nada roda')]
    public function testLogicExceptionNoMeioDoAbrir(): void
    {
        $conexao = $this->conexaoAutorizada();
        // Um vínculo que passa em todas as barreiras, mas não está gravado: o TenantContext recusa fixá-lo (LogicException)
        // DEPOIS de o portão ligar o filtro.
        $naoGravado = (new UserTenant($conexao['pessoa'], $conexao['escritorio']))->setTenantRole((new TenantRole())->setIsSystem(false));
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findPorUserETenant')->willReturn($naoGravado);

        $resposta = $this->chamarMcp($this->tokenDeAcesso($conexao['concessao']), $this->middlewareDoPortao($this->portaoCom(vinculos: $vinculos)));

        self::assertTrue($this->filtro()->estaLigadoEm((int) $conexao['escritorio']->getId()), 'a abertura falhou no meio, depois do filtro');
        $this->assertIndisponivelSemDespacho($resposta, $conexao['consentimento'], PortaoNoMcpMiddleware::MOTIVO_ERRO);
    }

    #[TestDox('portão sem token aceito pelo AuthorizationMiddleware (fora de ordem): 503, nada abre')]
    public function testPortaoSemTokenAceito(): void
    {
        $handler = new class () implements \Psr\Http\Server\RequestHandlerInterface {
            public bool $rodou = false;

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                $this->rodou = true;

                return (new \Http\Discovery\Psr17Factory())->createResponse(200);
            }
        };

        $resposta = $this->middlewareDoPortao()->process($this->pedidoMcp('qualquer'), $handler);

        self::assertSame(503, $resposta->getStatusCode());
        self::assertFalse($handler->rodou);
        self::assertFalse($this->requisicao()->estaAberta());
    }

    #[TestDox('429: passou do limite por pessoa e escritório, com Retry-After, e não despacha')]
    public function testLimiteDeChamadas(): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);
        $limite = $this->middlewareDoLimite(porPessoa: 1);

        self::assertSame(200, $this->chamarMcp($bearer, limite: $limite)->getStatusCode());
        $this->fimDaRequisicao();
        $resposta = $this->chamarMcp($bearer, limite: $limite);

        self::assertSame(429, $resposta->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $resposta->getHeaderLine('Retry-After'));
        self::assertSame(1, $this->despachos);
    }

    /**
     * O fim da requisição limpa o EntityManager: o cenário é relido para a barreira gravar de verdade.
     *
     * @param array<string, mixed> $conexao
     *
     * @return array<string, mixed>
     */
    private function recarregar(array $conexao): array
    {
        foreach ($conexao as $chave => $entidade) {
            if (\is_object($entidade) && method_exists($entidade, 'getId')) {
                $conexao[$chave] = $this->em()->find($entidade::class, $entidade->getId()) ?? throw new \LogicException('sumiu: ' . $chave);
            }
        }

        return $conexao;
    }

    /**
     * Aplica a barreira e devolve a mensagem fixa que o app de I.A. deve receber.
     *
     * @param array<string, mixed> $conexao
     */
    private function aplicar(string $barreira, array $conexao): string
    {
        /** @var User $pessoa */
        $pessoa = $conexao['pessoa'];
        /** @var Tenant $escritorio */
        $escritorio = $conexao['escritorio'];
        $vinculo = static::getContainer()->get(UserTenantRepository::class)->findPorUserETenant($pessoa, $escritorio) ?? throw new \LogicException('sem vínculo');
        $nome = (string) $escritorio->getName();
        $mensagem = match ($barreira) {
            'pessoa_desativada' => (static function () use ($pessoa): string {
                $pessoa->setIsActive(false);

                return AcessoDoConectorRecusado::PESSOA_INDISPONIVEL;
            })(),
            'escritorio_suspenso' => (static function () use ($escritorio, $nome): string {
                $escritorio->setIsActive(false);

                return AvisoDeContaSuspensa::para($nome);
            })(),
            'escritorio_em_quarentena' => (static function () use ($escritorio, $nome): string {
                $escritorio->setIsActive(false)->setExcluidoEm(new \DateTimeImmutable());

                return AvisoDeContaSuspensa::paraExcluida($nome);
            })(),
            'vinculo_suspenso' => (static function () use ($vinculo, $nome): string {
                $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);

                return SituacaoNaEquipe::Suspenso->mensagemDeAcesso($nome);
            })(),
            'vinculo_removido' => (function () use ($vinculo): string {
                $this->em()->remove($vinculo);

                return AcessoDoConectorRecusado::ACESSO_INDISPONIVEL;
            })(),
            'pedido_de_exclusao' => (function () use ($escritorio, $pessoa): string {
                $this->em()->persist(new PedidoDeExclusaoDeConta($escritorio, $pessoa, 'Não uso mais', new \DateTimeImmutable()));

                return BloqueioPorPedidoDeExclusao::MENSAGEM;
            })(),
            'primario_suspenso_a_pedido' => (function () use ($escritorio, $pessoa, $vinculo): string {
                $vinculo->getTenantRole()?->setIsSystem(true);
                $this->em()->persist(new PedidoDeExclusaoDeConta($escritorio, $pessoa, 'Não uso mais', new \DateTimeImmutable()));

                return BloqueioPorPedidoDeExclusao::MENSAGEM;
            })(),
            'master_em_analise' => (static function () use ($pessoa, $vinculo, $nome): string {
                $pessoa->setRoles(['ROLE_SUPER_ADMIN']);
                $vinculo->alterarSituacao(SituacaoNaEquipe::EmAnalise);

                return SituacaoNaEquipe::EmAnalise->mensagemDeAcesso($nome);
            })(),
            'termos' => (function () use ($pessoa): string {
                $this->em()->createQuery('DELETE FROM ' . AceiteTermo::class . ' a WHERE a.user = :pessoa')->setParameter('pessoa', $pessoa)->execute();

                return AcessoDoConectorRecusado::TERMOS_NAO_ACEITOS;
            })(),
            'liberacao_bloqueada' => (static function () use ($conexao): string {
                $conexao['liberacao']->bloquearPeloMaster();

                return AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO;
            })(),
            'liberacao_desligada' => (static function () use ($conexao): string {
                $conexao['liberacao']->desligar();

                return AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO;
            })(),
            'consentimento_revogado' => (function () use ($conexao): string {
                $conexao['consentimento']->revogar($this->agora());

                return ConcessaoRecusada::MENSAGEM;
            })(),
            'cliente_inativo' => (static function () use ($conexao): string {
                $conexao['cliente']->desativar();

                return ConcessaoRecusada::MENSAGEM;
            })(),
            'sessoes_encerradas' => (function () use ($pessoa): string {
                $pessoa->encerrarSessoes($this->agora()->modify('+1 second'));

                return ConcessaoRecusada::MENSAGEM;
            })(),
            'senha_trocada' => (static function () use ($pessoa): string {
                $pessoa->setPassword('outro-hash-de-senha');

                return ConcessaoRecusada::MENSAGEM;
            })(),
        };
        $this->em()->flush();

        return $mensagem;
    }

    private function assertRecusaComDesafio(ResponseInterface $resposta, string $mensagem): void
    {
        self::assertSame(401, $resposta->getStatusCode());
        $desafio = $resposta->getHeaderLine('WWW-Authenticate');
        self::assertStringContainsString('error="invalid_token"', $desafio);
        self::assertStringContainsString('error_description="' . addcslashes($mensagem, '"\\') . '"', $desafio);
        self::assertSame(0, $this->despachos, 'nada é despachado');
        self::assertFalse($this->requisicao()->estaAberta(), 'a requisição não é aberta');
        self::assertNull($this->validador()->tokenValidado());
    }

    private function assertIndisponivelSemDespacho(ResponseInterface $resposta, ConsentimentoDoConector $consentimento, string $motivo): void
    {
        self::assertSame(503, $resposta->getStatusCode());
        self::assertFalse($resposta->hasHeader('WWW-Authenticate'), '503 sem desafio');
        self::assertSame(0, $this->despachos, 'nada é despachado');
        self::assertFalse($this->requisicao()->estaAberta(), 'a requisição não é aberta');
        self::assertSame($motivo, $this->requisicao()->anotacao(PortaoNoMcpMiddleware::ANOTACAO_RECUSA));
        self::assertNull($this->ultimoUso($consentimento), 'o último uso não é gravado');
    }

    private function ultimoUso(ConsentimentoDoConector $consentimento): mixed
    {
        return $this->em()->getConnection()->fetchOne('SELECT ultimo_uso_em FROM conector_ia_consentimento WHERE id = ?', [$consentimento->getId()]);
    }

    private function concessaoParaOutroRecurso(ConsentimentoDoConector $consentimento): ConcessaoDoConector
    {
        $impressao = new ImpressaoDaSenha((string) static::getContainer()->getParameter('kernel.secret'));
        $concessao = new ConcessaoDoConector($consentimento, $consentimento->getEscopos(), 'https://bluejus.com.br/outro', $impressao->de($consentimento->getUser()), $this->agora());
        self::assertNotSame(ServidorDeAutorizacao::RESOURCE, $concessao->getResource());
        $this->em()->persist($concessao);
        $this->em()->flush();

        return $concessao;
    }

    /** O portão com as peças do container, trocando só o filtro ou o repositório de vínculos. */
    private function portaoCom(?FiltroDoEscritorio $filtro = null, ?UserTenantRepository $vinculos = null): PortaoDoConector
    {
        $c = static::getContainer();

        return new PortaoDoConector(
            $c->get(UserRepository::class),
            $c->get(TenantRepository::class),
            $vinculos ?? $c->get(UserTenantRepository::class),
            new BarreirasDoEscritorio($c->get(BloqueioPorPedidoDeExclusao::class), $c->get(RegraDosPrimarios::class)),
            $c->get(AceiteTermoRepository::class),
            new TermoVigente(),
            $filtro ?? $this->filtro(),
            $c->get(ModoDoConector::class),
            $c->get(TenantContext::class),
        );
    }
}
