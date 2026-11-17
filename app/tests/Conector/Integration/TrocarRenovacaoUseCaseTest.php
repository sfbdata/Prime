<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\UseCase\AlterarSenhaUseCase;
use App\Auth\UseCase\RedefinirSenhaUseCase;
use App\Auth\UseCase\SairDeTodosOsAparelhosUseCase;
use App\Conector\DTO\RespostaDeTokenOutput;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Service\ConferenciaDaConcessao;
use App\Conector\Service\TransacaoDoToken;
use App\Conector\UseCase\TrocarCodigoPorTokenUseCase;
use App\Conector\UseCase\TrocarRenovacaoUseCase;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use App\Tests\Conector\Support\TravaDeLinhaDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Rotação da renovação (spec mcp-remoto-oauth 9.1, 9.2, 12.6, 12.7, 12.16 e 12.24), no banco. Toda recusa derruba a
 * concessão e a queda é conferida NO BANCO (depois do `clear()`): a recusa não pode desfazer a própria revogação.
 */
#[CoversClass(TrocarRenovacaoUseCase::class)]
#[CoversClass(ConferenciaDaConcessao::class)]
#[CoversClass(TransacaoDoToken::class)]
final class TrocarRenovacaoUseCaseTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;
    use ClockSensitiveTrait;
    use TravaDeLinhaDoConectorTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-16 10:00:00');
    }

    private function renovar(string $renovacao, ClienteDoConector|string $cliente, ?string $resource = null): RespostaDeTokenOutput
    {
        return static::getContainer()->get(TrocarRenovacaoUseCase::class)->executar($this->pedidoDeRenovacao($renovacao, $cliente, $resource));
    }

    /**
     * A conexão autorizada e o primeiro par, trocado pelo código.
     *
     * @return array{0: array<string, mixed>, 1: RespostaDeTokenOutput}
     */
    private function conectado(): array
    {
        $conexao = $this->conexaoAutorizada();
        $par = static::getContainer()->get(TrocarCodigoPorTokenUseCase::class)->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente']));

        return [$conexao, $par];
    }

    private function assertRenovacaoRecusadaEDerrubada(string $renovacao, ClienteDoConector|string $cliente, int $concessaoId, MotivoDaRevogacaoDaConcessao $motivo): void
    {
        try {
            $this->renovar($renovacao, $cliente);
            self::fail('a renovação passou; esperado invalid_grant');
        } catch (ErroOAuth $erro) {
            self::assertSame(ErroOAuth::INVALID_GRANT, $erro->codigo);
        }

        $this->em()->clear();
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT revogada_em, motivo FROM conector_ia_concessao WHERE id = ?', [$concessaoId]);
        self::assertIsArray($linha);
        self::assertNotNull($linha['revogada_em'], 'a queda da concessão ficou gravada');
        self::assertSame($motivo->value, $linha['motivo']);
    }

    /** O token (pelo valor) ainda serve? Lido do banco: não revogado, não vencido, concessão viva. */
    private function vale(string $valor): bool
    {
        $this->em()->clear();
        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT t.revogado_em, t.expira_em, c.revogada_em AS concessao_revogada FROM conector_ia_token t JOIN conector_ia_concessao c ON c.id = t.concessao_id WHERE t.identificador_hash = ?',
            [hash('sha256', $valor)],
        );
        self::assertIsArray($linha);

        return $linha['revogado_em'] === null && $linha['concessao_revogada'] === null && new \DateTimeImmutable($linha['expira_em']) > $this->agora();
    }

    #[TestDox('Renovação nunca usada gira: par novo com scope, a usada fica marcada e o par novo é gerado por ela')]
    public function testRenovacaoGira(): void
    {
        [$conexao, $par] = $this->conectado();
        self::mockTime('+50 minutes');

        $novo = $this->renovar($par->refreshToken, $conexao['cliente']);

        self::assertSame('mcp:leitura offline_access', $novo->scope);
        self::assertNotSame($par->accessToken, $novo->accessToken);
        $usadoEm = $this->em()->getConnection()->fetchOne('SELECT usado_em FROM conector_ia_token WHERE identificador_hash = ?', [hash('sha256', $par->refreshToken)]);
        self::assertSame('2026-11-16 10:50:00', $usadoEm);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM conector_ia_token f JOIN conector_ia_token p ON p.id = f.gerado_por_id WHERE p.identificador_hash = ?',
            [hash('sha256', $par->refreshToken)],
        ));
    }

    #[TestDox('O acesso vence em 1 hora; a renovação nunca passa dos 7 dias da autorização: no 7º dia ela é recusada e a concessão cai')]
    public function testAcessoDeUmaHoraERenovacaoAteSeteDias(): void
    {
        [$conexao, $par] = $this->conectado();
        self::assertSame('2026-11-16 11:00:00', $this->em()->getConnection()->fetchOne('SELECT expira_em FROM conector_ia_token WHERE identificador_hash = ?', [hash('sha256', $par->accessToken)]));
        self::mockTime('2026-11-16 11:00:00');
        self::assertFalse($this->vale($par->accessToken), 'o acesso venceu na 1 hora');

        $renovacao = $par->refreshToken;
        foreach (['2026-11-18 10:00:00', '2026-11-20 10:00:00', '2026-11-23 09:30:00'] as $instante) {
            self::mockTime($instante);
            $novo = $this->renovar($renovacao, $conexao['cliente']);
            $renovacao = $novo->refreshToken;
            self::assertLessThanOrEqual(3600, $novo->expiresIn);
            self::assertSame('2026-11-23 10:00:00', $this->em()->getConnection()->fetchOne('SELECT expira_em FROM conector_ia_token WHERE identificador_hash = ?', [hash('sha256', $renovacao)]), 'a renovação vale até o fim da concessão, nunca mais');
        }
        self::assertSame(1800, $novo->expiresIn, 'o acesso emitido a 30 minutos do fim vale só até o fim da concessão');
        self::assertSame('2026-11-23 10:00:00', $this->em()->getConnection()->fetchOne('SELECT expira_em FROM conector_ia_token WHERE identificador_hash = ?', [hash('sha256', $novo->accessToken)]), 'no banco o acesso também vence junto com a concessão');

        self::mockTime('2026-11-23 10:00:00');
        $this->assertRenovacaoRecusadaEDerrubada($renovacao, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao);
    }

    #[TestDox('Mesma renovação, mesmo app, dentro da janela (duas renovações seguidas): par novo e o anterior revogado; só um par vale no fim')]
    public function testRepeticaoNaJanelaDaUmParSo(): void
    {
        [$conexao, $par] = $this->conectado();
        $primeiro = $this->renovar($par->refreshToken, $conexao['cliente']);
        self::mockTime('+30 seconds');

        $segundo = $this->renovar($par->refreshToken, $conexao['cliente']);

        self::assertFalse($this->vale($primeiro->accessToken), 'o acesso da primeira rotação caiu');
        self::assertFalse($this->vale($primeiro->refreshToken), 'a renovação da primeira rotação caiu');
        self::assertTrue($this->vale($segundo->accessToken));
        self::assertTrue($this->vale($segundo->refreshToken));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT revogada_em FROM conector_ia_concessao WHERE id = ?', [$conexao['concessao']->getId()]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM conector_ia_token f JOIN conector_ia_token p ON p.id = f.gerado_por_id WHERE p.identificador_hash = ? AND f.tipo = 'acesso' AND f.revogado_em IS NULL",
            [hash('sha256', $par->refreshToken)],
        ), 'um par válido só');
    }

    #[TestDox('Mesma renovação fora da janela (61 s depois do uso): sinal de roubo, a concessão cai com renovacao_reutilizada, gravada')]
    public function testRepeticaoForaDaJanelaDerruba(): void
    {
        [$conexao, $par] = $this->conectado();
        $novo = $this->renovar($par->refreshToken, $conexao['cliente']);
        self::mockTime('+61 seconds');

        $this->assertRenovacaoRecusadaEDerrubada($par->refreshToken, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada);
        self::assertFalse($this->vale($novo->accessToken), 'o par vivo caiu junto');
    }

    #[TestDox('Repetição dentro da janela, mas a renovação filha já foi usada: a concessão cai')]
    public function testRepeticaoComAFilhaUsadaDerruba(): void
    {
        [$conexao, $par] = $this->conectado();
        $filho = $this->renovar($par->refreshToken, $conexao['cliente']);
        self::mockTime('+10 seconds');
        $this->renovar($filho->refreshToken, $conexao['cliente']);

        $this->assertRenovacaoRecusadaEDerrubada($par->refreshToken, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada);
    }

    #[TestDox('Renovação apresentada por outro app registrado, mesmo na primeira vez: a concessão cai')]
    public function testOutroAppDerruba(): void
    {
        [$conexao, $par] = $this->conectado();

        $this->assertRenovacaoRecusadaEDerrubada($par->refreshToken, $this->appDeIa('chatgpt.com'), (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada);
    }

    #[TestDox('Renovação apresentada com um client_id que nem é de app registrado: a concessão cai')]
    public function testClientIdDesconhecidoDerruba(): void
    {
        [$conexao, $par] = $this->conectado();

        $this->assertRenovacaoRecusadaEDerrubada($par->refreshToken, 'https://atacante.example/client.json', (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada);
    }

    #[TestDox('Renovação desconhecida, ou o token de acesso no lugar dela: invalid_grant')]
    public function testRenovacaoDesconhecidaRecusa(): void
    {
        [$conexao, $par] = $this->conectado();

        foreach (['nao-existe', $par->accessToken] as $valor) {
            try {
                $this->renovar($valor, $conexao['cliente']);
                self::fail('passou com ' . $valor);
            } catch (ErroOAuth $erro) {
                self::assertSame(ErroOAuth::INVALID_GRANT, $erro->codigo);
            }
        }
        self::assertTrue($this->vale($par->refreshToken), 'nada caiu');
    }

    #[TestDox('resource diferente na renovação: invalid_target, sem derrubar nada; ausente é aceito')]
    public function testResourceNaRenovacao(): void
    {
        [$conexao, $par] = $this->conectado();

        try {
            $this->renovar($par->refreshToken, $conexao['cliente'], 'https://outro.example/mcp');
            self::fail('passou com outro resource');
        } catch (ErroOAuth $erro) {
            self::assertSame(ErroOAuth::INVALID_TARGET, $erro->codigo);
        }
        self::assertTrue($this->vale($par->refreshToken));

        $this->renovar($par->refreshToken, $conexao['cliente']);
        $this->addToAssertionCount(1);
    }

    #[TestDox('12.6: consentimento revogado depois de conectar: a renovação dá invalid_grant e a concessão cai, gravada')]
    public function testConsentimentoRevogadoRecusa(): void
    {
        [$conexao, $par] = $this->conectado();
        $conexao['consentimento']->revogar($this->agora());
        $this->em()->flush();

        $this->assertRenovacaoRecusadaEDerrubada($par->refreshToken, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao);
    }

    /** @return iterable<string, array{string}> */
    public static function suspensoes(): iterable
    {
        yield 'conector desligado pelo administrador' => ['desligar'];
        yield 'escritório bloqueado pelo Master' => ['bloquear'];
    }

    /** @param array<string, mixed> $conexao */
    private function suspender(string $como, array $conexao): void
    {
        if ($como === 'desligar') {
            $conexao['liberacao']->desligar();
        } else {
            $conexao['liberacao']->bloquearPeloMaster();
        }
        $this->em()->flush();
    }

    #[DataProvider('suspensoes')]
    #[TestDox('12.24 ($como): a liberação desligada só suspende; a renovação gira normalmente e a concessão não cai')]
    public function testLiberacaoDesligadaRenovaSemRevogar(string $como): void
    {
        [$conexao, $par] = $this->conectado();
        $this->suspender($como, $conexao);
        self::mockTime('+50 minutes');

        $novo = $this->renovar($par->refreshToken, $conexao['cliente']);

        self::assertSame('mcp:leitura offline_access', $novo->scope);
        self::assertTrue($this->vale($novo->accessToken), 'o acesso novo foi emitido; quem barra a suspensão é o /mcp');
        self::assertTrue($this->vale($novo->refreshToken));
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT revogada_em, motivo FROM conector_ia_concessao WHERE id = ?', [$conexao['concessao']->getId()]);
        self::assertIsArray($linha);
        self::assertNull($linha['revogada_em'], 'suspender não derruba a concessão');
        self::assertNull($linha['motivo']);
    }

    #[DataProvider('suspensoes')]
    #[TestDox('12.24 ($como): com o conector suspenso, o limite de 7 dias continua correndo: no fim, a renovação dá invalid_grant e a concessão cai')]
    public function testLiberacaoDesligadaNaoEstendeOsSeteDias(string $como): void
    {
        [$conexao, $par] = $this->conectado();
        $this->suspender($como, $conexao);

        self::mockTime('2026-11-22 09:00:00');
        $renovacao = $this->renovar($par->refreshToken, $conexao['cliente'])->refreshToken;

        self::mockTime('2026-11-23 10:00:00');
        $this->assertRenovacaoRecusadaEDerrubada($renovacao, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao);
    }

    #[TestDox('12.16: "Sair de todos os aparelhos" no mesmo segundo da autorização derruba a renovação já girada uma vez; autorizar depois do corte funciona')]
    public function testSairDeTodosOsAparelhos(): void
    {
        [$conexao, $par] = $this->conectado();
        $girada = $this->renovar($par->refreshToken, $conexao['cliente']);
        static::getContainer()->get(SairDeTodosOsAparelhosUseCase::class)->executar($conexao['pessoa']);

        $this->assertRenovacaoRecusadaEDerrubada($girada->refreshToken, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao);
        $this->assertAutorizarDeNovoFunciona($conexao);
    }

    #[TestDox('12.16: troca de senha pelo perfil (AlterarSenhaUseCase) derruba a renovação já girada; autorizar depois funciona')]
    public function testAlterarSenha(): void
    {
        [$conexao, $par] = $this->conectado();
        $girada = $this->renovar($par->refreshToken, $conexao['cliente']);
        static::getContainer()->get(AlterarSenhaUseCase::class)->executar($conexao['pessoa'], self::SENHA, 'Outra@Senha456');

        $this->assertRenovacaoRecusadaEDerrubada($girada->refreshToken, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao);
        $this->assertAutorizarDeNovoFunciona($conexao);
    }

    #[TestDox('12.16: troca de senha pelo link (RedefinirSenhaUseCase) derruba a renovação já girada; autorizar depois funciona')]
    public function testRedefinirSenha(): void
    {
        [$conexao, $par] = $this->conectado();
        $girada = $this->renovar($par->refreshToken, $conexao['cliente']);
        $this->em()->persist(new RedefinicaoSenha($conexao['pessoa'], RedefinicaoSenha::hashDoToken('link-conector'), new \DateTimeImmutable('+1 day'), '127.0.0.1'));
        $this->em()->flush();
        static::getContainer()->get(RedefinirSenhaUseCase::class)->executar('link-conector', 'Outra@Senha456');

        $this->assertRenovacaoRecusadaEDerrubada($girada->refreshToken, $conexao['cliente'], (int) $conexao['concessao']->getId(), MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao);
        $this->assertAutorizarDeNovoFunciona($conexao);
    }

    #[TestDox('Duas renovações ao mesmo tempo com a mesma renovação: a segunda espera a linha travada (FOR UPDATE) pela primeira')]
    public function testRenovacaoEsperaATrava(): void
    {
        $cadeia = $this->cadeiaConfirmadaPorFora();

        $this->assertEsperaATrava($cadeia, 'conector_ia_token', hash('sha256', $cadeia['renovacao']), fn () => $this->renovar($cadeia['renovacao'], $cadeia['cliente']));
    }

    #[TestDox('Mãe repetida na janela enquanto a filha gira: espera a trava da concessão, relê a filha usada e a concessão cai')]
    public function testRepeticaoDaMaeEsperaARotacaoDaFilha(): void
    {
        $cadeia = $this->cadeiaConfirmadaPorFora(jaGirada: true);
        $fora = $cadeia['conexao'];
        $doTeste = $this->em()->getConnection();
        try {
            // A rotação da filha, por fora: trava a concessão (a mesma ordem do UseCase) e marca a filha usada, sem confirmar.
            $fora->beginTransaction();
            self::assertNotFalse($fora->fetchOne('SELECT id FROM conector_ia_concessao WHERE id = ? FOR UPDATE', [$cadeia['concessaoId']]));
            $fora->executeStatement('UPDATE conector_ia_token SET usado_em = criado_em WHERE identificador_hash = ?', [hash('sha256', (string) $cadeia['filha'])]);
            $doTeste->executeStatement("SET LOCAL lock_timeout = '400ms'");

            // A mãe reapresentada na janela, ao mesmo tempo: espera a concessão, antes de ler a filha.
            $this->assertEsperouNaLeituraTravada('conector_ia_concessao', fn () => $this->renovar($cadeia['renovacao'], $cadeia['cliente']));

            // A rotação da filha termina; a mãe segue, relê a filha já usada e derruba a concessão.
            $fora->commit();
            $this->assertRenovacaoRecusadaEDerrubada($cadeia['renovacao'], $cadeia['cliente'], $cadeia['concessaoId'], MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada);
        } finally {
            // A queda gravada pela conexão do teste segura as linhas até o fim da transação do DAMA: solta antes de apagar
            // a cadeia por fora (senão o DELETE esperaria para sempre) e abre outra para o DAMA desfazer no fim.
            $doTeste->executeStatement('ROLLBACK');
            $doTeste->executeStatement('BEGIN');
            $this->apagarCadeiaPorFora($cadeia);
        }
    }

    /** @param array<string, mixed> $conexao */
    private function assertAutorizarDeNovoFunciona(array $conexao): void
    {
        self::mockTime('+1 second');
        $pessoa = $this->em()->find(\App\Entity\Auth\User::class, $conexao['pessoa']->getId());
        $consentimento = $this->em()->find(\App\Conector\Entity\ConsentimentoDoConector::class, $conexao['consentimento']->getId());
        self::assertNotNull($pessoa);
        self::assertNotNull($consentimento);
        [, $codigo] = $this->autorizarDeNovo($consentimento);
        $cliente = $consentimento->getCliente();

        $par = static::getContainer()->get(TrocarCodigoPorTokenUseCase::class)->executar($this->pedidoDoCodigo($codigo, $cliente));
        $this->renovar($par->refreshToken, $cliente);
        $this->addToAssertionCount(1);
    }
}
