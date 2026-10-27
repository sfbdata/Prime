<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Entity\InscricaoOab;
use App\Auth\Entity\RedefinicaoSenha;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Entity\ServiceDesk\ChamadoInteracao;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Pasta\Entity\PastaProcesso;
use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Profile\Entity\UserProfile;
use App\Shared\Contract\TenantAware;
use App\Shared\EspelhoRemoto\Entity\ReplicacaoDeArquivo;
use App\Termo\Entity\AceiteTermo;
use App\TrabalhoExterno\Entity\AvisoWhatsapp;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\ContatoWhatsapp;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\HistoricoVaga;
use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Entity\HistoricoDemanda;
use App\TrabalhoExterno\Entity\LeituraDemanda;
use App\TrabalhoExterno\Entity\MarcacaoDeMensagem;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Entity\MetricaPublica;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\RecusaDeOferta;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Entity\Vaga;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rede de cobertura do isolamento multi-tenant (docs/specs/isolamento-multitenant-cobertura.md).
 *
 * O TenantFilter só age em entidades que implementam TenantAware; quem fica de fora não é
 * filtrado e NÃO DÁ ERRO — a consulta devolve dado de todos os escritórios com cara de sucesso.
 * O Kanban inteiro ficou assim por meses porque nada cobrava a cobertura: os testes de
 * isolamento existentes provam que a ferramenta testada isola, nunca que o perímetro fecha.
 *
 * Este teste cobra. Toda entidade ORM precisa estar num de três estados, e o terceiro é o
 * ponto: uma entidade nova que ninguém classificou quebra a suíte na hora.
 */
