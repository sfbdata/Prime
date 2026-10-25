<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Tenant\Service\SituacaoDaConta;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Enum\NaturezaDoDespacho;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\TipoDeDespacho;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\Service\ApresentadorDeDespachos;
use App\TrabalhoExterno\Service\ArmazenadorDeDocumentos;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\PrazoDoDespacho;
use App\TrabalhoExterno\Service\ResolvedorDePerfil;
use App\TrabalhoExterno\Service\TransacaoDoDespacho;
use App\TrabalhoExterno\UseCase\DespacharNaDemandaUseCase;
use App\TrabalhoExterno\UseCase\LiberarRespostasAoDespachoUseCase;
use App\TrabalhoExterno\UseCase\ResponderAoDespachoUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O DESPACHO no Resumo (D8, desenho 06 de 24/10, l.512 a 530 e 1391 a 1394): a entidade, o prazo em dias úteis, a leitura e os casos de uso. */
#[CoversClass(DespachoDemanda::class)]
#[CoversClass(PrazoDoDespacho::class)]
#[CoversClass(ApresentadorDeDespachos::class)]
#[CoversClass(DespacharNaDemandaUseCase::class)]
#[CoversClass(ResponderAoDespachoUseCase::class)]
#[CoversClass(LiberarRespostasAoDespachoUseCase::class)]
final class DespachoDaDemandaTest extends TestCase
{
    private DemandaExterna $demanda;
    private int $salvos = 0;
    private int $transacoes = 0;
    private int $codigoLivre = 0;
    /** @var list<array{u: int, t: int, tipo: string, titulo: string, mensagem: ?string}> */
    private array $avisados = [];
    /** @var list<User> */
    private array $masters = [];

    protected function setUp(): void
    {
        $this->demanda = Apoio::demanda(Situacao::Execucao);
    }

    #[TestDox('despachar grava natureza, prazo, texto cortado em 5.000 e a cidade de execução, e registra "Novo despacho da Central BlueJus · natureza."')]
    public function testDespacharNaEntidade(): void
    {
        $d = $this->demanda;
        $x = $d->despachar(Apoio::autor(), str_repeat('a', 5100), NaturezaDoDespacho::Providencia, new \DateTimeImmutable('2026-11-06'));

        self::assertSame(TipoDeDespacho::Despacho, $x->getTipo());
        self::assertSame(5000, mb_strlen($x->getTexto()));
        self::assertSame('Brasília', $x->getCidade());
        self::assertSame('2026-11-06', $x->getPrazo()?->format('Y-m-d'));
        self::assertSame(8, strlen($x->codigoCurto()));
        $ultima = $d->getMensagens()[count($d->getMensagens()) - 1];
        self::assertSame('Novo despacho da Central BlueJus · Para providência.', $ultima->getTexto());
        self::assertSame(TipoMensagem::Sistema, $ultima->getTipo());

        $d->despachar(Apoio::autor(), 'Sem natureza', null, new \DateTimeImmutable('2026-11-06'));
        self::assertSame('Novo despacho da Central BlueJus.', $d->getMensagens()[count($d->getMensagens()) - 1]->getTexto());
        self::assertCount(2, $d->getDespachos());
    }

    #[TestDox('a resposta só entra com a chave ligada e ao menos um despacho; corta em 3.000 e registra "Resposta ao despacho de {quem}."')]
    public function testResponderNaEntidade(): void
    {
        $d = $this->demanda;
        $d->liberarRespostasAoDespacho(true);
        self::assertFalse($d->aceitaRespostaAoDespacho(), 'sem despacho não há o que responder');

        $d->despachar(Apoio::autor(), 'Junte a procuração', null, new \DateTimeImmutable('2026-11-06'));
        self::assertTrue($d->aceitaRespostaAoDespacho());
        $r = $d->responderAoDespacho(Apoio::autor(Perfil::Solicitante), str_repeat('b', 3100));
        self::assertTrue($r->ehResposta());
        self::assertSame(3000, mb_strlen($r->getTexto()));
        self::assertNull($r->getPrazo());
        self::assertSame('Resposta ao despacho de Fulano.', $d->getMensagens()[count($d->getMensagens()) - 1]->getTexto());

        $d->liberarRespostasAoDespacho(false);
        $this->expectException(TransicaoInvalida::class);
        $d->responderAoDespacho(Apoio::autor(Perfil::Solicitante), 'Outra');
    }

