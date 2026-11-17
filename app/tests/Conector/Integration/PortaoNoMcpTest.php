<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ConcessaoRecusada;
use App\Conector\Mcp\ConectorDesligadoMiddleware;
use App\Conector\Mcp\LimiteDeChamadasMiddleware;
use App\Conector\Mcp\PortaoNoMcpMiddleware;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Service\FiltroDoEscritorio;
use App\Conector\Service\ImpressaoDaSenha;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\Service\ModoDoConector;
use App\Conector\Service\PortaoDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Master\Service\RecursosDoSistema;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\SituacaoNaEquipe;
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
 * descrição ASCII fixa (spec mcp-remoto-oauth 12.3, 12.6, 12.16 e 12.18); a liberação desligada (12.24) só suspende e
 * dá 403 sem desafio, sem abrir nada; o portão que não abre dá 503 sem desafio e nada é despachado (12.2 e decisão 4
 * do PR 1).
 */
#[CoversClass(PortaoNoMcpMiddleware::class)]
#[CoversClass(ConectorDesligadoMiddleware::class)]
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

    /** @return iterable<string, array{string, string, string}> barreira, motivo no log e descrição no desafio */
    public static function barreiras(): iterable
    {
        // 12.3
        yield 'pessoa desativada' => ['pessoa_desativada', AcessoDoConectorRecusado::MOTIVO_PESSOA, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        yield 'escritório suspenso' => ['escritorio_suspenso', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        yield 'escritório em quarentena' => ['escritorio_em_quarentena', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        yield 'vínculo suspenso pela equipe' => ['vinculo_suspenso', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        yield 'vínculo removido' => ['vinculo_removido', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        yield 'pedido de exclusão (equipe)' => ['pedido_de_exclusao', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        yield 'primário de escritório suspenso a pedido' => ['primario_suspenso_a_pedido', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        yield 'Master com vínculo em análise' => ['master_em_analise', AcessoDoConectorRecusado::MOTIVO_ESCRITORIO, ValidadorDoTokenDoConector::DESCRICAO_ESCRITORIO];
        // 12.18
        yield 'termos de uso não aceitos' => ['termos', AcessoDoConectorRecusado::MOTIVO_TERMOS, ValidadorDoTokenDoConector::DESCRICAO_TERMOS];
        // 12.6 e 12.16
        yield 'consentimento revogado' => ['consentimento_revogado', ConcessaoRecusada::MOTIVO_CONCESSAO_INVALIDA, ValidadorDoTokenDoConector::DESCRICAO_AUTORIZACAO];
        yield 'app de I.A. desativado' => ['cliente_inativo', ConcessaoRecusada::MOTIVO_CLIENTE_INATIVO, ValidadorDoTokenDoConector::DESCRICAO_AUTORIZACAO];
        yield 'Sair de todos os aparelhos' => ['sessoes_encerradas', ConcessaoRecusada::MOTIVO_SESSOES_ENCERRADAS, ValidadorDoTokenDoConector::DESCRICAO_AUTORIZACAO];
        yield 'senha trocada' => ['senha_trocada', ConcessaoRecusada::MOTIVO_SENHA_TROCADA, ValidadorDoTokenDoConector::DESCRICAO_AUTORIZACAO];
    }

    #[DataProvider('barreiras')]
    #[TestDox('barreira $barreira depois de conectar: 401 invalid_token com a descrição ASCII fixa, sem despacho e sem abrir o escritório')]
    public function testBarreiraRecusaNaRequisicaoSeguinte(string $barreira, string $motivo, string $descricao): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);
        self::assertSame(200, $this->chamarMcp($bearer)->getStatusCode(), 'antes da barreira, o token vale');
        $this->fimDaRequisicao();
        $this->despachos = 0;

        $this->aplicar($barreira, $this->recarregar($conexao));
        $resposta = $this->chamarMcp($bearer);

        $this->assertRecusaComDesafio($resposta, $descricao);
        self::assertSame($motivo, $this->validador()->motivoDaRecusa());
        self::assertFalse(static::getContainer()->get(TenantContext::class)->hasCurrentTenant());
    }

    /** @return iterable<string, array{string}> */
    public static function suspensoes(): iterable
    {
        // 12.24: os três níveis da liberação (14.a) e o aceite de versão antiga.
        yield 'chave geral desligada pelo Master' => ['chave_geral_desligada'];
        yield 'escritório bloqueado pelo Master' => ['liberacao_bloqueada'];
        yield 'conector desligado no escritório' => ['liberacao_desligada'];
        yield 'aceite de versão antiga' => ['aceite_antigo'];
    }

    #[DataProvider('suspensoes')]
    #[TestDox('suspensão $suspensao depois de conectar: 403 sem WWW-Authenticate, nada despachado, nada aberto nem gravado, e a concessão não cai')]
    public function testLiberacaoDesligadaSuspendeCom403(string $suspensao): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);
        self::assertSame(200, $this->chamarMcp($bearer)->getStatusCode(), 'antes da suspensão, o token vale');
        $this->fimDaRequisicao();
        $this->despachos = 0;
        $this->em()->getConnection()->executeStatement('UPDATE conector_ia_consentimento SET ultimo_uso_em = NULL WHERE id = ?', [$conexao['consentimento']->getId()]);

        $this->aplicar($suspensao, $this->recarregar($conexao));
        $resposta = $this->chamarMcp($bearer);

        self::assertSame(403, $resposta->getStatusCode());
        self::assertFalse($resposta->hasHeader('WWW-Authenticate'), 'sem desafio: nem invalid_token nem insufficient_scope');
        $corpo = json_decode((string) $resposta->getBody(), true);
        self::assertSame(1, $corpo['id'] ?? null);
        self::assertSame(ConectorDesligadoMiddleware::CODIGO, $corpo['error']['code'] ?? null);
        self::assertSame(ConectorDesligadoMiddleware::MENSAGEM, $corpo['error']['message'] ?? null);
        self::assertSame(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, $this->validador()->motivoDaRecusa());
        // Zero acesso: nada despachado, nenhum escritório aberto, modo do conector desligado, nada gravado.
        self::assertSame(0, $this->despachos, 'nada é despachado');
        self::assertFalse($this->requisicao()->estaAberta(), 'a requisição não é aberta');
        self::assertNull($this->validador()->tokenValidado());
        self::assertFalse(static::getContainer()->get(TenantContext::class)->hasCurrentTenant());
        self::assertFalse(static::getContainer()->get(ModoDoConector::class)->estaLigado());
        self::assertNull($this->ultimoUso($conexao['consentimento']), 'o último uso não é gravado');
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT revogada_em FROM conector_ia_concessao WHERE id = ?', [$conexao['concessao']->getId()]);
        self::assertIsArray($linha);
        self::assertNull($linha['revogada_em'], 'suspender não derruba a concessão');
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

        $this->assertRecusaComDesafio($resposta, ValidadorDoTokenDoConector::DESCRICAO_TOKEN);
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
     * Aplica a barreira e grava.
     *
     * @param array<string, mixed> $conexao
     */
    private function aplicar(string $barreira, array $conexao): void
    {
        /** @var User $pessoa */
        $pessoa = $conexao['pessoa'];
        /** @var Tenant $escritorio */
        $escritorio = $conexao['escritorio'];
        $vinculo = static::getContainer()->get(UserTenantRepository::class)->findPorUserETenant($pessoa, $escritorio) ?? throw new \LogicException('sem vínculo');
        switch ($barreira) {
            case 'pessoa_desativada':
                $pessoa->setIsActive(false);
                break;
            case 'escritorio_suspenso':
                $escritorio->setIsActive(false);
                break;
            case 'escritorio_em_quarentena':
                $escritorio->setIsActive(false)->setExcluidoEm(new \DateTimeImmutable());
                break;
            case 'vinculo_suspenso':
                $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
                break;
            case 'vinculo_removido':
                $this->em()->remove($vinculo);
                break;
            case 'pedido_de_exclusao':
                $this->em()->persist(new PedidoDeExclusaoDeConta($escritorio, $pessoa, 'Não uso mais', new \DateTimeImmutable()));
                break;
            case 'primario_suspenso_a_pedido':
                $vinculo->getTenantRole()?->setIsSystem(true);
                $this->em()->persist(new PedidoDeExclusaoDeConta($escritorio, $pessoa, 'Não uso mais', new \DateTimeImmutable()));
                break;
            case 'master_em_analise':
                $pessoa->setRoles(['ROLE_SUPER_ADMIN']);
                $vinculo->alterarSituacao(SituacaoNaEquipe::EmAnalise);
                break;
            case 'termos':
                $this->em()->createQuery('DELETE FROM ' . AceiteTermo::class . ' a WHERE a.user = :pessoa')->setParameter('pessoa', $pessoa)->execute();
                break;
            case 'chave_geral_desligada':
                $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => LiberacaoDoConector::CHAVE_GERAL])
                    ?? new RecursoEstado(LiberacaoDoConector::CHAVE_GERAL);
                $estado->desativar('Pausa', null, null, new \DateTimeImmutable());
                $this->em()->persist($estado);
                // Sem requisição principal (cadeia PSR-15 à mão), o RecursosDoSistema guarda as chaves pela vida do
                // serviço; no `/mcp` de verdade o cache é por requisição. Zera para ler a chave gravada agora.
                (new \ReflectionProperty(RecursosDoSistema::class, 'semRequisicao'))->setValue(static::getContainer()->get(RecursosDoSistema::class), null);
                break;
            case 'aceite_antigo':
                (new \ReflectionProperty(EscritorioDoConector::class, 'aceiteVersao'))->setValue($conexao['liberacao'], 0);
                break;
            case 'liberacao_bloqueada':
                $conexao['liberacao']->bloquearPeloMaster();
                break;
            case 'liberacao_desligada':
                $conexao['liberacao']->desligar();
                break;
            case 'consentimento_revogado':
                $conexao['consentimento']->revogar($this->agora());
                break;
            case 'cliente_inativo':
                $conexao['cliente']->desativar();
                break;
            case 'sessoes_encerradas':
                $pessoa->encerrarSessoes($this->agora()->modify('+1 second'));
                break;
            case 'senha_trocada':
                $pessoa->setPassword('outro-hash-de-senha');
                break;
            default:
                throw new \LogicException('Barreira desconhecida: ' . $barreira);
        }
        $this->em()->flush();
    }

    /**
     * O 401 com a descrição fixa. RFC 6750, 3: `error` e `error_description` só com %x20-21 / %x23-5B / %x5D-7E (sem
     * aspas, sem barra invertida e sem acento); o resto de cada cabeçalho, só ASCII visível.
     */
    private function assertRecusaComDesafio(ResponseInterface $resposta, string $descricao): void
    {
        self::assertSame(401, $resposta->getStatusCode());
        $desafio = $resposta->getHeaderLine('WWW-Authenticate');
        self::assertStringContainsString('error="invalid_token"', $desafio);
        self::assertStringContainsString('error_description="' . $descricao . '"', $desafio);
        self::assertSame(1, preg_match('/error_description="([^"]*)"/', $desafio, $partes));
        self::assertSame(1, preg_match('/^[\x20-\x21\x23-\x5B\x5D-\x7E]+$/', $partes[1]), 'error_description fora da RFC 6750: ' . $partes[1]);
        foreach ($resposta->getHeaders() as $nome => $valores) {
            foreach ($valores as $valor) {
                self::assertSame(1, preg_match('/^[\x20-\x7E]*$/', $valor), 'cabeçalho ' . $nome . ' com byte fora do ASCII visível');
            }
        }
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
