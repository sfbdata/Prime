<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Central\Service\TiposDeAvisoDaCentral;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\ResolvedorDePerfil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os avisos do Trabalho Externo (spec VS 18.10.26 §4.7): para cada evento, quem recebe, em que
 * escritório, com que título e link; sem sede ou sem escritório, nada; o pendente igual não se repete; e
 * nunca o nome do candidato. As consultas de destinatário se provam no teste funcional.
 */
#[CoversClass(AvisosDoTrabalhoExterno::class)]
#[CoversClass(TiposDeAvisoDaCentral::class)]
final class AvisosDoTrabalhoExternoTest extends TestCase
{
    private Tenant $sede;
    private Tenant $escritorio;
    private User $master;
    private User $equipe;
    private User $solicitante;
    private User $semPermissao;
    private ConfiguracaoCentral $cfg;
    /** @var list<array{u: int, t: int, tipo: string, titulo: string, mensagem: ?string, url: ?string}> */
    private array $criadas = [];
    /** @var list<Notificacao> */
    private array $notificacoes = [];
    /** @var array<int, list<User>> */
    private array $doEscritorio = [];
    /** @var list<int> quem tem a permissão do módulo */
    private array $comPermissao = [];
    /** @var list<PreferenciaDoUsuario> */
    private array $alertas = [];
    /** @var array<int, Curriculo> */
    private array $cvs = [];
    private bool $pendente = false;
    /** @var array<int, Tenant> */
    private array $tenants = [];
    /** @var list<int> quem tem convite vigente (vira Convidado sem a permissão do módulo) */
    private array $comConvite = [];
    private ?Tenant $primeiroEscritorio = null;