#[CoversClass(TenantAware::class)]
final class TenantAwareCoberturaTest extends KernelTestCase
{
    /**
     * Entidades que NÃO devem ser filtradas por tenant — decisão permanente, com o motivo ao lado.
     * Entrar aqui exige justificar; não é lugar de estacionar dívida (para isso existe PENDENTE).
     */
    private const FORA_DO_ESCOPO = [
        // O próprio eixo do isolamento.
        Tenant::class,

        // Contribua: catálogo de planos e configuração das faixas de espaço valem para todos os
        // escritórios (só o Master edita). O espaço de cada escritório é TenantAware.
        \App\Contribua\Entity\Plano::class,
        \App\Contribua\Entity\ConfiguracaoDoEspaco::class,
        // Service Desk: preço de cada tipo de atendimento vale para todos os escritórios (só o Master define).
        \App\ServiceDesk\Entity\PrecoDoAtendimento::class,
        // Espaço do escritório: lido e escrito sempre por tenantId explícito (decorador pelo escopo da
        // chave, Master sobre outro escritório). Filtrar pela sessão faria o Master no escritório X
        // ver o Y como Free. Só o repositório do Contribua a consulta
        // (EspacoDoEscritorioSoNoContribuaArquiteturaTest).
        \App\Contribua\Entity\EspacoDoEscritorio::class,
        // Aviso recebido de gateway/banco: quem manda é o gateway, e o escritório só aparece depois da
        // reconsulta. Guardado antes de processar, para idempotência.
        \App\Contribua\Entity\NotificacaoRecebida::class,
        // Configuração do pagamento por cartão (gateway, chave pública, carência): uma linha só, da plataforma.
        \App\Contribua\Entity\ConfiguracaoDePagamento::class,
        // Bônus de espaço por indicação: mesmo motivo do espaço do escritório (lido por tenantId explícito
        // no SituacaoDoEspaco, inclusive em lote para o painel do Master). Só o Contribua o cita
        // (BonusDeEspacoSoNoContribuaArquiteturaTest).
        \App\Contribua\Entity\BonusDeEspaco::class,

        // Identidade: existe antes e acima do vínculo com escritório.
        User::class,

        // O vínculo user↔escritório. Filtrar por tenant aqui seria circular e QUEBRARIA a troca
        // de escritório: TenantContext::setCurrentTenant precisa enxergar o vínculo do tenant
        // para o qual a pessoa está indo, que por definição não é o tenant ativo.
        UserTenant::class,

        // Pré-conta: existe antes de haver User e Tenant (nascem juntos na confirmação).
        // Chave externa é o token, nunca o id.
        CadastroPendente::class,

        // Pré-autenticação e efêmero (1h). Sem tenant por natureza.
        RedefinicaoSenha::class,

        // Tela de entrada do desenho 10 (spec login-desenho-10), sem tenant por natureza:
        // identidade global do User (link de confirmação, ciência da Política), a tela de entrada única,
        // anterior a qualquer escritório (ajustes SÓ MASTER), e o suporte de quem ainda não entrou.
        \App\Auth\Entity\ConfirmacaoEmail::class,
        \App\Termo\Entity\CienciaPolitica::class,
        \App\Auth\Entity\AjusteEntrada::class,
        \App\Auth\Entity\MensagemSuporte::class,

        // Catálogo global de permissões. O que é por escritório é TenantRolePermission.
        Permission::class,

        // Tabela global de referência (índices oficiais do BCB). Sem dado de escritório.
        IndiceMonetario::class,

        // Medido em 12/08/2026: 13 linhas para 13 usuários distintos — o perfil é do USUÁRIO,
        // não do escritório. Quem atua em dois escritórios tem um perfil só.
        UserProfile::class,

        // Outbox da réplica do espelho (P2.3, docs/specs/p2-gravacao-dupla.md §14). Infraestrutura:
        // ESCRITA pelo decorador do armazenamento (na requisição do usuário, com o escopo da chave) e
        // LIDA só pelo worker e pelos comandos de operador — sempre por DBAL (que o TenantFilter nem
        // alcança), sem rota HTTP. O tenant de cada linha está em `escopo_tenant_id`; filtrar aqui
        // faria o worker, que roda sem escritório na sessão, não ver a fila.
        ReplicacaoDeArquivo::class,

        // Inscrições na OAB (spec docs/specs/meu-perfil-oab-inscricoes.md §2): credencial da PESSOA, como os
        // campos oab* do User de que a principal é o espelho; vale em todo escritório em que ela atua. Leitura
        // sempre por usuário (doUsuario/daPessoa); a fila global é só do Master.
        InscricaoOab::class,

        // Trabalho Externo (spec docs/specs/trabalho-externo.md §3): a Central do Master global lê
        // as demandas de TODOS os escritórios, por desenho. O TenantFilter as esconderia dela. O
        // isolamento é do DemandaExternaRepository (só consultas escopadas: Central ou escritório)
        // e da PoliticaDaDemanda, provado em TrabalhoExternoControllerTest (outro escritório é
        // 404). Os filhos (inclusive contatos/avisos do WhatsApp e convites) só são lidos a partir da
        // demanda; o convite também pelo token e pelo usuário que o aceitou. A configuração é única.
        DemandaExterna::class,
        HistoricoDemanda::class,
        MensagemDemanda::class,
        LeituraDemanda::class,
        PropostaDemanda::class,
        DocumentoDemanda::class,
        ContatoWhatsapp::class,
        AvisoWhatsapp::class,
        ConviteDemanda::class,
        ConfiguracaoCentral::class,
        // Marcações pessoais (spec docs/specs/trabalho-externo-vs-18-10-26.md §2): estrela, lixeira,
        // não lida e favorita são do USUÁRIO, como a LeituraDemanda; o Master marca demandas de
        // todos os escritórios. Toda leitura filtra pelo usuário logado e só alcança itens que o
        // LocalizadorDeDemanda já escopou, provado em MarcacoesDoTrabalhoExternoTest.
        MarcacaoDoUsuario::class,   // do usuário, não do escritório; MarcarDemandasEmLoteUseCase só marca o que o LocalizadorDeDemanda escopou (MarcacoesDoTrabalhoExternoTest)
        MarcacaoDeMensagem::class,  // do usuário; MarcarMensagensEmLoteUseCase só alcança mensagens visíveis pela PoliticaDaDemanda (MarcacoesDoTrabalhoExternoTest::testChatAvancado)
        // Vagas da Central (spec VS 18.10.26 §4.1): como a demanda, lidas entre escritórios por
        // desenho (a Central divulga para todos). O isolamento é do VagaRepository (só consultas
        // escopadas) e da PoliticaDaVaga, provado em VagasDoTrabalhoExternoTest (pendente de outro
        // escritório é 404). O histórico só é lido pela vaga.
        Vaga::class,          // vaga publicada pela Central para todos os escritórios; VagaRepository só consulta escopado e LocalizadorDeVaga confere a PoliticaDaVaga (VagasDoTrabalhoExternoTest::testFluxoDeDivulgacao)
        RecusaDeOferta::class, // só é lida pela vaga já localizada no escopo e pelo próprio usuário que recusou (RecusaEExpiracaoDaVagaTest: outro escritório é 404, interno é 403)
        DespachoDemanda::class, // só é lido pela demanda já localizada no escopo (DemandaExterna::getDespachos); toda rota passa pelo LocalizadorDeDemanda (DespachoDaDemandaTest, outro escritório é 404)
        HistoricoVaga::class, // só é lido pela vaga já localizada no escopo, e só pelo interno (ApresentadorDeVaga::detalhe, desenho l.1190)
        PreferenciaDoUsuario::class, // do usuário (última visita às vagas, ofertante padrão); toda leitura recebe o usuário logado (VagasDoTrabalhoExternoTest::testNovaEVista)
        EscritorioSolicitante::class, // do usuário (escritórios guardados na Nova solicitação, nunca Tenant); toda leitura recebe o usuário logado e id de outro é 404 (EscritorioSolicitanteTest::testAtualizaEDono)
        LinkDeVagas::class,   // link público, sem escritório por natureza; só se acha pelo hash do token e a página só mostra vagas abertas sem dado de escritório (VagasPublicasTest)
        InteressadoEmVagas::class, // visitante da página pública, sem conta nem escritório; só a inscrição grava e nenhuma rota lê (VagasPublicasTest::testInscricaoNosAlertas)
        MetricaPublica::class, // contador anônimo da página pública (dia, link, visitas, cliques), sem escritório nem pessoa; só o painel do Master lê, e só a soma (PainelDoTrabalhoExternoTest::testSoOMasterVeOPainel)
        Curriculo::class,     // currículo do usuário externo, da plataforma (um por usuário); a Central lê o banco inteiro e o externo só alcança o dele pelo LocalizadorDeCurriculo (CurriculosDoTrabalhoExternoTest::testExternoCriaEditaEExclui)
        Candidatura::class,   // candidatura de um currículo a uma vaga, ambos da plataforma; só é lida pela vaga já localizada no escopo ou pelo currículo do próprio usuário, e o dono da vaga só vê as enviadas a ele (CandidaturasDoTrabalhoExternoTest::testEnvioEDono)

        // Comercial e e-mails (docs/specs/comercial-e-emails.md §3): dado do Master, de escopo plataforma, que cruza
        // escritórios (envios, consentimentos, automações, ciclo da conta, varredura). O escritório, quando há, é uma
        // coluna nullable (null = plataforma) escrita à mão em cada consulta SQL; o filtro de tenant esconderia o que
        // o Master precisa ver e quebraria o envio pelo worker, que roda sem sessão.
        \App\Comercial\Entity\EnvioDeEmail::class,
        \App\Comercial\Entity\ConteudoDoEnvio::class,
        \App\Comercial\Entity\FatoDeConsentimento::class,
        \App\Comercial\Entity\ConfirmacaoDeEndereco::class,
        \App\Comercial\Entity\AutomacaoDeEmail::class,
        \App\Comercial\Entity\CicloDaConta::class,
        \App\Comercial\Entity\VarreduraComercial::class,
        // Fatia 1b (§4): a vez dos avisos de espaço é lida e gravada só por SQL no ouvinte, que roda sem sessão (o
        // escritório vem do evento e vai na consulta à mão); nunca é listada para ninguém.
        \App\Comercial\Entity\AvisoDeEspaco::class,
        // Fatia 2a (§8.2): os modelos próprios de campanha são do Master, de escopo plataforma, sem escritório.
        \App\Comercial\Entity\ModeloDeCampanha::class,
        // Fatia 2b (§8.4 e §8.5): as campanhas, os destinatários de cada execução e o controle do expansor são do
        // Master, de escopo plataforma (a tabela não tem `tenant_id`), gravados pelo worker sem sessão.
        \App\Comercial\Entity\CampanhaDeEmail::class,
        \App\Comercial\Entity\DestinoDaCampanha::class,
        \App\Comercial\Entity\ControleDoExpansor::class,
        // F3e (comercial-marcas.md): as marcas do Relatório são de cada Master sobre as campanhas da plataforma, sem
        // escritório (a tabela não tem `tenant_id`).
        \App\Comercial\Entity\CampanhaMarca::class,
        // F3i (§12): as imagens das campanhas do Master são da plataforma, sem escritório; a rota pública /e/i/ as
        // entrega sem sessão, pelo token.
        \App\Comercial\Entity\ImagemDaCampanha::class,
        // F4 (comercial-e-emails.md §13): o estado das 17 datas comemorativas é configuração da PLATAFORMA, do Master,
        // sem escritório (a tabela não tem `tenant_id`), lido pelo passo `datas` da varredura, que roda sem sessão.
        \App\Comercial\Entity\DataComemorativa::class,
        // Marca da varredura do push da Agenda (entrega 5b): uma linha só, global, que garante uma cadeia
        // única de envio para todos os escritórios. Não guarda dado de escritório nenhum.
        \App\Agenda\Entity\VarreduraDePush::class,
        // Marca da cadeia de sincronia do Google Agenda (entrega 6): mesma natureza, uma linha global.
        \App\Agenda\Entity\VarreduraDoGoogle::class,
        // Telas e recursos (spec docs/specs/perfis-1.2-telas-e-recursos.md, R2): configuração da
        // PLATAFORMA, ligada e desligada só pelo Master e válida para todas as contas ao mesmo tempo.
        // Uma linha por chave do catálogo, sem dado de escritório nem de cliente.
        RecursoEstado::class,
        // Seccionais da OAB no mapa do Painel do Master (docs/specs/master-seccionais-oab.md): as 27 sedes e as
        // divulgações registradas pelo Master são dado da PLATAFORMA (trabalho comercial do BlueJus), sem escritório
        // nem cliente; lidas e gravadas só atrás de ROLE_SUPER_ADMIN.
        \App\Master\Entity\SeccionalOab::class,
        \App\Master\Entity\DivulgacaoNaSeccional::class,
        // Desempenho do Master (docs/specs/master-desempenho.md): telemetria, incidentes e registros de backup da
        // PLATAFORMA, sem `tenant_id`. O evento guarda no máximo o usuário (SET NULL), nunca dado de escritório;
        // lidos e gravados só pelo Master (ROLE_SUPER_ADMIN) e pelo endpoint do monitor, que é do usuário logado.
        \App\Master\Entity\EventoDeDesempenho::class,
        \App\Master\Entity\IncidenteDeDesempenho::class,
        \App\Master\Entity\RegistroDeBackup::class,
        // Custos do Master (docs/specs/master-custos.md): lançamento de custo da PLATAFORMA, sem `tenant_id`; só o Master
        // (ROLE_SUPER_ADMIN) lê e grava.
        \App\Master\Entity\LancamentoDeCusto::class,
        // Janela da OAB dos estados que faltam (docs/specs/master-oab-estados-que-faltam.md): as subseções e a nota
        // explicativa de cada seccional são anotação de trabalho da PLATAFORMA, compartilhada entre os Masters, sem
        // escritório nem cliente; lidas e gravadas só atrás de ROLE_SUPER_ADMIN.
        \App\Master\Entity\SubsecaoOab::class,
        \App\Master\Entity\NotaDaSeccional::class,
        // Nota por cidade da janela da OAB (desenho 05 inteiro, 21/10/2026): mesmo modelo da nota da seccional.
        \App\Master\Entity\NotaDaCidadeOab::class,
        // Temas comemorativos (docs/specs/temas-comemorativos.md): linha única de configuração da PLATAFORMA (id 1),
        // escrita só pelo Master; a cor vale para todas as contas ao mesmo tempo. Sem dado de escritório nem de cliente.
        \App\Master\Entity\TemaConfig::class,
        // Configurações globais do Master (Perfis 1.2 VS 25.10.26, P2): linha única (id 1) da PLATAFORMA, escrita só pelo
        // Master, como o TemaConfig. Sem dado de escritório nem de cliente.
        \App\Master\Entity\ConfiguracaoGlobal::class,
        // BlueJus IA no Painel do Master (frente ia-anthropic-ligar, PR B, 24/10/2026): linha única de configuração da
        // PLATAFORMA (id 1) com a chave geral, o modelo e os meses de retenção, escrita só pelo Master. Sem dado de
        // escritório nem de cliente; o que é de cada escritório fica em ConfiguracaoDeInteligencia (TenantAware).
        \App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa::class,
        // Convênio OAB por estado do Master (P4b, 26/10/2026): uma linha por UF não gratuita, configuração da PLATAFORMA
        // escrita só pelo Master. Sem dado de escritório nem de cliente.
        \App\Master\Entity\ConvenioDaUf::class,
        // Metas de divulgação do Painel do Master (desenho 05 VS 25.10.26, P3): linha única de configuração da PLATAFORMA
        // (id 1), escrita só pelo Master. Sem dado de escritório nem de cliente.
        \App\Master\Entity\MetaDeDivulgacao::class,
        // Recompensas do Painel do Master (F1, 26/10/2026): a regra geral do programa Indique e ganhe (linha única, id 1) e
        // a condição individual de cada indicador. Configuração da PLATAFORMA, escrita só pelo Master; a chave da condição
        // é o usuário que indica, sem escritório. Sem dado de cliente.
        \App\Recompensa\Entity\ProgramaDeRecompensa::class,
        \App\Recompensa\Entity\CondicaoDeRecompensa::class,
        // Extrato de recompensas (F2, 26/10/2026): livro só de INSERT com os créditos e estornos manuais por indicador.
        // Dado da PLATAFORMA, lido e escrito só pelo Master; o indicador é um usuário, sem escritório. Sem dado de cliente.
        \App\Recompensa\Entity\LancamentoDeRecompensa::class,
        // Questões jurídicas da BlueJus IA no Painel do Master (24/10/2026): o status, o histórico de status e as versões
        // ajustadas dos documentos (Política de Privacidade, Termos de Uso, aceite da IA) são da PLATAFORMA, lidos e
        // gravados só pelo Master. Sem dado de escritório nem de cliente; o arquivo da versão tem escopo global.
        \App\Inteligencia\Entity\EstadoDaQuestaoJuridica::class,
        \App\Inteligencia\Entity\MudancaDeStatusDaQuestaoJuridica::class,
        \App\Inteligencia\Entity\VersaoDeDocumentoJuridico::class,

        // Indicar o BlueJus (docs/specs/indicar-bluejus.md): plataforma. A indicação é da pessoa (quem indica), não do
        // escritório: ela indica com ou sem escritório, e o Master lê todas. O escritório de origem é só para mostrar
        // (SET NULL na purga). Toda consulta do lado do usuário filtra pelo id de quem indica.
        \App\Indicacao\Entity\Indicacao::class,
        \App\Indicacao\Entity\IndicacaoCodigo::class,
        \App\Indicacao\Entity\IndicacaoConfig::class,
        \App\Indicacao\Entity\IndicacaoPausa::class,
        \App\Indicacao\Entity\IndicacaoAlerta::class,

        // Central de Notificações do Master (docs/specs/master-central-de-notificacoes.md, D1 a D3): o Master lê as
        // mensagens de TODOS os escritórios. O estado (lida, estrela, arquivada) é do Master, e toda consulta filtra por
        // `master_id = eu`; as respostas são o histórico compartilhado entre os Masters, lido só pela mensagem. O
        // `tenant_id` (nulo para suporte e alerta) é só para a purga do escritório (ORDEM_DELECAO).
        \App\Master\Entity\CentralMasterEstado::class,
        \App\Master\Entity\CentralMasterResposta::class,

        // Falha de tarefa agendada (spec scheduler-tarefas-agendadas, "Aviso de falha"): dado do SISTEMA, sem
        // escritório. A tarefa roda para todos (purga, DJEN, envio ao Drive) e quem vê o aviso é o Master ou o
        // e-mail de AGENDADOR_ALERTA_EMAIL. A linha só guarda comando, hora, classe do erro e código de saída.
        \App\Shared\Scheduler\Entity\FalhaAgendada::class,
    ];

