<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Repository\TokenDoConectorRepository;
use App\Conector\Service\ConferenciaDaConcessao;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Tests\Conector\Support\CadeiaDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;

/**
 * O validador do token do `/mcp` (spec mcp-remoto-oauth 3.2 e seção 4, regra 3; decisões 2 e 3 do PR 5): o token aceito
 * leva os escopos dele; o julgamento fica guardado até o fim da requisição (um juiz só); erro que não é recusa vira 503,
 * nunca 401 e nunca passa.
 */
#[CoversClass(ValidadorDoTokenDoConector::class)]
final class ValidadorDoTokenDoConectorTest extends KernelTestCase
{
    use CadeiaDoMcpTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('token de acesso em ordem: allow com os escopos do token, e o token aceito fica à mão')]
    public function testAceitaOTokenDeAcesso(): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);

        $resultado = $this->validador()->validate($bearer);

        self::assertTrue($resultado->isAllowed());
        self::assertSame(['mcp:leitura', 'offline_access'], $resultado->getAccessToken()?->getScopes());
        self::assertSame((string) $conexao['pessoa']->getId(), $resultado->getAccessToken()->getSubject());
        self::assertSame($conexao['cliente']->getIdentificador(), $resultado->getAccessToken()->getClientId());
        self::assertSame((new SegredoOpaco())->hashDe($bearer), $this->validador()->tokenValidado()?->getIdentificadorHash());
        self::assertNull($this->validador()->motivoDaRecusa());
    }

    #[TestDox('um juiz só: o mesmo token na mesma requisição recebe o mesmo julgamento; depois do reset, julga de novo')]
    public function testJulgamentoGuardadoAteOReset(): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);
        $primeiro = $this->validador()->validate($bearer);
        self::assertTrue($primeiro->isAllowed());

        // A pessoa revoga a conexão no meio da requisição: o julgamento desta requisição não muda.
        $conexao['consentimento']->revogar($this->agora());
        $this->em()->flush();
        self::assertSame($primeiro, $this->validador()->validate($bearer));
        self::assertNotNull($this->validador()->tokenValidado());

        $this->validador()->reset();
        self::assertNull($this->validador()->tokenValidado());
        self::assertNull($this->validador()->motivoDaRecusa());

        $depois = $this->validador()->validate($bearer);
        self::assertSame(401, $depois->getStatusCode());
        self::assertNull($this->validador()->tokenValidado());
    }

    #[TestDox('outro token na mesma requisição é julgado à parte (não herda o aceite do primeiro)')]
    public function testOutroTokenNaoHerdaOJulgamento(): void
    {
        $conexao = $this->conexaoAutorizada();
        self::assertTrue($this->validador()->validate($this->tokenDeAcesso($conexao['concessao']))->isAllowed());

        $outro = $this->validador()->validate('outro-token-qualquer');

        self::assertSame(401, $outro->getStatusCode());
        self::assertNull($this->validador()->tokenValidado());
        self::assertSame(ValidadorDoTokenDoConector::MOTIVO_TOKEN_DESCONHECIDO, $this->validador()->motivoDaRecusa());
    }

    #[TestDox('erro inesperado ao buscar o token: 503 sem desafio, nunca 401 e nunca passa')]
    public function testErroInesperadoDa503(): void
    {
        $tokens = $this->createStub(TokenDoConectorRepository::class);
        $tokens->method('buscarPorIdentificadorHash')->willThrowException(new \RuntimeException('banco fora do ar'));

        $resultado = $this->validadorCom($tokens)->validate('qualquer');

        self::assertSame(503, $resultado->getStatusCode());
        self::assertNull($resultado->getError());
        self::assertFalse($resultado->isAllowed());
    }

    #[TestDox('recusa do portão que não é de pessoa, escritório, termos nem liberação (filtro, já aberto): 503, não 401')]
    public function testRecusaDoPortaoQueNaoPedeLoginDa503(): void
    {
        foreach ([AcessoDoConectorRecusado::filtro(), AcessoDoConectorRecusado::jaAberto()] as $recusa) {
            $tokens = $this->createStub(TokenDoConectorRepository::class);
            $tokens->method('buscarPorIdentificadorHash')->willThrowException($recusa);
            $validador = $this->validadorCom($tokens);

            $resultado = $validador->validate('qualquer');

            self::assertSame(503, $resultado->getStatusCode(), $recusa->motivo);
            self::assertNull($validador->tokenValidado());
            self::assertSame(ValidadorDoTokenDoConector::MOTIVO_INDISPONIVEL, $validador->motivoDaRecusa());
        }
    }

    private function validadorCom(TokenDoConectorRepository $tokens): ValidadorDoTokenDoConector
    {
        return new ValidadorDoTokenDoConector($tokens, new SegredoOpaco(), static::getContainer()->get(ConferenciaDaConcessao::class), Clock::get());
    }
}
