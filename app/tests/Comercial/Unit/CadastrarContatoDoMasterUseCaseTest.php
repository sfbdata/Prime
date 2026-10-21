<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Consentimento\ConfirmacaoDeEnderecoInterface;
use App\Comercial\DTO\CadastroDeContatosInput;
use App\Comercial\DTO\ContatosCadastradosOutput;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\FatoDeConsentimentoRepository;
use App\Comercial\Service\RotulosDoConsentimento;
use App\Comercial\UseCase\CadastrarContatoDoMasterUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O "Cadastrar e-mail" do modo venda (F3h, spec comercial-e-emails §10; dc 05 VS 21.10.26 `cmLeadAdd` L644): cada
 * endereço recebe a confirmação dupla da Base de contatos pela interface da §3.3; quem já confirmou não recebe de novo;
 * o limite que segura o pedido (24 h, 30 dias do descadastrado, teto global) vira "barrado". A confirmação é dublada:
 * as regras dos limites estão no ConfirmacaoDeEnderecoTest.
 */
#[CoversClass(CadastrarContatoDoMasterUseCase::class)]
#[CoversClass(CadastroDeContatosInput::class)]
#[CoversClass(ContatosCadastradosOutput::class)]
final class CadastrarContatoDoMasterUseCaseTest extends TestCase
{
    private ConfirmacaoDeEnderecoInterface&MockObject $confirmacao;
    private FatoDeConsentimentoRepository&MockObject $fatos;
    /** @var list<array{lista: string, email: string, origem: string, versao: string, texto: string, escopo: mixed}> */
    private array $pedidos = [];
    /** @var array<string, \DateTimeImmutable> o último pedido de cada endereço, como o repositório devolveria */
    private array $ultimo = [];
    /** @var list<string> os endereços cujo pedido o limite segura (a interface não grava o fato) */
    private array $segurados = [];
    /** @var list<string> */
    private array $confirmados = [];

    protected function setUp(): void
    {
        $this->confirmacao = $this->createMock(ConfirmacaoDeEnderecoInterface::class);
        $this->confirmacao->method('estaConfirmado')->willReturnCallback(fn (string $lista, string $email): bool => \in_array($email, $this->confirmados, true));
        $this->confirmacao->method('pedir')->willReturnCallback(function (string $lista, string $email, string $origem, string $versao, string $texto, mixed $escopo = null): void {
            $this->pedidos[] = compact('lista', 'email', 'origem', 'versao', 'texto', 'escopo');
            if (!\in_array($email, $this->segurados, true)) {
                $this->ultimo[$email] = new \DateTimeImmutable('2026-10-21 10:00:00');
            }
        });
        $this->fatos = $this->createMock(FatoDeConsentimentoRepository::class);
        $this->fatos->method('ultimoPedidoEm')->willReturnCallback(fn (?int $tenant, string $lista, string $email): ?\DateTimeImmutable => $this->ultimo[$email] ?? null);
    }

    #[TestDox('Pede a confirmação dupla da lista comercial_campanhas, na plataforma, com a origem e o texto do cadastro manual')]
    public function testPedeAConfirmacao(): void
    {
        $saida = $this->executar('Ana@Exemplo.com');

        self::assertSame([[
            'lista' => 'comercial_campanhas', 'email' => 'ana@exemplo.com', 'origem' => CadastrarContatoDoMasterUseCase::ORIGEM,
            'versao' => CadastrarContatoDoMasterUseCase::VERSAO, 'texto' => CadastrarContatoDoMasterUseCase::TEXTO, 'escopo' => null,
        ]], $this->pedidos);
        self::assertSame([1, 0, 0], [$saida->pedidos, $saida->jaExistiam, $saida->barrados]);
        self::assertSame('1 pedido(s) de confirmação enviado(s). O contato entra na base quando confirmar pelo e-mail.', $saida->mensagem);
        self::assertStringContainsString('Só cadastre quem autorizou receber (LGPD)', CadastrarContatoDoMasterUseCase::TEXTO);
    }

    #[TestDox('A origem tem rótulo legível para o e-mail da confirmação, e a lista também (nunca o identificador cru)')]
    public function testRotulosDaOrigemEDaLista(): void
    {
        self::assertSame('pela equipe do BlueJus, em um cadastro manual', RotulosDoConsentimento::daOrigem(CadastrarContatoDoMasterUseCase::ORIGEM));
        self::assertSame('os convites, as ofertas e as novidades do BlueJus', RotulosDoConsentimento::daLista('comercial_campanhas'));
    }