    /**
     * Teto de cada lista, cobrado por testeTetosNaoSobem. Baixe o número ao fechar cada fatia.
     *
     * Sem isso, "só encolhe" seria promessa de comentário: o conserto mais barato para uma
     * entidade nova sem etiqueta é apendá-la em PENDENTE — uma linha, suíte verde, furo novo
     * entrando com a rede ligada. Com o teto, essa saída exige mexer no teto no mesmo diff, e
     * aí é decisão visível em vez de descuido.
     */
    private const MAX_PENDENTE = 13;
    // 8 → 18 com o Trabalho Externo (15/10/2026): exceção permanente e justificada acima.
    // 18 → 21 com o Contribua (19/10/2026): catálogo de planos e faixas de espaço são globais por
    // natureza; o espaço do escritório é lido por tenantId explícito (motivo na lista acima).
    // (A C3 do Contribua soma 2 no fim: o aviso de gateway, que chega sem escritório, e a configuração do pagamento.)
    // 21 → 22 com RecursoEstado (19/10/2026, Perfis 1.2 · Telas e recursos): configuração global da plataforma.
    // 22 → 26 com a tela de entrada do desenho 10 (19/10/2026): ConfirmacaoEmail, CienciaPolitica, AjusteEntrada e
    // MensagemSuporte são anteriores a qualquer escritório (identidade do User e a tela de entrada única); justificado acima.
    // 26 → 27 com a marca global da varredura do push da Agenda (19/10/2026), justificada acima.
    // 27 → 34 com o Comercial e e-mails (19/10/2026): dado do Master, de escopo plataforma (envios, consentimentos,
    // automações, ciclo da conta e varredura), justificado acima.
    // 34 → 35 com a fatia 2a do Comercial (20/10/2026): `comercial_modelo`, modelos de campanha do Master (plataforma).
    // 35 → 40 com o Indicar o BlueJus (19/10/2026): dado de plataforma, da pessoa e do Master, justificado acima.
    // 40 → 43 com a fatia 2b do Comercial (20/10/2026): campanha, destinatário da campanha e controle do expansor (plataforma).
    // 43 → 45 com as marcações pessoais do Trabalho Externo (19/10/2026): MarcacaoDoUsuario e
    // MarcacaoDeMensagem são do usuário, justificadas acima. 45 → 46 com a marca global da sincronia do
    // Google Agenda (19/10/2026), justificada acima.
    // 46 → 47 com as inscrições na OAB do Meu Perfil (19/10/2026): InscricaoOab é da pessoa, não do escritório.
    // 47 → 50 com as vagas e as preferências do usuário do Trabalho Externo (19/10/2026): Vaga, HistoricoVaga e PreferenciaDoUsuario, justificadas acima.
    // 50 → 52 com a página pública de vagas do Trabalho Externo (19/10/2026): LinkDeVagas e InteressadoEmVagas, justificados acima.
    // 52 → 53 com o currículo do banco de talentos do Trabalho Externo (19/10/2026): Curriculo, justificado acima.
    // 53 → 54 com a candidatura às vagas do Trabalho Externo (19/10/2026): Candidatura, justificada acima.
    // 54 → 55 com a fatia 1b do Comercial (21/10/2026): a vez dos avisos de espaço, justificada acima.
    // 55 → 57 com as Seccionais da OAB do Painel do Master (21/10/2026): SeccionalOab e DivulgacaoNaSeccional (plataforma).
    // 57 → 58 com os temas comemorativos (21/10/2026): TemaConfig, configuração global da plataforma, justificada acima.
    // 58 → 60 com a Central de Notificações do Master (21/10/2026): CentralMasterEstado e CentralMasterResposta são do
    // Master, que lê todos os escritórios, justificadas acima.
    // 60 → 62 com a janela da OAB dos estados que faltam (21/10/2026): SubsecaoOab e NotaDaSeccional (plataforma),
    // justificadas acima.
    // 62 → 63 com as marcas do Relatório da Central de envios (21/10/2026): CampanhaMarca, do Master, justificada acima.
    // 63 → 64 com a métrica anônima da página pública do Trabalho Externo (19/10/2026): MetricaPublica, justificada acima.
    // 64 → 65 com a nota por cidade da janela da OAB (21/10/2026): NotaDaCidadeOab (plataforma), justificada acima.
    // 65 → 66 com as imagens das campanhas do Master (21/10/2026, F3i): ImagemDaCampanha (plataforma), justificada acima.
    // 66 → 67 com as datas comemorativas do Comercial (21/10/2026, F4): DataComemorativa (plataforma), justificada acima.
    // 67 → 68 com as falhas das tarefas agendadas (23/10/2026, spec scheduler-tarefas-agendadas): FalhaAgendada é dado
    // do sistema, sem escritório, justificada acima.
    // 68 → 69 com a BlueJus IA no Painel do Master (24/10/2026): ConfiguracaoDaPlataformaDeIa, configuração global da
    // plataforma, justificada acima.
    // 69 → 70 com a outbox da réplica do espelho no R2 (P2.3, spec p2-gravacao-dupla): ReplicacaoDeArquivo é
    // infraestrutura lida só pelo worker, por DBAL, com o escritório na própria linha; justificada acima.
    // 70 → 73 com as questões jurídicas do Master (24/10/2026): EstadoDaQuestaoJuridica, MudancaDeStatusDaQuestaoJuridica
    // e VersaoDeDocumentoJuridico (plataforma), justificadas acima.
    // 73 → 74 com o despacho da Central no Trabalho Externo (24/10/2026): DespachoDemanda, justificado acima.
    // 74 → 75 com a recusa de oferta do Trabalho Externo (24/10/2026): RecusaDeOferta, justificada acima.
    // 75 → 78 com o Desempenho do Master (26/10/2026): EventoDeDesempenho, IncidenteDeDesempenho e RegistroDeBackup
    // (plataforma), justificadas acima.
    // 78 → 79 com as metas de divulgação do Master (26/10/2026): MetaDeDivulgacao, configuração global da plataforma.
    // 79 → 80 com o convênio OAB por estado do Master (26/10/2026): ConvenioDaUf, configuração global da plataforma.
    // 80 → 82 com as recompensas do Master (26/10/2026): ProgramaDeRecompensa e CondicaoDeRecompensa, justificadas acima.
    // 82 → 83 com as configurações globais do Master (26/10/2026): ConfiguracaoGlobal (plataforma), justificada acima.
    // 82 → 83 com as configurações globais do Master (26/10/2026): ConfiguracaoGlobal (plataforma), justificada acima.
    // 83 → 84 com o bônus de espaço por indicação (26/10/2026): BonusDeEspaco, justificado acima (mesmo motivo do
    // EspacoDoEscritorio: o Master lê o bônus de outro escritório por tenantId explícito, em lote).
    // 84 → 85 com o extrato de recompensas do Master (26/10/2026): LancamentoDeRecompensa, justificada acima.
    // 85 → 86 com o preço do atendimento do Service Desk (27/10/2026): PrecoDoAtendimento, justificado acima (catálogo
    // global que só o Master define, igual ao Plano do Contribua).
    // 86 → 87 com os custos do Master (27/10/2026): LancamentoDeCusto (plataforma), justificado acima.
    // 87 → 88 com os escritórios guardados da Nova solicitação (27/10/2026): EscritorioSolicitante é do usuário, justificado acima.
    // 88 → 90 com a C3 do Contribua: o aviso de gateway (sem escritório) e a configuração do pagamento por cartão (plataforma).
    private const MAX_FORA_DO_ESCOPO = 90;

