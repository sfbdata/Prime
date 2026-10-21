<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Indicacao\DTO\IndicarPorEmailInput;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Exception\IndicacaoRecusada;
use App\Indicacao\Service\AvisoDeDisparoAlto;
use App\Indicacao\Service\RegistroDeIndicacao;
use App\Indicacao\UseCase\IndicarPorEmailUseCase;
use App\Tests\Indicacao\Support\CenarioDeIndicacao;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** "Enviar convite por e-mail" (spec indicar-bluejus.md, comportamentos 2 a 4 e 10). */
#[CoversClass(IndicarPorEmailUseCase::class)]
#[CoversClass(RegistroDeIndicacao::class)]
#[CoversClass(AvisoDeDisparoAlto::class)]
final class IndicarPorEmailUseCaseTest extends TestCase
{
    use CenarioDeIndicacao;

    /** @var list<MensagemDeEmail> */
    private array $enviadas = [];
    private string $situacaoDoEnvio = ResultadoDoEnvio::ENFILEIRADO;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    private function useCase(): IndicarPorEmailUseCase
    {
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willReturnCallback(function (MensagemDeEmail $m): ResultadoDoEnvio {
            $this->enviadas[] = $m;

            return new ResultadoDoEnvio(1, $this->situacaoDoEnvio);
        });
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);

