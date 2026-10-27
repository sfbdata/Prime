<?php

declare(strict_types=1);

namespace App\Tests\Pagamento\Functional;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Contribua\Controller\WebhookDoBancoController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pagamento\Command\ConciliarPagamentosCommand;
use App\Pagamento\DTO\CriarPagamentoInput;
use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Enum\MetodoDePagamento;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\ResultadoDaReconsulta;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\Message\ReconsultarPagamentoNoBanco;
use App\Pagamento\MessageHandler\ReconsultarPagamentoNoBancoHandler;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use App\Pagamento\UseCase\ConfirmarPagamentoPeloBancoUseCase;
use App\Pagamento\UseCase\CriarPagamentoUseCase;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Gateway M0b contra uma Cora falsa: o aviso (sem corpo, sem assinatura) só enfileira; a reconsulta mTLS é quem
 * confirma, uma vez; a conciliação cobre o aviso perdido e expira o vencido. Spec: docs/specs/gateway-m0b-webhook-conciliacao.md.
 * A Cora falsa responde sempre R$ 20,00 pagos (2000 centavos) para uma cobrança paga: o valor cobrado nos testes
 * escolhe se o pago é igual, menor ou maior.
 */
#[CoversClass(WebhookDoBancoController::class)]
#[CoversClass(ConfirmarPagamentoPeloBancoUseCase::class)]
#[CoversClass(ReconsultarPagamentoNoBancoHandler::class)]
#[CoversClass(ConciliarPagamentosCommand::class)]
final class ReconsultaDoPagamentoTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CoraFalsa $cora;
    private Tenant $escritorio;
    private User $master;
    private int $sequencia = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->cora = new CoraFalsa();
        $cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        static::getContainer()->set(CofreDeCertificado::class, $cofre);
        static::getContainer()->set(ClienteCora::class, new ClienteCora($this->cora->cliente(), new ArrayAdapter(), $cofre));
        $this->instalarCsrf();
        // O resumo diário guarda a marca no cache da aplicação (que sobrevive entre execuções do teste).
        static::getContainer()->get('cache.app')->deleteItem('pagamento_resumo_de_divergentes_' . (new \DateTimeImmutable())->format('Ymd'));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant();
        $this->master = $this->pessoa('master', ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('Aviso de recurso do registro de pagamentos só enfileira a reconsulta; repetido, alheio e sem cabeçalho não fazem nada')]
    public function testWebhookEnfileira(): void
    {
        $this->conectar();
        $pagamento = $this->cobranca(2000);

        $this->webhook('evt_1', 'invoice.paid', $pagamento['id']);
        self::assertResponseIsSuccessful();
        self::assertEquals([new ReconsultarPagamentoNoBanco('cora', $pagamento['id'], 'evt_1')], $this->enfileiradas());
        $this->webhook('evt_1', 'invoice.paid', $pagamento['id']);
        self::assertSame([], $this->enfileiradas(), 'aviso repetido não enfileira');
        $this->webhook('evt_2', 'invoice.paid', 'inv_de_outro_sistema');
        self::assertSame([], $this->enfileiradas(), 'recurso alheio não enfileira');
        $this->client->request('POST', '/api/webhooks/cora/pix');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->enfileiradas());
        self::assertSame(1, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM contribua_notificacao_recebida WHERE origem = 'cora'"), 'só o aviso nosso foi gravado');
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($pagamento['id'])->getStatus(), 'o aviso sozinho não confirma nada');
    }

    #[TestDox('A reconsulta confirma uma vez: paga, trilha do banco, aviso processado; repetir não muda nada')]
    public function testReconsultaConfirma(): void
    {
        $this->conectar();
        $pagamento = $this->cobranca(2000);
        $this->cora->status[$pagamento['id']] = 'PAID';
        $this->webhook('evt_1', 'invoice.paid', $pagamento['id']);
        $mensagem = $this->enfileiradas()[0];

        static::getContainer()->get(ReconsultarPagamentoNoBancoHandler::class)($mensagem);
        $confirmado = $this->recarregar($pagamento['id']);
        self::assertSame(StatusDoPagamento::Paga, $confirmado->getStatus());
        self::assertSame(2000, $confirmado->getValorPagoCentavos());
        self::assertSame('banco', $confirmado->getConfirmadoPor());
        self::assertNotNull($this->banco()->fetchOne("SELECT processada_em FROM contribua_notificacao_recebida WHERE origem = 'cora' AND id_evento = 'evt_1'"));
        $eventos = $this->tiposDeEvento($pagamento['id']);

        self::assertSame(ResultadoDaReconsulta::Ignorado, $this->reconsultar($pagamento['id']), 'paga não é mais reconsultada');
        self::assertSame($eventos, $this->tiposDeEvento($pagamento['id']));
    }

    #[TestDox('Valor menor fica divergente e avisa o Master; valor maior libera e avisa a diferença')]
    public function testValoresQueNaoBatem(): void
    {
        $this->conectar();
        $menor = $this->cobranca(3000);
        $maior = $this->cobranca(1000);
        $this->cora->status[$menor['id']] = 'PAID';
        $this->cora->status[$maior['id']] = 'PAID';

        self::assertSame(ResultadoDaReconsulta::Divergente, $this->reconsultar($menor['id']));
        self::assertSame(StatusDoPagamento::Divergente, $this->recarregar($menor['id'])->getStatus(), 'valor menor não libera');
        self::assertSame(ResultadoDaReconsulta::Confirmado, $this->reconsultar($maior['id']));
        self::assertSame(StatusDoPagamento::Paga, $this->recarregar($maior['id'])->getStatus(), 'valor maior libera');
        self::assertSame(2, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'pagamento.divergente' AND usuario_id = :u", ['u' => $this->master->getId()]));
        self::assertSame(ResultadoDaReconsulta::Ignorado, $this->reconsultar($menor['id']), 'divergente espera a decisão do Master e não é reconsultado');
        self::assertSame(2, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'pagamento.divergente' AND usuario_id = :u", ['u' => $this->master->getId()]), 'sem aviso repetido');
    }

    #[TestDox('Cobrança cancelada no banco é cancelada aqui; ainda aberta no banco fica aguardando')]
    public function testCanceladaEAberta(): void
    {
        $this->conectar();
        $cancelada = $this->cobranca(2000);
        $aberta = $this->cobranca(2000);
        $this->cora->status[$cancelada['id']] = 'CANCELLED';

        self::assertSame(ResultadoDaReconsulta::Cancelado, $this->reconsultar($cancelada['id']));
        self::assertSame(StatusDoPagamento::Cancelada, $this->recarregar($cancelada['id'])->getStatus());
        self::assertSame(ResultadoDaReconsulta::Aguardando, $this->reconsultar($aberta['id']));
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($aberta['id'])->getStatus());
    }

    #[TestDox('O banco devolve outro código para a cobrança: nada é confirmado e a trilha registra, uma vez')]
    public function testCodigoQueNaoBate(): void
    {
        $this->conectar();
        $pagamento = $this->cobranca(2000, 'OUTRO-CODIGO');
        $this->cora->status[$pagamento['id']] = 'PAID';

        self::assertSame(ResultadoDaReconsulta::Inconsistente, $this->reconsultar($pagamento['id']));
        self::assertSame(ResultadoDaReconsulta::Inconsistente, $this->reconsultar($pagamento['id']));
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($pagamento['id'])->getStatus());
        self::assertSame(1, array_count_values($this->tiposDeEvento($pagamento['id']))['inconsistente']);
    }

    #[TestDox('No ambiente de testes (sandbox) nada é confirmado: a trilha registra o teste uma vez e o Master é avisado')]
    public function testSandboxNaoConfirma(): void
    {
        $this->conectar('sandbox');
        $pagamento = $this->cobranca(2000);
        $this->cora->status[$pagamento['id']] = 'PAID';

        self::assertSame(ResultadoDaReconsulta::Teste, $this->reconsultar($pagamento['id']));
        self::assertSame(ResultadoDaReconsulta::Teste, $this->reconsultar($pagamento['id']));
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($pagamento['id'])->getStatus());
        self::assertSame(1, array_count_values($this->tiposDeEvento($pagamento['id']))['teste']);
        self::assertSame(1, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'pagamento.divergente' AND usuario_id = :u", ['u' => $this->master->getId()]));
    }

    #[TestDox('Sem integração conectada a reconsulta não faz nada')]
    public function testSemIntegracao(): void
    {
        $pagamento = $this->cobranca(2000);
        $this->cora->status[$pagamento['id']] = 'PAID';

        self::assertSame(ResultadoDaReconsulta::Ignorado, $this->reconsultar($pagamento['id']));
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($pagamento['id'])->getStatus());
    }

    #[TestDox('Conciliação: confirma o aviso perdido, deixa a recém-criada em paz, expira a vencida sem pagamento e não expira a que o banco deu por paga')]
    public function testConciliacao(): void
    {
        $this->conectar();
        $perdida = $this->cobranca(2000);
        $nova = $this->cobranca(2000);
        $vencida = $this->cobranca(2000);
        $vencidaMasPaga = $this->cobranca(2000);
        $this->cora->status[$perdida['id']] = 'PAID';
        $this->cora->status[$nova['id']] = 'PAID';
        $this->cora->status[$vencidaMasPaga['id']] = 'PAID';
        $this->envelhecer([$perdida['id'], $vencida['id'], $vencidaMasPaga['id']]);
        $this->vencer([$vencida['id'], $vencidaMasPaga['id']]);

        $saida = $this->conciliar();

        self::assertSame(StatusDoPagamento::Paga, $this->recarregar($perdida['id'])->getStatus(), 'aviso perdido confirmado');
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($nova['id'])->getStatus(), 'com menos de 2 minutos ainda espera o aviso');
        self::assertSame(StatusDoPagamento::Expirada, $this->recarregar($vencida['id'])->getStatus(), 'vencida sem pagamento expira');
        self::assertSame(StatusDoPagamento::Paga, $this->recarregar($vencidaMasPaga['id'])->getStatus(), 'pagou no último minuto: reconsulta antes de expirar');
        self::assertStringContainsString('2 confirmado(s)', $saida);
        self::assertStringContainsString('1 expirado(s)', $saida);
        self::assertContains('expirado', $this->tiposDeEvento($vencida['id']));
    }

    #[TestDox('Conciliação: sem integração conectada não consulta nem expira nada; divergente aberto gera um resumo por dia')]
    public function testConciliacaoSemIntegracaoEResumo(): void
    {
        $vencida = $this->cobranca(2000);
        $this->vencer([$vencida['id']]);
        self::assertStringContainsString('Nada a fazer', $this->conciliar());
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($vencida['id'])->getStatus());

        $this->conectar();
        $menor = $this->cobranca(3000);
        $this->cora->status[$menor['id']] = 'PAID';
        $this->reconsultar($menor['id']);
        $antes = (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'pagamento.divergente'");
        $this->conciliar();
        $this->conciliar();
        self::assertSame($antes + 1, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'pagamento.divergente'"), 'um resumo por dia');
    }

    /** @return array{id: string, pagamento: int} uma cobrança no registro, com id no banco, e o espelho dela na Cora falsa */
    #[TestDox('Conciliação: cobrança que falha no banco não trava as outras e não expira sem ser conferida')]
    public function testConciliacaoIsolaACobrancaComFalha(): void
    {
        $this->conectar();
        $comFalha = $this->cobranca(2000);
        $normal = $this->cobranca(2000);
        $this->envelhecer([$comFalha['id'], $normal['id']]);
        $this->vencer([$comFalha['id'], $normal['id']]);
        unset($this->cora->status[$comFalha['id']]);

        $saida = $this->conciliar();

        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($comFalha['id'])->getStatus(), 'não conferida: não expira');
        self::assertSame(StatusDoPagamento::Expirada, $this->recarregar($normal['id'])->getStatus(), 'a outra segue o fluxo');
        self::assertStringContainsString('1 falha(s)', $saida);
        self::assertStringContainsString('1 expirado(s)', $saida);
    }

    #[TestDox('Conciliação: sem validade e com id no banco, só entra no lote enquanto nova; vencida sem id no banco expira sem consulta')]
    public function testConciliacaoSemValidadeESemId(): void
    {
        $this->conectar();
        $velha = $this->cobranca(2000);
        $semId = $this->cobranca(2000);
        $this->banco()->executeStatement('UPDATE pagamento SET expira_em = NULL, criado_em = :c WHERE id_externo = :i', ['c' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'), 'i' => $velha['id']]);
        $this->banco()->executeStatement('UPDATE pagamento SET id_externo = NULL, expira_em = :e WHERE id = :i', ['e' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'i' => $semId['pagamento']]);
        $this->cora->status[$velha['id']] = 'PAID';

        $this->conciliar();

        $this->em->clear();
        self::assertSame(StatusDoPagamento::Aguardando, $this->em->find(Pagamento::class, $velha['pagamento'])->getStatus(), 'sem validade e antiga: fora do lote');
        self::assertSame(StatusDoPagamento::Expirada, $this->em->find(Pagamento::class, $semId['pagamento'])->getStatus());
    }

    #[TestDox('O banco dá a cobrança por paga sem código: nada é confirmado, fica aguardando')]
    public function testPagaSemCodigoNaoConfirma(): void
    {
        $this->conectar();
        $pagamento = $this->cobranca(2000);
        $this->cora->cobrancaExistente($pagamento['id'], 'PAID', null);

        self::assertSame(ResultadoDaReconsulta::Aguardando, $this->reconsultar($pagamento['id']));
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($pagamento['id'])->getStatus());
    }

    #[TestDox('Dois pagamentos com o mesmo id no banco viram divergentes com aviso, sem escolher um à sorte')]
    public function testIdRepetidoViraDivergente(): void
    {
        $this->conectar();
        $a = $this->cobranca(2000);
        $b = $this->cobranca(2000);
        $this->banco()->executeStatement('UPDATE pagamento SET id_externo = :i WHERE id = :b', ['i' => $a['id'], 'b' => $b['pagamento']]);
        $this->cora->status[$a['id']] = 'PAID';

        self::assertSame(ResultadoDaReconsulta::Divergente, $this->reconsultar($a['id']));
        self::assertSame(ResultadoDaReconsulta::Divergente, $this->reconsultar($a['id']));

        $this->em->clear();
        self::assertSame(StatusDoPagamento::Divergente, $this->em->find(Pagamento::class, $a['pagamento'])->getStatus());
        self::assertSame(StatusDoPagamento::Divergente, $this->em->find(Pagamento::class, $b['pagamento'])->getStatus());
        self::assertSame(2, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'pagamento.divergente' AND usuario_id = :u", ['u' => $this->master->getId()]));
    }

    private function cobranca(int $valor, ?string $codigoNoBanco = null): array
    {
        $referencia = 'TE' . strtoupper(substr(uniqid(), -8)) . '-' . ++$this->sequencia;
        $c = static::getContainer();
        $criada = (new CriarPagamentoUseCase($c->get(PagamentoRepository::class), $c->get(PagamentoEventoRepository::class), $this->em))->executar(new CriarPagamentoInput(
            (int) $this->escritorio->getId(),
            OrigemDoPagamento::TrabalhoExterno,
            1000 + $this->sequencia,
            $referencia,
            $valor,
            MetodoDePagamento::Pix,
            'cora',
            new \DateTimeImmutable('+24 hours'),
        ));
        $id = 'inv_' . substr(sha1($referencia), 0, 20);
        $pagamento = $this->em->find(Pagamento::class, $criada->id);
        $pagamento->registrarIdExterno($id);
        $this->em->flush();
        $this->cora->cobrancaExistente($id, 'OPEN', $codigoNoBanco ?? $referencia);

        return ['id' => $id, 'pagamento' => (int) $criada->id];
    }

    private function reconsultar(string $idNoBanco): ResultadoDaReconsulta
    {
        $this->em->clear();

        return static::getContainer()->get(ConfirmarPagamentoPeloBancoUseCase::class)->executar('cora', $idNoBanco);
    }

    private function recarregar(string $idNoBanco): Pagamento
    {
        $this->em->clear();

        return static::getContainer()->get(PagamentoRepository::class)->porIdExterno('cora', $idNoBanco) ?? throw new \LogicException('pagamento não achado');
    }

    /** @return list<string> */
    private function tiposDeEvento(string $idNoBanco): array
    {
        return array_map('strval', $this->banco()->fetchFirstColumn('SELECT e.tipo FROM pagamento_evento e JOIN pagamento p ON p.id = e.pagamento_id WHERE p.id_externo = :i ORDER BY e.id', ['i' => $idNoBanco]));
    }

    /**
     * Datas calculadas no PHP (a coluna guarda a hora local sem fuso; o NOW() do banco é outro fuso).
     *
     * @param list<string> $ids
     */
    private function envelhecer(array $ids): void
    {
        $this->banco()->executeStatement('UPDATE pagamento SET criado_em = :c WHERE id_externo IN (:ids)', ['c' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'), 'ids' => $ids], ['ids' => ArrayParameterType::STRING]);
    }

    /** @param list<string> $ids */
    private function vencer(array $ids): void
    {
        $this->banco()->executeStatement('UPDATE pagamento SET expira_em = :e, criado_em = :c WHERE id_externo IN (:ids)', [
            'e' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'c' => (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'),
            'ids' => $ids,
        ], ['ids' => ArrayParameterType::STRING]);
    }

    private function conciliar(): string
    {
        $this->em->clear();
        $aplicacao = new Application($this->client->getKernel());
        $teste = new CommandTester($aplicacao->find('app:pagamento:conciliar'));
        $teste->execute([]);

        return $teste->getDisplay();
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @return list<object> */
    private function enfileiradas(): array
    {
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');

        return array_values(array_map(static fn ($e) => $e->getMessage(), $fila->getSent()));
    }

    private function webhook(string $evento, string $tipo, string $recurso): void
    {
        $this->client->request('POST', '/api/webhooks/cora/pix', [], [], ['HTTP_WEBHOOK_EVENT_ID' => $evento, 'HTTP_WEBHOOK_EVENT_TYPE' => $tipo, 'HTTP_WEBHOOK_RESOURCE_ID' => $recurso, 'HTTP_USER_AGENT' => 'Cora-Webhook']);
    }

    private function conectar(string $ambiente = 'producao'): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $par = ParDeCertificado::novo();
        $dir = sys_get_temp_dir() . '/bj-cert-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/cert.pem', $par['certificado']);
        file_put_contents($dir . '/chave.key', $par['chave']);
        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => self::TOKEN], ['arquivos' => [
            new UploadedFile($dir . '/cert.pem', 'cert.pem', null, null, true),
            new UploadedFile($dir . '/chave.key', 'chave.key', null, null, true),
        ]]);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['ok'], (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => $ambiente, 'clientId' => 'cliente-1', 'recursos' => ['cob' => '1', 'conc' => '1', 'bol' => '0', 'rel' => '1']])['ok']);
        self::assertTrue($this->post('/contribua/integracao-bancaria/testar')['conectado']);
    }

    /**
     * @param array<string, string|array<string, string>> $campos
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $campos = []): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function tenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório PAGAMENTO ' . uniqid());
        $tenant->setCnpj('12345678000199');
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, array $roles): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Primário ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $vinculo = new UserTenant($user, $this->escritorio);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function instalarCsrf(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }
}