    /**
     * Furos conhecidos, cada um endereçado a uma fatia da spec. Esta lista SÓ ENCOLHE — cobrado
     * por testeTetosNaoSobem, não prometido em comentário.
     *
     * Ela existe para a suíte permanecer verde enquanto a correção anda domínio por domínio —
     * uma suíte vermelha por semanas é ignorada, e aí a rede não protege nada. O que importa
     * está preservado: entidade nova que não esteja em NENHUMA das duas listas quebra o teste.
     */
    private const PENDENTE_DE_CORRECAO = [
        // Fatia 3 — ServiceDesk. Sem coluna; backfill pelo chamado dono.
        ChamadoInteracao::class,
        ChamadoAnexo::class,

        // Fatia 4 — Tenant. Já têm tenant_id NOT NULL; ligar o filtro muda toda query
        // existente, inclusive as telas de administração (ver §5.2 da spec).
        Sede::class,
        Cargo::class,
        Lotacao::class,

        // Fatia 5 — Permission (risco MÉDIO).
        TenantRole::class,
        TenantRolePermission::class,

        // Fatia 6 — Ponto eletrônico (risco ALTO, re-revisão obrigatória).
        BlocoJornada::class,
        BlocoJornadaColaborador::class,

        // Fatia 7 — depende de decisão do dono sobre os nulos (§5.1 da spec):
        // audit_log tem 19.390 de 29.791 linhas com tenant_id nulo, aceite_termo 7 de 10.
        // Pôr a etiqueta sem resolver isso sumiria com 2/3 da trilha de auditoria.
        AuditLog::class,
        AceiteTermo::class,

        // Fatia 8 — Pasta e Auth.
        // PastaProcesso: associação Pasta↔Processo, hoje isolada só pela Pasta dona.
        // Invitation: tem tenant_id nullable; convite vazado expõe e-mail de outro escritório.
        PastaProcesso::class,
        Invitation::class,
    ];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Toda entidade ORM implementa TenantAware ou está classificada explicitamente')]
    public function testTodaEntidadeEstaClassificada(): void
    {
        $naoClassificadas = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $class = $meta->getName();

            if (is_a($class, TenantAware::class, true)) {
                continue;
            }

            if (in_array($class, self::FORA_DO_ESCOPO, true)
                || in_array($class, self::PENDENTE_DE_CORRECAO, true)) {
                continue;
            }

            $naoClassificadas[] = $class;
        }

