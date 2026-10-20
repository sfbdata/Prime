# Changelog

Todas as mudanças relevantes do BlueJus ficam registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota o
[Versionamento Semântico](https://semver.org/lang/pt-BR/). A política de versões, os critérios de
cada nível e o procedimento de release estão em [docs/versionamento.md](docs/versionamento.md).

## [Não lançado]

## [2.6.0] - 2026-10-20

### Adicionado

- Agenda: botão direito nas datas do sistema (feriados, recesso, tribunais, comemorativas, aniversários) com Editar nome, Ocultar da agenda, Acrescentar feriado ou data e Restaurar datas do sistema; vale para o escritório e exige a permissão de Feriados e Folgas. Não altera a tabela de feriados do Ponto nem o cálculo da jornada.
- Banco: tabela `agenda_data_ajuste`.
- Agenda: lembretes com som em qualquer tela (cartão no canto com "Ver na agenda" ou "Abrir pasta" e "Lembrar em 10 min", e notificação do navegador quando permitida), para os compromissos da pessoa e os prazos e audiências de hoje com hora; endpoint `GET /agenda/lembretes`; "Testar lembretes" na lateral da Agenda.
- Busca rápida (Ctrl + K) em toda página logada, não só na pasta: a janela, o CSS e o JS entram pelo parcial global `layout/_globais/busca_rapida.html.twig` (para quem tem o módulo Pastas e um escritório atual) e saíram do `pasta/show.html.twig`. Abrir leva para a pasta; pasta trancada continua com o aviso de sigilo e o "Pedir acesso".
- Única exceção: com o foco na descrição ou no comentário do cartão do Kanban (Quill com botão de link, que usa Ctrl + K para inserir link), o atalho fica com o editor.
- Busca rápida na página da pasta (Ctrl + K): janela do desenho 02 Expedientes que acha pasta pelo número (ou "Pasta <número>"), cliente pelo nome ou CPF/CNPJ (com ou sem pontuação) e processo pelo número (todos os vinculados), sem acento e sem caixa. Até 8 resultados, só das pastas do alcance da pessoa. Pasta trancada pelo cadeado para quem busca aparece com o cadeado e só com número, cliente e CPF/CNPJ: casa só por eles (nunca pelo processo nem pela ação) e abre na tela "Pasta trancada", com o pedido de acesso de lá.
- Rota nova `GET /expediente/busca-rapida?q=` (`expediente_busca_rapida`), JSON, exige o módulo Pastas.
- Cabeçalho: no painel "quem está on-line", o usuário primário escolhe depois de quanto tempo sem atividade a equipe aparece ausente (15 min, 30 min, 1 h ou 2 h), como no desenho 03. Sem escolha, continua 1 hora.

### Corrigido

- Dashboard da equipe com cadeado de pasta (spec pasta-cadeado, D1 e D3): as metas de pasta Oculta para quem olha saem do card "Metas ativas", da "Meta global batida" e das colunas de metas da tabela (total, ativas, vencidas, prazo próximo, período anterior, "Em revisão" e "Tempo médio"), como já saíam da lista /tarefas/equipe a que os números levam. Os ids das pastas ocultas vêm de AcessoAPasta::idsOcultosPara, numa consulta por requisição. O primário e quem tem nível na pasta continuam contando tudo.
- Dashboard da equipe com cadeado de pasta (D1): as pastas Ocultas para quem olha também saem do card "Demandas urgentes", das colunas "Total demandas" e "Demandas ativas" (e do período anterior), do extra "Pastas urgentes" e do histórico do card "Pastas criadas", como já saíam das listas do Expediente a que os números levam. A foto diária (comando de fotografia) continua contando o escritório inteiro.
- Quem tem ao menos uma pasta Oculta não recebe as setas de Vencidas e Prazo próximo (nem as pílulas do Total) que comparam com a foto diária do escritório inteiro: a diferença revelaria a meta oculta. Quem não tem pasta oculta segue vendo as setas como antes.
- Tarefa::getPrazoInfo conta os dias pela RegraDoPrazo (dias de calendário, sem a hora gravada no prazo): um prazo de ontem às 23:59 não aparece mais como "Vence hoje" na tela da meta enquanto a Pasta mostra "Vencido há 1 dia". O formato do retorno (dias, cor, texto) não muda.
- Login: depois de entrar, o sistema não leva mais a uma página de dados (como o contador do sino); com a sessão vencida, os pedidos automáticos da tela recebem aviso de sessão terminada em vez de virar o destino do login.

## [2.5.0] - 2026-10-20

### Adicionado

- Agenda nova (`/agenda/nova`): modal de criar e editar compromisso do desenho 09, com tipo (Compromisso, Audiência, Reunião, Prazo), dia inteiro, "Equipe do escritório" ou "Só eu", pasta pelo número, lembrete, repetição, participantes e "Avisar no Chat I.A" com @colega; pergunta "Compartilhar com a equipe?" ao criar em Minha agenda; "Avisar no Chat" e "Excluir" no detalhe.
- Agenda: endpoints `POST /agenda/compromissos/novo`, `/{id}/salvar`, `/{id}/excluir` e `/{id}/avisar`.
- Agenda: a entidade Evento passa ao domínio `App\Agenda\Entity`; o histórico da auditoria acompanha a troca de nome da classe.
- Banco: colunas `tipo`, `pasta_id`, `lembrete_minutos` e `avisados` na tabela `evento`.
- Tela de entrada refeita pelo desenho 10: painel "Segurança e privacidade", selo de criptografia, frase do dia, aviso de Caps Lock e "Manter conectado".
- Recuperação de senha por código de 6 números enviado por e-mail, válido por 15 minutos, com 5 tentativas; trocar a senha encerra as outras sessões.
- "Criar minha conta e escritório" na própria tela: a conta nasce já logada, sem OAB; o e-mail de confirmação segue em paralelo e a conta fica com o e-mail "a confirmar" até o clique.
- O cadastro grava o aceite dos Termos de Uso e a ciência da Política de Privacidade, cada um com versão e data.
- "Falar com suporte" na tela de entrada: a mensagem fica registrada e avisa no sino quem o Master escolheu.
- Ferramentas só do Master na tela de entrada: editar, ocultar e restaurar os links do rodapé e escolher quem recebe o suporte.
- Depois do login, a pessoa volta para a página que tentou abrir (endereços de fora do sistema são recusados).
- Cadeado completo da pasta (spec docs/specs/pasta-cadeado.md): o Master e o primário escolhem, pelo ⋮ da pasta ("Acesso · Primário"), ocultar a pasta para toda a equipe ou dar Ocultar, Visualizar ou Editar por pessoa. Substitui a tranca binária da Lista (`pasta.trancada_em/por`), migrada para o cadeado "Ocultar para todos" e removida.
- Teto do cadeado no `PermissionChecker::canAccessResource` para `pasta` (depois dos bypasses e antes do `ResourceAccess`): um pedido de acesso genérico aprovado não fura o cadeado. Barreira de rota (`BarreiraDoCadeadoListener`) com o resolvedor de pasta compartilhado (`PastaDaRota`) e teste de arquitetura das rotas de pasta.
- Tela "Pasta trancada" para quem está oculto, faixa de somente visualização com "Pedir para editar" e pedido de acesso com estados (pendente, liberado para visualizar ou editar, recusado), janela de 24 horas e avisos no sino para os primários e para quem pediu.
- Fora da pasta: a Lista, a busca, o CSV, a tela do processo e a busca do agente de IA mostram da pasta oculta só o número e o cliente; o Push global e as Minhas Metas deixam de mostrar o que é dela; a auditoria do escritório esconde o conteúdo das linhas dela; a imagem embutida em peça dela é recusada; pasta com cadeado sai do sync do Drive.
- Modal Marcadores da pasta: a busca ganha a linha "Criar marcador" quando o texto não bate com nenhum marcador; cria pela rota `expediente_marcador_criar` (módulo Expediente e CSRF), e o novo marcador já entra marcado. A busca passa a ignorar acento.
- Aba Detalhes da pasta: card "Pontos do relatório inicial" (objetivos do cliente, acordos iniciais, resultado esperado), com selo e botão "Completar no relatório", calculado por `PontosDoRelatorioInicial` sobre todas as observações do Relatório inicial de Atendimento.
- O card se atualiza sozinho quando a pessoa envia, responde, edita ou exclui uma observação (rota GET `pasta_detalhes_pontos_relatorio`, mesma permissão de ver a pasta), e acende ou apaga a pendência da aba Detalhes.
- Linha vermelha da aba Detalhes quando falta algum ponto do relatório inicial (`PastaPendenciasOutput`); a mesma pendência entra no resumo impresso da pasta. Decisão do dono (T2, 20/10): a linha vermelha fica como no desenho, mesmo acendendo em cerca de 83% das pastas ativas.
- Sai da aba Detalhes o card "Como usar esta aba", que o desenho não tem.
- Cabeçalho: atalho de bater ponto na sua linha do painel de presença (desenho 03 VS 18.10.26): "Bater ponto", "Em intervalo desde", "Em jornada desde" e "Jornada X às Y". Usa a batida de sempre (`POST /ponto/batida`), com a mesma cerca permissiva, prazos e avisos da tela do ponto; não aparece na própria tela do ponto.
- Atalho de ponto: "Regularizar ponto de ontem" quando ontem ficou com entrada e sem saída num dia em que a folha oferece o "+" (leva ao pedido de esquecimento já preenchido); a confirmação da saída avisa o descanso mínimo do escritório antes da próxima entrada, quando a validação de interjornada está ligada.
- Ponto: rota de leitura `GET /ponto/estado-do-dia` com o estado do ponto de hoje da própria pessoa (a escolha da folha, tenant explícito).
- Ponto: a lógica pura da batida (posição com prazo, cerca, atraso, envio e leitura da resposta) saiu do script da tela para `public/js/ponto-batida.js`, sem mudar o comportamento; a tela avisa se o módulo não carregar.
- Aba Push da pasta: cartão "Avisar cliente" na coluna direita (desenho de 18/10), com os e-mails do cliente principal da pasta (lista por cliente, vale em todas as pastas dele): até 5, um principal (estrela), rótulos Pessoal, Trabalho, Familiar e Outro, switch "Recebendo avisos" / "Pausado" e remover com confirmação. O e-mail da ficha do cliente aparece como "Cadastro · principal" até a primeira alteração. E-mail descadastrado pelo cliente (assunto push_cliente) aparece com o selo "Descadastrado pelo cliente" e o switch travado. Histórico de envios da pasta (6 mais recentes). Rotas em /pasta/{id}/push/cliente (exigem editar a pasta e CSRF).
- Cartão da Análise IA: "Enviar ao cliente" / "Reenviar ao cliente" e "Enviado dd/mm às HH:MM", só na análise concluída e não interna. Modal "Enviar ao cliente" com Para, Cópia (CC) da Controladoria travada, Assunto, Mensagem padrão editável e o checkbox obrigatório "Revisei e corrigi o conteúdo". O envio sai um e-mail por destinatário (cópia da Controladoria só no primeiro), Responder para o responsável da pasta, remetente com o nome do escritório e o link "Não quero mais receber estes avisos" (List-Unsubscribe). Sem Controladoria cadastrada, envia e avisa o primário no sino. Limites: assunto até 200 caracteres, mensagem até 20.000, no máximo 10 envios por pasta por hora, e a mesma análise ao mesmo e-mail em 2 minutos não sai de novo. Exige editar a pasta e a BlueJus IA.
- Controladoria e envio isolados atrás de ControladoriaDoEscritorioInterface (provisória: ninguém, até o #75) e EnvioDeEmailAoClienteInterface (provisória: Symfony Mailer síncrono, espelhando a MensagemDeEmail do Carteiro da frente Comercial e e-mails).
- Migration: tabelas cliente_email e envio_ao_cliente (incluídas na purga do escritório).
- Aba Push da pasta, cartão "Avisar cliente": bloco "Envio automático" (desenho de 18/10) com as chaves "Novas movimentações", "Audiências e intimações" e "Resumo semanal", por cliente (valem em todas as pastas de que ele é o cliente principal) e desligadas por padrão, e a linha fixa "Análise da BlueJus IA" com o selo "Só manual". Sem e-mail cadastrado, as chaves ficam esmaecidas e o clique abre o cadastro. Rota POST /pasta/{id}/push/cliente/automatico (editar a pasta e CSRF).
- Ao fim de cada captação do DJEN, o cliente com "Novas movimentações" ligada recebe por e-mail as movimentações novas da pasta (tipo, data, órgão e o texto oficial até 4.000 caracteres); com "Audiências e intimações", só as intimações e as designações de audiência. Um e-mail por e-mail ativo do cliente (pausado e descadastrado ficam de fora), cópia da Controladoria na primeira mensagem, Responder para o responsável da pasta, remetente com o nome do escritório e o "Não quero mais receber estes avisos". Segredo de justiça não leva conteúdo. A mesma movimentação não sai duas vezes para o mesmo cliente. Sem Controladoria, envia e avisa o primário uma vez por execução.
- Comando app:push:resumo-semanal-cliente (--tenant, --dry-run): toda segunda-feira, o resumo das movimentações da semana anterior de cada pasta cujo cliente ligou a chave, uma vez por semana. Linha de cron no DEPLOY.md.
- Histórico de envios da pasta: "Envio automático: intimação de dd/mm", "…: movimentação de dd/mm", "…: resumo semanal de dd/mm a dd/mm".
- Migration: tabela cliente_envio_automatico (auditada; incluída na purga do escritório).

### Alterado

- Agenda: `/agenda` passa a abrir a tela nova (desenho 09); o calendário antigo (FullCalendar) sai. Lista, detalhe, novo e editar antigos continuam no ar.
- Agenda: arrastar e soltar compromissos na grade (Mês, Semana, Dia) e botões Concluir, Cancelar compromisso (avisa os participantes) e Reabrir no detalhe.
- Agenda: o editor de legendas de cor sai, substituído pelos calendários com cor fixa do desenho; a cor dos eventos antigos é preservada.
- Ponto: quem está com o ponto aberto hoje em outro escritório não bate a entrada neste; a resposta diz em qual escritório registrar a saída antes. Só valem os escritórios com vínculo ativo e módulo de ponto; a virada do dia solta a trava, e o pedido de esquecimento pendente não solta (decisão do dono, 19/10).
- Ponto: a interjornada e o repouso mínimo passam a ser contados com o escritório explícito na consulta, sem depender do filtro de escritório.

## [2.4.0] - 2026-10-20

### Adicionado

- Carteira (desenho 1B): item "Exportar lista (.xlsx)" no menu "⋮" do cabeçalho (rota GET `cobranca_carteira_exportar`). A planilha traz a lista de cobranças da tela inteira, sem paginação, com a busca, o filtro de Estado e a ordenação atuais; colunas da tela (identificação e descrição do objeto, pessoa cobrada, estado, pronto para encerrar, saldo, vencido, tem documentos), valores em número e nome de arquivo `carteira-{id}-{data}.xlsx`. Teto de 5.000 linhas: acima disso, volta para a carteira com aviso para recortar a lista. Mesma guarda da tela da carteira; o "⋮" passa a aparecer também para quem só lê a carteira, só com o exportar.
- Objeto (desenho 1A): a área vazia da aba Documentos mostra os chips de exemplo do desenho (Boletos, Notificações, Acordos assinados, Comprovantes).
- Contribua C2: página "Quem somos e contribua" (/contribua) com espaço e planos, apoio voluntário por PIX (QR e copia e cola gerados no servidor), pergunta anônima de motivo, Quem somos, Suporte e Sugestões (o Master atende e responde).
- Item "Contribua · PIX" no menu do avatar e título na barra azul.
- Aviso de espaço no topo de toda tela para o usuário primário, a partir da faixa Alerta (padrão 90%), uma vez por dia.
- Contribua C1: planos de espaço (Free 10 GB, Essencial 20 GB, Profissional 50 GB, Avançado 100 GB), espaço por escritório com limite próprio do Master ou sem limite, e faixas de uso 70/90/95/100.
- Bloqueio de novos envios quando o escritório passa do limite, num ponto único do armazenamento; consultar, baixar e excluir continuam liberados. O bloqueio nasce desligado e o Master o liga quando quiser. O escritório do dono do sistema (id 1 em produção) nasce sem limite.
- A importação do Drive interrompe a pasta ao encontrar o espaço esgotado, em vez de baixar os arquivos seguintes à toa.
- Pasta, aba Detalhes: item "Enviar no Chat I.A" no menu da observação (desenho 02, L.6872). Ele abre a Central de Comunicação flutuante (evento `bj-central:abrir`) e copia "Pasta N · autor · data" e o texto (até 900 caracteres) para colar na conversa.
- Toda janela, menu ou pop-up sem botão de fechar ganha um X no canto (protótipo do Designer, "X de fechar em toda janelinha"): fecha pelo botão próprio da janela, ou com Esc e um clique fora. Reconhece o fechar do Bootstrap e respeita `data-sem-x="1"`. Script e CSS globais em `public/js/fechar-janelas.js` e `public/css/fechar-janelas.css`, pelo parcial `layout/_globais/fechar.html.twig`.
- Pasta, trilho de Clientes: selo "CPF duplicado" (ou "CNPJ duplicado" para PJ) quando outro cliente vinculado à mesma pasta tem o mesmo documento, comparado só pelos dígitos (serviço `ClientesDuplicadosDaPasta`, desenho 02 VS 18.10.26).
- Pasta, cartões do Registro, do Financeiro e de Detalhes: presença do autor (ponto no avatar e rótulo Disponível, Ocupado, Ausente ou Off-line), a partir do batimento da Central; clicar no próprio rótulo abre o Chat I.A.
- Ocultar aba da pasta: o usuário primário (ou o Master) clica com o botão direito em qualquer aba da pasta e escolhe "Ocultar para toda a equipe", "Ocultar para…" (marca quem deixa de ver) ou "Mostrar para todos". A regra vale só para aquela pasta.
- Para quem está oculto, a aba e o conteúdo dela não aparecem e as ações daquela aba são recusadas pelo servidor. Com o Financeiro oculto, o financeiro também sai do resumo impresso, das pendências e da análise; com o Push oculto, sai o link externo do Push.
- Quem configura vê um ícone de olho cortado na aba com regra, com quantas pessoas ela está oculta.
- Painel do Master, "Planos e limites": cartões com os planos do catálogo (Free, Essencial, Profissional e Avançado), preço por mês, espaço, usuários e quantas contas usam cada um; "Editar plano" muda nome, espaço, preço, limite de usuários, descrição e situação (o Free continua grátis e ativo).
- Menu da conta: "Editar limite de armazenamento" (ou sem limite) e "Definir limite de usuários", com justificativa obrigatória que vai para a auditoria da conta.
- "Contas e escritórios" e "Armazenamento" mostram o plano, o limite, a % da cota, a faixa e a previsão de cada conta; o ranking ganha "Editar limite".
- Faixas de alerta configuráveis (Atenção, Alerta, Crítico, Bloqueio) e a chave do bloqueio de envio, desligada, com o aviso ao lado.
- Visão geral: o KPI de armazenamento mostra o espaço contratado, o livre e a barra pela faixa; o botão "Segurança e auditoria" já abre com o total de registros.
- Trilha do Master: "Limite de armazenamento", "Limite de usuários", "Plano alterado", "Faixas de alerta alteradas" e o "Alerta de consumo" do Sistema.
- Convite do escritório: com o bloqueio ligado, o escritório que chegou ao limite de usuários não convida mais ninguém.
- Painel do Master, nova aba "Telas e recursos" (desenho Perfis 1.2): o Master liga e desliga, para todas as contas, 19 itens do catálogo (10 telas, 7 itens do menu do usuário e 2 funções). Desligar pede o motivo exibido aos usuários e, opcionalmente, a data em que volta sozinho; religar é um clique. Expediente (lista de pastas) e Meu Perfil são essenciais e não desligam.
- Nova tabela global recurso_estado (uma linha por chave; sem linha = ativo). Desligado com data volta a valer como ativo no dia seguinte, sem tarefa agendada.
- Quem não é Master deixa de ver o item desligado (menu lateral, barra de atalhos, menu do usuário, botão Peticionar, BlueJus Intelligence da pasta) e, ao abrir a rota de uma tela desligada, recebe a página "em preparação" (503); chamadas de API da mesma função recebem 503 em JSON. O link público de convite do Trabalho Externo e a Política de Privacidade continuam abertos.
- O Master continua vendo tudo: o item desligado aparece apagado com contorno vermelho tracejado e a tela desligada mostra uma faixa vermelha fixa.
- Cada desligar e religar vai para a trilha de auditoria do painel como "Desativou X" e "Reativou X".
- Peticionar (desenho 07): botão "Voltar à pasta" no cabeçalho; menu de três pontos e de botão direito em cada documento (Abrir, Renomear, Baixar, Classificar e Excluir para a lixeira), pelas rotas que a aba Documentos já usa (`pasta_documento_edit` e `pasta_documentos_excluir_lote`).
- Peticionar: barra do documento selecionado com nome (duplo clique renomeia), tamanho e data, Baixar e Excluir; tamanho do arquivo e ícone por tipo em cada linha.
- Peticionar: soltar arquivos na lista envia pela rota do "Adicionar peça" (`pasta_peticionar_upload`), com os mesmos formatos, token e categoria padrão do modal; dica do desenho na lista vazia.
- Peticionar: a lista mostra o nome do arquivo da aba Documentos (o que o Renomear grava), e não mais o título antigo.
- Aba Push da pasta: cartão "Avisar quando chegar" na coluna direita (desenho 1.2.3), com três chaves por pasta: "Responsável da pasta", "Por e-mail" e "Análise da BlueJus IA" (POST /pasta/{id}/push/avisos com a chave e o estado desejado, exige editar a pasta). Por e-mail só vale com o aviso ao responsável ligado; a IA só com a IA da plataforma e do escritório ligadas e com o responsável da pasta podendo usá-la. Processo sob segredo de justiça: o e-mail sai só com uma linha genérica. Padrões: responsável e e-mail ligados, IA desligada.
- Ao fim de cada captação do DJEN (comando diário e "Buscar agora"), cada pasta viva com publicação nova vinculada avisa o responsável ativo: um aviso no sino por pasta (tipo push_publicacao_pasta) e, se ligado, um e-mail com a lista das movimentações (tipo, data, órgão e resumo de até 220 caracteres), o botão "Abrir a pasta" e o link "Não quero mais receber estes avisos". Com a chave da IA ligada, pede o resumo do Push da pasta. O aviso sai uma vez por execução (no comando diário, uma vez por escritório ao fim das duas fases). Falha de e-mail ou de IA vai ao log e não interrompe a captação.
- Descadastro de e-mail reaproveitável em Shared (DescadastroDeEmailInterface: verificar, registrar e gerar o link assinado), por remetente (escritório, ou a plataforma), endereço e assunto, com o opt-out total "todos" no mesmo escopo. Rota pública /email/descadastro: o GET só mostra o pedido (endereço mascarado, botão "Confirmar" e a opção de não receber nenhum e-mail do remetente); o POST na URL assinada grava, inclusive pelo one-click do List-Unsubscribe-Post.
- Migration: tabelas pasta_aviso_push e email_descadastro.
- Aba Push da pasta em duas colunas (desenho 1.2.3): à direita, o cartão de Monitoramento com o switch do processo principal e um switch para cada outro processo da pasta (POST /pasta/{id}/push/monitoramento com o id do processo, exige editar a pasta), os números com máscara CNJ, quem pausou e quando, e a última verificação no DJEN.
- "Buscar agora" (POST /pasta/{id}/push/buscar, exige ver a pasta): consulta o DJEN pelo número de cada processo da pasta com monitoramento ativo, nos últimos 30 dias, com a mesma deduplicação e reconciliação da captação; uma busca por pasta a cada minuto (429) e parada no limite do DJEN.
- A captação do DJEN pula os processos com monitoramento pausado e grava a última verificação de cada número consultado. Migration: colunas monitoramento_pausado_em, monitoramento_alterado_por_id, monitoramento_alterado_em e ultima_verificacao_em em processo; tabela previsao_processual (por pasta).
- "Situação atual": previsão por regras (porte das REGRAS_PROC do desenho) com fase, próximo ato, prazo em dias úteis nacionais (feriados do calendário do sistema e recesso de 20/12 a 20/01), data provável, confiança e a taxa de acertos das previsões anteriores, guardada no servidor.
- Relatório geral de processos das pastas (aba Processo, menu ⋮ do cabeçalho e de cada processo): analisa registros, documentos, metas e checklist de cada pasta ativa e separa as que deveriam ter processo, as administrativas que merecem revisão, as sem classificação e as administrativas coerentes, com sugestão, selo de confiança, metas em dias úteis, resumo para despacho e evolução mês a mês. Prévia com cabeçalho e tamanho do texto, impressão, cópia do texto e PDF gerado no servidor (GET /expediente/relatorio-processos e /pdf). Cada leitor vê só as pastas que pode ver.
- "Enviar à Controladoria agora" (POST /expediente/relatorio-processos/enviar, quem edita a pasta): avisa no sino e na Caixa da Central (tipo relatorio_sem_processo) a Controladoria e os primários com o resumo, e cada responsável com as pastas dele que pedem atenção; só quem tem o módulo Expediente recebe, e o envio manual tem teto de um a cada 30 minutos por escritório.
- Envio automático pelo comando app:pastas:relatorio-sem-processo (primeira segunda-feira do mês, uma vez por escritório; --dry-run e --tenant), com histórico para a evolução. Linha do cron em DEPLOY.md.
- Marcação "Controladoria" na edição do colaborador do escritório (coluna user_tenant.controladoria), só pelo Master ou por um primário.
- Marcar ou desmarcar "Administrativo sem processo" numa pasta com termos judiciais grava a decisão da equipe, que ajusta a leitura do relatório.
- Geração de PDF (Dompdf) extraída para o serviço compartilhado GeradorDePdf, usado também pelo resumo da pasta.

### Alterado

- A opção "Animações" do Guia visual (reduzidas) passa a valer no sistema inteiro: o `base.html.twig` põe `jp-sem-anim` no `<html>` pela preferência do usuário (`animacoes_reduzidas()`), e o `app.css` desliga transições e animações por ela.
- Quem pede movimento reduzido no sistema operacional (`prefers-reduced-motion`) ganha o mesmo em todas as telas.
- Trocar a opção no Dashboard aplica na hora, sem recarregar.
- Objeto (desenho 1A): o botão "⋯" e o botão direito na linha da obrigação abrem o mesmo menu, com a descrição no topo e os itens Receber, Marcar para cobrança em lote, Copiar valor e descrição (com aviso "Copiado"), Ver memória de cálculo, Editar (corrigir) e Excluir lançamento. Editar e Excluir seguem abrindo os modais de antes.
- Carteira (desenho 1B de 19/10): cantos de 4px em cartões, botões, campos, menus, modais e selos; o selo do topo mostra "CARTEIRA" com o número; o rodapé do popover de frescor diz qual relatório importar (o de emissão mais antiga); novo menu "⋮" no cabeçalho com "Editar configuração".
- Cantos de até 4px (padrão PJe) nas telas que ainda tinham cartões, botões e campos mais arredondados: Sedes, criar escritório, Kanban, Notificações, lista antiga de pastas, Demandas, aceite do termo, painel da plataforma, Política de privacidade e aviso de ZIP recusado. Pílulas de contador e etiqueta continuam pílulas.
- Expediente: dicas do desenho 03 no cabeçalho (Menu, Notificações, Tela cheia), na paginação ("Página N de M") e no responsável da linha ("NOME · clique para alterar").
- Expediente: o menu de responsável da linha segue o desenho 03, com "Filtrar responsável", X de fechar, ✓ na opção atual e "Nenhum responsável encontrado".
- Expediente: com um menu aberto, clicar numa linha só fecha o menu, sem abrir a pasta.
- Expediente: depois de salvar a Nova Pasta, a lista volta ao número mais recente e avisa "Pasta N aberta para CLIENTE", em vez de abrir a pasta.
- Expediente: o Limpar dos filtros não zera mais a Situação da pasta, como no desenho.
- Expediente: de 901 a 1280px a tabela segue o desenho 03: saem Ação, Responsável e Metas, e o Réu aparece (decisão do dono de 19/10, que substitui a de 15/10). O piso da tabela cai de 1262px para 742px.
- Expediente: nas larguras menores, NUP, Prioridade e Situação mantêm a largura do desenho, e até 900px a coluna do Réu sai junto com a célula (antes a Prioridade herdava a largura do Réu).
- Expediente (desenho 03, L.64 e L.67): o nome do escritório e o selo de presença no cabeçalho usam a dica do desenho (`data-tip`) dentro do Expediente; nas outras telas continua a dica do navegador.
- Expediente (desenho 03): o campo de busca ganha a dica "Buscar por número da pasta, nome do cliente, ação ou número do processo" (dc L.144). No filtro Responsável, o avatar mostra a foto de quem tem foto, no menu e no botão (dc L.146-148 e L.652, `avBg`); sem foto, ficam as iniciais.
- Documentos sugeridos da pasta: a fase do processo usada na sugestão passa a vir da última movimentação do Push Processual quando ela indica a fase (como o cálculo de prazo do Push); sem isso, segue pela classe processual, como antes.
- Motor da análise do prazo da meta (sem tela ainda): lê a data escrita na meta ("dd/mm", "hoje", "amanhã", "em N dias", "até sexta"), conta a publicação como o Push e conta "N dias" sem qualificador em dias úteis (CPC art. 219); só "dias corridos" escrito conta corridos.
- Minhas Metas (desenho 04 Minhas Metas 1.0 de 19/10): cada grupo vira um cartão próprio, com cabeçalho 15/20 em #f5f8fa, contador em #e2e9ee e ponto de 7px; Atrasadas em #a3232b e "Vencem em 7 dias" (antes "Próximos 7 dias") em #c08a12, com tokens próprios do grupo. A ordem dos grupos não mudou.
- Minhas Metas: a linha inteira da meta abre a página da meta (o título continua sendo o link; marcador, menu e link da pasta seguem com a ação deles). A pasta aparece com o nº em negrito azul e o nome inteiro do cliente; "criada dd/mm/aaaa" sem a data de modificação.
- Minhas Metas: lista vazia numa caixa só ("Nenhuma meta com esses filtros." quando há filtro), com o trilho ao lado. "Precisa de atenção" aparece sempre, e cada item é um link de largura inteira para a meta.
- Minhas Metas: grade lista + trilho de 340px com 16px; H1 de 36px sem ícone, subtítulo novo; busca e selects da barra com 36px, raio 4 e borda #e2e9ee (só nesta tela); marcador #98a9b4/#0a6fa0 com as dicas "Acompanhar esta meta" e "Parar de acompanhar".
- Minhas Metas: a busca passa a achar a meta também pelo nº da pasta e pelo nome do cliente, sem acento (a descrição continua entrando); "%" e "_" digitados são procurados como texto.
- Minhas Metas: "Precisa de atenção" lista as atrasadas pela regra do prazo, e na aba Criei passa a mostrar as metas delegadas que estão atrasadas.
- Minhas Metas: Ctrl, Cmd, Shift ou o botão do meio na linha abrem a meta em outra aba; busca, Status, Prioridade e Prazo ficam numa linha só em tela larga.
- Link externo do Push com a tela "Push compartilhado" desligada pelo Master: revogar um link continua liberado (tirar acesso nunca fica travado); gerar e renovar seguem barrados.
- Com a tela desligada, o menu do botão direito da aba Push some para quem não é Master, como o item do menu ⋮ da pasta.
- O Master que abre o link público com a tela desligada vê a faixa vermelha "Só o Master está vendo", como nas telas internas.

### Corrigido

- Auditoria: migration `Version20261019235500` tira o título, a descrição e o local dos registros ANTIGOS (anteriores ao #81) de compromissos "Só eu" da Agenda e marca `conteudo_particular`, como os registros novos. Vale para o registro que mostra a visibilidade "Só eu" e para todos os registros de um compromisso que foi ou é "Só eu"; os dois nomes da classe do `Evento` (antes e depois do #63). Irreversível; autorizada pelo dono em 19/10/2026 (87 registros em produção nesta data, um escritório: 77 com o conteúdo e 10 de mudança de participantes com o título no rótulo do dono).
- Corrige o nome da classe da migration do Contribua C2 (Version20261019235600): o arquivo tinha sido renomeado, mas a classe continuava com o nome antigo, e o Doctrine a registrava fora da ordem da fila.
- Menu do usuário: "Instalar aplicativo" sem o pedido de instalação do navegador mostra como instalar ("no Chrome ou Edge, use o ícone de instalar na barra de endereço; no celular, Adicionar à tela inicial"), como no desenho 03, em vez de não fazer nada.
- Dashboard: as colunas "Vencidas" e "Prazos próximos" passam a contar por dia, com a mesma regra da Pasta. A meta que vence hoje conta como prazo próximo o dia inteiro (antes, a partir de 00:01 ela já contava como vencida), e a janela de prazos próximos vai de hoje até daqui a 7 dias. A lista "Metas da equipe" aberta por esses números segue a mesma regra.
- Dashboard: metas de pasta excluída deixam de entrar nas colunas e nas listas de metas, e meta em revisão deixa de contar como vencida ou prazo próximo, como em Minhas Metas.

## [2.3.1] - 2026-10-20

### Corrigido

- O convite de plataforma abria o formulário de convite de escritório: o tipo era gravado como "platform" e lido como "plataforma".
- "Meus convites" nunca listava convite de escritório: o tipo era gravado como "office" e filtrado como "escritorio". Os dois lados usam agora as mesmas constantes.
- O aceite de convite (plataforma e escritório) recusa senha com menos de 8 caracteres também no servidor; os dois aparam e exigem o nome; o de plataforma recusa convite de outro tipo; senha longa demais vira mensagem, não erro.
- Documento renomeado com barra no nome (por exemplo "Contrato 01/2026") não abre mais com erro ao baixar ou visualizar. O nome novo é gravado com hífen no lugar da barra ("Contrato 01-2026"), e os documentos que já estavam com barra no nome voltam a baixar e abrir normalmente. Vale para a aba Documentos e para o Peticionar.

### Segurança

- Janela de vincular processo ("Buscar no CNJ"): os dados que vêm do Datajud (nome, documento e papel das partes; data, descrição, tipo e órgão das movimentações) passam a ser escapados antes de entrar no HTML das linhas. Antes, um texto com `"><script>` vindo da base externa virava HTML na tela.
- Escritório suspenso ou excluído não pode mais ser alterado por quem tem acesso a outro escritório. Antes, o administrador de um escritório suspenso que também tinha outro escritório continuava mexendo na equipe, no ponto manual, nas justificativas, nas horas pagas e nas sedes do escritório suspenso, pelas telas de administração. Agora essas telas recusam com o aviso de conta suspensa. O Master continua com acesso, e sair do escritório continua liberado.

## [2.3.0] - 2026-10-19

### Adicionado

- Agenda: novo endpoint `GET /agenda/datas` com as datas calculadas pelo sistema (feriados nacionais com os móveis pela Páscoa, pontos facultativos, feriados estaduais pela lotação e pelo estado do escritório, datas dos tribunais e eleições, recesso forense e datas comemorativas), cruzadas em modo só leitura com os feriados do Ponto do escritório. Base da Agenda nova (desenho 09 - Agenda - VS 18.10.26); a tela vem na próxima entrega.
- Agenda: tela nova em `/agenda/nova` (desenho 09 - Agenda - VS 18.10.26) com cartões de prazos e audiências, mini calendário, dez calendários que se ligam e desligam, Minha agenda e Equipe do escritório, visões Dia, Semana, Mês e Lista, busca, faixa de aniversário, próximos feriados com aviso de ponte, detalhe do item e menu de botão direito. A `/agenda` antiga continua até a próxima entrega.
- Agenda: endpoint `GET /agenda/compromissos` com os compromissos visíveis, os prazos e audiências abertos das pastas e os aniversários da equipe.
- Faixa de abas: aba "Agenda" para quem tem acesso ao módulo.
- Expediente: o realce do título da barra (Acervo geral, filtro ou marcador) fica no tamanho do texto, em vez de esticar até o botão Nova Pasta.
- Expediente: o menu de botão direito da pasta também funciona nos modos Lista, Cartões e Cartões grandes (abrir, nova aba, editar nome, copiar, trancar); F2 numa linha da Lista abre o editor do nome.
- Expediente: nome editado e cadeado de pasta trancada passam a valer nos três modos de visualização ao mesmo tempo.
- Expediente: erro ao gravar marcadores da pasta não devolve mais o stack trace ao navegador; vai para o log.
- Expediente: os avisos de erro do duplo clique na situação usam o aviso da página, não o alert do navegador.
- Busca livre das pastas encontra também pelo réu: o cadastrado na pasta ou, na pasta sem réu cadastrado, o polo passivo dos processos (desenho do Expediente VS 18.10.26). Vale em todas as telas que usam a busca do acervo.
- Expediente: a coluna Réu passa a ordenar, pelo nome que a coluna mostra.
- Expediente: a escolha de colunas da tabela fica guardada por usuário e escritório, com a subfrase "Ajuste só seu" do desenho.
- Expediente: textos e hover do botão do responsável alinhados ao desenho VS 18.10.26.
- Expediente: conferido no navegador contra o desenho VS 18.10.26 e ajustado: larguras das colunas e cabeçalho da tabela (rótulo com reticências, seta em todas as colunas), proporções da barra de filtros, passo da paginação, menu de colunas à esquerda do botão, altura de cartões, lista e título dos menus.
- Painel do Master, "Contas e escritórios" (desenho Perfis 1.2): o menu da conta ganha "Suspender conta" ou "Reativar conta", conforme a situação, com a confirmação do desenho. A conta suspensa fica sem acesso para os usuários no request seguinte, sem apagar nenhum dado; reativar devolve o acesso.
- Novas rotas POST /master/contas/{id}/suspender e /master/contas/{id}/reativar (só Master, CSRF por conta, resposta JSON para o painel). A lista /master/dados/contas passa a dizer se a conta foi criada por um Master (ehDoMaster).
- A conta criada por um Master (ou, sem criador registrado, com algum Master de vínculo ativo) não pode ser suspensa nem excluída: a recusa vale no painel e nas rotas antigas de exclusão (escritorio_excluir e app_tenant_delete).
- Suspender e reativar ficam na trilha de auditoria com a conta afetada ("Conta suspensa" ou "Conta reativada", detalhe "Ação confirmada pelo Master"), e não no escritório em que o Master está. A exclusão de conta passa a aparecer como "Conta excluída", não mais como suspensão.
- A conta suspensa continua ocupando vaga no limite de escritórios próprios; só a excluída libera a vaga.
- Conta suspensa barra o acesso com o aviso "A conta do escritório {nome} está suspensa. Fale com o responsável pela conta.", no redirecionamento e na tela de seleção de escritório (para quem só tem vínculo com conta suspensa). As barreiras de acesso ao escritório ficam em métodos próprios: primeiro a conta, depois o vínculo.
- Painel do Master: "Excluir conta" no menu da conta (oculto na conta do Master), com confirmação que exige digitar EXCLUIR, conferida também no servidor (POST /master/contas/{id}/excluir). A conta sai do ar na hora e fica em quarentena pela carência configurada (365 dias), depois da qual a purga automática a apaga; os usuários nunca são apagados. Excluir também vale para conta suspensa.
- "Contas e escritórios" ganha no filtro a opção "Em quarentena", com a data prevista da purga. "Reativar conta" recupera a conta da quarentena; a trilha registra "Conta excluída" e "Exclusão de conta cancelada", com "Ação confirmada pelo Master".
- Painel do Master, aba Permissões (desenho Perfis 1.2): passa a haver Master primário e Masters secundários. O Master primário promove um usuário secundário do escritório em que está a Master secundário e remove Masters secundários, sempre com confirmação na tela; o Master primário nunca é removido pela tela (cadeado). Master secundário vê a lista sem as ações.
- Promoção e remoção de Master ficam na trilha de auditoria (troca de papéis do usuário).
- Novo papel ROLE_MASTER_PRIMARIO, que só marca o Master primário. A migration o dá ao Master atual quando ele é o único; com zero ou mais de um, o dono escolhe com app:master:definir.
- app:master:definir passa a definir o Master primário (os demais Masters perdem o papel, como antes) e app:create-super-admin cria o primeiro Master já primário.
- O painel do Master abre direto na aba e na seção indicadas no endereço (#aba=perm, #sec=contas).
- Cabeçalho: selo de presença no avatar com quantos estão on-line e painel "quem está on-line" (On-line, Ausente, Não perturbe, Desligado), com aviso de entrada e saída, som e horas on-line de hoje só suas (desenho 03 VS 18.10.26).
- Cabeçalho: painel de presença conferido no navegador contra o desenho (altura das linhas, X de fechar, texto do desligado, posição do selo no avatar).
- Central: ausente por falta de atividade (60 min sem mexer em nada vira "sem atividade há N min" para os colegas), com o novo status `inativo`; o batimento de presença passa a responder quem está on-line no escritório.
- Link externo do Push Processual: a pasta gera um link público (/p/...) que mostra só as movimentações do processo, sem login, válido por 30 dias, com código de acesso opcional de 6 dígitos, nome do cliente e Análises IA liberadas opcionais.
- Lista "Links desta pasta" com estado (ativo, expirado, revogado, renovação pedida), contagem de acessos por dia, revogar, renovar por 30 dias e recusar renovação; painel "Acessos ao link do Push".
- Menu do botão direito na aba Push Processual (copiar link ativo, ver acessos, gerar link) e item "Link externo do Push" no menu da pasta.
- Página pública com proteção contra cópia, impressão e captura, marca d'água, bloqueio de 15 minutos após 5 códigos errados e pedido de renovação que vira aviso no sino para quem criou o link.
- O link não pode ser gerado (e o existente deixa de abrir) com pasta cancelada, arquivada, trancada ou excluída, ou com processo em segredo de justiça.

### Alterado

- Menu do usuário reorganizado conforme o desenho 03 EXPEDIENTE (VS 18.10.26): cabeçalho com selos MASTER e USUÁRIO PRIMÁRIO/SECUNDÁRIO, iniciais de nome e sobrenome no avatar, Convites do escritório só para o usuário primário (ou o Master), seção "Exclusivo do Master" com 8 atalhos (Central de vagas e Campanha por e-mail abrem o aviso "em preparação"), tema escuro com chave liga/desliga, seletor de Idioma (só Português por enquanto) e Ajuda e atalhos.
- Novo modal "Conta do escritório" com as abas Abrir conta (primário) e Trocar conta; a aba Trocar conta é carregada sob demanda (GET /escritorio/contas) e mostra a equipe, o primário e a conta atual. O nome do escritório no cabeçalho abre esse modal; Sair deste escritório passou para dentro dele.
- Meu Perfil ganhou a faixa de abas do desenho 07: Meu Perfil, Convites do escritório, Trocar de conta e Master.
- Perfis do Escritório (desenho Perfis 1.2): a lista ganha o resumo com 4 contadores (perfis, usuários, fixos do sistema, personalizados) e passa para 5 colunas com ícone no cabeçalho (PERFIL, CLASSIFICAÇÃO, PERMISSÕES, USUÁRIOS, AÇÕES). Saem as colunas DESCRIÇÃO e SISTEMA: a descrição vai para baixo do nome e o selo "FIXO" marca os perfis do sistema. PERMISSÕES mostra "N permissões" de TOTAL com uma barra proporcional ("Acesso total" no Master). O "Ver como Primário" também troca os números do resumo. A linha passa a destacar em azul claro no hover.
- Descrição da linha Master deixa de dizer "Único": agora cita o Master primário e os secundários.

### Corrigido

- Auditoria: compromisso "Só eu" da Agenda não expõe mais título, descrição e local no registro de auditoria (antes e depois, inclusive na troca de visibilidade nos dois sentidos); o registro mantém a ação, quem e quando, e o rótulo vira "Compromisso particular". Contrato `App\Shared\Contract\ConteudoParticular`, implementado pelo `Evento`. A tela antiga da Agenda não avisa mais os participantes de um "Só eu" (eles não o veem, e o aviso levava o título à auditoria pela Notificacao). Registros de auditoria já gravados não mudam.
- Segurança: o IP do cliente em produção podia ser forjado pelo cabeçalho X-Forwarded-For, o que furava os limitadores por IP (login, cadastro, aceite de convite, "esqueci a senha", PDF da política) e gravava o IP forjado na auditoria. O nginx de produção passa a mandar ao PHP só o IP que ele viu; o exemplo de produção e o DEPLOY.md deixam de confiar em REMOTE_ADDR e em private_ranges. Passo a passo da VPS em docs/guias/ip-do-cliente-em-producao.md.

## [2.2.2] - 2026-10-18

### Corrigido

- Central: o aviso resolvido sai da Caixa 30 minutos depois da baixa mesmo com a Central aberta e parada; com a lista à vista, ela se redesenha a cada minuto (sem nova consulta ao servidor) e o "sai da caixa em N min" deixa de congelar.

## [2.2.1] - 2026-10-18

### Corrigido

- Editar escritório: removida a aba "Alterar Senha", que nunca funcionou na v2 (o envio dava erro 500 sem trocar senha nenhuma) e, se consertada, deixaria quem só administra o escritório trocar a senha de um sócio sem passar pela regra dos primários. A troca de senha de outra pessoa continua em editar colaborador, com as guardas de sempre.

## [2.2.0] - 2026-10-18

### Adicionado

- Central de Comunicação: aviso resolvido sai da Caixa de entrada 30 minutos depois da baixa, para todos (a escolha de 1 hora, 1 dia ou 1 semana saiu do menu).
- Central de Comunicação: botão direito num aviso ganhou "Excluir", que apaga só a notificação de quem clicou (a meta, a pasta ou a menção de origem não mudam).
- Central de Comunicação: botão direito numa conversa ganhou "Excluir conversa", que limpa a conversa só para quem pediu (coluna `central_participante.limpa_ate_id`); o colega continua vendo tudo e mensagem nova traz a conversa de volta.

## [2.1.0] - 2026-10-18

### Adicionado

- Página Novidades (`/novidades`) no menu do usuário, com o que cada versão trouxe em linguagem de escritório e um selo de "novo" até a pessoa abrir; o texto vem de `app/config/novidades.yaml`.
- Versionamento automático: cada PR descreve a mudança em `mudancas/`, e um robô mantém o PR "Lança a vX.Y.Z" com o número calculado, o CHANGELOG e as novidades montados.
- Deploy automático depois do merge do PR de release, com backup conferido, versão anterior guardada, guarda contra migration destrutiva, conferência e volta automática (`scripts/vps/deploy-automatico.sh`).

### Segurança

- Dependências atualizadas, zerando os alertas do `composer audit` (eram 61): Symfony 7.4.21 (entre eles o bypass do `register_argc_argv` corrigido na origem), Twig 3.30, PhpSpreadsheet 2.4.8, Dompdf 3.1.6, PhpWord 1.4 com PhpOffice Math 0.3, Guzzle 7.15.5 e mcp/sdk 0.7.1.
- Na suíte de testes, a página de erro é a de produção (com as mensagens da exceção num comentário): a de depuração do Symfony 7.4.21 mostrava o código do próprio teste e gerava falso vazamento nos testes de isolamento entre escritórios.

## [2.0.0] - 2026-10-17

Primeira versão desde a `v1.0.0`. Reúne cerca de 2.200 commits, a maior parte feita antes de existir
este registro: a lista cobre o que dá para comprovar pelo histórico (o commit ou o PR de origem vem
entre parênteses), não cada correção. É MAJOR porque saíram funcionalidades e endereços que existiam
na `v1.0.0` (ver "Removido") e o jeito de entrar no sistema mudou (identidade global). A
justificativa está em `docs/versionamento.md`.

### Adicionado

- Expediente: listagem de pastas e processos (`c172e047`).
- Kanban com quadros, cartões, checklists e marcadores (`daf8b2a8`).
- Dashboard com indicadores agregados do escritório (`f8dcc817`).
- Termos de Uso com aceite versionado exigido antes do acesso (`e2372ce9`).
- Cadastro público de novos escritórios (`22fcfc21`).
- Sincronização de pastas com o Google Drive (`7103dfa8`).
- Captação de publicações do DJEN por escritório (`b044c0c1`).
- Módulo de Cobranças (`475aeeb9`).
- Atualização monetária por índices do Banco Central na simulação de acordo (`35475d00`).
- Política de Privacidade como página própria e em PDF (`69e5da9c`).
- Fundação da BlueJus IA, ainda sem provedor de linguagem ligado (`f05bf1f8`).
- Agente de Pastas, Fase 1: ferramentas de leitura em `/api/agente/*` (buscar e contar pastas com
  os filtros da Lista do Expediente, listar documentos de uma pasta, vocabulário do escritório).
- BlueJus IA: adaptador da API da Anthropic (Claude) na porta de provedor existente, dormente até haver
  chave e modelo configurados no ambiente.
- Central de Comunicação, A1: conversas individuais entre colegas do escritório, gravadas no servidor,
  com histórico, não lidas e atualização por polling, na página `/central`.
- Central de Comunicação, A2: janela com o título "Central de Comunicação"; @menção de quem participa
  da conversa, com aviso no sino (sem o texto da mensagem); responder citando a mensagem; marcar
  conversa como não lida; menus de botão direito da conversa (abrir, lida/não lida) e da mensagem
  (responder, copiar, mencionar).
- Central de Comunicação, B: conversas em grupo (criar, adicionar e remover participantes pelo administrador do
  grupo, sair, renomear, linhas de sistema, menção e aviso de "adicionou você"); fixar, silenciar e arquivar por
  pessoa; polling que não perde mensagem confirmada fora de ordem.
- Central de Comunicação: ícone flutuante em todas as telas (contador de não lidas, menções, animações do desenho) com
  a janela de conversas, que se move, encaixa, redimensiona, minimiza e maximiza.
- Central de Comunicação: balão de dica no padrão do desenho (azul-claro) em todos os botões da Central.
- Portões de CI em todo pull request: guarda de segredos (#5) e lints com testes unitários e de
  arquitetura (#9).
- Versionamento semântico, este CHANGELOG e o workflow Release, que só cria a tag num commit do
  master aprovado no CI; tags de versão protegidas por um App próprio (#10, #11).
- Pasta no desenho de 13/10, entre outros: janela de cadastro do cliente com as abas Documentos e
  Réu, coluna Réu na Lista, cadastro em toda pasta com novo cliente e seletor de autores, as 4
  situações da pasta com carimbo e cartão, datas escritas nos registros lidas como prazos e
  sugestões de fase pelo tipo do documento (#15, #19).
- Push Processual: processos monitorados e cadastro de processo na própria tela, e captação do
  DJEN também pelo número de cada processo cadastrado sem baixa (#22).
- Trabalho Externo: central nacional de advocacia correspondente. Escritórios abrem solicitações
  (com rascunho e documentos) e só a Central recebe: análise, pedido de informações, propostas com
  versões, aceite, execução com checklist e andamento, validação, conclusão, pagamento, arquivamento e
  reabertura, com chat próprio e histórico. Configuração do Master (visibilidade, disponibilidade,
  equipe e privacidade). Uma migration (`Version20261015210000`, só cria tabelas).
- Trabalho Externo: aba WhatsApp com contatos que autorizaram, mensagens prontas por fase e envio
  manual pelo `wa.me` (sem API externa).
- Trabalho Externo: profissional Convidado por e-mail (conta sem escritório, acesso só à demanda do
  convite e com as permissões dele, reenviar e revogar). O convidado não recebe nada da negociação
  anterior ao aceite.
- Trabalho Externo: pré-visualização de perfil para o Master (Solicitante, Equipe, Convidado), só
  leitura; o Master abre solicitação como usuário de um escritório dele e age como Solicitante nas
  demandas desses escritórios.

### Alterado

- Identidade global: uma conta por pessoa e login único por e-mail, com o escritório escolhido
  depois do login (refatoração em etapas, de `1e6ffb67` a `55bfeed5`; seletor de escritórios no
  cabeçalho em `576c093b`).
- Tarefas vinculadas a pastas, com o ciclo Pendente → Revisão → Concluída (`84e69c8a`).
- E-mails do sistema com identidade institucional BlueJus (#2).
- Pasta e Dashboard no padrão visual PJe, Trilha A (`4d1d054a` a `6de3b60d`).
- Fidelidade das telas ao desenho aprovado no Claude Designer, lotes 1 e 2 do Engineering Loop
  (#6, #7).
- Ícones de tipo de arquivo no padrão do Guia visual BlueJus (família preenchida, cores únicas por
  tipo), iguais aos da aba Documentos: Dados da Pasta, Financeiro e documentos da Cobrança.
- Menus: Processos sai do menu lateral e passa a ficar dentro do Push Processual (perfil que só tem
  Processos mantém o item), e Minhas Metas entra na barra superior no lugar dele (#22).
- Cabeçalho da pasta sem a faixa de dados, como no desenho (#21).
- O perfil de sistema aparece como "Usuário Primário" em todas as telas e nos convites pendentes, e
  o titular não vê mais "Sair do escritório" (#16).
- A busca no CNJ explica o motivo quando não encontra o processo (#17).
- Textos de tela sem travessão, e zero hora aparece como 0:00 na folha de ponto (#17, #20).

### Removido

- Módulo de Pré-cadastro, com suas telas e permissão (`e790cf87`).
- Página avulsa de Clientes e o seu item de menu (`c264fa06`).
- Telas e endereços do fluxo antigo de Tarefas: nova, admin, encerrar, enviar para revisão e enviar
  pendência (`84e69c8a`).
- Upload legado de documentos da Pasta (`b575428d`).

### Segurança

- Credencial de e-mail retirada do repositório e guarda de segredos no commit e no CI (#5).
- Requisições web não conseguem mais trocar o ambiente da aplicação pela query string
  (CVE-2026-47767; #8).
- O perfil Master fica protegido também na promoção e na remoção de sócios, e o convite com perfil
  de usuário primário é revalidado no aceite (#16).

## [1.0.0] - 2026-03-31

Primeira versão marcada no repositório (tag `v1.0.0`, commit `4940cc87`). Não há registro
detalhado do que ela continha.

[Não lançado]: https://github.com/sfbdata/Prime/compare/v2.6.0...HEAD
[2.6.0]: https://github.com/sfbdata/Prime/compare/v2.5.0...v2.6.0
[2.5.0]: https://github.com/sfbdata/Prime/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/sfbdata/Prime/compare/v2.3.1...v2.4.0
[2.3.1]: https://github.com/sfbdata/Prime/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/sfbdata/Prime/compare/v2.2.2...v2.3.0
[2.2.2]: https://github.com/sfbdata/Prime/compare/v2.2.1...v2.2.2
[2.2.1]: https://github.com/sfbdata/Prime/compare/v2.2.0...v2.2.1
[2.2.0]: https://github.com/sfbdata/Prime/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/sfbdata/Prime/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/sfbdata/Prime/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/sfbdata/Prime/releases/tag/v1.0.0
