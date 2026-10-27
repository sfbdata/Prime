<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Enum\StatusDoTermo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\Service\PermissionChecker;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\HtmlDoTermoDeVoluntariado;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeTermo;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoTermo;
use App\TrabalhoExterno\Service\RascunhoDoTermo;
use App\TrabalhoExterno\Service\ResolvedorDePerfil;
use App\TrabalhoExterno\UseCase\AceitarTermoUseCase;
use App\TrabalhoExterno\UseCase\EnviarTermoParaAceiteUseCase;
use App\TrabalhoExterno\UseCase\GerarRascunhoDoTermoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Os casos de uso do Termo de Voluntariado (spec `trabalho-externo-termo-voluntariado.md`) com os repositórios
 * mockados: rascunho pré-preenchido sem gravar, envio com os 7 obrigatórios, aceite com hash e IP, termo enviado
 * imutável e que não se aceita duas vezes. As consultas e as rotas se provam no teste funcional.
 */
#[CoversClass(GerarRascunhoDoTermoUseCase::class)]
#[CoversClass(EnviarTermoParaAceiteUseCase::class)]
#[CoversClass(AceitarTermoUseCase::class)]
#[CoversClass(TermoDeVoluntariado::class)]
final class CasosDeUsoDoTermoTest extends TestCase
{
    private User $voluntario;
    private User $master;
    private Tenant $sede;
    private ConfiguracaoCentral $cfg;
    private Curriculo $cv;
    private CurriculoRepository&MockObject $curriculos;
    private TermoDeVoluntariadoRepository&MockObject $termos;
    private EntityManagerInterface&MockObject $em;
    /** @var list<array{u: int, tipo: string, titulo: string}> */
    private array $avisosCriados = [];