        self::assertSame([], $naoClassificadas, sprintf(
            "Entidade sem isolamento de tenant e sem classificação: %s.\n"
            . 'Ou implemente TenantAware (com ManyToOne(Tenant) JoinColumn nullable: false), '
            . 'ou declare em FORA_DO_ESCOPO com o motivo, ou em PENDENTE_DE_CORRECAO apontando a fatia.',
            implode(', ', $naoClassificadas)
        ));
    }

    #[TestDox('PENDENTE_DE_CORRECAO não guarda entrada obsoleta')]
    public function testPendenciaNaoTemEntradaObsoleta(): void
    {
        $obsoletas = $this->entradasObsoletas(self::PENDENTE_DE_CORRECAO);

        self::assertSame([], $obsoletas, sprintf(
            'Saia de PENDENTE_DE_CORRECAO (já implementa TenantAware, ou não é mais entidade '
            . 'mapeada): %s. E baixe MAX_PENDENTE no mesmo diff.',
            implode(', ', $obsoletas)
        ));
    }

    #[TestDox('FORA_DO_ESCOPO não guarda entrada obsoleta')]
    public function testForaDoEscopoNaoTemEntradaObsoleta(): void
    {
        $obsoletas = $this->entradasObsoletas(self::FORA_DO_ESCOPO);

        self::assertSame([], $obsoletas, sprintf(
            'Saia de FORA_DO_ESCOPO (já implementa TenantAware, ou não é mais entidade '
            . 'mapeada): %s.',
            implode(', ', $obsoletas)
        ));
    }

    #[TestDox('Os tetos das listas não sobem: a dívida só encolhe')]
    public function testTetosNaoSobem(): void
    {
        self::assertLessThanOrEqual(
            self::MAX_PENDENTE,
            count(self::PENDENTE_DE_CORRECAO),
            'PENDENTE_DE_CORRECAO cresceu. Furo novo não se resolve entrando na lista de '
            . 'pendências: implemente TenantAware. Se a entrada for mesmo inevitável, subir '
            . 'MAX_PENDENTE tem de ser decisão explícita, revisada neste diff.'
        );

        self::assertLessThanOrEqual(
            self::MAX_FORA_DO_ESCOPO,
            count(self::FORA_DO_ESCOPO),
            'FORA_DO_ESCOPO cresceu. Exceção permanente ao isolamento de tenant exige '
            . 'justificativa escrita ao lado da entrada e revisão — nunca é conserto de suíte '
            . 'vermelha.'
        );
    }

    /**
     * Entradas de uma lista que perderam o motivo de existir: ou a entidade já foi corrigida
     * (implementa TenantAware), ou saiu do mapeamento. Cada lista é conferida separadamente e
     * por um teste próprio — de propósito.
     *
     * Antes as duas eram varridas por uma asserção só, e isso tornava a asserção da PENDENTE
     * logicamente subsumida: não existia mutação capaz de derrubá-la sozinha. Pareciam duas
     * propriedades independentes e eram uma.
     *
     * @param list<class-string> $lista
     *
     * @return list<class-string>
     */
    private function entradasObsoletas(array $lista): array
    {
        $mapeadas = array_map(
            static fn ($meta): string => $meta->getName(),
            $this->em->getMetadataFactory()->getAllMetadata()
        );

        return array_values(array_filter(
            $lista,
            static fn (string $class): bool => !in_array($class, $mapeadas, true)
                || is_a($class, TenantAware::class, true)
        ));
    }

    #[TestDox('Uma entidade não pode estar nas duas listas ao mesmo tempo')]
    public function testListasNaoSeSobrepoem(): void
    {
        $intersecao = array_values(array_intersect(self::FORA_DO_ESCOPO, self::PENDENTE_DE_CORRECAO));

        self::assertSame([], $intersecao, sprintf(
            'Classificação ambígua — está em FORA_DO_ESCOPO e em PENDENTE_DE_CORRECAO: %s.',
            implode(', ', $intersecao)
        ));
    }
}