    protected function setUp(): void
    {
        [$this->sede, $this->escritorio] = [Apoio::tenant(1), Apoio::tenant(2)];
        $this->master = Apoio::usuario(1, ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        [$this->equipe, $this->solicitante, $this->semPermissao] = [Apoio::usuario(2), Apoio::usuario(3), Apoio::usuario(4)];
        $this->comPermissao = [2, 3, 5, 6];
        $this->doEscritorio = [1 => [$this->master, $this->equipe, $this->semPermissao], 2 => [$this->master, $this->solicitante, $this->semPermissao]];
        $this->tenants = [1 => $this->sede, 2 => $this->escritorio, 5 => Apoio::tenant(5), 6 => Apoio::tenant(6)];
        $this->cfg = new ConfiguracaoCentral();
        $this->cfg->definirSede($this->sede);
    }

    #[TestDox('pedido de divulgação: Master e Equipe com permissão na sede, link da vaga; sem talentos só o Master; sem sede nada')]
    public function testPedidoDeDivulgacao(): void
    {
        $vaga = $this->vagaDoEscritorio();
        $this->avisos()->pedidoDeDivulgacao($this->ctx($this->solicitante), $vaga, false);
        self::assertSame([[1, 1], [2, 1]], $this->quem());
        self::assertSame(['trabalho_externo_pedido_de_vaga', 'Novo pedido de divulgação de vaga: Advogado cível', '/trabalho-externo#vaga=VG-0040'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo'], $this->notificacoes[0]->getUrl()]);

        $this->limpar();
        $this->cfg->definirEquipe('talentos', false);
        $this->avisos()->pedidoDeDivulgacao($this->ctx($this->solicitante), $vaga, true);
        self::assertSame([[1, 1]], $this->quem());
        self::assertSame('Pedido de divulgação reenviado: Advogado cível', $this->criadas[0]['titulo']);

        $this->limpar();
        $this->cfg->definirSede(null);
        $this->avisos()->pedidoDeDivulgacao($this->ctx($this->solicitante), $vaga, false);
        self::assertSame([], $this->criadas, 'sem sede, nenhum aviso');
    }

    #[TestDox('candidatura comum e aceite na vaga da Central: a Central; aceite na vaga de escritório: os Solicitantes dele (não o Master), no escritório da vaga')]
    public function testCandidaturaEAceite(): void
    {
        $candidato = Apoio::usuario(9);
        $daCentral = $this->comId(Vaga::daCentral($this->master, 'Ana', $this->dados(['oferta' => true]), new \DateTimeImmutable()), 41);
        $this->avisos()->candidatura($this->ctx($candidato, Perfil::Solicitante), Candidatura::candidatar($daCentral, $this->cv($candidato), new \DateTimeImmutable()));
        self::assertSame([[1, 1], [2, 1]], $this->quem());
        self::assertSame(['trabalho_externo_candidatura', 'Nova candidatura na vaga Advogado cível'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo']]);

        $this->limpar();
        $this->avisos()->candidatura($this->ctx($candidato, Perfil::Solicitante), Candidatura::aceitarOferta($daCentral, $this->cv($candidato), new \DateTimeImmutable()));
        self::assertSame(['trabalho_externo_oferta_aceita', 'Oferta aceita na vaga Advogado cível'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo']]);

        $this->limpar();
        $vaga = $this->vagaDoEscritorio(['oferta' => true]);
        $this->avisos()->candidatura($this->ctx($candidato, Perfil::Solicitante), Candidatura::aceitarOferta($vaga, $this->cv($candidato), new \DateTimeImmutable()));
        self::assertSame([[3, 2]], $this->quem(), 'só o Solicitante com permissão; o Master não é dono da vaga');
        $this->semTextoPessoal();
    }

    #[TestDox('candidatos enviados: o dono e cada candidato enviado; vaga da Central nada; Convidado sem escritório na vaga: ele, no primeiro escritório; sem nenhum, nada')]
    public function testCandidatosEnviados(): void
    {
        $candidato = Apoio::usuario(9);
        $this->doEscritorio[6] = [$candidato];
        $vaga = $this->vagaDoEscritorio();
        $this->avisos()->candidatosEnviados($this->ctx($this->master), $vaga, [Candidatura::candidatar($vaga, $this->cv($candidato), new \DateTimeImmutable(), $this->tenants[6])]);
        self::assertSame([[3, 2], [9, 6]], $this->quem());
        self::assertSame(['Candidatos indicados pelo BlueJus na vaga Advogado cível', 'trabalho_externo_candidatura_status'], [$this->criadas[0]['titulo'], $this->criadas[1]['tipo']]);

        $this->limpar();
        $this->avisos()->candidatosEnviados($this->ctx($this->master), $this->comId(Vaga::daCentral($this->master, 'Ana', $this->dados(), new \DateTimeImmutable()), 42));
        self::assertSame([], $this->criadas);

        $convidado = Apoio::usuario(7);
        $avulsa = $this->comId(Vaga::doExterno(null, $convidado, 'Caio', $this->dados(), new \DateTimeImmutable()), 43);
        $this->avisos()->candidatosEnviados($this->ctx($this->master), $avulsa);
        self::assertSame([], $this->criadas, 'Convidado sem escritório: nenhum aviso');
        $this->primeiroEscritorio = Apoio::tenant(5);
        $this->avisos()->candidatosEnviados($this->ctx($this->master), $avulsa);
        self::assertSame([[7, 5]], $this->quem());
    }

    #[TestDox('aprovada e não aprovada: o dono; a não aprovada leva o motivo; o próprio autor não se avisa')]
    public function testAprovadaEReprovada(): void
    {
        $vaga = $this->vagaDoEscritorio();
        $this->avisos()->vagaAprovada($this->ctx($this->master), $vaga);
        self::assertSame(['trabalho_externo_vaga_aprovada', 'Sua vaga foi aprovada e publicada: Advogado cível', null], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo'], $this->criadas[0]['mensagem']]);

        $this->limpar();
        $reprovada = $this->vagaDoEscritorio();
        $reprovada->aplicar(AcaoVaga::Reprovar, 'Falta a remuneração', 'Master', 0, 0, new \DateTimeImmutable());
        $this->avisos()->vagaReprovada($this->ctx($this->master), $reprovada);
        self::assertSame(['Sua vaga não foi aprovada: Advogado cível', 'Motivo: Falta a remuneração'], [$this->criadas[0]['titulo'], $this->criadas[0]['mensagem']]);

        $this->limpar();
        $this->avisos()->vagaAprovada($this->ctx($this->solicitante), $vaga);
        self::assertSame([], $this->criadas, 'quem age não recebe o próprio aviso');
    }

    #[TestDox('andamento: o candidato no escritório gravado (com vínculo ativo), senão no primeiro dele; sem nenhum nada; pendente igual não repete')]
    public function testAndamentoEDedup(): void
    {
        $candidato = Apoio::usuario(9);
        $vaga = $this->vagaDoEscritorio();
        $this->avisos()->andamentoDaCandidatura($this->ctx($this->master), Candidatura::candidatar($vaga, $this->cv($candidato), new \DateTimeImmutable()));
        self::assertSame([], $this->criadas, 'sem escritório gravado nem outro, nenhum aviso');
        $outro = $this->tenants[6];
        $this->primeiroEscritorio = $this->tenants[5];
        $this->avisos()->andamentoDaCandidatura($this->ctx($this->master), Candidatura::candidatar($vaga, $this->cv($candidato), new \DateTimeImmutable(), $outro));
        self::assertSame([[9, 5]], $this->quem(), 'sem vínculo no gravado: o primeiro escritório ativo dele');

        $this->limpar();
        $this->primeiroEscritorio = null;
        $this->doEscritorio[6] = [$candidato];
        $this->avisos()->andamentoDaCandidatura($this->ctx($this->master), Candidatura::candidatar($vaga, $this->cv($candidato), new \DateTimeImmutable(), $outro));
        self::assertSame([[9, 6]], $this->quem());
        self::assertSame(['trabalho_externo_candidatura_status', 'Andamento atualizado na sua candidatura à vaga Advogado cível'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo']]);
        $this->semTextoPessoal();

        $this->limpar();
        $this->pendente = true;
        $this->avisos()->andamentoDaCandidatura($this->ctx($this->master), Candidatura::candidatar($vaga, $this->cv($candidato), new \DateTimeImmutable(), $outro));
        self::assertSame([], $this->criadas, 'o aviso não lido do mesmo link não se repete');
    }

    #[TestDox('vaga na área: quem ligou e vê a aba como externo (Solicitante ou Convidado), no escritório guardado; área em comum ou sem currículo; desligado, sem escritório, sem perfil, área diferente, dono e interno ficam de fora; sino desligado nada')]
    public function testVagaNaArea(): void
    {
        [$comArea, $semCv, $outraArea, $desligado, $semEscritorio, $doDono] = [Apoio::usuario(11), Apoio::usuario(12), Apoio::usuario(13), Apoio::usuario(14), Apoio::usuario(15), $this->solicitante];
        [$convidado, $semPerfil] = [Apoio::usuario(8), $this->semPermissao];
        $this->comConvite = [8];
        $this->doEscritorio[5] = [$comArea, $semCv, $outraArea, $desligado, $convidado, $semPerfil];
        $this->cvs = [11 => $this->cv($comArea, ['Cível', 'Família']), 13 => $this->cv($outraArea, ['Penal'])];
        $this->alertas = [
            $this->alerta($comArea, true, 5), $this->alerta($semCv, true, 5), $this->alerta($outraArea, true, 5), $this->alerta($desligado, false, 5),
            $this->alerta($semEscritorio, true, null), $this->alerta($doDono, true, 2), $this->alerta($this->equipe, true, 1), $this->alerta($convidado, true, 5), $this->alerta($semPerfil, true, 5),
        ];
        $vaga = $this->vagaDoEscritorio();
        $vaga->aplicar(AcaoVaga::Aprovar, null, 'Master', 0, 0, new \DateTimeImmutable());

        $this->avisos()->vagaNaArea($this->ctx($this->master), $vaga, new \DateTimeImmutable());
        self::assertSame([[11, 5], [12, 5], [8, 5]], $this->quem());
        self::assertSame(['trabalho_externo_vaga_na_area', 'Nova vaga na sua área: Advogado cível'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo']]);

        $this->limpar();
        $this->cfg->definirVagas(['sino' => false]);
        $this->avisos()->vagaNaArea($this->ctx($this->master), $vaga, new \DateTimeImmutable());
        self::assertSame([], $this->criadas, 'com o sino desligado pelo Master, nada');

        $this->cfg->definirVagas(['sino' => true]);
        $this->avisos()->vagaNaArea($this->ctx($this->master), $this->vagaDoEscritorio(), new \DateTimeImmutable());
        self::assertSame([], $this->criadas, 'a vaga pendente não avisa');
    }

    #[TestDox('pedido de informações na demanda: quem solicitou no escritório da demanda, link ?te=; fora do escritório, os Solicitantes')]
    public function testPedidoDeInformacoes(): void
    {
        $demanda = Apoio::demanda(Situacao::Info, $this->escritorio);
        $this->doEscritorio[2][] = $demanda->getCriadoPor();
        $this->avisos()->pedidoDeInformacoes($this->ctx($this->master), $demanda);
        self::assertSame([[10, 2]], $this->quem());
        self::assertSame(['trabalho_externo_demanda_informacoes', 'Pedido de informações na demanda ' . $demanda->getCodigo(), '/trabalho-externo?te=' . $demanda->getCodigo()], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo'], $this->notificacoes[0]->getUrl()]);

        $this->limpar();
        array_pop($this->doEscritorio[2]);
        $this->avisos()->pedidoDeInformacoes($this->ctx($this->master), $demanda);
        self::assertSame([[3, 2]], $this->quem());
    }

    #[TestDox('andamento da demanda (D10): solicitante e convidado vigente, um aviso por andamento, sem quem agiu nem Master/Equipe; pendente igual não segura')]
    public function testAndamentoDaDemanda(): void
    {
        $demanda = Apoio::demanda(Situacao::Execucao, $this->escritorio);
        $quemPediu = $demanda->getCriadoPor();
        $this->doEscritorio[2][] = $quemPediu;
        [$revogado, $convidado] = [Apoio::usuario(21), Apoio::usuario(20)];
        $this->convidarEAceitar($demanda, $revogado);
        $this->convidarEAceitar($demanda, $convidado);
        $demanda->retirarAndamentosParaAvisar();
        $this->avisos()->andamentoDaDemanda($this->ctx($this->master), $demanda);
        self::assertSame([], $this->criadas, 'sem andamento novo, nada');

        $demanda->registrar(Apoio::autor(), 'Cheguei ao fórum', \App\TrabalhoExterno\Enum\TipoMensagem::Andamento);
        $this->pendente = true;
        $this->avisos()->andamentoDaDemanda($this->ctx($this->master), $demanda);
        self::assertSame([[10, 2], [20, 2]], $this->quem(), 'quem pediu e o convidado vigente (sem escritório: no da demanda); o revogado e o Master não');
        $codigo = $demanda->getCodigo();
        self::assertSame(
            ['trabalho_externo_demanda_andamento', $codigo . ' · Advocacia correspondente presencial', 'Cheguei ao fórum', '/trabalho-externo?te=' . $codigo],
            [$this->criadas[0]['tipo'], $this->criadas[0]['titulo'], $this->criadas[0]['mensagem'], $this->notificacoes[0]->getUrl()],
        );

        $this->limpar();
        $demanda->aplicar(\App\TrabalhoExterno\Enum\AcaoDemanda::EnviarParaValidacao, Apoio::autor(Perfil::Convidado), null);
        $this->primeiroEscritorio = $this->tenants[5];
        $this->avisos()->andamentoDaDemanda($this->ctx($convidado, Perfil::Convidado), $demanda);
        self::assertSame('Andamento: Aguardando validação', $this->criadas[0]['mensagem'], 'a mudança de situação, com o rótulo');
        self::assertSame([10], array_values(array_unique(array_column($this->criadas, 'u'))), 'quem agiu (o convidado) fica de fora');

        $this->limpar();
        $demanda->registrar(Apoio::autor(), 'Documento entregue', \App\TrabalhoExterno\Enum\TipoMensagem::Arquivo);
        $this->avisos()->andamentoDaDemanda($this->ctx($this->master), $demanda);
        self::assertSame([[10, 2]], $this->quem(), 'convidado sem a permissão "ver" não lê o registro de arquivo no chat: nem o nome dos documentos no aviso');

        $this->limpar();
        $this->primeiroEscritorio = $this->sede;
        $this->doEscritorio[2] = [$this->master, $this->solicitante];
        $demanda->registrar(Apoio::autor(), 'Mais um', \App\TrabalhoExterno\Enum\TipoMensagem::Sistema);
        $demanda->registrar(Apoio::autor(), 'Conversa comum', \App\TrabalhoExterno\Enum\TipoMensagem::Normal);
        $this->avisos()->andamentoDaDemanda($this->ctx($this->equipe, Perfil::Equipe), $demanda);
        self::assertSame([[3, 2]], $this->quem(), 'quem pediu saiu: os Solicitantes; o convidado que é Equipe na sede fica de fora; a mensagem comum não avisa');
    }

    #[TestDox('andamento da demanda (D10): o convidado com "ver" recebe o registro de arquivo, no primeiro escritório dele')]
    public function testAndamentoDeArquivoComVer(): void
    {
        $demanda = Apoio::demanda(Situacao::Execucao, $this->escritorio);
        $this->doEscritorio[2][] = $demanda->getCriadoPor();
        $this->convidarEAceitar($demanda, Apoio::usuario(20), ['ver' => true]);
        $demanda->retirarAndamentosParaAvisar();
        $this->primeiroEscritorio = $this->tenants[5];

        $demanda->registrar(Apoio::autor(), 'Documento entregue', \App\TrabalhoExterno\Enum\TipoMensagem::Arquivo);
        $this->avisos()->andamentoDaDemanda($this->ctx($this->master), $demanda);
        self::assertSame([[10, 2], [20, 5]], $this->quem());
        self::assertSame(['Documento entregue', 'Documento entregue'], array_column($this->criadas, 'mensagem'));
    }

    #[TestDox('andamento da demanda (D10): quem pediu e também é o convidado recebe um aviso só por andamento')]
    public function testAndamentoSemAvisoEmDobro(): void
    {
        $demanda = Apoio::demanda(Situacao::Execucao, $this->escritorio);
        $quemPediu = $demanda->getCriadoPor();
        $this->doEscritorio[2][] = $quemPediu;
        $this->primeiroEscritorio = $this->tenants[2];
        $this->convidarEAceitar($demanda, $quemPediu, ['ver' => true]);
        $demanda->retirarAndamentosParaAvisar();

        $demanda->registrar(Apoio::autor(), 'Protocolo feito', \App\TrabalhoExterno\Enum\TipoMensagem::Andamento);
        $demanda->registrar(Apoio::autor(), 'Documento entregue', \App\TrabalhoExterno\Enum\TipoMensagem::Arquivo);
        $this->avisos()->andamentoDaDemanda($this->ctx($this->master), $demanda);
        self::assertSame([[10, 2], [10, 2]], $this->quem(), 'dois andamentos, um aviso de cada para a mesma pessoa');
        self::assertSame(['Protocolo feito', 'Documento entregue'], array_column($this->criadas, 'mensagem'));
    }

    #[TestDox('oferta recusada (D11b, P7): os Solicitantes do escritório dono, no escritório da vaga, e os Master da Central, na sede; quem recusou, a Equipe e quem não tem permissão ficam de fora; vaga da Central só os Master; sem sede só o dono')]
    public function testOfertaRecusada(): void
    {
        $quemRecusou = Apoio::usuario(9);
        $vaga = $this->vagaDoEscritorio(['oferta' => true]);
        $this->avisos()->ofertaRecusada($this->ctx($quemRecusou, Perfil::Solicitante), $vaga, 'Bia Lima');
        self::assertSame([[3, 2], [1, 1]], $this->quem());
        self::assertSame(['trabalho_externo_oferta_recusada', 'Oferta recusada na vaga Advogado cível', 'Uma oferta sua foi recusada.', '/trabalho-externo#vaga=VG-0040'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo'], $this->criadas[0]['mensagem'], $this->notificacoes[0]->getUrl()], 'o dono sem o nome');
        self::assertSame('Oferta recusada por Bia Lima', $this->criadas[1]['mensagem'], 'o Master com o nome');
        self::assertSame('trabalho_externo_oferta_recusada', $this->criadas[1]['tipo']);

        $this->limpar();
        $daCentral = $this->comId(Vaga::daCentral($this->master, 'Ana', $this->dados(['oferta' => true]), new \DateTimeImmutable()), 41);
        $this->avisos()->ofertaRecusada($this->ctx($quemRecusou, Perfil::Solicitante), $daCentral, 'Bia Lima');
        self::assertSame([[1, 1]], $this->quem(), 'vaga da Central: só os Master');

        $this->limpar();
        $this->pendente = true;
        $this->avisos()->ofertaRecusada($this->ctx($quemRecusou, Perfil::Solicitante), $vaga, 'Bia Lima');
        self::assertSame([], $this->criadas, 'o aviso igual ainda não lido não se repete');

        $this->pendente = false;
        $this->cfg->definirSede(null);
        $this->avisos()->ofertaRecusada($this->ctx($quemRecusou, Perfil::Solicitante), $vaga, 'Bia Lima');
        self::assertSame([[3, 2]], $this->quem(), 'sem sede: só o dono');
    }

    #[TestDox('termo de voluntariado: enviado vai ao voluntário, no primeiro escritório dele; aceito vai à Central na sede; nenhum texto leva CPF, RG nem nome')]
    public function testTermoDeVoluntariado(): void
    {
        $voluntaria = Apoio::usuario(9);
        $cv = $this->comId($this->cv($voluntaria), 12);
        $termo = TermoDeVoluntariado::enviar($this->sede, $cv, ['vNome' => 'Ana Souza', 'vCpf' => '111.222.333-44', 'vRg' => '1.234.567'], 'TV-0003', $this->master, new \DateTimeImmutable());

        $this->primeiroEscritorio = $this->escritorio;
        $this->avisos()->termoEnviado($this->ctx($this->master), $termo);
        self::assertSame([[9, 2]], $this->quem());
        self::assertSame(['trabalho_externo_termo_enviado', 'Você tem um termo de voluntariado para aceitar', '/trabalho-externo#cv=CV-0012'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo'], $this->notificacoes[0]->getUrl()]);

        $this->limpar();
        $this->primeiroEscritorio = null;
        $this->avisos()->termoEnviado($this->ctx($this->master), $termo);
        self::assertSame([], $this->criadas, 'voluntário sem escritório ativo: sem aviso');

        $this->avisos()->termoAceito($this->ctx($voluntaria, Perfil::Solicitante), $termo);
        self::assertSame([[1, 1], [2, 1]], $this->quem(), 'Master e Equipe com talentos, na sede');
        self::assertSame(['trabalho_externo_termo_aceito', 'Termo de voluntariado aceito: TV-0003 (currículo CV-0012)'], [$this->criadas[0]['tipo'], $this->criadas[0]['titulo']]);
        $tudo = json_encode($this->criadas, JSON_THROW_ON_ERROR);
        foreach (['111.222.333-44', '1.234.567', 'Ana Souza', 'CPF', 'RG'] as $dado) {
            self::assertStringNotContainsString($dado, $tudo);
        }
    }

    #[TestDox('cada tipo novo tem ícone no sino e grupo, rótulo, ícone e cor na Caixa da Central')]
    public function testTiposNovos(): void
    {
        $tipos = array_filter((new \ReflectionClass(Notificacao::class))->getConstants(), static fn (mixed $v, string $k): bool => str_starts_with($k, 'TIPO_TRABALHO_EXTERNO_'), ARRAY_FILTER_USE_BOTH);
        self::assertCount(14, $tipos, 'o 11º é a resposta ao despacho (D8); o 12º, a oferta recusada (D11b); o 13º e o 14º, o termo de voluntariado enviado e aceito');
        foreach ($tipos as $tipo) {
            self::assertLessThanOrEqual(50, strlen($tipo), 'a coluna tipo tem 50 caracteres');
            self::assertNotSame('bi-bell text-secondary', (new Notificacao())->setTipo($tipo)->getIcone(), $tipo);
            $mapa = (new TiposDeAvisoDaCentral())->para($tipo);
            self::assertSame('atencao', $mapa['grupo'], $tipo);
            self::assertNotSame('Aviso', $mapa['rotulo'], $tipo);
        }
        self::assertSame('Abrir a demanda', (new TiposDeAvisoDaCentral())->acao(Notificacao::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO), 'a ação do desenho (`avisarPartes`)');
        self::assertSame('Abrir a demanda', (new TiposDeAvisoDaCentral())->acao(Notificacao::TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA), 'a resposta ao despacho abre a demanda');
    }

    /** @param array<string, bool> $permissoes */
    private function convidarEAceitar(\App\TrabalhoExterno\Entity\DemandaExterna $demanda, User $pessoa, array $permissoes = []): void
    {
        $convite = new ConviteDemanda($demanda, 'Convidado ' . $pessoa->getId(), (string) $pessoa->getEmail(), null, new \DateTimeImmutable('+30 days'), str_repeat('a', 64), $permissoes, Apoio::autor());
        $demanda->convidar($convite, Apoio::autor());
        $convite->aceitar($pessoa, new \DateTimeImmutable('today'));
    }

    private function avisos(): AvisosDoTrabalhoExterno
    {
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $mensagem = null): Notificacao {
            $n = (new Notificacao())->setUsuario($u)->setTenant($t)->setTipo($tipo)->setTitulo($titulo)->setMensagem($mensagem);
            $this->criadas[] = ['u' => (int) $u->getId(), 't' => (int) $t->getId(), 'tipo' => $tipo, 'titulo' => $titulo, 'mensagem' => $mensagem, 'url' => null];
            $this->notificacoes[] = $n;

            return $n;
        });
        $destinatarios = $this->createMock(DestinatariosDosAvisosRepository::class);
        $destinatarios->method('masters')->willReturnCallback(fn (): array => [$this->master]);
        $destinatarios->method('ativosDoEscritorio')->willReturnCallback(fn (Tenant $t): array => $this->doEscritorio[(int) $t->getId()] ?? []);
        $destinatarios->method('escritorioDaPessoa')->willReturnCallback(fn (User $u, int $id): ?Tenant => in_array($u, $this->doEscritorio[$id] ?? [], true) ? $this->tenants[$id] : null);
        $destinatarios->method('primeiroEscritorio')->willReturnCallback(fn (): ?Tenant => $this->primeiroEscritorio);
        $destinatarios->method('temPendente')->willReturnCallback(fn (): bool => $this->pendente);
        $preferencias = $this->createMock(PreferenciaDoUsuarioRepository::class);
        // Como o SQL: só as ligadas (o serviço confere de novo).
        $preferencias->method('alertasDeVagasLigados')->willReturnCallback(fn (): array => array_values(array_filter($this->alertas, static fn (PreferenciaDoUsuario $p): bool => ($p->getValor()['ligado'] ?? false) === true)));
        $convites = $this->createMock(ConviteDemandaRepository::class);
        $convites->method('vigentesDo')->willReturnCallback(fn (User $u): array => in_array((int) $u->getId(), $this->comConvite, true) ? [$this->createStub(ConviteDemanda::class)] : []);
        $curriculos = $this->createMock(CurriculoRepository::class);
        $curriculos->method('doUsuario')->willReturnCallback(fn (User $u): ?Curriculo => $this->cvs[(int) $u->getId()] ?? null);
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturnCallback(fn (User $u): bool => in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true) || in_array((int) $u->getId(), $this->comPermissao, true) || (int) $u->getId() >= 10);

        return new AvisosDoTrabalhoExterno($notificacoes, $destinatarios, $preferencias, $curriculos, new ResolvedorDePerfil($permissoes), new PoliticaDaVaga(new PoliticaDaDemanda()), $convites, new PoliticaDaDemanda());
    }

    private function ctx(User $usuario, Perfil $perfil = Perfil::Master): Contexto
    {
        return new Contexto($usuario, $perfil === Perfil::Master ? $this->sede : $this->escritorio, $perfil, $this->cfg);
    }

    /** @return list<array{0: int, 1: int}> quem recebeu e em que escritório */
    private function quem(): array
    {
        return array_map(static fn (array $c): array => [$c['u'], $c['t']], $this->criadas);
    }

    private function limpar(): void
    {
        $this->criadas = [];
        $this->notificacoes = [];
    }

    private function semTextoPessoal(): void
    {
        foreach ($this->criadas as $c) {
            self::assertStringNotContainsString('Ana Souza', $c['titulo'] . $c['mensagem']);
            self::assertStringNotContainsString('ana@exemplo.test', $c['titulo'] . $c['mensagem']);
        }
    }

    /** @param array<string, mixed> $extra */
    private function vagaDoEscritorio(array $extra = []): Vaga
    {
        return $this->comId(Vaga::doExterno($this->escritorio, $this->solicitante, 'Sol', $this->dados($extra), new \DateTimeImmutable()), 40);
    }

    /** @param list<string> $areas */
    private function cv(User $usuario, array $areas = []): Curriculo
    {
        return Curriculo::criar($usuario, DadosDoCurriculo::deArray(['nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'situacao' => 'bach', 'lgpd' => true, 'areas' => $areas]), new \DateTimeImmutable());
    }

    private function alerta(User $usuario, bool $ligado, ?int $escritorioId): PreferenciaDoUsuario
    {
        $p = new PreferenciaDoUsuario($usuario, PreferenciaDoUsuario::ALERTA_DE_VAGAS);
        $p->definir(['ligado' => $ligado, 'escritorioId' => $escritorioId], new \DateTimeImmutable());

        return $p;
    }

    /** @param array<string, mixed> $extra */
    private function dados(array $extra = []): DadosDaVaga
    {
        return DadosDaVaga::deArray($extra + ['titulo' => 'Advogado cível', 'cargo' => 'Advogado(a)', 'areas' => ['Cível'], 'ofertante' => ['nome' => 'Escritório']]);
    }

    /**
     * @template T of object
     *
     * @param T $objeto
     *
     * @return T
     */
    private function comId(object $objeto, int $id): object
    {
        (new \ReflectionProperty($objeto, 'id'))->setValue($objeto, $id);

        return $objeto;
    }
}