    protected function setUp(): void
    {
        $this->voluntario = Apoio::usuario(20);
        $this->master = Apoio::usuario(1, ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->master->setFullName('Marta Master');
        $this->master->setOabNumero('1234');
        $this->master->setOabUf('DF');
        $this->sede = Apoio::tenant(1);
        $this->sede->setCnpj('12.345.678/0001-90');
        $this->sede->setLogradouro('SQS 100');
        $this->sede->setNumero('10');
        $this->sede->setBairro('Asa Sul');
        $this->sede->setCidade('Brasília');
        $this->sede->setEstado('DF');
        $this->cfg = new ConfiguracaoCentral();
        $this->cfg->definirSede($this->sede);
        $this->cv = $this->comId(Curriculo::criar($this->voluntario, DadosDoCurriculo::deArray([
            'nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'situacao' => 'est', 'inst' => 'UnB', 'sem' => '7º semestre',
            'cidade' => 'Goiânia', 'uf' => 'GO', 'modalidade' => 'Remoto', 'vol' => 'sim', 'lgpd' => true,
        ]), new \DateTimeImmutable()), 7);
        $this->curriculos = $this->createMock(CurriculoRepository::class);
        $this->curriculos->method('porId')->willReturnCallback(fn (): Curriculo => $this->cv);
        $this->curriculos->method('doUsuario')->willReturnCallback(fn (): Curriculo => $this->cv);
        $this->termos = $this->createMock(TermoDeVoluntariadoRepository::class);
        $this->termos->method('proximoCodigo')->willReturn('TV-0001');
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f): mixed => $f());
    }

    #[TestDox('rascunho: pré-preenche escritório (sede), representante, voluntário e os padrões do desenho; CPF e RG em branco; nada é gravado')]
    public function testRascunho(): void
    {
        $this->termos->method('maisRecenteDo')->willReturn(null);
        $this->termos->expects(self::never())->method('salvar');
        $r = $this->rascunho()->executar($this->ctx($this->master, Perfil::Master), 'CV-0007');

        self::assertSame(['rascunho', '1.0'], [$r['status'], $r['versao']]);
        $c = $r['campos'];
        self::assertSame(['Escritório 1', '12.345.678/0001-90', 'SQS 100, 10, Asa Sul, Brasília/DF', 'Brasília', 'Brasília/DF'], [$c['eNome'], $c['eDoc'], $c['eEnd'], $c['foro'], $c['cidade']]);
        self::assertSame(['', ''], [$c['eEmail'], $c['eTel']], 'o cadastro do escritório não tem e-mail nem telefone');
        self::assertSame(['Marta Master', '1234', 'DF'], [$c['resp'], $c['respOab'], $c['respOabUf']]);
        self::assertSame(['Ana Souza', '', '', 'est', 'UnB', '7º semestre', 'Goiânia/GO', 'ana@exemplo.test'], [$c['vNome'], $c['vCpf'], $c['vRg'], $c['vSit'], $c['vInst'], $c['vSem'], $c['vEnd'], $c['vEmail']]);
        self::assertSame(['20', 'remoto', (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'), '6', '10', 'nao'], [$c['horas'], $c['modal'], $c['inicio'], $c['meses'], $c['aviso'], $c['ress']]);
        self::assertStringStartsWith('pesquisa jurídica, apoio na elaboração de peças', $c['ativ']);
    }

    #[TestDox('rascunho: havendo termo anterior, copia os campos dele (com a data do instrumento de hoje); currículo que não aceita voluntário e Equipe sem talentos são recusados')]
    public function testRascunhoCopiaOAnterior(): void
    {
        $anterior = TermoDeVoluntariado::enviar($this->sede, $this->cv, ['vCpf' => '111.222.333-44', 'meses' => '12', 'data' => '2026-01-05'], 'TV-0001', $this->master, new \DateTimeImmutable());
        $this->termos->method('maisRecenteDo')->willReturn($anterior);
        $c = $this->rascunho()->executar($this->ctx($this->master, Perfil::Master), 'CV-0007')['campos'];
        self::assertSame(['111.222.333-44', '12'], [$c['vCpf'], $c['meses']]);
        self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'), $c['data']);

        $semTalentos = new ConfiguracaoCentral();
        $semTalentos->definirEquipe('talentos', false);
        try {
            $this->rascunho()->executar($this->ctx(Apoio::usuario(2), Perfil::Equipe, $semTalentos), 'CV-0007');
            self::fail('403');
        } catch (AcaoNaoPermitida) {
        }
        $this->cv = $this->comId(Curriculo::criar($this->voluntario, DadosDoCurriculo::deArray(['nome' => 'Ana', 'email' => 'a@a.test', 'situacao' => 'est', 'vol' => 'nao', 'lgpd' => true]), new \DateTimeImmutable()), 7);
        $this->expectException(AcaoNaoPermitida::class);
        $this->rascunho()->executar($this->ctx($this->master, Perfil::Master), 'CV-0007');
    }

    #[TestDox('enviar: sem os 7 obrigatórios é 422 com a mensagem do desenho e nada é gravado')]
    public function testEnviarExigeOsObrigatorios(): void
    {
        $this->termos->expects(self::never())->method('salvar');
        $this->expectException(DadosInvalidos::class);
        $this->expectExceptionMessage('Preencha antes de enviar: CNPJ/CPF do escritório, CPF do voluntário.');
        $this->enviar()->executar($this->ctx($this->master, Perfil::Master), 'CV-0007', self::campos(['eDoc' => '', 'vCpf' => '']));
    }

    #[TestDox('enviar: grava "enviado" com o snapshot (CPF e RG dentro), código, escritório da sede e quem enviou; currículo "novo" vai para "em análise"')]
    public function testEnviar(): void
    {
        $guardado = null;
        $this->termos->expects(self::exactly(2))->method('salvar')->willReturnCallback(function (TermoDeVoluntariado $t) use (&$guardado): void {
            $guardado = $t;
        });
        self::assertSame(StatusCurriculo::Novo, $this->cv->getStatus());

        $termo = $this->enviar()->executar($this->ctx($this->master, Perfil::Master), 'CV-0007', self::campos(['intruso' => 'x']));

        self::assertSame($termo, $guardado);
        self::assertSame(['TV-0001', StatusDoTermo::Enviado, '1.0'], [$termo->getCodigo(), $termo->getStatus(), $termo->getVersaoModelo()]);
        self::assertSame([1, 7], [$termo->getTenant()->getId(), $termo->getCurriculo()->getId()]);
        self::assertSame(['111.222.333-44', '1.234.567 SSP/DF'], [$termo->getCampos()['vCpf'], $termo->getCampos()['vRg']]);
        self::assertArrayNotHasKey('intruso', $termo->getCampos());
        self::assertNull($termo->getAceitoEm());
        self::assertSame(StatusCurriculo::Analise, $this->cv->getStatus());

        $this->cv->mudarStatus(StatusCurriculo::Favorito);
        $this->enviar()->executar($this->ctx($this->master, Perfil::Master), 'CV-0007', self::campos());
        self::assertSame(StatusCurriculo::Favorito, $this->cv->getStatus(), 'só o "novo" passa para análise');
    }

    #[TestDox('enviar: sem sede definida não há escritório emissor (422); o externo não gera termo (404 no currículo alheio)')]
    public function testEnviarSemSedeEPermissao(): void
    {
        $semSede = new ConfiguracaoCentral();
        try {
            $this->enviar()->executar($this->ctx($this->master, Perfil::Master, $semSede), 'CV-0007', self::campos());
            self::fail('422');
        } catch (DadosInvalidos $e) {
            self::assertSame('Defina o escritório-sede da Central antes de enviar o termo.', $e->getMessage());
        }
        $this->expectException(DemandaNaoEncontrada::class);
        $this->enviar()->executar($this->ctx(Apoio::usuario(21), Perfil::Solicitante), 'CV-0007', self::campos());
    }

    #[TestDox('aceitar: o dono grava aceito em (relógio do servidor), quem, IP, user-agent e o sha256 do HTML com o aceite; avisa a Central sem CPF')]
    public function testAceitar(): void
    {
        $termo = $this->termoEnviado();
        $this->termos->method('porCodigo')->willReturn($termo);
        $antes = new \DateTimeImmutable();

        $r = $this->aceitar()->executar($this->ctx($this->voluntario, Perfil::Solicitante), 'TV-0001', true, '203.0.113.9', 'Mozilla/5.0 Teste');

        self::assertSame($termo, $r);
        self::assertSame(StatusDoTermo::Aceito, $termo->getStatus());
        self::assertGreaterThanOrEqual($antes, $termo->getAceitoEm());
        self::assertSame([20, '203.0.113.9'], [$termo->getAceitoPor()?->getId(), $termo->getAceiteIp()]);
        $html = (new HtmlDoTermoDeVoluntariado())->doTermo($termo);
        self::assertSame(hash('sha256', $html), $termo->getHashAceite(), 'o hash é do HTML renderizado com o aceite, reproduzível depois');
        self::assertStringContainsString('Código do termo: TV-0001 · versão 1.0', $html);
        self::assertSame(['trabalho_externo_termo_aceito'], array_column($this->avisosCriados, 'tipo'));
        self::assertSame('Termo de voluntariado aceito: TV-0001 (currículo CV-0007)', $this->avisosCriados[0]['titulo']);
        self::assertStringNotContainsString('111.222.333-44', json_encode($this->avisosCriados, JSON_THROW_ON_ERROR));
    }

    #[TestDox('aceitar: sem o "Li e aceito" é 422; aceito não aceita de novo (409); só o dono (outro voluntário 404, interno 403)')]
    public function testAceitarRecusas(): void
    {
        $termo = $this->termoEnviado();
        $this->termos->method('porCodigo')->willReturn($termo);

        try {
            $this->aceitar()->executar($this->ctx($this->voluntario, Perfil::Solicitante), 'TV-0001', false, '1.1.1.1', null);
            self::fail('422');
        } catch (DadosInvalidos $e) {
            self::assertSame('Marque "Li e aceito" para confirmar.', $e->getMessage());
        }
        self::assertSame(StatusDoTermo::Enviado, $termo->getStatus());

        try {
            $this->aceitar()->executar($this->ctx(Apoio::usuario(21), Perfil::Solicitante), 'TV-0001', true, '1.1.1.1', null);
            self::fail('404');
        } catch (DemandaNaoEncontrada) {
        }
        try {
            $this->aceitar()->executar($this->ctx($this->master, Perfil::Master), 'TV-0001', true, '1.1.1.1', null);
            self::fail('403');
        } catch (AcaoNaoPermitida) {
        }
        self::assertSame(StatusDoTermo::Enviado, $termo->getStatus());

        $this->aceitar()->executar($this->ctx($this->voluntario, Perfil::Solicitante), 'TV-0001', true, '1.1.1.1', null);
        $hash = $termo->getHashAceite();
        $this->expectException(TransicaoInvalida::class);
        $this->expectExceptionMessage('Este termo já foi aceito.');
        try {
            $this->aceitar()->executar($this->ctx($this->voluntario, Perfil::Solicitante), 'TV-0001', true, '2.2.2.2', null);
        } finally {
            self::assertSame($hash, $termo->getHashAceite(), 'o aceite não é reescrito');
            self::assertSame('1.1.1.1', $termo->getAceiteIp());
        }
    }

    #[TestDox('aceitar: com termo mais novo o antigo dá 409; aceite em sequência trava a linha e o segundo não avisa nem reescreve')]
    public function testAceitarMaisRecenteETrava(): void
    {
        $antigo = $this->termoEnviado();
        $novo = TermoDeVoluntariado::enviar($this->sede, $this->cv, self::campos(), 'TV-0002', $this->master, new \DateTimeImmutable());
        $this->termos->method('porCodigo')->willReturn($antigo);
        $this->termos->method('maisRecenteDo')->willReturn($novo);
        try {
            $this->aceitar()->executar($this->ctx($this->voluntario, Perfil::Solicitante), 'TV-0001', true, '1.1.1.1', null);
            self::fail('409');
        } catch (TransicaoInvalida $e) {
            self::assertSame('Existe um termo mais novo para este currículo. Aceite o mais recente.', $e->getMessage());
        }
        self::assertSame(StatusDoTermo::Enviado, $antigo->getStatus());
        self::assertSame([], $this->avisosCriados);
    }

    #[TestDox('aceitar: duas chamadas em sequência, a segunda cai em 409 sem novo aviso nem sobrescrever o aceite')]
    public function testAceitarEmSequencia(): void
    {
        $termo = $this->termoEnviado();
        $this->termos->method('porCodigo')->willReturn($termo);
        $this->termos->method('maisRecenteDo')->willReturn($termo);
        $this->aceitar()->executar($this->ctx($this->voluntario, Perfil::Solicitante), 'TV-0001', true, '1.1.1.1', 'UA1');
        $em = $termo->getAceitoEm();
        try {
            $this->aceitar()->executar($this->ctx($this->voluntario, Perfil::Solicitante), 'TV-0001', true, '2.2.2.2', 'UA2');
            self::fail('409');
        } catch (TransicaoInvalida) {
        }
        self::assertCount(1, $this->avisosCriados);
        self::assertSame([$em, '1.1.1.1'], [$termo->getAceitoEm(), $termo->getAceiteIp()]);
    }

    #[TestDox('aceitar: o aceite da prévia do Master (somente leitura) é negado')]
    public function testAceitarNaPrevia(): void
    {
        $termo = $this->termoEnviado();
        $this->termos->method('porCodigo')->willReturn($termo);
        $previa = new Contexto($this->voluntario, Apoio::tenant(2), Perfil::Solicitante, $this->cfg, previa: true);
        $this->expectException(AcaoNaoPermitida::class);
        $this->aceitar()->executar($previa, 'TV-0001', true, '1.1.1.1', null);
    }

    private function termoEnviado(): TermoDeVoluntariado
    {
        return TermoDeVoluntariado::enviar($this->sede, $this->cv, self::campos(), 'TV-0001', $this->master, new \DateTimeImmutable());
    }

    /** @return array<string, string> */
    private static function campos(array $extra = []): array
    {
        return $extra + [
            'eNome' => 'Escritório Central', 'eDoc' => '12.345.678/0001-90', 'vNome' => 'Ana Souza', 'vCpf' => '111.222.333-44', 'vRg' => '1.234.567 SSP/DF',
            'inicio' => '2026-10-27', 'meses' => '6', 'foro' => 'Brasília', 'ress' => 'nao', 'data' => '2026-10-27',
        ];
    }

    private function ctx(User $usuario, Perfil $perfil, ?ConfiguracaoCentral $cfg = null): Contexto
    {
        return new Contexto($usuario, $perfil === Perfil::Solicitante ? Apoio::tenant(2) : $this->sede, $perfil, $cfg ?? $this->cfg);
    }

    private function politica(): PoliticaDoCurriculo
    {
        return new PoliticaDoCurriculo(new PoliticaDaVaga(new PoliticaDaDemanda()), new PoliticaDaDemanda());
    }

    private function localizador(): LocalizadorDeCurriculo
    {
        return new LocalizadorDeCurriculo($this->curriculos, $this->politica(), $this->createStub(CandidaturaRepository::class), new PoliticaDaVaga(new PoliticaDaDemanda()));
    }

    private function rascunho(): GerarRascunhoDoTermoUseCase
    {
        return new GerarRascunhoDoTermoUseCase($this->localizador(), new PoliticaDoTermo($this->politica()), $this->termos, new RascunhoDoTermo());
    }

    private function enviar(): EnviarTermoParaAceiteUseCase
    {
        return new EnviarTermoParaAceiteUseCase($this->localizador(), new PoliticaDoTermo($this->politica()), $this->termos, $this->avisos(), $this->em);
    }

    private function aceitar(): AceitarTermoUseCase
    {
        $politica = new PoliticaDoTermo($this->politica());

        return new AceitarTermoUseCase(new LocalizadorDeTermo($this->termos, $politica, $this->politica()), $politica, new HtmlDoTermoDeVoluntariado(), $this->avisos(), $this->termos, $this->em);
    }

    /** Os avisos com o sino em memória: Master na sede, sem escritório para o voluntário (o texto se prova no `AvisosDoTrabalhoExternoTest`). */
    private function avisos(): AvisosDoTrabalhoExterno
    {
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $mensagem = null) {
            $this->avisosCriados[] = ['u' => (int) $u->getId(), 'tipo' => $tipo, 'titulo' => $titulo];

            return (new \App\Entity\Notificacao())->setUsuario($u)->setTenant($t)->setTipo($tipo)->setTitulo($titulo)->setMensagem($mensagem);
        });
        $destinatarios = $this->createStub(DestinatariosDosAvisosRepository::class);
        $destinatarios->method('masters')->willReturn([$this->master]);

        return new AvisosDoTrabalhoExterno(
            $notificacoes,
            $destinatarios,
            $this->createStub(PreferenciaDoUsuarioRepository::class),
            $this->curriculos,
            new ResolvedorDePerfil($this->createStub(PermissionChecker::class)),
            new PoliticaDaVaga(new PoliticaDaDemanda()),
            $this->createStub(ConviteDemandaRepository::class),
            new PoliticaDaDemanda(),
        );
    }

    private function comId(object $objeto, int $id): object
    {
        (new \ReflectionProperty($objeto, 'id'))->setValue($objeto, $id);

        return $objeto;
    }
}
