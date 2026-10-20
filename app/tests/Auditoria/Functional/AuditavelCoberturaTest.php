<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Entity\RedefinicaoSenha;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioLinha;
use App\Cobranca\Entity\RelatorioTotalizador;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Audit\AuditLog;
use App\Expediente\Entity\Marcador;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Entity\KanbanMarcador;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\AssuntoProcesso;
use App\Processo\Entity\DocumentoProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Profile\Entity\UserProfile;
use App\Shared\Contract\Auditavel;
use App\Termo\Entity\AceiteTermo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(Auditavel::class)]
final class AuditavelCoberturaTest extends KernelTestCase
{
    // Entidades deliberadamente fora do escopo de Auditavel.
    // Toda entidade ausente desta lista E que não implemente Auditavel quebra o teste.
    private const NAO_AUDITAVEIS = [
        // Contribua: motivo de ainda não apoiar. O desenho promete resposta ANÔNIMA; a trilha gravaria
        // quem respondeu (actor do audit_log) e desfaria o anonimato.
        \App\Contribua\Entity\MotivoDeNaoApoiar::class,
        // Contribua: sugestão. O Master a atende a partir de outro escritório e o audit_log gravaria no
        // escritório da sessão dele (vazamento entre trilhas). Volta quando houver o escopo de plataforma.
        \App\Contribua\Entity\Sugestao::class,

        // Favorito de pasta: preferência pessoal de ordenação (estrela), sem efeito sobre o dado da
        // pasta. Auditar cada clique encheria o audit_log de ruído sem valor de prova.
        \App\Pasta\Entity\PastaFavorita::class,
        // Destaque de linha da Lista (E12): cor pessoal de quem olha, mesma natureza do favorito.
        \App\Pasta\Entity\PastaDestaque::class,
        // Estrela de arquivo/subpasta na aba Documentos (D2): mesma natureza do favorito de pasta.
        \App\Pasta\Entity\PastaDocumentoFavorito::class,

        // Preferência pessoal de tela (densidade, animações, setas, colunas ocultas do Dashboard):
        // estilo de quem olha, sem efeito sobre dado nenhum do escritório. Auditar cada clique no
        // menu ⋮ encheria o audit_log de ruído sem valor de prova.
        \App\Dashboard\Entity\PreferenciaDoUsuario::class,
        // Foto diária do estoque do Dashboard: dado DERIVADO de tarefa/pasta (contagens), regravado
        // pelo cron. Não é ato de ninguém e não prova nada — auditar só duplicaria números.
        \App\Dashboard\Entity\DashboardFoto::class,
        // Previsão da Situação atual do Push (taxa de acertos, push-painel-monitoramento §3): dado DERIVADO
        // das publicações, gravado pelo sistema ao montar a aba, sem ato de ninguém. Auditar só duplicaria
        // a previsão; o processo dela já está fora da auditoria por decisão de produto.
        \App\Processo\Entity\PrevisaoRegistrada::class,
        // Descadastro de e-mail (push-avisar-quando-chegar D3): a própria linha é o registro do pedido (escritório,
        // endereço, assunto, origem e quando), gravada por SQL na rota pública sem sessão (não há autor
        // para auditar) e nunca editada. Auditar só duplicaria o endereço no audit_log.
        \App\Shared\Email\Descadastro\Entity\EmailDescadastro::class,
        // E-mails do cliente do "Avisar cliente" (push-avisar-cliente §1): dado pessoal que acompanha a decisão
        // do Cliente (não auditado). Auditar copiaria cada endereço para o audit_log, onde ele ficaria mesmo
        // depois de o escritório removê-lo; o cadastro já fica no histórico de envios da pasta.
        \App\Cliente\Entity\ClienteEmail::class,
        // Histórico de envios ao cliente (push-avisar-cliente §1): ele próprio é o registro do ato (quem, quando,
        // para quem, com cópia), nunca editado, como o EventoHistorico. Auditar seria log de log.
        \App\Pasta\Entity\EnvioAoCliente::class,
        // Versão anterior da nota técnica (M8/PMC-020, 07/10): ela MESMA é o histórico — só nasce (nunca
        // é editada) a cada edição da nota, que já é auditada. Auditar a versão duplicaria o texto.
        \App\Processo\Entity\NotaTecnicaVersao::class,
        // Última visita do usuário à Timeline da pasta (M4/PAS-043, 07/10): marcador pessoal de leitura,
        // regravado a cada abertura, como os favoritos e as preferências. Não é ato sobre dado do escritório.
        \App\Pasta\Entity\PastaTimelineVisita::class,
        // Link externo do Push (19/10, spec push-link-externo §1): o link guarda o código de acesso em claro (o
        // escritório precisa copiá-lo de novo) e o AuditLogSubscriber gravaria o estado inteiro no audit_log. O acesso
        // é contagem de aberturas pelo terceiro, sem dado de negócio.
        \App\Pasta\Entity\LinkExternoDoPush::class,
        \App\Pasta\Entity\AcessoAoLinkExternoDoPush::class,
        // Central de Comunicação (A1, 14/10): a conversa é das duas pessoas. O AuditLogSubscriber grava o estado
        // inteiro da entidade, e o audit_log é lido por quem administra o escritório — auditar entregaria a
        // conversa privada a terceiros (spec central-comunicacao-a1 §3). O participante é cursor de leitura.
        \App\Central\Entity\Conversa::class,
        \App\Central\Entity\Participante::class,
        \App\Central\Entity\Mensagem::class,
        // Presença na Central (F2, 16/10): batimento a cada 20 s por pessoa. É atividade, não dado de negócio; auditar
        // cada batimento inundaria o audit_log e exporia a rotina de cada um a quem administra.
        \App\Central\Entity\Presenca::class,
        // Histórico do envio automático do relatório de pastas sem processo: a fotografia das contagens
        // gravada pelo comando agendado. Dado derivado das pastas, não ato de uma pessoa (como a foto do
        // Dashboard).
        \App\Pasta\Entity\EnvioDoRelatorioSemProcesso::class,
        // Decisão da equipe sobre pasta sem processo: gravada por upsert SQL (corrida no UNIQUE não pode
        // derrubar a marcação), derivada do ato já auditado (a troca de `administrativa` na Pasta).
        \App\Pasta\Entity\DecisaoSemProcessoDaPasta::class,
        // Baixa de aviso na Central (F3): preferência de leitura da pessoa, como "lida" no sino (que não é auditada).
        \App\Central\Entity\AvisoResolvido::class,
        // Push dos lembretes da Agenda (entrega 5b): endereço técnico do navegador, recibo de envio (evita
        // repetir o aviso) e a marca da varredura a cada minuto. Nenhum é ato sobre dado do escritório;
        // auditar cada envio ou minuto encheria o audit_log de ruído.
        \App\Agenda\Entity\InscricaoDePush::class,
        \App\Agenda\Entity\PushEnviado::class,
        \App\Agenda\Entity\VarreduraDePush::class,
        // Google Agenda (entrega 6): a conexão guarda a autorização cifrada e a marca técnica da sincronia,
        // a ligação guarda o id do evento lá e o resumo do último envio, a exclusão pendente é fila de
        // trabalho, e a marca da cadeia é técnica. O que vem do Google para os compromissos É auditado
        // (edição do Evento pelo ORM).
        \App\Agenda\Entity\ConexaoGoogleAgenda::class,
        \App\Agenda\Entity\LigacaoComGoogle::class,
        \App\Agenda\Entity\ExclusaoNoGoogle::class,
        \App\Agenda\Entity\VarreduraDoGoogle::class,

        // Excluída por design: seria recursão infinita auditar o próprio log
        AuditLog::class,

        // Registro imutável append-only de consentimento — ele próprio é a prova/auditoria
        AceiteTermo::class,

        // Cadastro público efêmero (pré-conta, some em 24h na confirmação/expiração) — sem valor de auditoria
        CadastroPendente::class,

        // Pedido de redefinição de senha: efêmero (1h), pré-autenticação e sem tenant. É
        // purgado de fato — PurgarRedefinicoesSenhaUseCase, chamado pelo
        // app:purgar-dados-expirados —, e não só por promessa de comentário. O fato relevante
        // (a senha mudou) já é auditado no próprio User, com o hash fora por IGNORED_FIELDS.
        // Auditar esta linha só espalharia IP/user agent para uma segunda tabela.
        RedefinicaoSenha::class,

        // Tela de entrada do desenho 10 (spec login-desenho-10):
        // - CienciaPolitica: registro append-only de ciência da Política, como o AceiteTermo, é a própria prova;
        // - ConfirmacaoEmail: link efêmero (7 dias), sem tenant; o fato (e-mail confirmado) muda no próprio User;
        // - AjusteEntrada: guarda quem alterou e quando (alterado_por, alterado_em); sem tenant;
        // - MensagemSuporte: texto de visitante anônimo, sem tenant; ela própria é o registro.
        \App\Termo\Entity\CienciaPolitica::class,
        \App\Auth\Entity\ConfirmacaoEmail::class,
        \App\Auth\Entity\AjusteEntrada::class,
        \App\Auth\Entity\MensagemSuporte::class,

        // Domínios novos — auditoria não expandida nesta fatia (decisão de produto)
        UserProfile::class,
        Cliente::class,
        ClientePF::class,
        ClientePJ::class,
        ClienteDocumento::class,
        Processo::class,
        DocumentoProcesso::class,
        ParteProcesso::class,
        MovimentacaoProcesso::class,
        AssuntoProcesso::class,
        // Associação Pasta↔Processo: acompanha a fatia Processo (não auditada)
        PastaProcesso::class,
        KanbanBoard::class,
        KanbanColuna::class,
        KanbanCard::class,
        KanbanComentario::class,
        KanbanChecklist::class,
        KanbanChecklistItem::class,
        KanbanAnexo::class,
        KanbanMarcador::class,
        Marcador::class,

        // DJEN — domínio novo (mesma decisão de Processo/Cliente): auditoria não expandida.
        // PublicacaoDjen é captada em lote pelo sync (alto volume); OabMonitorada é config do escritório.
        OabMonitorada::class,
        PublicacaoDjen::class,

        // Cobranças — EventoHistorico é o LOG DE DOMÍNIO (linha do tempo operacional, SPEC §13):
        // histórico de negócio visível ao usuário, distinto da auditoria técnica (invariável 26).
        // Auditar o log seria log de log. CasoCobranca/Obrigacao (que tocam dinheiro) SÃO Auditavel.
        EventoHistorico::class,

        // Tabela GLOBAL de referência (spec §7.1 da calculadora de atualização monetária): índice
        // oficial do BCB, sem tenant_id e sem dado de escritório. Ninguém a edita pela aplicação —
        // a única escrita é o cron `app:importar-indices-monetarios`, que já registra na tela e no
        // log toda revisão de índice já gravado. Auditar por usuário não teria usuário nenhum.
        IndiceMonetario::class,

        // Espelho da contabilidade (spec `cobranca-espelho-da-contabilidade.md`): cópia IMUTÁVEL do
        // relatório, nunca editada pela aplicação — a única escrita é a leitura do arquivo, e ela
        // já grava a própria procedência (hash do arquivo, `lidoEm`, `lidoPor`). Auditar aqui
        // custaria uma linha de log por linha de planilha: a carga do acervo tem 38.861 linhas em
        // 23 emissões, e nenhuma delas traria informação que o hash já não prove. Se o número da
        // planilha for contestado, quem responde é o arquivo original, não o log.
        RelatorioImportado::class,
        RelatorioLinha::class,
        RelatorioTotalizador::class,

        // Trabalho Externo (spec docs/specs/trabalho-externo.md): domínio novo, mesma decisão de
        // Processo/Cliente/DJEN. A demanda já tem o LOG DE DOMÍNIO próprio (HistoricoDemanda: de, para,
        // quem, quando, motivo; e os registros de sistema no chat), visível a quem atende, como o
        // EventoHistorico da Cobrança. Auditar seria log de log. Expandir é decisão do dono.
        \App\TrabalhoExterno\Entity\DemandaExterna::class,
        \App\TrabalhoExterno\Entity\HistoricoDemanda::class,
        \App\TrabalhoExterno\Entity\MensagemDemanda::class,
        \App\TrabalhoExterno\Entity\LeituraDemanda::class,
        \App\TrabalhoExterno\Entity\PropostaDemanda::class,
        \App\TrabalhoExterno\Entity\DocumentoDemanda::class,
        \App\TrabalhoExterno\Entity\ConviteDemanda::class,
        \App\TrabalhoExterno\Entity\ContatoWhatsapp::class,
        \App\TrabalhoExterno\Entity\AvisoWhatsapp::class,
        \App\TrabalhoExterno\Entity\ConfiguracaoCentral::class,
    ];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Toda entidade ORM fora de NAO_AUDITAVEIS implementa Auditavel')]
    public function testTodasEntidadesAuditaveisImplementamInterface(): void
    {
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $class = $meta->getName();

            if (in_array($class, self::NAO_AUDITAVEIS, true)) {
                continue;
            }

            self::assertTrue(
                is_a($class, Auditavel::class, true),
                "Entidade $class deve implementar Auditavel ou constar em NAO_AUDITAVEIS."
            );
        }
    }
}
