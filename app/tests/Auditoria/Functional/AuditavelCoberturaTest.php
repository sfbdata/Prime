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
use App\Djen\Entity\ProcessoAcompanhado;
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
use App\Shared\EspelhoRemoto\Entity\ReplicacaoDeArquivo;
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
        // Código de 6 dígitos da exclusão da conta (PR 5): só o hash, vive 15 minutos e não diz nada a
        // ninguém. O rastro é o do pedido (PedidoDeExclusaoDeConta, Auditavel).
        \App\Tenant\Entity\CodigoDeExclusaoDeConta::class,
        // Contribua: motivo de ainda não apoiar. O desenho promete resposta ANÔNIMA; a trilha gravaria
        // quem respondeu (actor do audit_log) e desfaria o anonimato.
        \App\Contribua\Entity\MotivoDeNaoApoiar::class,
        // Contribua: sugestão. O Master a atende a partir de outro escritório e o audit_log gravaria no
        // escritório da sessão dele (vazamento entre trilhas). Volta quando houver o escopo de plataforma.
        \App\Contribua\Entity\Sugestao::class,
        // Contribua: aviso bruto recebido de gateway/banco. É ele mesmo a trilha (o que chegou e quando);
        // quem muda dado de escritório é a reconsulta, que audita a assinatura/apoio.
        \App\Contribua\Entity\NotificacaoRecebida::class,

        // Favorito de pasta: preferência pessoal de ordenação (estrela), sem efeito sobre o dado da
        // pasta. Auditar cada clique encheria o audit_log de ruído sem valor de prova.
        \App\Pasta\Entity\PastaFavorita::class,
        // Service Desk: linha do andamento do chamado. Ela JÁ é o registro de quem fez o quê e quando; auditar cada
        // linha duplicaria a trilha. O chamado (ChamadoDeSuporte) e o dinheiro são Auditavel.
        \App\ServiceDesk\Entity\HistoricoDoChamado::class,
        // "Acompanhar alterações" desligado: preferência pessoal de aviso, mesma natureza do favorito.
        \App\Pasta\Entity\PastaAcompanhamentoDesligado::class,
        // Destaque de linha da Lista (E12): cor pessoal de quem olha, mesma natureza do favorito.
        \App\Pasta\Entity\PastaDestaque::class,
        // Estrela de arquivo/subpasta na aba Documentos (D2): mesma natureza do favorito de pasta.
        \App\Pasta\Entity\PastaDocumentoFavorito::class,
        // Cobrança automática do checklist (desenho 02): estado operacional da rotina (datas dos envios,
        // previsão); cada aviso que sai já fica na caixa da pessoa. Auditar daria uma linha por dia por item.
        \App\Pasta\Entity\PastaChecklistCobranca::class,
        // Contador da sequência de metas do escritório: estado operacional (o último número entregue), gravado
        // só por SQL. O número que vale fica na própria meta, que é Auditavel e já entra na trilha ao nascer.
        \App\Tarefa\Entity\NumeracaoDeMetas::class,
        // Evento do monitor de saúde (Desempenho do Master): telemetria com retenção de 90 dias, uma linha por erro,
        // carga, ping ou login. Auditar encheria o audit_log de ruído sem valor de prova; incidentes e registros
        // de backup, que são decisão do Master, são Auditavel.
        \App\Master\Entity\EventoDeDesempenho::class,
        // Aprendizado de Minhas Metas (Fase 4, D4): texto de despacho e linhas de pendência guardados só para as
        // sugestões da própria pessoa. Auditar daria uma linha por despacho e deixaria metadados no audit_log depois do
        // "Apagar o que foi aprendido"; o ato que importa (o despacho, a passagem) já é auditado na meta.
        \App\Tarefa\Entity\AprendizadoDoDespacho::class,
        \App\Tarefa\Entity\AprendizadoDaPendencia::class,

        // Preferência pessoal de tela (densidade, animações, setas, colunas ocultas do Dashboard):
        // estilo de quem olha, sem efeito sobre dado nenhum do escritório. Auditar cada clique no
        // menu ⋮ encheria o audit_log de ruído sem valor de prova.
        \App\Dashboard\Entity\PreferenciaDoUsuario::class,

        // Resumo diário por e-mail: a trava do envio (uma linha por pessoa e dia); o próprio envio fica em comercial_envio.
        \App\Profile\Entity\ResumoDiarioEnvio::class,

        // Saída da equipe: o próprio registro já é a trilha (só inclusão, nunca alterado), e a remoção que o
        // cria já entra no audit_log (RemoverColaboradorDoEscritorioUseCase::registrarAuditoria).
        \App\Tenant\Entity\SaidaDaEquipe::class,
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
        // Comercial e e-mails (comercial-e-emails.md §3): o envio, o conteúdo (apagado no estado final), o ciclo e a
        // varredura são registros gravados pelo worker sem sessão (não há autor); a prova de consentimento já é a
        // própria trilha, só de acréscimo, e o estado da confirmação também é escrito por SQL na rota pública. A
        // alteração do Master nas automações É auditada (AutomacaoDeEmail implementa Auditavel).
        \App\Comercial\Entity\EnvioDeEmail::class,
        \App\Comercial\Entity\ConteudoDoEnvio::class,
        \App\Comercial\Entity\FatoDeConsentimento::class,
        \App\Comercial\Entity\ConfirmacaoDeEndereco::class,
        \App\Comercial\Entity\CicloDaConta::class,
        \App\Comercial\Entity\VarreduraComercial::class,
        // A vez dos avisos de espaço (comercial-e-emails.md §4): só um marcador de deduplicação, gravado por SQL no
        // ouvinte, sem sessão (não há autor) e nunca editado.
        \App\Comercial\Entity\AvisoDeEspaco::class,
        // Modelo de campanha (comercial-e-emails.md §8.2, R6): o subscriber gravaria até 500 caracteres do conteúdo do
        // e-mail. A trilha é manual (modelo_new, modelo_upd, modelo_del) com `changes` só {nome, modo}.
        \App\Comercial\Entity\ModeloDeCampanha::class,
        // Campanha (comercial-e-emails.md §8.4, R6): trilha manual na confirmação e no encerramento (camp_conf,
        // camp_fim) com `changes` só {publico, frequencia, total}; o subscriber gravaria o assunto e o conteúdo. Os
        // destinatários e o controle do expansor são gravados pelo worker sem sessão (não há autor).
        \App\Comercial\Entity\CampanhaDeEmail::class,
        \App\Comercial\Entity\DestinoDaCampanha::class,
        \App\Comercial\Entity\ControleDoExpansor::class,
        // Marcas do Relatório (comercial-marcas.md): estrela, arquivo e "remover" de cada Master, só organizam a lista
        // dele e não mudam campanha nem envio. O que importa já tem trilha manual (camp_csv na exportação).
        \App\Comercial\Entity\CampanhaMarca::class,
        // Imagem da campanha do modo simples (comercial-e-emails.md §12, F3i): a própria linha diz quem enviou e quando, e
        // a validade é renovada pelo worker, sem sessão (não há autor).
        \App\Comercial\Entity\ImagemDaCampanha::class,
        // E-mails do cliente do "Avisar cliente" (push-avisar-cliente §1): dado pessoal que acompanha a decisão
        // do Cliente (não auditado). Auditar copiaria cada endereço para o audit_log, onde ele ficaria mesmo
        // depois de o escritório removê-lo; o cadastro já fica no histórico de envios da pasta.
        \App\Cliente\Entity\ClienteEmail::class,
        // Histórico de envios ao cliente (push-avisar-cliente §1): ele próprio é o registro do ato (quem, quando,
        // para quem, com cópia), nunca editado, como o EventoHistorico. Auditar seria log de log.
        \App\Pasta\Entity\EnvioAoCliente::class,
        // Indicar o BlueJus (docs/specs/indicar-bluejus.md): dado de plataforma, sem tenant. O audit_log é por escritório e
        // lido por quem administra o escritório; a indicação, o código, os limites, as pausas e os alertas são do Master
        // e da própria pessoa, e a própria linha já guarda quem e quando (alterado_por, pausado_por, criada_em).
        \App\Indicacao\Entity\Indicacao::class,
        \App\Indicacao\Entity\IndicacaoCodigo::class,
        \App\Indicacao\Entity\IndicacaoConfig::class,
        \App\Indicacao\Entity\IndicacaoPausa::class,
        \App\Indicacao\Entity\IndicacaoAlerta::class,
        // Central de Notificações do Master (docs/specs/master-central-de-notificacoes.md D1, D3): dado de plataforma,
        // lido e gravado só pelo Master, fora do audit_log por escritório. O estado é marcador pessoal de leitura
        // (lida, estrela, arquivada), como a PastaTimelineVisita; a resposta é ela própria o registro (quem, quando,
        // canal e destino), nunca editada.
        \App\Master\Entity\CentralMasterEstado::class,
        \App\Master\Entity\CentralMasterResposta::class,
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
        // Envio mensal do relatório de pastas paradas: a trava do mês com as contagens, gravada pelo comando
        // agendado. Dado derivado das pastas, não ato de uma pessoa (como o histórico acima).
        \App\Pasta\Entity\EnvioDoRelatorioPastasParadas::class,
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
        // a ligação (e a do prazo da pasta) guarda o id do evento lá e o resumo do último envio, a exclusão pendente é fila de
        // trabalho, e a marca da cadeia é técnica. O que vem do Google para os compromissos É auditado
        // (edição do Evento pelo ORM).
        \App\Agenda\Entity\ConexaoGoogleAgenda::class,
        \App\Agenda\Entity\LigacaoComGoogle::class,
        \App\Agenda\Entity\PrazoNoGoogle::class,
        \App\Agenda\Entity\DataNoGoogle::class,
        \App\Agenda\Entity\ExclusaoNoGoogle::class,
        \App\Agenda\Entity\VarreduraDoGoogle::class,
        // Pergunta da aba Perguntar da BlueJus IA (drawer, F3, 19/10): a conversa é da pessoa, e o AuditLogSubscriber
        // gravaria o texto da pergunta no audit_log, que quem administra o escritório lê (mesmo motivo da Central).
        // A análise da pergunta continua auditada, sem o texto do modelo (ConteudoParticular).
        \App\Inteligencia\Entity\PerguntaDaConversa::class,
        // Histórico do status das questões jurídicas da IA (Master): é o LOG DE DOMÍNIO da questão (de, para, quem,
        // quando), nunca editado, como o HistoricoDemanda. O estado da questão (EstadoDaQuestaoJuridica) É auditado.
        // Auditar o histórico seria log de log.
        \App\Inteligencia\Entity\MudancaDeStatusDaQuestaoJuridica::class,

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
        // Histórico do cadastro (E12 3d, 19/10): ela MESMA é o registro (quem, quando, quais campos), só com rótulos,
        // nunca valores. Cliente não é Auditavel de propósito: o AuditLogSubscriber poria CPF e RG em claro no audit_log.
        \App\Cliente\Entity\ClienteHistorico::class,
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
        // ProcessoAcompanhado é anotação do Push (observação, área, fase) e número incluído à mão.
        ProcessoAcompanhado::class,

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

        // Outbox da réplica do espelho no R2 (P2.3, spec `p2-gravacao-dupla.md` §3.2/§14): o mapeamento
        // existe só para o Doctrine conhecer a tabela. Toda escrita é por DBAL (`OutboxDeReplicacao`),
        // então o listener do ORM nunca a veria: `Auditavel` seria promessa vazia. Auditar ainda
        // copiaria `nome`/`chave_remota` (nome de pessoa nos legados) para o audit_log, o oposto da
        // retenção pedida na decisão I4. O próprio estado da linha (tentativas, erro com nome e chave
        // mascarados quando aparecem literais) é o registro da réplica.
        ReplicacaoDeArquivo::class,

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
        // Despachos e respostas da Central: já ficam registrados no chat da demanda (mensagem de sistema) e
        // têm assinatura eletrônica com código; auditar seria log de log.
        \App\TrabalhoExterno\Entity\DespachoDemanda::class,
        // Recusa de oferta: já deixa rastro no histórico da vaga ("Oferta recusada por"); auditar seria log de log.
        \App\TrabalhoExterno\Entity\RecusaDeOferta::class,
        \App\TrabalhoExterno\Entity\ConviteDemanda::class,
        \App\TrabalhoExterno\Entity\ContatoWhatsapp::class,
        \App\TrabalhoExterno\Entity\AvisoWhatsapp::class,
        \App\TrabalhoExterno\Entity\ConfiguracaoCentral::class,
        // Marcações pessoais do Trabalho Externo (spec VS 18.10.26 §2): estrela, lixeira e favorita
        // de UM usuário, como a LeituraDemanda. Não mudam nada para os outros; auditar seria ruído.
        \App\TrabalhoExterno\Entity\MarcacaoDoUsuario::class,
        \App\TrabalhoExterno\Entity\MarcacaoDeMensagem::class,
        // Vagas do Trabalho Externo (spec VS 18.10.26 §4.1): têm o LOG DE DOMÍNIO próprio
        // (HistoricoVaga, o "HISTÓRICO DA OFERTA" do desenho), como a demanda. Auditar seria log de log.
        \App\TrabalhoExterno\Entity\Vaga::class,
        \App\TrabalhoExterno\Entity\HistoricoVaga::class,
        // Preferências do usuário (última visita às vagas, ofertante padrão): dado de conveniência, não de negócio.
        \App\TrabalhoExterno\Entity\PreferenciaDoUsuario::class,
        // Escritórios que o usuário guarda na Nova solicitação (nome e contato de preencher formulário): dado de conveniência
        // pessoal, sem efeito sobre o escritório nem sobre o Tenant; auditar seria ruído e copiaria contato para o audit_log.
        \App\TrabalhoExterno\Entity\EscritorioSolicitante::class,
        // Página pública de vagas: o link guarda só o hash e as vagas; o interessado guarda o próprio
        // consentimento (data e versão do texto), que é o registro que a LGPD pede.
        \App\TrabalhoExterno\Entity\LinkDeVagas::class,
        \App\TrabalhoExterno\Entity\InteressadoEmVagas::class,
        // Contadores anônimos da página pública (spec VS 18.10.26 §4.9): somados por UPSERT a cada visita
        // e clique, sem ator nem dado pessoal. Auditar seria uma linha de log por visita anônima.
        \App\TrabalhoExterno\Entity\MetricaPublica::class,
        // Currículo do banco de talentos (spec VS 18.10.26 §4.5): é dado pessoal do candidato, que pode
        // excluí-lo a qualquer momento (LGPD). Auditar copiaria nome, contato e resumo para o audit_log
        // do escritório de quem age, e a cópia sobreviveria à exclusão pedida pelo titular. O registro de
        // prova que a LGPD pede é o próprio consentimento (data e versão do texto) gravado no currículo.
        \App\TrabalhoExterno\Entity\Curriculo::class,
        // Candidatura às vagas (spec VS 18.10.26 §4.6): candidatura, aceite e envio ao escritório já vão
        // para o LOG DE DOMÍNIO da vaga (HistoricoVaga) e cada etapa guarda a própria data; é dado do
        // candidato, que some com o currículo excluído (LGPD), pelo mesmo motivo do Curriculo acima.
        \App\TrabalhoExterno\Entity\Candidatura::class,
        // Falha de tarefa agendada (spec scheduler-tarefas-agendadas): gravada pelo agendador, sem sessão nem
        // escritório, e o audit_log é por escritório. A própria linha guarda quem dispensou e quando.
        \App\Shared\Scheduler\Entity\FalhaAgendada::class,
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
