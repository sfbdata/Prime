<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Message\EnviarEmail;
use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\MessageHandler\VarrerAutomacoesHandler;
use App\Comercial\Service\RecuperacaoDeEnvios;
use App\Comercial\UseCase\PurgarDadosDoComercialUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A varredura, a recuperação de envios parados e as purgas, contra o banco (spec comercial-e-emails.md §3.1, §3.2 e
 * §3.4): uma volta por janela (a repetida não faz nada), sem reagendamento (quem dispara é o schedule `cadeias`), o
 * redespacho só do envio perdido e a purga de escritório apagando os envios e retendo a prova de consentimento.
 */
#[CoversClass(VarrerAutomacoesHandler::class)]
#[CoversClass(RecuperacaoDeEnvios::class)]
#[CoversClass(PurgarDadosDoComercialUseCase::class)]
final class VarreduraEPurgaComercialTest extends KernelTestCase
{
    private Connection $conexao;
    private InMemoryTransport $fila;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->fila = static::getContainer()->get('messenger.transport.comercial');
        $this->fila->reset();
    }

    #[TestDox('A volta reivindica a vez por meio intervalo (450 s) e não se reagenda; a repetida dentro da janela não faz nada')]
    public function testUmaVoltaPorJanela(): void
    {
        // Pela hora do PHP (a do sistema), não pelo NOW() do banco, que está em UTC.
        $this->conexao->executeStatement('UPDATE comercial_varredura SET proxima_em = ? WHERE id = 1', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $handler = static::getContainer()->get(VarrerAutomacoesHandler::class);
        $antes = new \DateTimeImmutable();

        $handler(new VarrerAutomacoes());
        $proxima = new \DateTimeImmutable((string) $this->conexao->fetchOne('SELECT proxima_em FROM comercial_varredura WHERE id = 1'));
        self::assertEqualsWithDelta($antes->getTimestamp() + VarrerAutomacoesHandler::VEZ_SEGUNDOS, $proxima->getTimestamp(), 5, 'a vez seguinte abre em meio intervalo');

        $marcador = '2000-01-01 00:00:00';
        $this->conexao->executeStatement('UPDATE comercial_varredura SET executada_em = ? WHERE id = 1', [$marcador]);
        $handler(new VarrerAutomacoes());
        self::assertSame($marcador, $this->conexao->fetchOne('SELECT executada_em FROM comercial_varredura WHERE id = 1'), 'a segunda, dentro da janela, não reivindica e não roda');
        self::assertSame([], array_filter($this->fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof VarrerAutomacoes), 'nenhuma volta se reagenda');
    }

    #[TestDox('Recuperação: o enviando parado volta à fila; o enfileirado perdido é redespachado; o que espera a vazão, não')]
    public function testRecuperacao(): void
    {
        $parado = $this->envio('enviando', ['tentativa_em' => '-20 minutes']);
        $perdido = $this->envio('enfileirado', ['proxima_tentativa_em' => '-1 hour']);
        $esperando = $this->envio('enfileirado', ['proxima_tentativa_em' => '+5 hours']);

        static::getContainer()->get(RecuperacaoDeEnvios::class)->recuperar(new \DateTimeImmutable());

        self::assertSame('enfileirado', $this->conexao->fetchOne('SELECT situacao FROM comercial_envio WHERE id = ?', [$parado]));
        $redespachados = array_map(static fn ($e): int => $e->getMessage()->envioId, array_filter($this->fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof EnviarEmail));
        self::assertContains($perdido, $redespachados);
        self::assertNotContains($esperando, $redespachados, 'quem espera a vazão do dia seguinte não é redespachado');
    }

    #[TestDox('Purga de escritório: envio, conteúdo, confirmação e ciclo do escritório saem; a prova de consentimento fica')]
    public function testPurgaDeEscritorio(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Purga Comercial ' . uniqid())->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $user = (new User())->setEmail('purga_' . uniqid() . '@test.com')->setFullName('Purga')->setIsActive(true)->setPassword('x');
        $em->persist($tenant);
        $em->persist($user);
        $em->flush();
        $t = (int) $tenant->getId();
        $envio = $this->envio('enfileirado', ['tenant_id' => $t]);
        $this->conexao->insert('comercial_envio_conteudo', ['envio_id' => $envio, 'corpo_html' => '<p>x</p>', 'cc' => '[]', 'rastrear' => 'false']);
        $this->conexao->insert('comercial_confirmacao_endereco', ['tenant_id' => $t, 'lista' => 'push_cliente', 'email' => 'c@test.com', 'pedido_em' => date('Y-m-d H:i:s')]);
        $this->conexao->insert('comercial_ciclo_conta', ['user_id' => $user->getId(), 'tenant_id' => $t]);
        $this->conexao->insert('comercial_consentimento', ['tenant_id' => $t, 'fato' => 'confirmado', 'lista' => 'push_cliente', 'email' => 'c@test.com', 'origem' => 'teste', 'versao_consentimento' => '1', 'texto_consentimento' => 't', 'em' => date('Y-m-d H:i:s')]);

        static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($tenant, dryRun: false);

        foreach (['comercial_envio', 'comercial_confirmacao_endereco', 'comercial_ciclo_conta'] as $tabela) {
            self::assertSame(0, (int) $this->conexao->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE tenant_id = ?", [$t]), $tabela);
        }
        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio_conteudo WHERE envio_id = ?', [$envio]));
        self::assertSame(1, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_consentimento WHERE tenant_id = ?', [$t]), 'prova retida (5 anos)');
    }

    #[TestDox('Purga do Comercial: simulações com mais de 7 dias saem; as recentes e os envios reais ficam; dry-run só conta')]
    public function testPurgaDasSimulacoes(): void
    {
        $velha = $this->envio('simulado', ['criado_em' => '-8 days']);
        $nova = $this->envio('simulado', ['criado_em' => '-1 day']);
        $real = $this->envio('enviado', ['criado_em' => '-30 days']);
        $purga = static::getContainer()->get(PurgarDadosDoComercialUseCase::class);

        self::assertGreaterThanOrEqual(1, $purga->executar(new \DateTimeImmutable(), true));
        self::assertSame(1, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE id = ?', [$velha]), 'dry-run não apaga');
        $purga->executar(new \DateTimeImmutable(), false);

        self::assertSame(0, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE id = ?', [$velha]));
        self::assertSame(2, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE id IN (?, ?)', [$nova, $real]));
    }

    /** @param array<string, int|string> $extra horários relativos ('-1 hour') ou valores */
    private function envio(string $situacao, array $extra = []): int
    {
        $linha = ['token' => bin2hex(random_bytes(16)), 'destinatario' => 'r@test.com', 'assunto' => 'A', 'tipo' => 'transacional', 'origem' => 'teste', 'situacao' => $situacao, 'criado_em' => date('Y-m-d H:i:s'), 'aberturas' => 0, 'cliques' => 0];
        foreach ($extra as $coluna => $valor) {
            $linha[$coluna] = is_string($valor) && preg_match('/^[+-]\d/', $valor) === 1 ? (new \DateTimeImmutable($valor))->format('Y-m-d H:i:s') : $valor;
        }
        $this->conexao->insert('comercial_envio', $linha);

        return (int) $this->conexao->lastInsertId();
    }
}