    #[TestDox('Vírgula, espaço, ponto e vírgula e quebra de linha separam; repetidos (com maiúsculas) contam uma vez')]
    public function testSeparadoresERepetidos(): void
    {
        $saida = $this->executar("ana@exemplo.com, bia@exemplo.com;caio@exemplo.com dani@exemplo.com\nANA@exemplo.com");

        self::assertSame(['ana@exemplo.com', 'bia@exemplo.com', 'caio@exemplo.com', 'dani@exemplo.com'], array_column($this->pedidos, 'email'));
        self::assertSame(4, $saida->pedidos);
    }

    #[TestDox('Já confirmado e na lista: não recebe outro pedido e conta como "já existiam"')]
    public function testJaConfirmado(): void
    {
        $this->confirmados = ['ana@exemplo.com'];
        $saida = $this->executar('ana@exemplo.com, bia@exemplo.com');

        self::assertSame(['bia@exemplo.com'], array_column($this->pedidos, 'email'));
        self::assertSame([1, 1, 0], [$saida->pedidos, $saida->jaExistiam, $saida->barrados]);
        self::assertSame('1 pedido(s) de confirmação enviado(s) · 1 já existiam na base. O contato entra na base quando confirmar pelo e-mail.', $saida->mensagem);
    }

    #[TestDox('Limitadores: pedido de 24 h atrás, descadastrado dentro dos 30 dias ou teto global seguram o pedido, que conta como barrado')]
    public function testLimitadores(): void
    {
        // O pedido anterior de ontem (o limite de 24 h segura o novo: a interface não grava fato).
        $this->ultimo['ana@exemplo.com'] = new \DateTimeImmutable('2026-10-20 18:00:00');
        // O descadastrado não aparece como confirmado e o limite de 30 dias da interface segura o pedido.
        $this->segurados = ['ana@exemplo.com', 'saiu@exemplo.com'];
        $saida = $this->executar('ana@exemplo.com saiu@exemplo.com bia@exemplo.com');

        self::assertSame(['ana@exemplo.com', 'saiu@exemplo.com', 'bia@exemplo.com'], array_column($this->pedidos, 'email'), 'quem decide o limite é a confirmação');
        self::assertSame([1, 0, 2], [$saida->pedidos, $saida->jaExistiam, $saida->barrados]);
    }

    #[TestDox('Descadastrado com pedido vencido (mais de 30 dias): a confirmação grava o pedido de novo e ele conta como enviado')]
    public function testDescadastradoForaDoIntervalo(): void
    {
        $this->ultimo['saiu@exemplo.com'] = new \DateTimeImmutable('2026-08-01 10:00:00');
        $saida = $this->executar('saiu@exemplo.com');

        self::assertSame([1, 0, 0], [$saida->pedidos, $saida->jaExistiam, $saida->barrados]);
    }

    #[TestDox('Inválidos não vão à confirmação e contam como barrados, na frase sem travessão')]
    public function testInvalidos(): void
    {
        $saida = $this->executar('ana@exemplo.com, nao-e-email, x@y');

        self::assertSame(['ana@exemplo.com'], array_column($this->pedidos, 'email'));
        self::assertSame([1, 0, 2], [$saida->pedidos, $saida->jaExistiam, $saida->barrados]);
        self::assertSame('1 pedido(s) de confirmação enviado(s) · 2 barrado(s) (endereço inválido, descadastrado ou pedido recente). O contato entra na base quando confirmar pelo e-mail.', $saida->mensagem);
        self::assertStringNotContainsString('—', $saida->mensagem);
        self::assertStringNotContainsString('–', $saida->mensagem);
    }

    #[TestDox('Recusa: nenhum e-mail válido, mais de 50 de uma vez e quem não é Master')]
    public function testRecusas(): void
    {
        foreach (['', ' , ; ', 'sem-arroba'] as $texto) {
            try {
                CadastroDeContatosInput::doTexto($texto);
                self::fail('deveria recusar: ' . $texto);
            } catch (CampanhaRecusada $e) {
                self::assertSame('Nenhum e-mail válido.', $e->getMessage());
            }
        }
        $muitos = implode(',', array_map(static fn (int $i): string => 'p' . $i . '@exemplo.com', range(1, 51)));
        try {
            CadastroDeContatosInput::doTexto($muitos);
            self::fail('deveria recusar 51');
        } catch (CampanhaRecusada $e) {
            self::assertSame('Cadastre até 50 e-mails de cada vez.', $e->getMessage());
        }

        $this->expectException(CampanhaRecusada::class);
        (new CadastrarContatoDoMasterUseCase($this->confirmacao, $this->fatos))->executar(CadastroDeContatosInput::doTexto('ana@exemplo.com'), (new User())->setRoles(['ROLE_USER']));
    }

    private function executar(string $texto): ContatosCadastradosOutput
    {
        $master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);

        return (new CadastrarContatoDoMasterUseCase($this->confirmacao, $this->fatos))->executar(CadastroDeContatosInput::doTexto($texto), $master);
    }
}
