<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Consentimento\ResultadoDaConfirmacao;
use App\Comercial\Entity\FatoDeConsentimento;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Comercial\Repository\FatoDeConsentimentoRepository;
use App\Comercial\Service\ConfirmacaoDeEndereco;
use App\Comercial\UseCase\PurgarConfirmacoesVencidasUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * O double opt-in contra o banco (spec `comercial-e-emails.md` §3.3 e §5): os repositórios por SQL, o descadastro
 * real da 0d e um Carteiro de mentira (o envio é do bloco do Carteiro). O relógio é falso para andar no tempo.
 */
#[CoversClass(ConfirmacaoDeEndereco::class)]
#[CoversClass(ConfirmacaoDeEnderecoRepository::class)]
#[CoversClass(FatoDeConsentimentoRepository::class)]
#[CoversClass(PurgarConfirmacoesVencidasUseCase::class)]
final class ConfirmacaoDeEnderecoIntegracaoTest extends KernelTestCase
{
    private const LISTA = 'vagas_alerta';
    private const EMAIL = 'joana@gmail.com';

    private MockClock $relogio;
    private DescadastroDeEmailInterface $descadastro;
    private ConfirmacaoDeEndereco $servico;
    /** @var CarteiroInterface&object{mensagens: list<MensagemDeEmail>} */
    private CarteiroInterface $carteiro;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->relogio = new MockClock(new \DateTimeImmutable('2026-10-19 10:00:00'));
        $this->descadastro = $c->get(DescadastroDeEmailInterface::class);
        $this->carteiro = new class () implements CarteiroInterface {
            /** @var list<MensagemDeEmail> */
            public array $mensagens = [];

            public function enviar(MensagemDeEmail $mensagem): ResultadoDoEnvio
            {
                $this->mensagens[] = $mensagem;

                return new ResultadoDoEnvio(\count($this->mensagens), ResultadoDoEnvio::ENFILEIRADO);
            }
        };
        $this->servico = new ConfirmacaoDeEndereco(
            $c->get(ConfirmacaoDeEnderecoRepository::class),
            $c->get(FatoDeConsentimentoRepository::class),
            $this->descadastro,
            $this->carteiro,
            $c->get(Environment::class),
            $c->get(UrlGeneratorInterface::class),
            new NullLogger(),
            $this->relogio,
        );
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function tenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Confirmação ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function pedir(?Tenant $escopo = null, string $email = self::EMAIL, string $versao = 'v1'): void
    {
        $this->servico->pedir(self::LISTA, $email, 'pagina-publica-vagas', $versao, 'Quero receber os alertas de vagas.', $escopo);
    }

    /** O token do último e-mail mandado (o banco só tem o hash). */
    private function ultimoToken(): string
    {
        $ultima = $this->carteiro->mensagens[\count($this->carteiro->mensagens) - 1];
        self::assertSame(1, preg_match('#/e/confirmar/([0-9a-f]{64})#', $ultima->corpoHtml, $achado));

        return $achado[1];
    }

    /** @return list<array<string, mixed>> */
    private function fatos(string $email = self::EMAIL): array
    {
        return $this->conexao()->fetchAllAssociative(
            'SELECT tenant_id, fato, origem, versao_consentimento, ip, agente FROM comercial_consentimento WHERE email = ? ORDER BY id',
            [$email],
        );
    }

    /** @return array<string, mixed>|false */
    private function estado(string $email = self::EMAIL): array|false
    {
        return $this->conexao()->fetchAssociative('SELECT * FROM comercial_confirmacao_endereco WHERE email = ?', [$email]);
    }

    private function confirmar(string $token): ResultadoDaConfirmacao
    {
        return $this->servico->confirmar($token, '203.0.113.9', 'Navegador/1.0');
    }

    #[TestDox('pedir grava o fato "pedido" sem IP e o estado só com o hash do token, com prazo de 7 dias')]
    public function testPedir(): void
    {
        $this->pedir(email: '  Joana@Gmail.com ');

        $token = $this->ultimoToken();
        $estado = $this->estado();
        self::assertNotFalse($estado);
        self::assertSame(hash('sha256', $token), $estado['token_hash']);
        self::assertNull($estado['tenant_id']);
        self::assertSame('2026-10-26 10:00:00', $estado['expira_em']);
        self::assertNull($estado['confirmado_em']);
        self::assertSame([['tenant_id' => null, 'fato' => 'pedido', 'origem' => 'pagina-publica-vagas', 'versao_consentimento' => 'v1', 'ip' => null, 'agente' => null]], $this->fatos());
        self::assertFalse($this->servico->estaConfirmado(self::LISTA, self::EMAIL));
    }