    #[TestDox('sem cidade de execução a assinatura é de Brasília')]
    public function testCidadePadrao(): void
    {
        $d = new DemandaExterna(Apoio::tenant(1), Apoio::usuario(10));

        self::assertSame('Brasília', $d->despachar(Apoio::autor(), 'Texto', null, new \DateTimeImmutable('2026-11-06'))->getCidade());
    }

    #[TestDox('dias úteis pulam sábado, domingo e o feriado do sistema (P5): de 29/10/2026, Finados em 02/11 empurra o padrão para 06/11')]
    public function testDiasUteisComFeriado(): void
    {
        $p = new PrazoDoDespacho(new CalendarioDoSistema());
        $hoje = new \DateTimeImmutable('2026-10-29');

        self::assertFalse($p->ehDiaUtil(new \DateTimeImmutable('2026-11-02')));
        self::assertFalse($p->ehDiaUtil(new \DateTimeImmutable('2026-10-31')));
        self::assertTrue($p->ehDiaUtil(new \DateTimeImmutable('2026-11-03')));
        self::assertSame('2026-11-06', $p->padrao($hoje)->format('Y-m-d'));
        self::assertSame(2, $p->uteisAte(new \DateTimeImmutable('2026-11-03'), $hoje));
        self::assertSame(1, $p->uteisAte(new \DateTimeImmutable('2026-11-02'), $hoje));
        self::assertSame(0, $p->uteisAte(new \DateTimeImmutable('2026-10-20'), $hoje));
        self::assertContains('2026-11-02', $p->feriadosEmDiaDeSemana($hoje, 10));
        self::assertNotContains('2026-10-31', $p->feriadosEmDiaDeSemana($hoje, 10));
    }

    #[TestDox('Master despacha: sem prazo vale o padrão, o registro avisa e tudo sai numa transação')]
    public function testDespacharUseCase(): void
    {
        $x = $this->despachar()->executar(Apoio::contexto(Perfil::Master), 'TE-0007', '  Junte a procuração  ', 'cumpra_se', '', [], new \DateTimeImmutable('2026-10-29'));

        self::assertSame('Junte a procuração', $x->getTexto());
        self::assertSame(NaturezaDoDespacho::CumpraSe, $x->getNatureza());
        self::assertSame('2026-11-06', $x->getPrazo()?->format('Y-m-d'));
        self::assertSame([1, 1], [$this->transacoes, $this->salvos]);
        self::assertSame($this->codigoLivre, $x->getCodigo(), 'o código da assinatura é o livre que o repositório achou');
        self::assertSame(str_pad((string) ($this->codigoLivre % DespachoDemanda::CICLO_DO_CODIGO), 8, '0', STR_PAD_LEFT), $x->codigoCurto());

        $y = $this->despachar()->executar(Apoio::contexto(Perfil::Master), 'TE-0007', 'Com prazo', '', '2026-12-01', []);
        self::assertSame('2026-12-01', $y->getPrazo()?->format('Y-m-d'));
        self::assertNull($y->getNatureza());
    }

    #[TestDox('a Equipe, o solicitante e a prévia não despacham')]
    public function testSoOMasterDespacha(): void
    {
        foreach ([Apoio::contexto(Perfil::Equipe), Apoio::contexto(Perfil::Solicitante), new Contexto(Apoio::usuario(99), Apoio::tenant(1), Perfil::Master, new ConfiguracaoCentral(), previa: true)] as $ctx) {
            try {
                $this->despachar()->executar($ctx, 'TE-0007', 'Junte a procuração', '', '', []);
                self::fail('deveria recusar ' . $ctx->perfil->value);
            } catch (AcaoNaoPermitida) {
            }
        }
        self::assertSame(0, $this->transacoes);
        self::assertCount(0, $this->demanda->getDespachos());
    }

    #[TestDox('texto com menos de 3 caracteres, natureza desconhecida e prazo torto são recusados')]
    public function testValidacoesDoDespacho(): void
    {
        foreach ([['ab', '', '', DespacharNaDemandaUseCase::TEXTO_VAZIO], ['Texto', 'urgente', '', 'Natureza do despacho desconhecida.'], ['Texto', '', '2026-02-30', 'Prazo do despacho inválido.']] as [$texto, $nat, $prazo, $msg]) {
            try {
                $this->despachar()->executar(Apoio::contexto(Perfil::Master), 'TE-0007', $texto, $nat, $prazo, []);
                self::fail('deveria recusar: ' . $msg);
            } catch (DadosInvalidos $e) {
                self::assertSame($msg, $e->getMessage());
            }
        }
        self::assertCount(0, $this->demanda->getDespachos());
    }