        return new IndicarPorEmailUseCase($this->registro(), $this->indicacoes, $this->codigo(), $carteiro, $twig, $this->relogio);
    }

    private function recusa(callable $acao): string
    {
        try {
            $acao();
        } catch (IndicacaoRecusada $e) {
            return $e->getMessage();
        }
        self::fail('Esperava a recusa.');
    }

    #[TestDox('sucesso: grava a indicação (e-mail normalizado, nome) e manda o convite pelo Carteiro na lista indicacao')]
    public function testEnviaOConvite(): void
    {
        $user = Pessoas::usuario(7, 'Ana Souza Lima');
        $escritorio = Pessoas::escritorio(3);

        $mensagem = $this->useCase()->executar(new IndicarPorEmailInput('  Bia@Exemplo.COM ', 'Bia'), $user, $escritorio);

        self::assertSame('Convite enviado para bia@exemplo.com. Obrigado por indicar o BlueJus!', $mensagem);
        self::assertSame([7], $this->travas, 'roda dentro da trava da pessoa');
        self::assertCount(1, $this->gravadas);
        $indicacao = $this->gravadas[0];
        self::assertSame(CanalDeIndicacao::Email, $indicacao->getCanal());
        self::assertSame('bia@exemplo.com', $indicacao->getEmail());
        self::assertSame('Bia', $indicacao->getNome());
        self::assertSame($escritorio, $indicacao->getTenantOrigem());

        self::assertCount(1, $this->enviadas);
        $email = $this->enviadas[0];
        self::assertSame('bia@exemplo.com', $email->para);
        self::assertSame(TipoDeEnvio::Lista, $email->tipo);
        self::assertSame('indicacao', $email->lista);
        self::assertSame('indicacao', $email->origem);
        self::assertNull($email->escopo);
        self::assertNull($email->nome, 'o nome digitado não vai nem no cabeçalho Para');
        self::assertSame('Ana Souza Lima convidou você para o BlueJus', $email->assunto);
        self::assertSame('Ana Souza Lima via BlueJus', $email->nomeDoRemetente);
        self::assertSame('Você recebeu este e-mail porque Ana Souza Lima indicou o BlueJus para você.', $email->motivoDoEnvio);
        self::assertStringContainsString('https://bluejus.test/i/ABCD2345', $email->corpoHtml);
        self::assertStringContainsString('Olá!', $email->corpoHtml);
        self::assertStringNotContainsString('Uso o BlueJus no meu escritório', $email->corpoHtml, 'o texto livre da caixa não vai no e-mail');
    }

    #[TestDox('o nome digitado do convidado não entra no corpo do e-mail (sem texto livre), só na indicação')]
    public function testNomeDoConvidadoForaDoCorpo(): void
    {
        $this->useCase()->executar(new IndicarPorEmailInput('bia@exemplo.com', 'Compre já em spam.example'), Pessoas::usuario(7), null);

        self::assertStringNotContainsString('spam.example', $this->enviadas[0]->corpoHtml);
        self::assertStringContainsString('Olá!', $this->enviadas[0]->corpoHtml);
        self::assertSame('Compre já em spam.example', $this->gravadas[0]->getNome());
    }

    #[TestDox('descadastrado (bloqueado_descadastro): a indicação fica gravada e a resposta é o mesmo sucesso')]
    public function testDescadastradoMostraOMesmoSucesso(): void
    {
        $this->situacaoDoEnvio = ResultadoDoEnvio::BLOQUEADO_DESCADASTRO;

        $mensagem = $this->useCase()->executar(new IndicarPorEmailInput('saiu@exemplo.com'), Pessoas::usuario(7), null);

        self::assertSame('Convite enviado para saiu@exemplo.com. Obrigado por indicar o BlueJus!', $mensagem);
        self::assertCount(1, $this->gravadas);
    }

    #[TestDox('cadastro incompleto: recusa antes de tudo, sem gravar nem enviar')]
    public function testCadastroIncompleto(): void
    {
        $this->pausado = true;

        $motivo = $this->recusa(fn () => $this->useCase()->executar(new IndicarPorEmailInput('x'), Pessoas::usuario(7, 'Ana', null), null));

        self::assertSame('Complete seu cadastro para enviar convites', $motivo);
        self::assertSame([], $this->gravadas);
        self::assertSame([], $this->enviadas);
    }

    #[TestDox('trava vem antes da validação do e-mail (ordem do desenho)')]
    public function testTravaAntesDoEmail(): void
    {
        $this->pausado = true;

        $motivo = $this->recusa(fn () => $this->useCase()->executar(new IndicarPorEmailInput('invalido'), Pessoas::usuario(7), null));

        self::assertSame('Seus envios de convite foram pausados pela administração do BlueJus.', $motivo);
    }

    #[TestDox('limite do dia: recusa com o texto do desenho, sem gravar')]
    public function testLimiteDoDia(): void
    {
        $this->em24h = 20;

        $motivo = $this->recusa(fn () => $this->useCase()->executar(new IndicarPorEmailInput('bia@exemplo.com'), Pessoas::usuario(7), null));

        self::assertSame('Você chegou ao limite de 20 convites por dia. Tente novamente amanhã.', $motivo);
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('e-mail inválido: "Informe um e-mail válido."')]
    public function testEmailInvalido(): void
    {
        foreach (['', 'sem-arroba', 'a@', str_repeat('a', 175) . '@x.com'] as $email) {
            self::assertSame('Informe um e-mail válido.', $this->recusa(fn () => $this->useCase()->executar(new IndicarPorEmailInput($email), Pessoas::usuario(7), null)));
        }
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('membro do MEU escritório: "Esta pessoa já faz parte da sua conta."')]
    public function testMembroDoEscritorio(): void
    {
        $this->membro = true;

        $motivo = $this->recusa(fn () => $this->useCase()->executar(new IndicarPorEmailInput('colega@exemplo.com'), Pessoas::usuario(7), Pessoas::escritorio(3)));

        self::assertSame('Esta pessoa já faz parte da sua conta.', $motivo);
        self::assertSame([], $this->enviadas);
    }

    #[TestDox('sem escritório na sessão: não consulta membro nenhum (não sonda contas de fora)')]
    public function testSemEscritorioNaoConsultaMembros(): void
    {
        $this->membro = true;

        $this->useCase()->executar(new IndicarPorEmailInput('colega@exemplo.com'), Pessoas::usuario(7), null);

        self::assertSame(0, $this->consultasDeMembro);
        self::assertCount(1, $this->enviadas);
    }

    #[TestDox('alerta: ao CRUZAR o alerta de 24h grava o alerta e avisa cada Master, uma vez só')]
    public function testAlertaAoCruzarUmaVez(): void
    {
        $this->config->alterarLimites(100, 1000, 30, Pessoas::master(), new \DateTimeImmutable());
        $this->masters = [Pessoas::master(901), Pessoas::master(902)];
        $this->em24h = 28;
        $user = Pessoas::usuario(7, 'Ana Souza Lima');
        $escritorio = Pessoas::escritorio(3, 'Souza Advogados');

        $this->useCase()->executar(new IndicarPorEmailInput('a1@exemplo.com'), $user, $escritorio); // 28 -> 29
        self::assertSame([], $this->alertas);
        $this->useCase()->executar(new IndicarPorEmailInput('a2@exemplo.com'), $user, $escritorio); // 29 -> 30: cruza
        $this->useCase()->executar(new IndicarPorEmailInput('a3@exemplo.com'), $user, $escritorio); // 30 -> 31: já passou

        self::assertCount(1, $this->alertas);
        self::assertSame('Ana Souza Lima enviou 30 convites em 24h', $this->alertas[0]->getTexto());
        self::assertCount(2, $this->notificacoes, 'um aviso por Master');
        [$master, $tipo, $titulo, $texto] = $this->notificacoes[0];
        self::assertSame(901, $master->getId());
        self::assertSame('indicacao_disparo_alto', $tipo);
        self::assertSame('Disparo alto de convites', $titulo);
        self::assertSame('Ana Souza Lima (Souza Advogados) enviou 30 convites nas últimas 24h. Revise em Painel do Master › Comercial › Indicações.', $texto);
    }

    #[TestDox('alerta: Master inativo não é avisado; o alerta do card fica')]
    public function testMasterInativoNaoEAvisado(): void
    {
        $this->config->alterarLimites(100, 1000, 1, Pessoas::master(), new \DateTimeImmutable());
        $inativo = Pessoas::master(903);
        $inativo->setIsActive(false);
        $this->masters = [$inativo];

        $this->useCase()->executar(new IndicarPorEmailInput('a1@exemplo.com'), Pessoas::usuario(7), null);

        self::assertCount(1, $this->alertas);
        self::assertSame([], $this->notificacoes);
    }
}
