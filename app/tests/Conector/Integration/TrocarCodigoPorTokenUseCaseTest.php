<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\PedidoDeTokenInput;
use App\Conector\DTO\RespostaDeTokenOutput;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Service\ConferenciaDaConcessao;
use App\Conector\Service\EmissorDeTokens;
use App\Conector\Service\TransacaoDoToken;
use App\Conector\UseCase\TrocarCodigoPorTokenUseCase;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use App\Tests\Conector\Support\TravaDeLinhaDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Troca do código pelo primeiro par (spec mcp-remoto-oauth 3.2, 9.3, 9.6 e 12.7), no banco: hashes, PKCE, `resource`,
 * código reapresentado (derruba e GRAVA a queda), vencido, cliente e redirect, a conferência da concessão e a trava.
 */
#[CoversClass(TrocarCodigoPorTokenUseCase::class)]
#[CoversClass(EmissorDeTokens::class)]
#[CoversClass(TransacaoDoToken::class)]
final class TrocarCodigoPorTokenUseCaseTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;
    use ClockSensitiveTrait;
    use TravaDeLinhaDoConectorTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-16 10:00:00');
    }

    private function sut(): TrocarCodigoPorTokenUseCase
    {
        return static::getContainer()->get(TrocarCodigoPorTokenUseCase::class);
    }

    private function assertRecusa(string $codigoOAuth, callable $trocar): void
    {
        try {
            $trocar();
            self::fail('a troca passou; esperado ' . $codigoOAuth);
        } catch (ErroOAuth $erro) {
            self::assertSame($codigoOAuth, $erro->codigo);
        }
    }

    /** @return array{revogada_em: ?string, motivo: ?string} lido do banco, não da memória */
    private function concessaoNoBanco(int $id): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT revogada_em, motivo FROM conector_ia_concessao WHERE id = ?', [$id]);
        self::assertIsArray($linha);

        return ['revogada_em' => $linha['revogada_em'], 'motivo' => $linha['motivo']];
    }

    #[TestDox('Código certo: par emitido com scope, acesso de 1 hora; o banco guarda só o SHA-256 do acesso e da renovação; o código fica usado')]
    public function testTrocaCertaEmiteOPar(): void
    {
        $conexao = $this->conexaoAutorizada();

        $par = $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente']));

        self::assertInstanceOf(RespostaDeTokenOutput::class, $par);
        self::assertSame('mcp:leitura offline_access', $par->scope);
        self::assertSame(3600, $par->expiresIn);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $par->accessToken);
        $hashes = $this->em()->getConnection()->fetchAllKeyValue(
            'SELECT tipo, identificador_hash FROM conector_ia_token WHERE concessao_id = ?',
            [$conexao['concessao']->getId()],
        );
        self::assertSame(['acesso' => hash('sha256', $par->accessToken), 'renovacao' => hash('sha256', $par->refreshToken)], $hashes);
        $guardado = (string) $this->em()->getConnection()->fetchOne('SELECT string_agg(identificador_hash, \'\') FROM conector_ia_token');
        self::assertStringNotContainsString($par->accessToken, $guardado);
        self::assertStringNotContainsString($par->refreshToken, $guardado);
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT usado_em FROM conector_ia_codigo WHERE concessao_id = ?', [$conexao['concessao']->getId()]));
    }

    #[TestDox('Código apresentado duas vezes: a segunda recusa (invalid_grant) e derruba a concessão com codigo_reutilizado, GRAVADO no banco; o acesso emitido para de valer')]
    public function testCodigoReapresentadoDerrubaEGrava(): void
    {
        $conexao = $this->conexaoAutorizada();
        $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente']));

        $this->assertRecusa(ErroOAuth::INVALID_GRANT, fn () => $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente'])));

        $this->em()->clear();
        $noBanco = $this->concessaoNoBanco((int) $conexao['concessao']->getId());
        self::assertNotNull($noBanco['revogada_em'], 'a queda não pode ser desfeita com a recusa');
        self::assertSame(MotivoDaRevogacaoDaConcessao::CodigoReutilizado->value, $noBanco['motivo']);
    }

    #[TestDox('Código vencido (mais de 10 minutos) recusa com invalid_grant e não derruba nada')]
    public function testCodigoVencidoRecusa(): void
    {
        $conexao = $this->conexaoAutorizada();
        self::mockTime('2026-11-16 10:10:00');

        $this->assertRecusa(ErroOAuth::INVALID_GRANT, fn () => $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente'])));
        self::assertNull($this->concessaoNoBanco((int) $conexao['concessao']->getId())['revogada_em']);
    }

    /** @return iterable<string, array{array<string, string|null>, string}> */
    public static function pedidosErrados(): iterable
    {
        yield 'PKCE errado' => [['codeVerifier' => str_repeat('a', 43)], ErroOAuth::INVALID_GRANT];
        yield 'verifier curto' => [['codeVerifier' => 'curto'], ErroOAuth::INVALID_GRANT];
        yield 'redirect diferente' => [['redirectUri' => 'https://claude.ai/api/mcp/auth_callback/'], ErroOAuth::INVALID_GRANT];
        yield 'outro app' => [['clientId' => 'https://chatgpt.com/oauth/client.json'], ErroOAuth::INVALID_GRANT];
        yield 'resource diferente' => [['resource' => 'https://bluejus.com.br/mcp/'], ErroOAuth::INVALID_TARGET];
        yield 'resource ausente' => [['resource' => null], ErroOAuth::INVALID_TARGET];
        yield 'sem code_verifier' => [['codeVerifier' => null], ErroOAuth::INVALID_REQUEST];
    }

    /** @param array<string, string|null> $troca */
    #[DataProvider('pedidosErrados')]
    #[TestDox('Pedido errado ($_dataName) recusa sem gastar o código: depois, o pedido certo ainda troca')]
    public function testPedidoErradoRecusaSemGastarOCodigo(array $troca, string $esperado): void
    {
        $conexao = $this->conexaoAutorizada();

        $this->assertRecusa($esperado, fn () => $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente'], $troca)));

        $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente']));
        $this->addToAssertionCount(1);
    }

    #[TestDox('resource conferido duas vezes: o do pedido tem de ser o do servidor (mesmo igual ao gravado na concessão) e o gravado na concessão tem de ser o do pedido')]
    public function testResourceDoServidorEDaConcessao(): void
    {
        $conexao = $this->conexaoAutorizada();
        $outro = 'https://outro.example/mcp';
        $this->em()->getConnection()->executeStatement('UPDATE conector_ia_concessao SET resource = ? WHERE id = ?', [$outro, $conexao['concessao']->getId()]);
        $this->em()->clear();

        $this->assertRecusa(ErroOAuth::INVALID_TARGET, fn () => $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente'], ['resource' => $outro])));
        $this->assertRecusa(ErroOAuth::INVALID_TARGET, fn () => $this->sut()->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente'])));
    }

    #[TestDox('Código desconhecido recusa com invalid_grant')]
    public function testCodigoDesconhecidoRecusa(): void
    {
        $conexao = $this->conexaoAutorizada();

        $this->assertRecusa(ErroOAuth::INVALID_GRANT, fn () => $this->sut()->executar($this->pedidoDoCodigo('nao-existe', $conexao['cliente'])));
    }

    #[TestDox('A conferência da concessão roda na troca do código: liberação desligada, consentimento revogado ou senha trocada recusam com invalid_grant')]
    public function testConferenciaDaConcessaoNaTrocaDoCodigo(): void
    {
        $desligado = $this->conexaoAutorizada();
        $desligado['liberacao']->desligar();
        $this->em()->flush();
        $this->assertRecusa(ErroOAuth::INVALID_GRANT, fn () => $this->sut()->executar($this->pedidoDoCodigo($desligado['codigo'], $desligado['cliente'])));

        $revogado = $this->conexaoAutorizada();
        $revogado['consentimento']->revogar($this->agora());
        $this->em()->flush();
        $this->assertRecusa(ErroOAuth::INVALID_GRANT, fn () => $this->sut()->executar($this->pedidoDoCodigo($revogado['codigo'], $revogado['cliente'])));

        $senha = $this->conexaoAutorizada();
        $senha['pessoa']->setPassword('outro-hash');
        $this->em()->flush();
        $this->assertRecusa(ErroOAuth::INVALID_GRANT, fn () => $this->sut()->executar($this->pedidoDoCodigo($senha['codigo'], $senha['cliente'])));
    }

    #[TestDox('Duas trocas ao mesmo tempo do mesmo código: a segunda espera a linha travada (FOR UPDATE) pela primeira')]
    public function testTrocaEsperaATravaDoCodigo(): void
    {
        $cadeia = $this->cadeiaConfirmadaPorFora();

        $this->assertEsperaATrava($cadeia, 'conector_ia_codigo', hash('sha256', $cadeia['codigo']), fn () => $this->sut()->executar(
            new PedidoDeTokenInput('authorization_code', $cadeia['cliente'], $cadeia['codigo'], 'https://claude.ai/api/mcp/auth_callback', self::VERIFIER, null, 'https://bluejus.com.br/mcp'),
        ));
    }

    #[TestDox('A conferência é serviço do container (o PR 5 a reusa)')]
    public function testConferenciaNoContainer(): void
    {
        self::assertInstanceOf(ConferenciaDaConcessao::class, static::getContainer()->get(ConferenciaDaConcessao::class));
    }
}
