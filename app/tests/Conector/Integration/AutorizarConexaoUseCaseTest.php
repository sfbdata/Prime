<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\AutorizarConexaoInput;
use App\Conector\DTO\PedidoDeAutorizacao;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\CodigoDoConectorRepository;
use App\Conector\Repository\ConcessaoDoConectorRepository;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\ImpressaoDaSenha;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\Service\RegistroDeClientes;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\UseCase\AutorizarConexaoUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;
use App\Repository\UserTenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tests\Conector\Support\CenarioDaAutorizacaoTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A autorização gravada no banco (spec mcp-remoto-oauth 4, 9.4, 13.2 e 14.a; 12.3, 12.24, 12.27): o escritório do
 * formulário é conferido contra os vínculos ativos, o portão (sem o passe do Master) e a liberação; consentimento,
 * concessão (impressão da senha, `autorizado_em` do relógio, `resource`) e código (só o hash, 10 minutos).
 *
 * O caso de uso é montado à mão para usar um relógio fixo; o resto sai do container (os mesmos repositórios, o mesmo
 * registro de clientes e o mesmo escopo de auditoria que o `AuditLogSubscriber` consulta).
 */
#[CoversClass(AutorizarConexaoUseCase::class)]
final class AutorizarConexaoUseCaseTest extends KernelTestCase
{
    use CenarioDaAutorizacaoTrait;

