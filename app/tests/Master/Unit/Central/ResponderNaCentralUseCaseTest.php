<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit\Central;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\RespostaNaOrigem;
use App\Master\Entity\CentralMasterResposta;
use App\Master\Exception\RespostaRecusada;
use App\Master\Notificacoes\OrigemDaCentral;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterRespostaRepository;
use App\Master\UseCase\ResponderNaCentralUseCase;
use App\Service\NotificacaoService;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(ResponderNaCentralUseCase::class)]
#[CoversClass(DestinoDaResposta::class)]
#[CoversClass(CentralMasterResposta::class)]
final class ResponderNaCentralUseCaseTest extends TestCase
{
    /** @var list<CentralMasterResposta> */
    private array $salvas = [];

    #[TestDox('Com e-mail: Carteiro transacional, origem central_master:resposta, "Re: assunto", só o texto da resposta; a origem guarda a resposta')]
    public function testPorEmail(): void
    {
        $carteiro = $this->createMock(CarteiroInterface::class);
        $carteiro->expects(self::once())->method('enviar')->with(self::callback(static function (MensagemDeEmail $m): bool {
            return $m->para === 'ana@exemplo.com' && $m->tipo === TipoDeEnvio::Transacional && $m->origem === 'central_master:resposta'
                && $m->assunto === 'Re: Sugestão' && $m->nomeDoRemetente === 'BlueJus' && $m->motivoDoEnvio === 'Você escreveu ao BlueJus'
                && str_contains($m->corpoHtml, 'Obrigado &lt;b&gt;pela&lt;/b&gt; ideia') && !str_contains($m->corpoHtml, 'Texto original secreto');
        }))->willReturn(new ResultadoDoEnvio(42, ResultadoDoEnvio::ENFILEIRADO));
        $origem = MensagensDeTeste::origem('sugestao', [MensagensDeTeste::mensagem('sugestao', 1, texto: 'Texto original secreto')], new DestinoDaResposta('Ana@Exemplo.com'));

        $saida = $this->useCase($origem, $carteiro)->executar($this->master(), 'sugestao', 1, '  Obrigado <b>pela</b> ideia  ');

        self::assertSame('email', $saida->canal);
        self::assertTrue($saida->minha);
        self::assertCount(1, $this->salvas);
        self::assertSame([42, 'ana@exemplo.com', 7, 'Obrigado <b>pela</b> ideia'], [$this->salvas[0]->getEnvioId(), $this->salvas[0]->getDestino(), $this->salvas[0]->getTenantId(), $this->salvas[0]->getTexto()]);
        self::assertSame([[1, 'Obrigado <b>pela</b> ideia']], $origem->registradas);
    }

    #[TestDox('Responder pela Central preserva antes, no histórico (canal contribua), a resposta que só existia na página do Contribua')]
    public function testPreservaARespostaDoContribua(): void
    {
        $antigo = (new User())->setFullName('Master Antigo')->setEmail('antigo@exemplo.com');
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willReturn(new ResultadoDoEnvio(9, ResultadoDoEnvio::ENFILEIRADO));
        $naOrigem = new RespostaNaOrigem('Resposta antiga do Contribua', $antigo, new \DateTimeImmutable('2026-10-01 09:00'));
        $origem = MensagensDeTeste::origem('sugestao', [MensagensDeTeste::mensagem('sugestao', 1, respondidaNaOrigem: true)], new DestinoDaResposta('ana@exemplo.com'), $naOrigem);

        $this->useCase($origem, $carteiro)->executar($this->master(), 'sugestao', 1, 'Resposta nova');

        self::assertCount(2, $this->salvas);
        $preservada = $this->salvas[0];
        self::assertSame(['Resposta antiga do Contribua', 'contribua', null, null, 'Master Antigo', '2026-10-01 09:00'], [$preservada->getTexto(), $preservada->getCanal(), $preservada->getDestino(), $preservada->getEnvioId(), $preservada->getPorNome(), $preservada->getEm()->format('Y-m-d H:i')]);
        self::assertSame('Resposta nova', $this->salvas[1]->getTexto());
        self::assertSame([[1, 'Resposta nova']], $origem->registradas, 'a origem só é sobrescrita depois de preservada');
    }