    #[TestDox('confirmar: uso único; grava o fato "confirmado" com IP, agente e a versão do pedido; o segundo uso vence')]
    public function testUsoUnico(): void
    {
        $this->pedir();
        $token = $this->ultimoToken();

        self::assertSame(ResultadoDaConfirmacao::Confirmado, $this->confirmar($token));
        self::assertSame(ResultadoDaConfirmacao::VencidoOuUsado, $this->confirmar($token));

        $estado = $this->estado();
        self::assertNotFalse($estado);
        self::assertNull($estado['token_hash'], 'o token some na confirmação');
        self::assertSame('2026-10-19 10:00:00', $estado['confirmado_em']);
        $confirmados = array_values(array_filter($this->fatos(), static fn (array $f): bool => $f['fato'] === FatoDeConsentimento::CONFIRMADO));
        self::assertSame([['tenant_id' => null, 'fato' => 'confirmado', 'origem' => 'pagina-publica-vagas', 'versao_consentimento' => 'v1', 'ip' => '203.0.113.9', 'agente' => 'Navegador/1.0']], $confirmados);
        self::assertTrue($this->servico->estaConfirmado(self::LISTA, 'JOANA@gmail.com'));
    }

    #[TestDox('Token vencido depois de 7 dias não confirma')]
    public function testVencido(): void
    {
        $this->pedir();
        $token = $this->ultimoToken();
        $this->relogio->modify('+7 days +1 second');

        self::assertSame(ResultadoDaConfirmacao::VencidoOuUsado, $this->confirmar($token));
        self::assertFalse($this->servico->estaConfirmado(self::LISTA, self::EMAIL));
    }

    #[TestDox('Um pedido novo sobre a pendência troca o token: o antigo deixa de valer, o novo confirma')]
    public function testTokenTrocado(): void
    {
        $this->pedir();
        $antigo = $this->ultimoToken();
        $this->relogio->modify('+25 hours');
        $this->pedir(versao: 'v2');
        $novo = $this->ultimoToken();

        self::assertNotSame($antigo, $novo);
        self::assertSame(ResultadoDaConfirmacao::VencidoOuUsado, $this->confirmar($antigo));
        self::assertSame(ResultadoDaConfirmacao::Confirmado, $this->confirmar($novo));
        self::assertSame('v2', $this->fatos()[2]['versao_consentimento'], 'o fato confirmado leva a versão do último pedido');
    }

    #[TestDox('Limite de 24 h: o segundo pedido no mesmo dia não manda e-mail nem grava fato')]
    public function testLimiteDe24Horas(): void
    {
        $this->pedir();
        $this->relogio->modify('+23 hours');
        $this->pedir();

        self::assertCount(1, $this->carteiro->mensagens);
        self::assertCount(1, $this->fatos());
    }

    #[TestDox('pedir sobre confirmado não envia nem desconfirma (N4)')]
    public function testPedirSobreConfirmado(): void
    {
        $this->pedir();
        $this->confirmar($this->ultimoToken());
        $this->relogio->modify('+40 days');

        $this->pedir();

        self::assertCount(1, $this->carteiro->mensagens);
        self::assertCount(2, $this->fatos(), 'nenhum fato novo');
        $estado = $this->estado();
        self::assertNotFalse($estado);
        self::assertSame('2026-10-19 10:00:00', $estado['confirmado_em']);
        self::assertTrue($this->servico->estaConfirmado(self::LISTA, self::EMAIL));
    }

    #[TestDox('Descadastrado: estaConfirmado cai; o pedido de volta sai no máximo 1 vez a cada 30 dias; confirmar reativa a lista')]
    public function testDescadastradoVolta(): void
    {
        $this->pedir();
        $this->confirmar($this->ultimoToken());
        $this->descadastro->descadastrar(null, self::EMAIL, self::LISTA, 'link_do_email');
        self::assertFalse($this->servico->estaConfirmado(self::LISTA, self::EMAIL));

        $this->relogio->modify('+29 days');
        $this->pedir();
        self::assertCount(1, $this->carteiro->mensagens, 'pedido de 29 dias atrás: ainda dentro dos 30 dias');

        $this->relogio->modify('+2 days');
        $this->pedir();
        self::assertCount(2, $this->carteiro->mensagens);
        self::assertSame(ResultadoDaConfirmacao::Confirmado, $this->confirmar($this->ultimoToken()));
        self::assertFalse($this->descadastro->estaDescadastrado(null, self::EMAIL, self::LISTA), 'reativado pela nova confirmação');
        self::assertTrue($this->servico->estaConfirmado(self::LISTA, self::EMAIL));
    }