    #[TestDox('demanda de outro escritório não é encontrada')]
    public function testOutroEscritorio(): void
    {
        $this->expectException(DemandaNaoEncontrada::class);
        $this->despachar()->executar(Apoio::contexto(Perfil::Solicitante, Apoio::tenant(2)), 'TE-0007', 'Texto', '', '', []);
    }

    #[TestDox('o solicitante e a Equipe respondem só com a chave ligada; o Master não responde')]
    public function testResponderUseCase(): void
    {
        $this->demanda->despachar(Apoio::autor(), 'Junte a procuração', null, new \DateTimeImmutable('2026-11-06'));
        $responder = new ResponderAoDespachoUseCase($this->localizador(), new PoliticaDaDemanda(), $this->repositorio(), $this->avisos());

        try {
            $responder->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007', 'Segue a procuração');
            self::fail('a chave está desligada');
        } catch (AcaoNaoPermitida) {
        }

        $this->demanda->liberarRespostasAoDespacho(true);
        try {
            $responder->executar(Apoio::contexto(Perfil::Master), 'TE-0007', 'Segue a procuração');
            self::fail('o Master não responde');
        } catch (AcaoNaoPermitida) {
        }
        // Decisão do dono de 24/10 (`dpPodeResp: p !== 'master'`): a Equipe responde.
        self::assertTrue($responder->executar(Apoio::contexto(Perfil::Equipe), 'TE-0007', 'Da equipe')->ehResposta());
        self::assertSame(1, $this->salvos);
        $this->salvos = 0;
        try {
            $responder->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007', 'a');
            self::fail('texto curto');
        } catch (DadosInvalidos) {
        }

        $r = $responder->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007', 'Segue a procuração');
        self::assertTrue($r->ehResposta());
        self::assertSame($this->codigoLivre, $r->getCodigo(), 'a resposta também assina com código livre');
        self::assertSame(1, $this->salvos);
    }

    #[TestDox('só o Master liga e desliga "Aceitar resposta"')]
    public function testLiberarUseCase(): void
    {
        $liberar = new LiberarRespostasAoDespachoUseCase($this->localizador(), new PoliticaDaDemanda(), $this->repositorio());

        $liberar->executar(Apoio::contexto(Perfil::Master), 'TE-0007', true);
        self::assertTrue($this->demanda->respostasAoDespachoLiberadas());
        $liberar->executar(Apoio::contexto(Perfil::Master), 'TE-0007', false);
        self::assertFalse($this->demanda->respostasAoDespachoLiberadas());

        $this->expectException(AcaoNaoPermitida::class);
        $liberar->executar(Apoio::contexto(Perfil::Equipe), 'TE-0007', true);
    }

    #[TestDox('a leitura do bloco: o mais novo em cima, numeração só dos despachos, resposta recuada, local por extenso e assinatura com o código')]
    public function testApresentador(): void
    {
        $d = $this->demanda;
        $d->despachar(Apoio::autor(), 'Primeiro', NaturezaDoDespacho::Ciencia, new \DateTimeImmutable('2026-11-06'));
        $d->liberarRespostasAoDespacho(true);
        $d->responderAoDespacho(Apoio::autor(Perfil::Solicitante), 'Ciente');
        $segundo = $d->despachar(Apoio::autor(), 'Segundo', null, new \DateTimeImmutable('2026-12-01'));
        $ap = new ApresentadorDeDespachos(new PoliticaDaDemanda(), new PrazoDoDespacho(new CalendarioDoSistema()));

        $m = $ap->bloco(Apoio::contexto(Perfil::Master), $d, new \DateTimeImmutable('2026-10-29'));
        self::assertTrue($m['podeDespachar']);
        self::assertFalse($m['podeResponder']);
        self::assertFalse($m['respInfo']);
        self::assertFalse($m['leSem']);
        self::assertSame('2026-11-06', $m['prazoPadrao']);
        self::assertContains('2026-11-02', $m['feriados']);
        self::assertSame(['DESPACHO 2', 'RESPOSTA DE FULANO', 'DESPACHO 1'], array_column($m['lista'], 'n'));
        self::assertSame(['despacho', 'resposta', 'despacho'], array_column($m['lista'], 'tipo'));
        self::assertSame('Prazo: 01/12/2026', $m['lista'][0]['prazo']);
        self::assertSame('', $m['lista'][1]['prazo']);
        self::assertSame('Para ciência', $m['lista'][2]['nat']);
        $em = $segundo->getEm();
        self::assertSame('Brasília, ' . $em->format('j') . ' de ' . ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][(int) $em->format('n') - 1] . ' de ' . $em->format('Y') . '.', $m['lista'][0]['local']);
        self::assertSame('Assinado eletronicamente por FULANO (Central BlueJus) em ' . $em->format('d/m/Y, H:i') . ' · Cód. ' . $segundo->codigoCurto(), $m['lista'][0]['ass']);
        self::assertStringStartsWith('Assinado eletronicamente por FULANO em ', $m['lista'][1]['ass']);