    #[TestDox('O e-mail do destino é aparado e vai em minúsculas antes de validar')]
    public function testEmailNormalizado(): void
    {
        $destino = new DestinoDaResposta('  Ana@Exemplo.COM ');

        self::assertSame('ana@exemplo.com', $destino->email);
        self::assertTrue($destino->porEmail());
    }

    #[TestDox('Sem e-mail válido: aviso na Central do autor, no escritório da origem, e nada no Carteiro')]
    public function testPelaCentral(): void
    {
        $autor = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($autor, 55);
        $escritorio = new Tenant();
        $carteiro = $this->createMock(CarteiroInterface::class);
        $carteiro->expects(self::never())->method('enviar');
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::once())->method('criarNotificacao')
            ->with($autor, $escritorio, 'central_master.resposta', 'Resposta do BlueJus: Sugestão', '/contribua', 'Resposta')
            ->willReturn(new Notificacao());
        $origem = MensagensDeTeste::origem('sugestao', [MensagensDeTeste::mensagem('sugestao', 1, email: null)], new DestinoDaResposta('sem-arroba', $autor, $escritorio));

        $saida = $this->useCase($origem, $carteiro, $notificacoes)->executar($this->master(), 'sugestao', 1, 'Resposta');

        self::assertSame('central', $saida->canal);
        self::assertSame(['central', '55', null], [$this->salvas[0]->getCanal(), $this->salvas[0]->getDestino(), $this->salvas[0]->getEnvioId()]);
    }

    #[TestDox('Alerta (não aceita resposta), mensagem que sumiu, texto vazio e ninguém a quem responder: recusados sem enviar')]
    public function testRecusas(): void
    {
        $carteiro = $this->createMock(CarteiroInterface::class);
        $carteiro->expects(self::never())->method('enviar');
        $alerta = MensagensDeTeste::origem('alerta_indicacao', [MensagensDeTeste::mensagem('alerta_indicacao', 1, podeResponder: false)]);
        $semDestino = MensagensDeTeste::origem('sugestao', [MensagensDeTeste::mensagem('sugestao', 1)], new DestinoDaResposta(null));

        foreach ([
            [$alerta, 'alerta_indicacao', 1, 'Oi', 'Esta mensagem não aceita resposta.'],
            [$semDestino, 'sugestao', 99, 'Oi', 'Mensagem não encontrada.'],
            [$semDestino, 'sugestao', 1, '   ', 'Escreva a resposta.'],
            [$semDestino, 'sugestao', 1, 'Oi', 'Não há mais a quem responder esta mensagem.'],
            [$semDestino, 'sugestao', 1, str_repeat('a', 5001), 'A resposta passou de 5.000 caracteres.'],
        ] as [$origem, $chave, $id, $texto, $erro]) {
            try {
                $this->useCase($origem, $carteiro)->executar($this->master(), $chave, $id, $texto);
                self::fail('deveria recusar: ' . $erro);
            } catch (RespostaRecusada $e) {
                self::assertSame($erro, $e->getMessage());
            }
        }
        self::assertSame([], $this->salvas);
    }

    private function useCase(OrigemDaCentral $origem, CarteiroInterface $carteiro, ?NotificacaoService $notificacoes = null): ResponderNaCentralUseCase
    {
        $respostas = $this->createMock(CentralMasterRespostaRepository::class);
        $respostas->method('emTransacao')->willReturnCallback(static fn (callable $t): mixed => $t());
        $respostas->method('salvar')->willReturnCallback(function (CentralMasterResposta $r): void {
            $this->salvas[] = $r;
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/contribua');

        return new ResponderNaCentralUseCase(
            new OrigensDaCentral([$origem]),
            $respostas,
            $carteiro,
            $notificacoes ?? $this->createStub(NotificacaoService::class),
            new EscopoDeAuditoriaDePlataforma(),
            $urls,
        );
    }

    private function master(): User
    {
        return (new User())->setFullName('Master Um')->setEmail('master@exemplo.com');
    }
}