    private MockClock $relogio;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->relogio = new MockClock('2026-11-16 10:00:00');
    }

    private function sut(): AutorizarConexaoUseCase
    {
        $c = static::getContainer();

        return new AutorizarConexaoUseCase(
            $c->get(UserTenantRepository::class),
            $this->portao(),
            new LiberacaoDoConector($c->get(RecursosDoSistema::class), $c->get(EscritorioDoConectorRepository::class)),
            $c->get(RegistroDeClientes::class),
            $c->get(ConsentimentoDoConectorRepository::class),
            $c->get(ConcessaoDoConectorRepository::class),
            $c->get(CodigoDoConectorRepository::class),
            $c->get(ImpressaoDaSenha::class),
            new SegredoOpaco(),
            $this->relogio,
            $c->get(EscopoDeAuditoriaDePlataforma::class),
            $c->get(EntityManagerInterface::class),
        );
    }

    /** @param list<string> $escopos */
    private static function pedido(array $escopos = ['mcp:leitura', 'mcp:escrita', 'offline_access'], string $redirect = self::REDIRECT): PedidoDeAutorizacao
    {
        return new PedidoDeAutorizacao(self::CLIENT_ID, $redirect, 'xyz', self::DESAFIO, ServidorDeAutorizacao::RESOURCE, $escopos);
    }

    private static function input(?int $tenantId, bool $escrita = false): AutorizarConexaoInput
    {
        $input = new AutorizarConexaoInput();
        $input->pedido = 'id';
        $input->tenantId = $tenantId;
        $input->escrita = $escrita;

        return $input;
    }

    /** @return array{0: Tenant, 1: User} escritório liberado e pessoa com vínculo ativo nele */
    private function cenario(): array
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant);
        $this->liberarConector($tenant);

        return [$tenant, $pessoa];
    }

    private function codigo(string $valor): CodigoDoConector
    {
        $codigo = static::getContainer()->get(CodigoDoConectorRepository::class)->buscarPorIdentificadorHash(hash('sha256', $valor));
        self::assertInstanceOf(CodigoDoConector::class, $codigo, 'o banco guarda o código pelo SHA-256 do valor');

        return $codigo;
    }

    private function exigirRecusa(string $mensagem, AutorizarConexaoInput $input, User $pessoa): void
    {
        try {
            $this->sut()->executar($input, self::pedido(), $pessoa);
            self::fail('Autorizou; esperava a recusa: ' . $mensagem);
        } catch (AcessoDoConectorRecusado $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
        }
        self::assertSame(0, $this->em()->getRepository(ConcessaoDoConector::class)->count(['user' => $pessoa]), 'recusada, nada é gravado');
    }

    #[TestDox('autoriza: consentimento, concessão (impressão da senha, autorizado_em do relógio, resource) e código de 10 minutos só pelo hash')]
    public function testAutoriza(): void
    {
        [$tenant, $pessoa] = $this->cenario();

        $valor = $this->sut()->executar(self::input((int) $tenant->getId(), escrita: true), self::pedido(), $pessoa);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $valor);
        $codigo = $this->codigo($valor);
        $concessao = $codigo->getConcessao();
        self::assertSame(self::REDIRECT, $codigo->getRedirectUri());
        self::assertSame(self::DESAFIO, $codigo->getCodeChallenge());
        self::assertEquals($this->relogio->now()->modify('+10 minutes'), $codigo->getExpiraEm());
        self::assertSame($tenant->getId(), $codigo->getTenant()?->getId());
        self::assertSame($tenant->getId(), $concessao->getTenant()?->getId());
        self::assertSame($pessoa->getId(), $concessao->getUser()->getId());
        self::assertSame(['mcp:leitura', 'mcp:escrita', 'offline_access'], $concessao->getEscopos());
        self::assertSame(ServidorDeAutorizacao::RESOURCE, $concessao->getResource());
        self::assertEquals($this->relogio->now(), $concessao->getAutorizadoEm());
        self::assertTrue($concessao->impressaoDaSenhaBate(static::getContainer()->get(ImpressaoDaSenha::class)->de($pessoa)));
        self::assertSame(self::CLIENT_ID, $concessao->getCliente()->getIdentificador());
        self::assertTrue($concessao->getConsentimento()->isAtivo());
        self::assertSame(['mcp:leitura', 'mcp:escrita', 'offline_access'], $concessao->getConsentimento()->getEscopos());
    }

    #[TestDox('a gravação entra no audit_log com o escritório escolhido')]
    public function testAuditoriaNoEscritorioEscolhido(): void
    {
        [$tenant, $pessoa] = $this->cenario();

        $logs = $this->em()->getRepository(AuditLog::class);
        $doConsentimento = ['entityClass' => ConsentimentoDoConector::class, 'action' => 'create'];
        $antes = $logs->count($doConsentimento);

        $this->sut()->executar(self::input((int) $tenant->getId()), self::pedido(), $pessoa);

        self::assertSame($antes + 1, $logs->count($doConsentimento));
        self::assertSame(1, $logs->count($doConsentimento + ['tenantId' => $tenant->getId()]), 'o registro do consentimento cai na trilha do escritório escolhido');
    }

    #[TestDox('mcp:escrita só entra se o app pediu E a pessoa marcou; offline_access só se o app pediu')]
    public function testEscopos(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $outra = $this->pessoa();
        $this->vinculo($outra, $tenant);
        $terceira = $this->pessoa();
        $this->vinculo($terceira, $tenant);

        $semMarcar = $this->sut()->executar(self::input((int) $tenant->getId(), escrita: false), self::pedido(), $pessoa);
        $semPedir = $this->sut()->executar(self::input((int) $tenant->getId(), escrita: true), self::pedido(['mcp:leitura']), $outra);
        $nada = $this->sut()->executar(self::input((int) $tenant->getId(), escrita: true), self::pedido([]), $terceira);

        self::assertSame(['mcp:leitura', 'offline_access'], $this->codigo($semMarcar)->getConcessao()->getEscopos());
        self::assertSame(['mcp:leitura'], $this->codigo($semPedir)->getConcessao()->getEscopos());
        self::assertSame(['mcp:leitura'], $this->codigo($nada)->getConcessao()->getEscopos());
    }

    #[TestDox('reautorizar o mesmo app no mesmo escritório: o consentimento ativo fica e troca os escopos; a concessão é nova e a anterior cai como substituída')]
    public function testReautorizar(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $primeira = $this->codigo($this->sut()->executar(self::input((int) $tenant->getId(), escrita: true), self::pedido(), $pessoa))->getConcessao();

        $segunda = $this->codigo($this->sut()->executar(self::input((int) $tenant->getId(), escrita: false), self::pedido(), $pessoa))->getConcessao();

        self::assertSame($primeira->getConsentimento()->getId(), $segunda->getConsentimento()->getId());
        self::assertNotSame($primeira->getId(), $segunda->getId());
        self::assertSame(['mcp:leitura', 'offline_access'], $segunda->getConsentimento()->getEscopos());
        self::assertSame(['mcp:leitura', 'mcp:escrita', 'offline_access'], $primeira->getEscopos(), 'a concessão anterior guarda os escopos dela');

        $this->em()->refresh($primeira);
        self::assertSame(MotivoDaRevogacaoDaConcessao::Substituida, $primeira->getMotivo(), 'a cadeia antiga, com gravação, não fica viva');
        self::assertNotNull($primeira->getRevogadaEm());
        self::assertNull($segunda->getRevogadaEm(), 'a concessão nova vale');
    }

    #[TestDox('12.27: escritório forjado no formulário, sem vínculo da pessoa, recusa (mesmo liberado)')]
    public function testEscritorioSemVinculoRecusa(): void
    {
        [, $pessoa] = $this->cenario();
        $alheio = $this->escritorio('Alheio');
        $this->liberarConector($alheio);

        $this->exigirRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL, self::input((int) $alheio->getId()), $pessoa);
        $this->exigirRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL, self::input(null), $pessoa);
    }

    #[TestDox('12.27: vínculo suspenso pela equipe (inativo) recusa')]
    public function testVinculoInativoRecusa(): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $tenant);
        $this->liberarConector($tenant);
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();

        $this->exigirRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL, self::input((int) $tenant->getId()), $pessoa);
    }

    #[TestDox('12.3: o primário de conta suspensa a pedido é recusado com a mensagem do pedido de exclusão')]
    public function testPrimarioSuspensoAPedido(): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $primario = $this->pessoa();
        $this->vinculo($primario, $tenant, sistema: true);
        $this->liberarConector($tenant);
        $this->em()->persist(new PedidoDeExclusaoDeConta($tenant, $primario, 'Não uso mais', new \DateTimeImmutable()));
        $this->em()->flush();

        $this->exigirRecusa(BloqueioPorPedidoDeExclusao::MENSAGEM, self::input((int) $tenant->getId()), $primario);
    }

    /**
     * O vínculo em análise fica inativo (`UserTenant::alterarSituacao`), então ele nem está entre os vínculos ativos que
     * a 9.4 manda conferir: a recusa é a do vínculo indisponível. No navegador o Master passaria por esta barreira.
     */
    #[TestDox('12.3: o Master com vínculo em análise é recusado (sem o passe do Master)')]
    public function testMasterEmAnalise(): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $master = $this->pessoa(['ROLE_SUPER_ADMIN']);
        $vinculo = $this->vinculo($master, $tenant);
        $this->liberarConector($tenant);
        $vinculo->alterarSituacao(SituacaoNaEquipe::EmAnalise);
        $this->em()->flush();

        $this->exigirRecusa(AcessoDoConectorRecusado::ACESSO_INDISPONIVEL, self::input((int) $tenant->getId()), $master);
    }

    #[TestDox('12.24: chave geral desligada recusa')]
    public function testChaveGeralDesligada(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $this->desligarChaveGeral();

        $this->exigirRecusa(AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO, self::input((int) $tenant->getId()), $pessoa);
    }

    #[TestDox('12.24: escritório não liberado pelo Master recusa')]
    public function testNaoLiberado(): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant);
        $this->liberarConector($tenant, liberado: false);

        $this->exigirRecusa(AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO, self::input((int) $tenant->getId()), $pessoa);
    }

    #[TestDox('12.24: escritório liberado e não ligado recusa')]
    public function testNaoLigado(): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant);
        $this->liberarConector($tenant, ligado: false);

        $this->exigirRecusa(AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO, self::input((int) $tenant->getId()), $pessoa);
    }

    #[TestDox('12.24: aceite em versão anterior à vigente recusa')]
    public function testAceiteEmVersaoAntiga(): void
    {
        $this->clienteValido();
        $tenant = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $tenant);
        $this->aceiteEmVersaoAntiga($this->liberarConector($tenant));

        $this->exigirRecusa(AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO, self::input((int) $tenant->getId()), $pessoa);
    }

    #[TestDox('12.24: liberar o escritório A não libera o B da mesma pessoa')]
    public function testLiberarANaoLiberaB(): void
    {
        [$a, $pessoa] = $this->cenario();
        $b = $this->escritorio('B');
        $this->vinculo($pessoa, $b);

        $this->sut()->executar(self::input((int) $a->getId()), self::pedido(), $pessoa);
        try {
            $this->sut()->executar(self::input((int) $b->getId()), self::pedido(), $pessoa);
            self::fail('B não foi liberado.');
        } catch (AcessoDoConectorRecusado $recusa) {
            self::assertSame(AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO, $recusa->getMessage());
        }
    }

    #[TestDox('o app deixou de ser aceito entre o GET e o POST (desativado): recusa como cliente, sem gravar')]
    public function testClienteDesativadoNoMeio(): void
    {
        [$tenant, $pessoa] = $this->cenario();
        $this->em()->getRepository(\App\Conector\Entity\ClienteDoConector::class)->findOneBy(['identificador' => self::CLIENT_ID])?->desativar();
        $this->em()->flush();

        $this->expectException(ClienteRecusado::class);
        $this->sut()->executar(self::input((int) $tenant->getId()), self::pedido(), $pessoa);
    }
}