        $s = $ap->bloco(Apoio::contexto(Perfil::Solicitante), $d);
        self::assertFalse($s['podeDespachar']);
        self::assertTrue($s['podeResponder']);
        self::assertTrue($s['respInfo']);
        self::assertFalse($s['leSem']);
        self::assertSame('', $s['prazoPadrao']);
        self::assertSame([], $s['feriados']);

        $d->liberarRespostasAoDespacho(false);
        $s = $ap->bloco(Apoio::contexto(Perfil::Solicitante), $d);
        self::assertFalse($s['podeResponder']);
        self::assertTrue($s['leSem']);

        $ex = $ap->extrato(Apoio::contexto(Perfil::Master), $d);
        self::assertSame(['DESPACHO 1 · Para ciência', 'DESPACHO 2', 'DESPACHO 3'], array_column($ex, 'titulo'));
        self::assertSame('Assinado eletronicamente por Fulano em ' . $em->format('d/m/Y, H:i:s'), $ex[2]['ass']);
    }

    #[TestDox('resposta ao despacho: aviso no sino a cada Master da Central, no escritório da Central, menos quem respondeu; sem sede, ninguém')]
    public function testAvisoDaRespostaAosMaster(): void
    {
        $this->demanda->despachar(Apoio::autor(), 'Junte a procuração', null, new \DateTimeImmutable('2026-11-06'));
        $this->demanda->liberarRespostasAoDespacho(true);
        $sede = Apoio::tenant(3);
        $cfg = new ConfiguracaoCentral();
        $cfg->definirSede($sede);
        $quemResponde = Apoio::usuario(21, ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->masters = [Apoio::usuario(20, ['ROLE_USER', 'ROLE_SUPER_ADMIN']), $quemResponde, Apoio::usuario(22, ['ROLE_USER', 'ROLE_SUPER_ADMIN'])];
        $responder = new ResponderAoDespachoUseCase($this->localizador(), new PoliticaDaDemanda(), $this->repositorio(), $this->avisos());

        // O Master que também é usuário do escritório responde como Solicitante dele.
        $responder->executar(Apoio::contexto(Perfil::Solicitante, null, $cfg, $quemResponde), 'TE-0007', 'Segue a procuração');
        $paraMaster = array_values(array_filter($this->avisados, static fn (array $a): bool => $a['tipo'] === Notificacao::TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA));
        self::assertSame([20, 22], array_column($paraMaster, 'u'), 'todos os Master, menos quem respondeu');
        self::assertSame([3, 3], array_column($paraMaster, 't'), 'no escritório da Central');
        self::assertSame('Resposta ao despacho de Usuário 21.', $paraMaster[0]['mensagem']);
        self::assertSame('TE-0007 · ', mb_substr($paraMaster[0]['titulo'], 0, 10));

        $this->avisados = [];
        $responder->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007', 'Sem sede');
        self::assertSame([], array_filter($this->avisados, static fn (array $a): bool => $a['tipo'] === Notificacao::TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA), 'sem sede não há onde avisar');
    }

    #[TestDox('assinatura (decisão do dono de 24/10): com o nome real, "{NOME} (Central BlueJus)"; quando o leitor vê "Central BlueJus", sem repetir; o Extrato segue o desenho, sem o sufixo')]
    public function testAssinaturaSemRepeticao(): void
    {
        $d = $this->demanda;
        $d->despachar(Apoio::autor(), 'Primeiro', null, new \DateTimeImmutable('2026-11-06'));
        $ap = new ApresentadorDeDespachos(new PoliticaDaDemanda(), new PrazoDoDespacho(new CalendarioDoSistema()));

        self::assertStringStartsWith('Assinado eletronicamente por FULANO (Central BlueJus) em ', $ap->bloco(Apoio::contexto(Perfil::Master), $d)['lista'][0]['ass']);
        $externo = $ap->bloco(Apoio::contexto(Perfil::Solicitante), $d)['lista'][0]['ass'];
        self::assertStringStartsWith('Assinado eletronicamente por CENTRAL BLUEJUS em ', $externo);
        self::assertSame(1, substr_count(mb_strtolower($externo), 'central bluejus'), 'sem a repetição');

        $comNome = new ConfiguracaoCentral();
        $comNome->definirPrivacidade('nomeResp', true);
        self::assertStringStartsWith('Assinado eletronicamente por FULANO (Central BlueJus) em ', $ap->bloco(Apoio::contexto(Perfil::Solicitante, null, $comNome), $d)['lista'][0]['ass'], 'com priv.nomeResp, o nome real');

        self::assertStringStartsWith('Assinado eletronicamente por Central BlueJus em ', $ap->extrato(Apoio::contexto(Perfil::Solicitante), $d)[0]['ass']);
        self::assertStringStartsWith('Assinado eletronicamente por Fulano em ', $ap->extrato(Apoio::contexto(Perfil::Master), $d)[0]['ass']);
    }

    private function despachar(): DespacharNaDemandaUseCase
    {
        $transacao = $this->createMock(TransacaoDoDespacho::class);
        $transacao->method('executar')->willReturnCallback(function (\Closure $passos, \Closure $arquivosNovos): mixed {
            ++$this->transacoes;
            $r = $passos();
            self::assertSame([], $arquivosNovos());

            return $r;
        });

        return new DespacharNaDemandaUseCase(
            $this->localizador(),
            new PoliticaDaDemanda(),
            new ArmazenadorDeDocumentos($this->createStub(ArmazenamentoDeArquivos::class)),
            $this->repositorio(),
            $this->avisos(),
            new PrazoDoDespacho(new CalendarioDoSistema()),
            $transacao,
        );
    }

    private function repositorio(): DemandaExternaRepository
    {
        $r = $this->createMock(DemandaExternaRepository::class);
        $r->method('daCentralPorId')->willReturnCallback(fn (int $id): ?DemandaExterna => $id === 7 ? $this->demanda : null);
        $r->method('doEscritorioPorId')->willReturnCallback(fn (int $id, $tenant): ?DemandaExterna => $id === 7 && $tenant->getId() === $this->demanda->getTenant()->getId() ? $this->demanda : null);
        // Simula a colisão: o candidato está em uso e o seguinte livre fica 7 adiante.
        $r->method('codigoDeDespachoLivre')->willReturnCallback(fn (int $candidato): int => $this->codigoLivre = $candidato + 7);
        $r->method('salvar')->willReturnCallback(function (): void {
            ++$this->salvos;
        });

        return $r;
    }

    private function localizador(): LocalizadorDeDemanda
    {
        return new LocalizadorDeDemanda($this->repositorio(), $this->createStub(ConviteDemandaRepository::class), new PoliticaDaDemanda(), new SituacaoDaConta());
    }

    /** Os avisos com o sino vazio: o que cada evento cria se prova no `AvisosDoTrabalhoExternoTest`. */
    private function avisos(): AvisosDoTrabalhoExterno
    {
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $mensagem = null): Notificacao {
            $this->avisados[] = ['u' => (int) $u->getId(), 't' => (int) $t->getId(), 'tipo' => $tipo, 'titulo' => $titulo, 'mensagem' => $mensagem];

            return (new Notificacao())->setUsuario($u)->setTenant($t)->setTipo($tipo)->setTitulo($titulo)->setMensagem($mensagem);
        });
        $destinatarios = $this->createMock(DestinatariosDosAvisosRepository::class);
        $destinatarios->method('masters')->willReturnCallback(fn (): array => $this->masters);

        return new AvisosDoTrabalhoExterno(
            $notificacoes,
            $destinatarios,
            $this->createStub(PreferenciaDoUsuarioRepository::class),
            $this->createStub(CurriculoRepository::class),
            new ResolvedorDePerfil($this->createStub(PermissionChecker::class)),
            new PoliticaDaVaga(new PoliticaDaDemanda()),
            $this->createStub(ConviteDemandaRepository::class),
            new PoliticaDaDemanda(),
        );
    }
}
