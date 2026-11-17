<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\RespostaDeTokenOutput;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Exception\ErroOAuth;
use App\Conector\UseCase\RevogarConexaoUseCase;
use App\Conector\UseCase\TrocarCodigoPorTokenUseCase;
use App\Conector\UseCase\TrocarRenovacaoUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CadeiaDoMcpTrait;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A pessoa revoga uma conexão de I.A. no Meu Perfil (`docs/specs/mcp-remoto-oauth.md`, seção 4 regra 3, 13.2 e PR 8),
 * no banco: o consentimento cai, as concessões abertas caem com `revogada_pela_pessoa`, e com isso o token de acesso, a
 * renovação e o código ainda não trocado param. A sessão fica em OUTRO escritório da pessoa (o `TenantFilter` ligado
 * nele), como no Meu Perfil. Conexão alheia não existe para quem pede, e revogar de novo não muda nada.
 */
#[CoversClass(RevogarConexaoUseCase::class)]
final class RevogarConexaoUseCaseTest extends KernelTestCase
{
    use CadeiaDoMcpTrait;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-16 10:00:00');
    }

    private function revogarConexao(): RevogarConexaoUseCase
    {
        return static::getContainer()->get(RevogarConexaoUseCase::class);
    }

    /**
     * A conexão autorizada, o primeiro par trocado pelo código e uma segunda autorização com o código ainda pendente.
     *
     * @return array{0: array<string, mixed>, 1: RespostaDeTokenOutput, 2: string}
     */
    private function conectado(): array
    {
        $conexao = $this->conexaoAutorizada();
        $par = static::getContainer()->get(TrocarCodigoPorTokenUseCase::class)->executar($this->pedidoDoCodigo($conexao['codigo'], $conexao['cliente']));
        [, $codigoPendente] = $this->autorizarDeNovo($conexao['consentimento']);

        return [$conexao, $par, $codigoPendente];
    }

    /** A sessão da pessoa num outro escritório dela: o `TenantFilter` ligado nele, como o listener faz no Meu Perfil. */
    private function sessaoEmOutroEscritorio(ConsentimentoDoConector $consentimento): Tenant
    {
        $outro = $this->escritorio('Outro');
        $this->vinculo($consentimento->getUser(), $outro);
        $this->em()->getFilters()->enable('tenant')->setParameter('tenant', (int) $outro->getId(), Types::INTEGER);

        return $outro;
    }

    private function semSessao(): void
    {
        $filtros = $this->em()->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
        $this->em()->clear();
    }

    /** @return list<array{revogada_em: ?string, motivo: ?string}> as concessões do consentimento, lidas no banco */
    private function concessoesNoBanco(int $consentimentoId): array
    {
        /** @var list<array{revogada_em: ?string, motivo: ?string}> */
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT revogada_em, motivo FROM conector_ia_concessao WHERE consentimento_id = ? ORDER BY id',
            [$consentimentoId],
        );
    }

    private function revogadoEmNoBanco(int $consentimentoId): ?string
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT revogado_em FROM conector_ia_consentimento WHERE id = ?', [$consentimentoId]);

        return $valor === null ? null : (string) $valor;
    }

    private function exigirNaoEncontrada(int $id, User $pessoa): void
    {
        try {
            $this->revogarConexao()->executar($id, $pessoa);
            self::fail('revogou; esperado "não encontrada"');
        } catch (\DomainException $erro) {
            self::assertSame(RevogarConexaoUseCase::NAO_ENCONTRADA, $erro->getMessage());
        }
    }

    private function exigirInvalidGrant(callable $troca): void
    {
        try {
            $troca();
            self::fail('a troca passou; esperado invalid_grant');
        } catch (ErroOAuth $erro) {
            self::assertSame(ErroOAuth::INVALID_GRANT, $erro->codigo);
        }
    }

    #[TestDox('Revoga a conexão de outro escritório da pessoa: consentimento revogado e as duas concessões com revogada_pela_pessoa; o filtro volta no escritório da sessão')]
    public function testRevogaConsentimentoEConcessoes(): void
    {
        [$conexao] = $this->conectado();
        $id = (int) $conexao['consentimento']->getId();
        $sessao = $this->sessaoEmOutroEscritorio($conexao['consentimento']);

        $app = $this->revogarConexao()->executar($id, $conexao['pessoa']);

        self::assertSame('claude.ai', $app, 'o host do client_id, não o client_name');
        $filtros = $this->em()->getFilters();
        self::assertTrue($filtros->isEnabled('tenant'));
        self::assertSame((string) $sessao->getId(), trim($filtros->getFilter('tenant')->getParameter('tenant'), "'"));
        self::assertSame('2026-11-16 10:00:00', $this->revogadoEmNoBanco($id));
        $concessoes = $this->concessoesNoBanco($id);
        self::assertCount(2, $concessoes);
        foreach ($concessoes as $concessao) {
            self::assertSame('2026-11-16 10:00:00', $concessao['revogada_em']);
            self::assertSame(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa->value, $concessao['motivo']);
        }
    }

    #[TestDox('Depois de revogar: o validador recusa o token de acesso, a renovação dá invalid_grant sem trocar o motivo e o código pendente é recusado')]
    public function testTokensParamDepoisDeRevogar(): void
    {
        [$conexao, $par, $codigoPendente] = $this->conectado();
        $id = (int) $conexao['consentimento']->getId();
        self::assertTrue($this->validador()->validate($par->accessToken)->isAllowed(), 'sanidade: o token valia antes');
        $this->validador()->reset();
        $this->sessaoEmOutroEscritorio($conexao['consentimento']);

        $this->revogarConexao()->executar($id, $conexao['pessoa']);
        $this->semSessao();
        self::mockTime('+1 minute');

        self::assertSame(401, $this->validador()->validate($par->accessToken)->getStatusCode());
        $this->exigirInvalidGrant(fn () => static::getContainer()->get(TrocarRenovacaoUseCase::class)->executar($this->pedidoDeRenovacao($par->refreshToken, $conexao['cliente'])));
        $this->exigirInvalidGrant(fn () => static::getContainer()->get(TrocarCodigoPorTokenUseCase::class)->executar($this->pedidoDoCodigo($codigoPendente, $conexao['cliente'])));
        $this->em()->clear();
        foreach ($this->concessoesNoBanco($id) as $concessao) {
            self::assertSame(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa->value, $concessao['motivo'], 'a recusa da troca não troca o motivo');
            self::assertSame('2026-11-16 10:00:00', $concessao['revogada_em']);
        }
    }

    #[TestDox('Conexão de outra pessoa, no mesmo escritório ou em outro, e id inexistente: não encontrada, e nada muda')]
    public function testConexaoAlheiaNaoEncontrada(): void
    {
        [$daOutra] = $this->conectado();
        [$deOutroEscritorio] = $this->conectado();
        $pessoa = $this->pessoaComSenha($daOutra['escritorio']);
        $this->sessaoEmOutroEscritorio($daOutra['consentimento']);

        $this->exigirNaoEncontrada((int) $daOutra['consentimento']->getId(), $pessoa);
        $this->exigirNaoEncontrada((int) $deOutroEscritorio['consentimento']->getId(), $pessoa);
        $this->exigirNaoEncontrada(999999999, $pessoa);

        foreach ([$daOutra, $deOutroEscritorio] as $conexao) {
            $id = (int) $conexao['consentimento']->getId();
            self::assertNull($this->revogadoEmNoBanco($id));
            foreach ($this->concessoesNoBanco($id) as $concessao) {
                self::assertNull($concessao['revogada_em']);
                self::assertNull($concessao['motivo']);
            }
        }
    }

    #[TestDox('Revogar de novo não muda nada: a data da revogação e o motivo ficam os da primeira vez')]
    public function testRevogarDeNovoNaoMudaNada(): void
    {
        [$conexao] = $this->conectado();
        $id = (int) $conexao['consentimento']->getId();
        $this->revogarConexao()->executar($id, $conexao['pessoa']);
        self::mockTime('+10 minutes');

        $this->exigirNaoEncontrada($id, $conexao['pessoa']);

        $this->em()->clear();
        self::assertSame('2026-11-16 10:00:00', $this->revogadoEmNoBanco($id));
        foreach ($this->concessoesNoBanco($id) as $concessao) {
            self::assertSame('2026-11-16 10:00:00', $concessao['revogada_em']);
            self::assertSame(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa->value, $concessao['motivo']);
        }
    }

    #[TestDox('A revogação entra no audit_log com o escritório da conexão')]
    public function testAuditoriaComOEscritorioDaConexao(): void
    {
        [$conexao] = $this->conectado();
        $id = (int) $conexao['consentimento']->getId();

        $this->revogarConexao()->executar($id, $conexao['pessoa']);

        $registros = $this->em()->getRepository(AuditLog::class)->findBy(['entityClass' => ConsentimentoDoConector::class, 'entityId' => (string) $id, 'action' => 'update']);
        self::assertCount(1, $registros);
        self::assertSame($conexao['escritorio']->getId(), $registros[0]->getTenantId());
    }
}