    #[TestDox('Opt-out total ("todos") também derruba o estaConfirmado e conta como quem saiu')]
    public function testTodos(): void
    {
        $this->pedir();
        $this->confirmar($this->ultimoToken());
        $this->descadastro->descadastrar(null, self::EMAIL, DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');

        self::assertFalse($this->servico->estaConfirmado(self::LISTA, self::EMAIL));
        $this->relogio->modify('+2 days');
        $this->pedir();
        self::assertCount(1, $this->carteiro->mensagens, 'em "todos" vale o intervalo de 30 dias');
    }

    #[TestDox('Teto global: com 200 pedidos na última hora, o próximo não sai')]
    public function testTetoGlobal(): void
    {
        $fatos = static::getContainer()->get(FatoDeConsentimentoRepository::class);
        $consentimento = ['origem' => 'pagina-publica-vagas', 'versao' => 'v1', 'texto' => 'texto'];
        for ($i = 0; $i < ConfirmacaoDeEndereco::TETO_POR_HORA; ++$i) {
            $fatos->registrar(null, FatoDeConsentimento::PEDIDO, self::LISTA, 'teto' . $i . '@exemplo.com', $consentimento, null, null, $this->relogio->now()->modify('-30 minutes'));
        }

        $this->pedir();

        self::assertSame([], $this->carteiro->mensagens);
        self::assertFalse($this->estado());
    }

    #[TestDox('Escopos isolados: confirmação no escritório não vale para a plataforma nem para outro escritório; o fato leva o tenant_id')]
    public function testEscopos(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->pedir($a);
        self::assertSame($a, $this->carteiro->mensagens[0]->escopo);
        $this->confirmar($this->ultimoToken());

        self::assertTrue($this->servico->estaConfirmado(self::LISTA, self::EMAIL, $a));
        self::assertFalse($this->servico->estaConfirmado(self::LISTA, self::EMAIL));
        self::assertFalse($this->servico->estaConfirmado(self::LISTA, self::EMAIL, $b));
        self::assertSame([$a->getId(), $a->getId()], array_map(static fn (array $f): int => (int) $f['tenant_id'], $this->fatos()));

        $this->pedir();
        self::assertCount(2, $this->carteiro->mensagens, 'na plataforma é outro endereço: sem limite herdado do escritório');
    }

    #[TestDox('Purga: apaga a pendência nunca confirmada vencida há mais de 30 dias (estado e fato "pedido"); nunca o confirmado')]
    public function testPurga(): void
    {
        $this->pedir(email: 'pendente@exemplo.com');
        $this->pedir(email: 'confirmado@exemplo.com');
        $this->confirmar($this->ultimoToken());
        $this->descadastro->descadastrar(null, 'confirmado@exemplo.com', self::LISTA, 'link_do_email');
        $this->relogio->modify('+31 days');
        $this->pedir(email: 'confirmado@exemplo.com');
        $this->pedir(email: 'recente@exemplo.com');
        $this->relogio->modify('+30 days');
        $purga = new PurgarConfirmacoesVencidasUseCase(static::getContainer()->get(ConfirmacaoDeEnderecoRepository::class));

        $simulados = $purga->executar($this->relogio->now(), true);
        $purgados = $purga->executar($this->relogio->now(), false);

        self::assertSame($simulados, $purgados);
        self::assertGreaterThanOrEqual(1, $purgados);
        self::assertFalse($this->estado('pendente@exemplo.com'));
        self::assertSame([], $this->fatos('pendente@exemplo.com'));
        self::assertNotFalse($this->estado('confirmado@exemplo.com'), 'estado confirmado nunca sai, mesmo com pedido novo vencido por cima');
        self::assertSame(['pedido', 'confirmado', 'pedido'], array_column($this->fatos('confirmado@exemplo.com'), 'fato'));
        self::assertNotFalse($this->estado('recente@exemplo.com'), 'vencida há só 23 dias');
    }
}
