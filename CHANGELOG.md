# Changelog

Todas as mudanças relevantes do BlueJus ficam registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota o
[Versionamento Semântico](https://semver.org/lang/pt-BR/). A política de versões, os critérios de
cada nível e o procedimento de release estão em [docs/versionamento.md](docs/versionamento.md).

## [Não lançado]

## [2.12.0] - 2026-10-22

### Adicionado

- Google Agenda: as datas do sistema que a Agenda mostra (feriados nacionais e estaduais, pontos facultativos, recesso forense, datas dos tribunais e eleições, comemorativas, feriados do escritório, datas acrescentadas e aniversários da equipe) vão também à agenda do escritório no Google, como dia inteiro e sem ocupar horário, com os ajustes do escritório (oculta não vai, renomeada vai com o nome novo). Lá são só leitura. Tabela nova `agenda_google_data`.
- Comercial (F4): o bloco "Datas comemorativas" aparece na seção Comercial do Painel do Master, logo depois das Automações, como no desenho 05 de 21/10. São 17 datas nacionais (Ano Novo, Carnaval, Dia da Mulher, Páscoa, Tiradentes, Dia do Trabalho, Dia das Mães, Namorados, Dia dos Pais, Dia do Advogado, Independência, Dia do Cliente, Dia das Crianças, Finados, Consciência Negra, Natal e Réveillon), em ordem da próxima data, cada uma com o interruptor, o dia, "em N dias · envio dd/mm às 9h" (laranja a 7 dias ou menos), a antecedência (No dia, 1 dia antes, 3 dias antes), o "Para quem" (Todos, Primários, Base de contatos) e o "Ver mensagem", que abre o modelo da data na Central de envios. Os 17 modelos também entram na grade de modelos, para duplicar. Todas nascem ligadas, no dia, para Todos. No dia do envio, a partir das 9h, a varredura cria uma campanha única da data (uma por ano), que sai pelo mesmo caminho das campanhas (vazão, teto diário, descadastro, Relatório e Cancelar). Com o envio de campanhas desligado na instalação (o padrão), nada sai e a tela avisa. Rotas novas `GET /master/comercial/datas` e `POST /master/comercial/datas/{chave}`, só para o Master. Migration `Version20261020149800` (tabela `comercial_data_comemorativa`, semeada com as 17).
- Comercial (F3h): a Central de envios aparece também na seção "Indicações do BlueJus" do Painel do Master, em modo venda, como no desenho 05 de 21/10: sem o seletor de modelo (sai sempre o Convite, editável), com os passos 1 PARA QUEM e 2 MENSAGEM, os públicos Novos contatos (base), Importar lista (CSV) e Individual em 3 colunas e o filtro só com a busca e o Estado. O botão "Cadastrar e-mail" manda a cada endereço o pedido de confirmação dupla da Base de contatos (o contato só entra quando confirmar) e diz quantos pedidos saíram, quantos já existiam e quantos foram barrados. O servidor recusa, no modo venda, público, modelo e filtro que a tela não mostra. Rota nova `POST /master/comercial/contatos`, só para o Master. A seção Comercial continua igual.
- Central de envios, modo simples (decisão do dono 21/10, F3i (a); spec comercial-e-emails §12; dc 05 L274, L596, L659): "Adicionar imagem" / "Trocar imagem" e "Remover". O navegador reduz a 1080 px em JPEG, como o desenho; o servidor (`POST /master/comercial/imagens`, só o Master, CSRF) aceita só JPG ou PNG pelo conteúdo, até 1 MB e 4000 px, e regrava sem EXIF.
- A imagem fica no BlueJus (tabela `comercial_imagem`, migration `Version20261020149700`, categoria `COMERCIAL_IMAGEM`) e o e-mail a busca em `GET /e/i/{token}.{jpg|png}`, público, sem sessão, com `nosniff` e `noindex`. A mensagem guarda só o token; URL e `data:` são recusados.
- Validade: 30 dias sem uso; 1 ano a partir de cada envio (inclusive cada execução da recorrente) ou de cada modelo salvo. O `app:purgar-dados-expirados` apaga a linha e, depois do COMMIT, o arquivo.
- nginx de produção: `location ^~ /e/` manda as rotas dos e-mails (pixel `.gif`, imagem, clique, confirmação) ao PHP antes do regex de imagens estáticas, que respondia 404. Um teste guarda a ordem.
- Comercial do Master, F3g (tela): a chave "Confirmação de leitura" na barra de envio, entre os ícones e o escudo de LGPD, como no desenho 05. Com o rastreio global desligado (até a Política de Privacidade tratar dele), aparece desligada, sem clique e com a explicação; liberada, começa ligada e a escolha vai no "Enviar" da própria Central (seção Comercial ou modo venda das Indicações).
- Modo venda da seção Indicações: o botão "Descadastrados (N)" também no cabeçalho da Central; a lista (a mesma da seção Comercial) abre logo acima da Central de onde veio o clique.
- Comercial (F3e): o Relatório da Central de envios ganha as marcas de cada Master, como no desenho 05 de 21/10. Abas "Com estrela" e "Arquivadas" com a contagem; a estrela em cada linha (marca e desmarca sem abrir a campanha); com campanhas selecionadas, "Arquivar" (ou "Desarquivar"), "Estrela" e a lixeira "Remover do relatório" (só esconde para quem removeu, nada é apagado). O botão direito na linha abre o menu: Abrir campanha, Duplicar como nova, Reenviar a quem não leu (por enquanto só avisa), Encaminhar para um e-mail (desabilitado, aguardando decisão), estrela, arquivo, Exportar destinatários (CSV) e Excluir do relatório. O visualizador ganha "Reenviar a quem não leu" e "Exportar destinatários": o CSV vem do servidor com todos os destinatários (sem os endereços anonimizados), protegido contra fórmulas de planilha, e cada exportação fica registrada na auditoria. Rotas `POST /master/comercial/relatorio/marcas` e `GET /master/comercial/campanhas/{id}/destinatarios.csv`, só para o Master; tabela nova `comercial_campanha_marca`.
- Comercial: o Relatório da Central de envios (abas, busca, marcas, menu do botão direito, visualizador e CSV) aparece também na seção "Indicações do BlueJus" do /master, abaixo da Central, como no desenho 05 de 21/10. É a mesma lista nas duas seções; marcar, arquivar ou cancelar numa atualiza a outra, e o "Duplicar" preenche o editor da mesma seção.
- Idioma do sistema: os textos do Relatório da Central de envios passam ao catálogo de tradução (bloco `comercial_relatorio`, com o catálogo do JS). Nenhuma mudança visível.
- Comercial do Master (F3f): o ícone de teste abre o modal "Enviar e-mail de teste" do desenho, com o destino travado no e-mail do Master; o teste chega com [TESTE] no assunto e fica gravado como uma linha "Teste" no Relatório, contando no KPI Campanhas.
- Central de envios (decisão do dono 21/10, F3.3 (a), spec comercial-e-emails §11): com o modelo `vagas` ("Ofertas · vagas abertas"), o público "Base de contatos" soma os confirmados de `comercial_campanhas` e de `vagas_alerta`, sem repetidos (quem está nas duas sai por `comercial_campanhas`). Os outros modelos, inclusive as cópias `cu<id>`, continuam só com `comercial_campanhas`.
- Cada destino leva a lista pela qual entrou: coluna `lista` em `comercial_campanha_destino` (migration `Version20261020149600`, padrão `comercial_campanhas`). Ela decide o link de descadastro, o List-Unsubscribe e a checagem do Carteiro. No modelo `vagas`, quem saiu de qualquer uma das duas listas fica de fora.
- Âncora no servidor: o modelo `vagas` só é aceito no modo simples, com o botão para as vagas. A trava vale na confirmação, no envio de teste e na execução (uma campanha antiga sem a âncora não alcança a lista de vagas).
- A contagem recebe o modelo; a tela reconta ao trocar de modelo.
- Número da meta no escritório, o "Nº 0001" do desenho 02: selo depois do título na aba Metas da pasta, no cartão de Minhas Metas e no detalhe da meta, com a dica "Meta N desta pasta · nº X na sequência do escritório". A sequência é por escritório (nunca o id) e nunca reaproveita número: a meta excluída não o devolve.
- Toda meta nova ganha o número ao nascer (`GravarMetaNovaUseCase`, contador `tarefa_numeracao` sob a trava `pg_advisory_xact_lock(4717, escritório)`), inclusive as criadas pelo "Criar tarefa" do Push e do drawer da IA, que usam o mesmo modal.
- Migration `Version20261020148700`, só estrutura: coluna `tarefa.numero` (nula), índice único `(tenant_id, numero)` e a tabela `tarefa_numeracao`. As metas antigas ficam sem número, e sem selo, até o comando `app:tarefa:numerar-metas` rodar uma vez depois da publicação (DEPLOY.md); ele numera por escritório, em ordem de criação, aceita `--dry-run` e `--escritorio=ID`, e rodar de novo não muda nada.
- Minhas Metas: "Esteira da pasta" do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, Fase 1). Oito caixas (Comercial a Encerramento) com as metas abertas e atrasadas de todo o escritório, o selo GARGALO na caixa com mais atrasadas e, ao clicar numa caixa, a lista das metas daquela etapa de toda a equipe (aba Todas, filtro `etapa`). Abaixo, o divisor "Suas metas · Individual" antes dos números pessoais.
- Cada meta ganhou a etapa da pasta (`tarefa.etapa`) e o tipo de demanda (`tarefa.tipo_de_demanda`, judicial por padrão; consultoria pula Documentação e Acompanhamento). Meta nova sem etapa recebe a deduzida pelo título. O cartão mostra "i/T · Etapa" e "próximo: Etapa · Setor".
- Modal "Configurar caixa" (⋮, clique direito ou duplo clique no "CAIXA N"): nome da caixa, nome da etapa e quem é avisado quando chega demanda (@ com a equipe ativa). Só o usuário primário e quem administra perfis configuram (decisão D2). O aviso em si sai na passagem de etapa (Fase 3); nesta fase a caixa só guarda quem será avisado. Rotas `tarefa_minhas_caixa_salvar` e `tarefa_minhas_caixa_restaurar` (POST com CSRF), desligadas junto com a tela Minhas Metas pelo Master.
- Migration `Version20261020149900`: colunas `etapa` (preenchida nas metas existentes pela regra do título) e `tipo_de_demanda` em `tarefa`; tabelas `tarefa_caixa_esteira` e `tarefa_caixa_esteira_avisado`.
- Nova Pasta (desenho 03, L276): quando as pastas com o nome de cliente digitado apontam para um cadastro só (o cliente principal), `POST /pasta/nova` liga a nova pasta a esse cadastro (`CriarPastaDTO::vincularAoCadastroDoMesmoNome`, `PastaRepository::cadastroUnicoPorNomeDeCliente`). Nome sem cadastro, homônimos com cadastros diferentes e cadastro de outro escritório não ligam nada. Acervo, Drive e cobrança continuam sem vínculo por nome.
- `GET /expediente/nova-pasta/sugestoes` devolve `cadastro` em cada cliente; o aviso da Nova Pasta só mostra "A nova pasta será vinculada ao mesmo cadastro." quando é verdade.
- Prazo calculado do Push: quando a publicação do DJEN traz "no prazo de N dias", a pasta ganha o prazo contado a partir da publicação (dias úteis com feriados da UF e recesso), marcado "prazo calculado, confira". Quando a parte parece Fazenda, MP ou Defensoria, o aviso lembra que pode ser prazo em dobro. O prazo pode ser marcado como cumprido. Os filtros "Expediente com prazo", "Prazo findou" e "Audiência" passam a contar esses prazos, e a publicação do juízo não conta como resposta do escritório.
- Trabalho Externo: campanha por e-mail do Master (spec §4.10, desenho 06). Monta, mostra e exporta; não envia. Modal "Campanha por e-mail · Master" aberto pelo menu ⋮ ("Campanha por e-mail…"), pelo endereço `#camp=1` e pelo clique no KPI ALERTAS POR E-MAIL do painel (o `ir` passa a ser `{acao: "campanha"}`).
- Estado de hoje: a campanha sai com 0 destinatários. As três fontes estão desligadas até o dono decidir, numa regra só (`DestinatariosDaCampanha`): currículos (`CURRICULOS_LIBERADOS = false`, o aceite do currículo não cobre e-mail de campanha), inscritos nos alertas (entram só com o e-mail confirmado, que depende da 5d) e e-mails dos ofertantes no público Escritórios (`ESCRITORIOS_LIBERADOS = false`, botão desabilitado com a dica "A confirmar"). O modal diz quantos ficam de fora e por quê. O modal, a prévia e o HTML funcionam; o CSV responde "Sem destinatários".
- Regra quando liberada: currículos com o aceite LGPD nos filtros de UF e área, nunca de conta desativada; inscritos nos alertas nos dois públicos, como no desenho; sem e-mail repetido; ponto de troca do descadastro da 0d marcado no código.
- `GET /trabalho-externo/api/campanha` (`trabalho_externo_campanha`, `?pub=cand|esc&uf=&area=&assunto=`): públicos, filtros, assunto padrão, número de destinatários, quem fica de fora e por quê, vagas abertas no e-mail, resumo e a prévia do HTML com o link da página pública geral (não grava nada).
- `POST /trabalho-externo/campanha/destinatarios.csv` (`trabalho_externo_campanha_csv`, CSRF, filtros no corpo JSON): E-mail, Nome, Origem e UF, no formato das outras planilhas (BOM, `;`, aspas, proteção contra fórmula). Lista vazia: 404 em JSON com o toast "Sem destinatários".
- `POST /trabalho-externo/campanha/email.html` (`trabalho_externo_campanha_html`, CSRF, anexo `email-campanha-bluejus.html`) e `POST /trabalho-externo/api/campanha/registro` (`trabalho_externo_campanha_registro`, `{acao: "copiar"}`, CSRF, devolve o HTML para a tela copiar): o e-mail do desenho (tabelas e estilos inline, gerado por Twig, texto escapado) com um link de vagas próprio com as vagas abertas nos filtros e o marcador `{{descadastrar}}` no rodapé para a ferramenta de envio trocar.
- Só o Master de verdade: Equipe, externo e prévia `?como=` recebem 403 em JSON. Cada exportação (CSV, baixar e copiar o HTML) fica no log de auditoria (canal `audit`) com quem, quando, público, filtros e quantidade, nunca a lista. Sem migration.
- O download da lista de alertas por e-mail (§4.9) também passa a registrar no canal `audit`: no canal padrão, a produção descartava o registro.
- O item "Campanha por e-mail" do menu do Master (e da aba Master do Meu Perfil) deixa de abrir "em preparação" e leva ao modal da campanha no Trabalho Externo (`#camp=1`, desenho 06 l.1078).
- Trabalho Externo: Painel do banco de talentos, só do Master (F3b-1, spec §4.9). `GET /trabalho-externo/api/curriculos/painel` (`trabalho_externo_curriculos_painel`) devolve os 8 KPIs do desenho 06 (rótulos, ícones, cores, subtítulos e cliques), os 6 gráficos de barras (top 8, com o filtro de cada clique), as contagens por mês do crescimento (currículos, candidaturas e voluntários) e o número de inscritos. A Equipe, o externo e a prévia `?como=` recebem 403.
- Correções da §4.9: "ativos" (sem arquivados) é a base de todos os KPIs e barras de currículo; COM OAB conta e filtra advogado(a) e estagiário(a) com OAB (filtro `oab`, aceito também no CSV da lista); VOLUNTÁRIOS mostra "0 termo(s) aceito(s) · 0 aguardando" até o Termo de Voluntariado; a média de aceite das ofertas é a mesma do chip do banner.
- `GET /trabalho-externo/curriculos/alertas.csv` (`trabalho_externo_alertas_csv`, só Master): `alertas-vagas-bluejus.csv` com E-mail, UF, Áreas, Inscrito em e Origem, no formato das outras planilhas (BOM, `;`, aspas e proteção contra fórmula). Sem inscritos, 404 em JSON com o texto do toast "Ainda sem inscritos".
- Contadores anônimos da página pública, por dia (horário de Brasília) e por link: cada GET de `/vagas-juridicas/{token}` válido soma uma visita; `POST /vagas-juridicas/cta` (`vagas_publicas_cta`, 204, sem sessão nem CSRF, limitador `vagas_cta` de 30 por minuto por IP) soma um clique em "Criar conta". Robôs conhecidos e pedidos sem User-Agent não contam. Todo link de cadastro da página leva `data-url-cta`.
- Migration `Version20261019235900`: tabela `trabalho_externo_metrica_publica` (dia, link opcional com SET NULL, visitas, cliques; único por dia e `chave_do_link`), somada com `INSERT ... ON CONFLICT DO UPDATE`.

### Alterado

- Expediente: o menu "Colunas da tabela" guarda o ajuste de cada usuário no servidor, por escritório (`preferencia_usuario`, chave `expediente.colunas`, rota POST `/expediente/colunas`), traz uma vez o ajuste local da própria pessoa e deixa de ler a chave local sem usuário.
- Idioma do sistema: textos da aba Financeiro da pasta (contrato, pró-bono, honorários, relatório financeiro, arquivos e pagamentos, com as mensagens dos scripts da aba) no catálogo de tradução em português. Nenhuma mudança visível.
- Idioma do sistema: textos do Kanban (lista de murais, mural, detalhe do card e comentários, com as mensagens dos scripts) e do detalhe da meta no catálogo de tradução em português. Nenhuma mudança visível.
- Lembrete de confirmação (`email/comercial/automacao/lembrete_confirmacao.html.twig`) e modelo "validar" da Central de envios: o botão passa a "Confirmar e criar minha senha" e o prazo a "O link vale por 7 dias.", porque o link agora abre "Crie sua senha" e vale 7 dias (decisão (a) do dono, frente cadastro-senha-no-link). Um teste amarra o texto a `ConfirmacaoEmail::VALIDADE`.
- Minhas Metas segue o desenho 04 (decisão do dono, 21/10): grupos na ordem Atrasadas, Vencem em 7 dias, Aguardando revisão, No prazo, Sem prazo e Concluídas, cada um com a cor do desenho, sem nota; Concluídas é um grupo aberto (o corte de 30 dias continua). Rótulos de grupo e pílulas de status vêm do catálogo (`metas.grupos.*`, `metas.status.*`).
- "Seu andamento" mostra três itens na legenda (concluídas, atrasadas, em revisão); a fatia "em aberto" saiu.
- A barra de filtro perdeu o filtro Status (o `status` da URL é ignorado).
- Prioridade própria da meta (`PrioridadeDaMeta`: normal, alta, urgente; coluna `tarefa.prioridade`, migration `Version20261020149300`). A meta nova herda a da pasta (urgente vira Urgente, prioridade vira Alta); as antigas herdam pelo comando `app:tarefa:herdar-prioridade-da-pasta` (`--dry-run`, `--escritorio=ID`), a rodar uma vez depois da publicação (DEPLOY.md). O filtro "Prioridade" passa a olhar a prioridade da meta, e o cartão deixa de mostrar a prioridade da pasta.
- Painel Master, mapa "Onde estamos atuando": seccionais da OAB, janela da OAB e lista do que falta alinhadas ao desenho 05 inteiro. Modo "OAB" no seletor (Pessoas, Escritórios, OAB), com a coluna "Inscrições" e as linhas que abrem o cartão da sede; marca da seccional com 6 status, cor e data; seccionais visíveis guardadas no banco ("Mostrar todas" / "Todas visíveis"); subseção por cidade; nota do estado ou da capital, com "Nota salva em" data e hora; pílulas Estados e Capitais; busca do endereço oficial no Google; no Mundo o item que falta fica sem ação. Textos do mapa do Brasil no catálogo de idioma.
- Migration `Version20261020149500`: só acrescenta (colunas na `master_seccional_oab` e na `master_subsecao_oab`, tabela nova `master_nota_cidade_oab`). A tabela antiga de divulgações fica sem uso, para uma limpeza futura com o dono.
- Central de envios: a descrição do público "Base de contatos" passa de "Inscritos pela página de vagas e campanhas" para "Contatos que autorizaram campanhas" (decisão do dono 21/10, F3.9 (b); spec comercial-e-emails §11).

### Corrigido

- Google Agenda: a falha na volta da conexão (e o escritório trocado entre a ida e a volta) passa a ir ao log de erro de produção com o motivo; antes era um aviso que o log de produção descartava. A recusa do token traz o código do OAuth (ex.: `invalid_client`), sem a descrição livre do Google.
- Google Agenda: quem volta do Google sem a permissão da agenda (a caixinha desmarcada no consentimento) vê o aviso "Marque a permissão da agenda na tela do Google e tente de novo" com o botão "Tentar de novo", em vez do "Não foi possível conectar".
- Google Agenda: na primeira conexão, antes de ir ao Google, uma janelinha avisa para deixar marcada a permissão da agenda na tela do Google.
- Ponto: salvar um feriado (novo ou editar) com a data vazia dava erro 500 (`Feriado::setData(null)`). Agora a tela volta com "Informe a data do feriado." no campo Data (código 422) e nada é gravado; no editar o feriado fica como estava. O mesmo vale para o nome vazio no editar ("O nome é obrigatório.").
- Chat da Central: quem excluía uma conversa direta (`limpar`, que só esconde o histórico para si) e a reabria pelo colega via "Você não participa mais desta conversa." e não conseguia escrever. A tela tratava a conversa fora da lista como saída, mas a 1:1 não tem saída: ela só volta à lista com mensagem nova. Agora o bloqueio vale só para grupo (quem saiu ou foi removido); na 1:1 a pessoa escreve de novo na mesma conversa, sem o histórico que excluiu, e o colega segue com tudo.
- Sedes (Ponto): salvar uma sede (nova ou editar) com a latitude ou a longitude vazia dava erro 500 (`Sede::setLongitude(null)`). Agora a tela volta com o aviso no campo ("Latitude é obrigatória.", "Longitude é obrigatória.") e nada é gravado; no editar a sede fica com a cerca de antes. Os avisos de faixa e de texto não numérico (latitude, longitude e raio) também passam a aparecer na tela: antes o form recusava sem dizer o motivo. O ponto eletrônico segue conferindo a batida contra a cerca gravada, sem mudança.

### Segurança

- Cadastro: o passo "Criar conta" da tela de entrada não pede mais a senha nem mostra a régua de força; a senha é criada no link de confirmação do e-mail. O servidor continua ignorando `senha`, se um cliente antigo ainda mandar.
- Cadastro: a página "Crie sua senha" do link de confirmação pede a caixa "Li e aceito os Termos de uso e a Política de privacidade" (obrigatória; sem ela, a mensagem do cadastro e o link continua valendo) e grava, na mesma transação da senha e só para quem ganha a corrida, um aceite novo dos Termos e a ciência da Política (versão vigente, IP, user agent, escritório de que a pessoa é dona). Os do cadastro ficam.
- Termos: `aceite_termo` e `ciencia_politica` ganham a coluna `origem` (`cadastro`, `link_confirmacao`, `reaceite`, `cadastro_nao_ratificado`; as linhas existentes ficam `cadastro`), e o índice único passa a (user_id, versao, origem). O gate dos Termos grava `reaceite`. Migration `Version20261020149400`, com DROP INDEX: deploy MANUAL, aplicada pelo dono.
- Senha: a conta a confirmar que é confirmada pelo código do "Esqueceu a senha?" perde o valor do aceite dos Termos e da ciência da Política do cadastro (origem `cadastro_nao_ratificado`, sem apagar). Na primeira entrada, o gate dos Termos pede Termos e Política e grava os dois como `reaceite`.
- Cadastro: a senha passa a ser criada no link de confirmação do e-mail. A conta nasce com uma senha inutilizável (a digitada no cadastro é ignorada) e o link abre "Crie sua senha", com "Nova senha", "Confirmar nova senha" e a régua de força; "Criar senha e confirmar" grava a senha, confirma o e-mail e marca o link como usado numa transação só. Fecha a brecha de um terceiro cadastrar o e-mail de outra pessoa com a senha dele e entrar depois que a dona clica no link.
- Cadastro: abrir o link não confirma mais nada (o robô de antivírus que abre o link também não). Erro de senha volta ao formulário sem gastar o link; dois envios ao mesmo tempo só gravam uma senha. Não faz login sozinho.
- Cadastro: textos novos no modal "Confirme seu e-mail", no e-mail de confirmação (botão "Confirmar e criar minha senha") e na resposta do reenvio sem conta na sessão, que agora aponta o "Esqueci a senha".
- Processo: no Novo/Editar processo, os dados trazidos pela busca no DataJud (nome, documento e papel das partes; data, descrição, tipo e órgão das movimentações; nome e código dos assuntos) e a mensagem de erro da busca passam por escape antes de entrar na página. Um texto com HTML ou script vindo do tribunal aparece como texto e não executa nada.

## [2.11.1] - 2026-10-21

### Corrigido

- Build de produção: o `.env` de mentira do `cache:warmup` no Dockerfile ganhou `MAILER_FROM` (endereço válido, a `IdentidadeDeEmail` valida) e `DJEN_BASE_URL`. Sem o `MAILER_FROM`, o warmup do Twig instanciava a `FiltroDaCampanhaExtension` e o build da v2.11.0 caía em "Environment variable not found". O CI não via porque usa o `.env` do repositório.
- Teste de arquitetura `EnvDoBuildArquiteturaTest`: toda `%env(NOME)%` sem `default:` nos YAML de `config/` (fora de `when@dev`/`when@test`) precisa estar no printf do Dockerfile.
- Meu Perfil: depois de salvar a foto nova, a tela mostrava "Erro de comunicação." e o recorte não fechava (o script procurava o avatar antigo, `.perfil-foto-btn`). Agora o recorte fecha e a foto troca no avatar, no modal e no cabeçalho; o aviso de comunicação fica só para falha de rede.

## [2.11.0] - 2026-10-21

### Adicionado

- Central de Notificações do Master: entram os pedidos de exclusão de conta (com o motivo e a situação, e resposta para quem pediu) e os alertas de consumo de espaço (vindos da trilha, sem resposta). O link "Abrir Indicações" passa a aparecer só nos alertas do Indicar o BlueJus.
- Janela de Cadastro do cliente: nenhum campo é obrigatório para salvar (decisão do dono, 21/10/2026). Nome, razão social, CPF e CNPJ podem ficar vazios; CPF/CNPJ informado continua precisando ser válido e não pode ser de outro cadastro do escritório. O que falta segue como pendência, sem asterisco.
- Correção: o "Novo cliente" com "Empresa" escolhido era enviado sem o tipo e caía como pessoa física ("Informe o nome completo."). O JS agora envia o `tipo`.
- Modal Nova carteira de cobrança: link "Cadastrar cliente" abre a mesma janela de Cadastro sem pasta. Rotas novas `cliente_cadastro_novo` (GET/POST `/clientes/cadastro/novo`) e `cliente_cadastro_novo_documento`, só para quem tem `resources.carteira.gerenciar` (`CriarClienteAvulsoUseCase`). Ao fechar depois de salvar, a página recarrega com o cliente no select.
- Cadastro do cliente: aba "Histórico" na janela de Cadastro, com as 40 alterações mais novas (quando e o quê, a mais nova primeiro). Tabela própria `cliente_historico` (migration nova), gravada ao salvar o cadastro ("<quem> alterou nome, CPF..."), ao criar o cliente pela pasta (janela nova e "Cadastrar cliente" antigo) ou pela judicialização da cobrança ("<quem> criou o cadastro"), na ficha antiga de edição, na troca de contato da janela Detalhes do cliente e ao anexar, editar ou remover documento do cliente. O texto leva só os rótulos dos campos e o tipo do documento, nunca valores nem nome de arquivo. Entra na purga do escritório.
- Cadastro do cliente: bloco "Conferência do cadastro" (título e ícone neutros, sem `bi-stars`; o nome fica com o dono) no topo da aba Documentos (fatia F-A, só regras internas, sem leitura de documento nem consulta externa). Aparece para PF com CPF válido e PJ com CNPJ válido, carregado ao abrir a aba (`GET /cliente/{id}/inteligencia-cadastral`). Alertas no mesmo escritório: "Possível homônimo." (mesmo nome sem acento, caixa e pontuação, documento diferente) e "Mesmo CPF com outro nome." (outro cliente com os mesmos dígitos; pessoa da Cobrança só para quem acessa o módulo de cobranças). Nenhum CPF ou CNPJ no texto; o nome do outro cliente só aparece para quem pode vê-lo.
- "Encontrados no menu Documentos": documentos de identificação e comprovante de residência das pastas do cliente que o usuário vê (pasta excluída fica fora), fora da lixeira, ainda não vinculados e só da categoria que o cadastro ainda não tem. "Vincular ao cadastro" (`POST /cliente/{id}/inteligencia-cadastral/vincular/{documentoId}`, CSRF no header) liga o documento da pasta ao cadastro sem cópia do arquivo e grava no Histórico do cliente. Migration nova: `cliente_documento.pasta_documento_id` (FK com `ON DELETE CASCADE` e índice único por cliente).
- O documento vinculado abre e baixa pelo arquivo da pasta (com a permissão da pasta); remover o vínculo ou excluir o cliente nunca apaga o arquivo da pasta; documento da pasta na lixeira some da aba e das pendências do cadastro; o vínculo de pasta que o usuário não vê não aparece para ele nem resolve as pendências do cadastro para ele; o vinculado não é editável na página do cliente, só desvinculado. A purga do escritório não trata o vinculado como arquivo do cliente.
- Textos do bloco num ponto único (`RotulosDaInteligenciaCadastral`), neutros e sem rótulo de IA, a confirmar com o dono.
- Comercial (1b): avisos de espaço por e-mail aos usuários primários ativos do escritório. "Conta chegou a 90% do espaço" ao cruzar o limite escolhido (80, 85 ou 90%), "Espaço esgotado (100%)" com o bloqueio ligado (pela faixa ou pela primeira recusa de envio) e "Pedido de mais espaço" a cada plano pedido. Um aviso por escritório e dia (por plano, no pedido), em `comercial_aviso_espaco`. A prévia dos três modelos abre no painel do Master.
- Comercial do Master, F3g (servidor e relatório): a confirmação de leitura é gravada por campanha (`comercial_campanha.rastrear_leitura`) e o envio só rastreia com ela e com as duas chaves globais (a configuração e a Política de Privacidade). No relatório, a campanha sem rastreio mostra ✓ sem %, "não rastreada" em Lidos e Taxa de leitura e "Leitura: não rastreada nesta campanha" na dica. A chave na tela de envio vem depois da barra nova (F3a).
- Comercial (F2): motor do "Filtrar destinatários" das campanhas, com os nove campos do desenho 05 de 21/10 (busca, gênero, idade, estado, perfil, cargo, OAB, plano e aniversário), combinados com E. A contagem devolve quantos atendem, a base e quantos ficam de fora por falta do dado no cadastro. O filtro fica guardado na campanha e vale de novo a cada execução (idade e aniversário pela data da vez). A tela é a F3.
- Comercial (F3a): sai a lista antiga do Monitoramento (rota GET, caso de uso e estilos), substituída pelo relatório da F3d. O editor da campanha virou a "Central de envios" do desenho 05 de 21/10: cabeçalho com "Descadastrados (N)", "Salvar como meu modelo" nos modelos padrão, selo acima do título, "Nome na prévia", emojis no texto, a barra com "Enviar" ou "Programar envio" (data e repetição num só lugar), "Ver como vai chegar" no computador e no celular, e a linha de destinatários com os repetidos e inválidos retirados.
- Comercial (F3b): painel "Filtrar destinatários" na campanha de e-mail do Painel do Master (desenho 05 de 21/10): busca por e-mail ou nome, atalhos (Só mulheres, Só homens, Advogados, Estagiários, Primários, Aniversariantes do mês), gênero, idade, estado, perfil, cargo, OAB, plano e aniversário, com "N de M atendem ao filtro" e quantos ficam de fora por falta do dado no cadastro.
- Painel do Master, Comercial: os 4 KPIs do fim da seção (Campanhas, E-mails na fila, Base de contatos e Descadastrados) e o bloco "Descadastrados", com busca, "Registrar pedido", "Excluir" um por vez (com o aviso de LGPD e auditoria) e CSV. "Limpar lista" fica desabilitado até a decisão do dono.
- Descadastro de e-mail: a listagem aceita recortar por assunto, e há uma consulta em lote de quem não quer receber (a base de contatos já sai sem os descadastrados).
- Comercial (F3d): o bloco "Monitoramento" do Painel do Master vira o "Relatório como caixa de entrada" da Central de envios, como no desenho 05 de 21/10. Abas Enviadas, Agendadas e Recorrentes com a contagem, busca por campanha, público ou e-mail, páginas de 50 e seleção. Cada linha traz o selo do estado, o público, o nome, a leitura (✓ enviado, ✓ verde recebido, ✓✓ com o percentual de lidos, e os números no tooltip) e a data. O visualizador mostra os 4 indicadores (Destinatários, Enviados, Lidos, Taxa de leitura), o e-mail como sai (remontado da cópia guardada na campanha) e os destinatários com o estado do envio, com "Duplicar" (leva assunto e título ao editor) e "Cancelar envio" nas campanhas agendadas e recorrentes. Rotas `GET /master/comercial/relatorio`, `GET /master/comercial/campanhas/{id}` e `POST /master/comercial/campanhas/{id}/cancelar`, só para o Master.
- Meu Perfil: o usuário primário pede a exclusão da conta do escritório em 2 etapas (motivo; código de 6 dígitos enviado ao e-mail + EXCLUIR). A conta fica "suspensa a pedido": a equipe perde o acesso (sai da seleção de escritório, do login automático e do seletor), o primário fica só no Meu Perfil e pode "Desistir e reativar". Os Masters são avisados no sino. Tabelas `pedido_exclusao_conta` e `codigo_exclusao_conta`; limitador `exclusao_conta_codigo`.
- Painel do Master: reativar uma conta "suspensa a pedido" decide o pedido; excluir (quarentena de 365 dias) também, e a contagem começa na decisão do Master.
- Drawer da BlueJus IA na pasta com a aba Documentos, entre Análise e Relatórios: a lista dos documentos da pasta (nome, tipo, tamanho e data), com "Selecionar todos" e a contagem dos marcados.
- O botão "Índice inteligente" monta o índice só dos documentos marcados, pelos dados de cada arquivo (nome, título, categoria e data); o conteúdo dos arquivos não é lido, e a tela e o pedido dizem isso.
- A seleção é conferida no servidor: só valem documentos desta pasta, visíveis para quem pede e entre os mais recentes; seleção vazia ou documento fora da lista é recusado sem gastar o limite de pedidos. Pedir de novo com a mesma seleção e nada novo devolve o último índice; com um índice em andamento de outra seleção, a tela avisa para esperar.
- A aba some para quem tem a aba Documentos da pasta oculta. As análises antigas do Agente Documental passam a aparecer no histórico do "Índice inteligente".
- Aba Perguntar no drawer da BlueJus IA da pasta: a pessoa escreve uma pergunta (até 2000 caracteres), ou clica numa das quatro sugestões, e a resposta chega pela fila, com o cartão "Consultando os dados desta pasta" enquanto isso.
- A conversa é de cada pessoa em cada pasta: ninguém vê as perguntas de outra pessoa, nem pela lista nem pelos endereços da análise, e o texto da pergunta e da resposta não vai para o registro de auditoria do escritório.
- Uma pergunta em andamento por pessoa e pasta: um segundo envio, mesmo simultâneo, devolve a que já está em andamento sem criar outra nem gastar o limite de pedidos.
- A IA recebe as últimas quatro perguntas respondidas da conversa como histórico, com os mesmos cuidados de sigilo, máscara de dados pessoais e abas ocultas das outras análises.
- Painel do Master: seção "Central de Notificações" (desenho 05 Perfis 1.2, VS 21.10.26) com as sugestões do Contribua de todos os escritórios, as mensagens de suporte e os alertas de indicação numa caixa só: abas Recebidas, Não lidas, Com estrela, Respondidas e Arquivadas, busca, seleção em lote e páginas de 50.
- Estado por Master (lida, estrela, arquivada) em `central_master_estado` e histórico de respostas compartilhado em `central_master_resposta` (migration `Version20261020147500`); as duas tabelas entram na purga do escritório.
- Responder: por e-mail pelo Carteiro (transacional, origem `central_master:resposta`, sem citar a mensagem original) ou, sem e-mail, como aviso na Central do autor; a sugestão respondida fica atendida com a resposta. Alerta não aceita resposta.
- Painel do Master: nova seção "Indicações do BlueJus" (desenho 05 de 21/10). O cabeçalho mostra quantos convites foram enviados, quantos nas últimas 24 horas e quantos viraram conta; a chave pausa ou libera os convites de todos; os campos "Limite por dia (por pessoa)", "Limite por mês" e "Avisar no chat a partir de (24h)" gravam os limites (inteiros de pelo menos 1). Rotas `GET /master/indicacoes`, `POST /master/indicacoes/pausa` e `POST /master/indicacoes/limites`, só do Master, com token CSRF.
- Painel do Master, mapa "Onde estamos atuando" (desenho 05, Perfis 1.2, VS 21.10.26, L118, L140 e L481-495; spec `docs/specs/master-oab-estados-que-faltam.md`): no Brasil, cada estado da lista "Estados que faltam" e cada linha da tabela de UFs abre a janela da OAB daquela UF. O item mostra o ícone quando há nota e, no lugar do "0", "N subseções" ou "sem endereço". No Mundo, o clique avisa que a janela vale só para os estados do Brasil.
- Janela da OAB: "SECCIONAL · SEDE" com a sede da seccional, "Editar" (o mesmo "Editar sede"), "Ver no mapa" (Google Maps, só com endereço) e "Site oficial"; "SUBSEÇÕES" com "Adicionar endereço" (nome, endereço e telefone) e "Remover" com confirmação; "NOTA EXPLICATIVA" (uma por UF, compartilhada entre os Masters, até 1000 caracteres, com a data da última gravação). Nota vazia apaga.
- Rotas novas `POST /master/seccionais/{uf}/subsecoes`, `POST /master/seccionais/{uf}/subsecoes/{id}/remover` e `POST /master/seccionais/{uf}/nota` (só Master, CSRF `master_seccionais` no header, auditoria de plataforma na trilha do Master). O `GET /master/dados/seccionais` passa a trazer as subseções e a nota de cada UF. Migration `Version20261020148800`: tabelas `master_subsecao_oab` e `master_nota_seccional`.
- Painel do Master, seção "Contas e escritórios": bloco "Pedidos de exclusão de conta" (desenho 05 Perfis 1.2) com os pedidos pendentes de todas as contas (escritório, quem pediu, data e motivo). Só aparece com pedido pendente. "Reativar" e "Excluir conta" abrem as mesmas confirmações do menu da conta (a exclusão é a quarentena de 365 dias) e a linha sai do bloco depois da decisão. Os dados vêm em `/master/dados/contas` (`pedidosExclusao`), só para o Master.
- Tabela "Contas e escritórios": a conta com pedido de exclusão pendente aparece como "Suspensa a pedido", e não "Ativa" (desvio pelo dado: protótipo mostrava Ativa). Cada conta de `/master/dados/contas` leva `pedidoPendente`.
- Painel do Master, mapa "Onde estamos atuando" (desenho 05, Perfis 1.2, VS 21.10.26, F8; spec `docs/specs/master-seccionais-oab.md`): bloco "SECCIONAIS VISÍVEIS" embaixo da tabela de UFs no Brasil + Pessoas, com os 27 chips (clique abre o cartão da sede; botão direito mostra ou oculta a seccional no mapa, guardado no navegador), "Mostrar todas" / "Ocultar todas" e ponto colorido pela última divulgação.
- Cartão da sede com usuários inscritos na seccional (inscrição principal e suplementar, uma vez por pessoa, só com vínculo ativo), endereço, telefone, site, link oficial do CFOAB, "Editar sede" e as 5 últimas divulgações. Sem endereço cadastrado, o cartão diz "Endereço não cadastrado. Confira no CFOAB.".
- Mapa do Brasil: marcador da seccional no centro de cada estado e "Registrar divulgação" (Material enviado, Reunião, Parceria, com nota e data) no menu do botão direito, acima das cores.
- Rotas novas `GET /master/dados/seccionais`, `POST /master/seccionais/{uf}/sede` e `POST /master/seccionais/{uf}/divulgacoes` (só Master, CSRF `master_seccionais` no header, auditoria de plataforma). Migration `Version20261020148400`: tabelas `master_seccional_oab` (carga das 27 UFs, só UF e nome) e `master_divulgacao_seccional`.
- Meu Perfil: a preferência "Notificações no sistema" passa a valer. Desligada, o sininho fica em silêncio (sem contador, sem lista e sem o salto e o som da Central), mas as notificações continuam em "Ver todas"; publicações do DJEN, movimentos de pasta, pedidos de acesso e a resposta a eles, renovação do link externo e pedidos de exclusão de conta continuam avisando.
- Modal "Alterar senha" no padrão do Meu Perfil (cabeçalho azul, SENHA ATUAL, NOVA SENHA, CONFIRMAR NOVA SENHA).
- Menu do Master: "Central de vagas e currículos" abre o Trabalho Externo na aba de vagas.
- Pasta: "Destacar comentário" no botão direito (e no ⋮) dos registros de Dados da pasta, Detalhes e Financeiro, com a paleta de 15 cores do desenho e "Sem cor". Um destaque por pasta e aba: o último prevalece e o aviso diz de quem era o anterior. O destaque é compartilhado com quem vê a pasta, só quem pode editar a pasta destaca, e excluir o comentário apaga o destaque. Tabela `pasta_comentario_destaque`, auditada.
- Meu Perfil: campos Gênero e Atuação no "Editar informações" (desenho 05 de 21/10), preenchidos pela pessoa, com a atuação sugerida pelo sistema (OAB confirmada sugere advogado; vínculo de estágio sugere estagiário); o selo do papel ganha o nome do escritório na dica. Colunas user_profiles.genero e user_profiles.atuacao (migration Version20261020141000), sem backfill. Leitor em lote para os filtros do Comercial.
- Push processual: "Editar" a Análise IA (menu e rodapé do cartão) com o selo "editado". A versão do advogado fica em colunas próprias (`resumo_editado`, `pontos_editados`, `editada_em`, `editada_por_id`) ao lado da saída do modelo, que não muda; o cartão, o .txt e o link externo mostram a versão editada e o próximo pedido à IA continua lendo o original. Exige editar a pasta; pasta excluída recusa.
- Pasta, aba Push processual: "Encaminhar via Chat I.A" na movimentação, no teor (entre "Criar tarefa" e "Marcar como lida") e no menu do botão direito do cartão, como no desenho 02 (FPN-016). Só com a Central na página e fora da pasta excluída.
- Resumo diário por e-mail (Meu Perfil): todo dia útil, às 8h, quem deixou a preferência ligada recebe os prazos e as audiências do dia das pastas de que é responsável e da sua agenda, e as metas que vencem hoje. Dia sem nada não envia; pasta oculta pelo cadeado não aparece; uma vez por dia. Comando app:perfil:resumo-diario (cron no DEPLOY.md) e tabela resumo_diario_envio (migration Version20261020148900).
- Temas comemorativos: nas 11 datas do catálogo (Dia da Mulher, Mães, Pais, Advogado, Independência, Crianças, Outubro Rosa, Novembro Azul, Justiça, Natal e Réveillon) o sistema muda a cor do cabeçalho e a primária, mostra uma faixa no topo, ícones no cabeçalho e uma mensagem no canto que cada pessoa pode fechar.
- Painel do Master, aba "Telas e recursos": card "Temas comemorativos" com o modo (automático pelo calendário, tema escolhido ou desligado), interruptor por tema e prévia de 30 segundos só no navegador do Master.
- Migration `Version20261020147400`: tabela `tema_config` (linha única, configuração da plataforma).
- Trabalho Externo: candidatura às vagas (F2b, spec §4.6). O usuário externo com currículo se candidata ("Recebida", com "Candidatura de {nome}" no histórico da vaga); sem currículo, a tela abre o formulário e "Salvar e candidatar-se" (`candidatarA` em `POST /api/curriculos/meu`). O servidor recusa o interno, a vaga própria, a vaga fechada e o prazo de inscrição vencido.
- Aceite da oferta com tempo sob trava da linha da vaga (`SELECT ... FOR UPDATE`): nasce "Aprovado", conta os aceites da rodada atual contra a quantidade e, ao completar, a vaga fica preenchida com o tempo da rodada. "Ofertar novamente" e "Reabrir" contam do zero. Aceite em vaga de escritório entra direto nos enviados.
- Central com `talentos`: muda o andamento do candidato (Recebida, Em análise, Entrevista, Aprovado, Contratado, Não selecionado) e envia candidatos ao escritório dono da vaga. O dono vê só os enviados e abre o currículo deles sem a nota interna, sem a situação no banco e sem as outras candidaturas.
- Linha do tempo da candidatura, compatibilidade com o currículo (ordem das vagas do externo e selo "N% compatível"), "Minhas candidaturas" com marcações pessoais, e importância das vagas e dos currículos com as candidaturas.
- Rotas novas: `trabalho_externo_vaga_candidatura`, `trabalho_externo_vaga_enviar`, `trabalho_externo_candidatura_status`, `trabalho_externo_candidaturas_marcacoes`. Migration `Version20261019231700`: tabela `trabalho_externo_candidatura` (FKs CASCADE para vaga e currículo) e `rodada`, `inicio_da_rodada` na vaga.
- Trabalho Externo: currículos e banco de talentos (F3a, spec §4.5). O usuário externo (Solicitante ou Convidado) cadastra "Meu currículo", um por usuário, com consentimento LGPD (data e versão do texto), percentual de preenchimento e "Complete: ..." calculados no servidor.
- Arquivos do currículo (foto, carteira da OAB, comprovante do curso, currículo): tipo conferido pelo conteúdo, até 1,5 MB (foto até 5 MB, regravada em JPG 256x256), só depois do primeiro salvar. Download sempre pela rota, com a permissão, como anexo (a foto abre na tela) e `nosniff`. Categoria de arquivo nova `trabalho_externo_curriculo`, escopo global, em `trabalho-externo/curriculos/`.
- Banco de talentos da Central: lista com importância, marcações pessoais (estrela, lixeira, visto), situação no banco (Novo, Em análise, Entrevista, Favorito, Arquivado) e nota interna, que nunca sai para o dono. Mudar situação, escrever nota e exportar CSV exigem a chave `talentos`; excluir o currículo de outra pessoa é só do Master.
- Currículo formatado em HTML (pré-visualização, inclusive de dados ainda não salvos) e em PDF pelo Dompdf. CSV `curriculos-bluejus.csv` com as colunas do desenho, da lista filtrada.
- Rotas novas em `/trabalho-externo/api/curriculos` e `/trabalho-externo/curriculos/...`. Migration `Version20261019213000`: tabela `trabalho_externo_curriculo`.
- Trabalho Externo: visão de engajamento (F4, spec §4.7). `GET /trabalho-externo/api/engajamento` devolve os selos das abas, o banner (saudação pelo horário de Brasília, subtítulo e chips do interno e do externo), o estado do aviso de vaga nova, o lembrete diário do externo (cadastrar, completar, revisar ou em dia), as boas-vindas de quem chega pelo link de vagas (`?vagas=` e `?cadastro=`), o `motiv` e a situação das ofertas acompanhadas (`?ofertas=`, só das vagas já publicadas). Aceita `?como=` da prévia do Master, só para leitura.
- Aviso de vaga nova por usuário no servidor: `POST /trabalho-externo/api/vagas/alerta` `{ligado}`, com CSRF, só para o externo e só com o sino ligado pelo Master. A escolha guarda o escritório do contexto (`PreferenciaDoUsuario` `vagas.alerta`).
- Avisos no sino da Central (`AvisosDoTrabalhoExterno`, tipos `trabalho_externo_*` em `Notificacao` e `TiposDeAvisoDaCentral`): pedido de divulgação novo ou reenviado e candidatura para a Central (Master e Equipe com `talentos`, na sede); aceite em vaga de escritório, candidatos enviados, vaga aprovada e não aprovada (com o motivo) para o dono; mudança de andamento para o candidato, no escritório gravado na candidatura; vaga nova, reaberta ou ofertada de novo para quem ligou o aviso, com área em comum; "Solicitar informações" numa demanda para quem solicitou. Sem sede ou sem escritório, nenhum aviso; o aviso não lido do mesmo link não se repete; o título nunca leva nome nem contato de candidato.
- Banco de currículos ordenado pela última atualização ("Currículos atualizados aparecem primeiro"); a coluna RECEBIDO e "Novos na semana" seguem pela data de recebimento.
- Rotas novas: `trabalho_externo_engajamento`, `trabalho_externo_vagas_alerta`. Migration `Version20261019235800`: coluna `escritorio_do_candidato` na candidatura (FK para o escritório, SET NULL). A tela ganha `data-url-engajamento`, `data-url-vagas-alerta` e `data-eu-id`.

### Alterado

- Cadastro por e-mail exige a confirmação antes de usar o sistema (spec `cadastro-exige-confirmacao.md`). `POST /cadastro/criar` cria a conta e manda o link, mas não faz mais login: responde `{ok, confirmar, email}` e o modal mostra "Confirme seu e-mail" com o reenvio do link e o "Ir para o login".
- Login de conta com o e-mail a confirmar (formulário e lembrar-me) recusado no `UserChecker::checkPostAuth`, depois da senha conferida: "Confirme o seu e-mail para entrar. Enviamos o link para {email}." e o botão "Reenviar o link de confirmação". Senha errada continua com o erro de credencial comum.
- Reenvio público `POST /login/reenviar-confirmacao` (`auth_confirmacao_reenviar_publico`): CSRF, conta lida só da sessão (`conta_a_confirmar`, gravada pelo cadastro, pelo login com a senha certa ou pela sessão encerrada; vale 30 minutos e sai quando outra conta entra), os mesmos tetos da rota logada (2 minutos e 5 por 24 h, agora no `TetoDoReenvioDeConfirmacao`). Formulário recebe flash; o `fetch` do modal recebe JSON.
- Sessão já aberta de conta a confirmar termina no próximo pedido (`SessaoDeContaAConfirmarListener`, `kernel.request` prioridade 7): a navegação volta ao login com o aviso, o pedido de dados recebe o 401 em JSON. Liberadas as rotas do próprio fluxo (login, logout, link do e-mail, reenvio, senha, Política).
- Convites (escritório, plataforma, Trabalho Externo) continuam criando contas confirmadas; as recusas por e-mail a confirmar nos convites e no link do Push ficam como defesa.
- Cobrança, Objeto: selo "Cobrança ativa" com o ponto na cobrança em curso; contador "N obrigações" ao lado de "Dívida em aberto" e rodapé "N obrigações em aberto" (a lista continua completa, sem "Carregar mais", pela decisão de 28/08 do Histórico); subtítulo "registra no histórico da cobrança" e a dica das 48 horas na Nova anotação.
- Cobrança, Carteira: área "Arraste o arquivo ou clique para escolher" no modal Adicionar documento, com os limites reais (PDF ou planilha até 10 MB, imagem até 3 MB).
- Drawer da BlueJus IA: o resultado de cada comando, relatório ou índice ganhou o botão "Criar tarefa" logo abaixo da análise, como no desenho 02. Ele abre a nova meta da pasta já preenchida com a providência e o resumo; quem confirma é a pessoa, no próprio modal. Antes a opção ficava escondida no menu do cartão.
- Painel do Master, mapa "Onde estamos atuando": a seta de tendência por estado passa a ser o saldo de entradas menos saídas da equipe (`saida_da_equipe`), nos últimos 30 dias e nos 30 anteriores, nos modos "Geral" e "Só primários" (este conta só `era_primario`). Leitura nova `PainelMasterRepository::saidasRecentes`; o JSON segue `{ag, an}`, agora podendo ser negativo. Sem migration.
- Não conta como saída quem continua ativo em outro escritório; escritório em quarentena fica fora; a UF da saída é a da OAB e, sem ela (ou sem usuário), a do escritório; saída sem UF é ignorada; a mesma pessoa conta uma saída só.
- O título da seta deixa de dizer "só entradas" e mostra o saldo com sinal, como no desenho.
- Minhas Metas (desenho 04): a aba Criei agrupa por prazo, com os mesmos grupos, nomes, ordem e cores das outras abas (Atrasadas, Vencem em 7 dias, Depois, Sem prazo, Enviadas para revisão, Concluídas recolhido). Sai o agrupamento por etapa da revisão (`AgrupadorDeMetas::porEtapaDaRevisao`, `AbaMetas::agrupaPorEtapaDaRevisao`). Na aba Criei, a nota de "Enviadas para revisão" passa a "o responsável terminou e enviou para você revisar".
- Trilho: o cartão se chama sempre "Seu andamento" e o "X de Y concluídas", a barra e a legenda (com "em aberto") contam todas as metas da pessoa (universo da aba Todas, concluídas dos últimos 30 dias, sem as pastas do cadeado), sem depender da aba nem dos filtros da tela, também na recarga parcial. Consulta agregada nova `TarefaRepository::contarAndamento` e DTO `AndamentoDasMetasOutput`.
- "Precisa de atenção": as atrasadas de todas as metas da pessoa, da que venceu primeiro, no máximo 4 (`MinhasMetasOutput::TETO_ATENCAO`), sem "Ver todas as N atrasadas" e sem a frase de rodapé. Consulta nova `TarefaRepository::findAtrasadasParaAtencao`, em duas idas ao banco.
- Filtro de prazo com os rótulos do desenho ("Atrasadas", "Vencem em 7 dias", "Sem prazo"; os valores `vencidas`, `proximas` e `sem` não mudam) e botão "Limpar filtros" de texto na linha dos selects, só nesta tela (CSS local; `filtro-tabela.js` e `filtro-tabela.css` sem mudança). Os chips dos filtros ativos não aparecem mais nesta barra.
- Pasta: os erros da tela (prioridade, situação, administrativo sem processo, cliente e processo vinculados, responsável, Financeiro, arquivos, apensados e pedidos de acesso) aparecem no aviso da própria tela, em vermelho, e não mais na caixa do navegador. O conflito de valor no pagamento continua na caixa do navegador, porque a página recarrega em seguida.
- Registro da pasta ganha os cartões do sistema que faltavam (desenho 02, `autoReg`): "Responsável: alterado de "X" para "Y"." (com "Sem responsável" no lado vazio), "Pró-bono: pasta marcada como pró-bono." / "marcação de pró-bono retirada.", "Dados da pasta: dados editados: …" (número, cliente e ação, de → para) e "Contrato: marcado como assinado." / "reaberto como pendente." a cada mudança pelo selo do Financeiro. Cada cartão só sai quando o valor de fato muda, com o tenant da pasta e o autor da ação.
- Responsável e pró-bono saem do `PastaController` legado para `AlterarResponsavelDaPastaUseCase` e `AlterarProBonoDaPastaUseCase` (guarda do escritório no caso de uso); `EditarPastaUseCase` e `AlterarSituacaoContratoUseCase` passam a escrever o cartão. O selo não duplica o cartão do contrato: o "(fase Contrato concluída no andamento)" continua só quando é o andamento que move o contrato.
- Cartões do sistema de pró-bono e de contrato (inclusive os do andamento) são dado financeiro: seguem a aba Financeiro além da Dados (família `registro_financeiro` em `VisibilidadeDasAbasDaPasta`). Somem do Registro e da timeline de quem tem o Financeiro oculto, e as anotações do contexto da I.A. não os levam sem o financeiro (`FonteDeDadosDaPasta::anotacoesDaPasta`, parâmetro `$comFinanceiro`).
- Cartões gravados no mesmo instante saem em ordem de id (`PastaMensagemRepository::findByPasta`). O cartão do responsável nunca leva e-mail: colaborador sem nome aparece como "Usuário sem nome".
- Ícones dos cartões novos no Registro: `bi-person-badge`, `bi-heart`, `bi-folder2-open` e `bi-lock-fill` no cadeado (antes caía no megafone).
- Registros de Detalhes ganham a tag fixa "Observação" (roxo, caixa alta) depois do autor, nos dois moldes do cartão, e o "· Observação" no cabeçalho do menu, na linha da impressão e no PDF do registro (antes "Atendimento"). O "editado" entra depois da tag. A etiqueta variável (Combinado, Ligação, Atendimento) segue pendente (PAS-029).
- Perfis do Escritório: os quatro números do resumo (perfis, usuários, fixos do sistema e personalizados) ficam num cartão só, com o rótulo em cima, o número grande embaixo e o ícone à direita, como no desenho de 21/10.
- Aba Processo (desenho 02): faixa do cartão "Ação · Classe · Tribunal · Situação"; "Copiar/Imprimir resumo" com as linhas do desenho e "Não informado" no que falta (uma fonte só para a tela e o PDF); vincular e desvincular processo escrevem o cartão do sistema na timeline.
- Janela de vincular processo: valor da causa, jurisdição e as flags (segredo, 100% digital, gratuita, tutela, prioridade) gravados como dados manuais do processo; listas de situação, instância e tipos de parte do desenho; textos da busca no CNJ.
- Segurança: os dados do Datajud são escapados antes de entrar nas linhas de partes e movimentações da janela de vincular.
- Pasta, aba Processo: o cadastro de processo novo pela janela "Vincular processo" passa a ser validado no servidor (`CadastrarProcessoPelaPastaUseCase`): número completo no padrão CNJ, órgão julgador, classe e assunto obrigatórios ("Preencha: ..."), situação e instância só nas opções da janela, datas válidas e limites das colunas. A recusa responde 422 com a mensagem e não grava nada.
- Número que já é de um processo do escritório: vincula o existente, não sobrescreve nada e avisa "Este processo já estava cadastrado no escritório. Os dados digitados não foram aplicados.".
- "Buscar no CNJ": o resultado fica guardado no servidor por 30 minutos (por escritório, pessoa, sessão e número) e o cadastro aplica o que a janela não leva (assuntos, sigilo, formato, sistema, códigos, id e o bruto do Datajud). O que a pessoa editou na janela vale mais; nada disso é aceito vindo do navegador.
- "Buscar no CNJ" dentro da pasta vale para quem pode vincular processo nela (rota `POST /pasta/{id}/processo/buscar-cnj`), mesmo sem o módulo de processos. Fora da pasta continua exigindo o módulo.
- "Encaminhar via Chat I.A" no menu (⋮) do cartão do processo.

### Corrigido

- Encaminhar via Chat I.A: o cartão do sistema de pró-bono ou de contrato no Registro da aba Dados passa a seguir a regra do registro do Financeiro (`ItensEncaminhaveisDaPasta::tipoDoAcesso`). Quem envia e quem recebe precisam ver o financeiro da pasta, e a referência do despacho grava `registro_financeiro`, então quem perde o financeiro depois lê "Item de pasta sem acesso". Antes, o cartão chegava a um colega com o Financeiro oculto, pelo próprio ↪ da tela ou por um POST montado à mão.
- A prévia do item (`GET /central/encaminhar/item`) passa a conferir o financeiro, também para o registro do Financeiro: sem ele, 404.
- Processo: o nome do órgão julgador vindo do CNJ (processo e movimentações) não tem mais a acentuação estragada ("1ª Vara Cível" virava "1Âª VARA CÃ­VEL"). O `DatajudProcessoMapper` só converte o texto quando ele não é UTF-8 válido.
- Comando `app:processo:corrigir-acentuacao-do-orgao` para reverter os nomes já gravados (simula por padrão; grava com `--aplicar`).
- Resumo diário por e-mail: a chave do Meu Perfil passa a seguir o descadastro do rodapé do e-mail. Quem se descadastra pelo link vê a chave desligada; religar pelo Meu Perfil volta a receber (só o resumo, mesmo com "não quero receber nada" do escritório); desligar descadastra.

### Segurança

- Link externo do Push: deixa de abrir quando o escritório está suspenso pelo Master, em quarentena ou "suspenso a pedido". Antes, terceiros com o link continuavam vendo o andamento de conta fora do ar.
- Hierarquia de perfis (spec perfis-1.2-permissoes-e-hierarquia, parte C): fecha a escalada em que um secundário com `admin.roles.manage` editava o próprio perfil, marcava qualquer permissão, criava perfis com tudo e excluía o perfil de um colega. Serviço novo `App\Tenant\Service\HierarquiaDePerfis` (níveis: Master 3, primário 2, secundário 1), aplicado no servidor no criar, editar e excluir perfil. O secundário com `admin.roles.manage` fica só com a leitura da lista de secundários; o perfil de sistema sai da lista dele.
- Na lista de Perfis, a lixeira some para quem não pode excluir; o lápis, a linha e "Novo Perfil" de quem não pode mostram o aviso "Seu perfil não pode editar um perfil do mesmo nível ou acima."
- Quem não tem acesso aos Perfis vê a tela "Acesso restrito" (continua 403) com "Pedir acesso": avisa os primários ativos do escritório pela Central, com link para a lista de Perfis, um pedido por pessoa por dia (rota `app_tenant_role_pedir_acesso`, tipo de aviso `perfis_pedido_acesso`). Sem tabela nova: o próprio aviso guarda o pedido.
- Edit-role (`admin.users.manage`) e convite de escritório (`admin.users.invite`): quem não é primário nem Master não troca o próprio perfil, só atribui perfil cujas permissões estejam contidas nas dele e não troca o perfil nem a senha de quem tem permissões que ele não tem, nem o remove do escritório. Quem não é Master também não troca a senha de quem usa outro escritório (a senha vale em todos). Primário e Master sem mudança.
- Painel do Master, Contas e escritórios (parte A): selo com o número da conta (o mesmo "conta Nº 000123" do Contribua e do Pix, agora num ponto comum, `App\Tenant\Service\NumeroDaConta`) e busca "Buscar nº, conta ou primário", que acha o número exato, com ou sem zeros à esquerda.
- Sem migration.

## [2.10.0] - 2026-10-21

### Adicionado

- Google Agenda: os prazos e audiências abertos das pastas vão também à agenda do escritório no Google de quem tem a permissão "Ver prazos na agenda" (de 30 dias atrás a um ano à frente, respeitando o cadeado da pasta). Lá são só leitura: o que for mudado volta como está no BlueJus, e o que for apagado fica apagado até o prazo mudar. Saem do Google quando são cumpridos, excluídos ou quando a pessoa perde a permissão. Tabela nova `agenda_google_prazo`.
- Agenda: sincronia com o Google Agenda nos dois sentidos, numa agenda com o nome do escritório criada na conta Google de cada pessoa (escopo `calendar.app.created`: a agenda principal não é lida nem escrita). OAuth com PKCE, refresh token cifrado, e-mail da conta pelo `id_token`, o do cadastro só sugerido; rotas `/agenda/google/conectar`, `/agenda/google/retorno` e `/agenda/google/desconectar`; sincronia a cada 5 minutos pelo Messenger e logo depois de cada mudança; comando `app:agenda:google-varredura`.
- O compromisso vai também à agenda do escritório de cada participante conectado (cópia só de leitura); criado no Google entra visível à equipe, com os convidados da equipe como participantes; cancelado no Google fica aqui como "cancelado". A mesma conta Google pode ser ligada em vários escritórios.
- Desconectar pergunta se apaga também a agenda do escritório no Google ("Não, manter" marcado) e revoga a autorização quando a conta não está ligada em outro escritório.
- Tabelas `agenda_google_conexao`, `agenda_google_ligacao`, `agenda_google_exclusao` e `agenda_google_varredura`; coluna `evento.importado_do_google` ("Importado do Google Agenda." no detalhe).
- Agenda: permissão de perfil "Ver prazos na agenda" (`resources.agenda.ver_prazos`). Os prazos e audiências das pastas na grade, nos lembretes, no push e no "Avisar no Chat" do prazo passam a seguir essa permissão, e não mais o módulo Pastas; vincular e mostrar a pasta de um compromisso continua com o módulo Pastas. A migration concede a permissão a todo perfil que vê pastas e aos perfis de sistema.
- Aba "Minha conta" no modal "Conta do escritório" (desenho bj-contas.js, VS 18.10.26), só para o usuário primário ou o Master: equipe da conta com PRIMÁRIO/SECUNDÁRIO e a situação de cada pessoa (APROVADO, EM ANÁLISE, SUSPENSO), menu pelo ⋮ ou botão direito para aprovar, voltar para análise, suspender o acesso e demitir (a mesma remoção da tela de equipe), e o formulário de convite. "Convites do escritório" no menu e nas abas do Meu Perfil abre essa aba.
- Coluna nova user_tenant.situacao (migration Version20261020132000); quem está em análise ou suspenso não entra no escritório e vê o motivo na tela de seleção. O acesso passa a conferir as barreiras em ordem (escritório, pedido de exclusão, vínculo, situação). O primário e o Master não têm a situação alterada.
- Drawer da BlueJus IA na pasta com as abas Análise e Relatórios: dez comandos (Raio-X, Prazos, Próximos passos e os demais do desenho 02) e cinco relatórios, cada um rodando no clique e guardando o próprio histórico.
- Cada comando tem a própria fila: pedir de novo com uma análise em andamento ou sem nada novo desde a última não cria outra nem gasta o limite de pedidos.
- As análises dos agentes antigos aparecem no histórico do comando que os substituiu; as rotas por agente continuam funcionando sem misturar com as dos comandos.
- Várias inscrições na OAB por pessoa (desenho 07, VS 18.10.26): uma principal e suplementares, no máximo uma por seccional; card "Registro OAB" com o tipo e a situação de cada uma (Pendente, Confirmada, Não confirmada) e modal "Inscrição na OAB" para adicionar, editar, trocar a principal e remover suplementares.
- Tabela nova inscricao_oab com backfill da OAB atual como principal (migration Version20261020131500); os campos de OAB do usuário passam a ser a cópia da principal. A revisão de OAB do Master continua igual e também atualiza a principal; leitor e UseCases por inscrição prontos para a fila do Master mostrar as suplementares.
- Pasta, aba Documentos: cobrança automática do checklist (desenho 02). Todo dia útil o responsável é cobrado dos documentos pendentes, com no máximo 2 avisos por pasta por dia; procuração e contrato são urgentes; o tom sobe a cada aviso e a controladoria acompanha a partir do 4º.
- Item marcado como entregue sem o documento anexado avisa quem marcou e a controladoria (auditoria).
- Desativar o checklist avisa a controladoria; desativado há 30 dias, o responsável recebe o lembrete de revisar.
- Selo "cobrado Nx" nos itens e a pendência da aba conta os documentos cobrados e ainda não anexados.
- Rotina diária `app:pasta:cobrar-checklist` (cron no `DEPLOY.md`).
- Meu Perfil: a preferência "Sons de alerta" (`perfil.sons`) passa a valer. Desligada, silencia o toque da Central flutuante, o bipe de entrada e saída da presença no cabeçalho e o som do calendário do Dashboard. Ligada, continuam valendo as opções de som de cada um.
- Profile: função Twig `perfil_sons()` (padrão ligado, por usuário e escritório, memória por requisição) e atributo `data-perfil-sons` no elemento raiz de cada um dos três componentes.
- Meu Perfil: ponto de inclusão do selo de apoiador do Contribua (`contribua/_selo_apoiador.html.twig`, opcional).
- Pasta, aba Processo: botão "Vincular apensado" abre o modal de vínculo em modo apenso, com o "Tipo de apenso" (Embargos, Agravo de instrumento, Cumprimento de sentença, Execução, Impugnação, Recurso, Cautelar, Outro); o cartão do apensado mostra "Apensado · Tipo" e o X que o desvincula, com confirmação (desenho 02 VS 18.10.26).
- Pasta: nova coluna `pasta_processo.tipo_apenso`; o vínculo que não é o principal é apensado (sem tipo gravado, "Outro") e apensado nunca vira principal por omissão.
- Pasta, timeline de Dados: cartões do sistema "apensado X (Tipo) vinculado ao processo principal Y." e "apensado X desvinculado.".
- Pasta, aba Dados: a pílula do Processo vinculado passa a contar e listar os apensados, com o tipo, a ação e "classe · tribunal · situação".
- Pasta, aba Push: chips por processo (Todos os processos, Principal e um por apensado, com contagem) antes de Todas/Novas/Geram prazo, e a fonte da movimentação de apensado ganha " · Apensado · Tipo número".
- Pasta: a confirmação de marcar "Administrativo sem processo" com processo vinculado avisa que o andamento passa para a via administrativa.
- Aba Processo: "Editar dados do processo" no próprio cartão (classe, assuntos, jurisdição, órgão julgador, última distribuição, valor da causa e as flags Sim/Não/Não informado de segredo de justiça, juízo 100% digital, justiça gratuita, tutela/liminar e prioridade). O valor manual prevalece sobre o do Datajud, com o selo "manual"; as flags "Sim" viram marcas no cartão; a linha de fonte diz que a integração com o PJe não está configurada. Colunas `*_manual` no `processo` (o Datajud não as sobrescreve) e registro no `audit_log`.
- Segredo de justiça marcado como "Sim" no cartão bloqueia a IA e o link externo do Push, como o sigilo informado pelo tribunal.
- Tabela saida_da_equipe (migration Version20261020136500): toda remoção de pessoa do escritório (tela de equipe, "Demitir" da equipe da conta e "Sair do escritório") grava a data, o motivo e se ela era primária, na mesma transação. Suspender não conta. É a base das saídas do painel do Master.
- Trabalho Externo: "Compartilhar vagas" gera um link público (`/vagas-juridicas/{token}`, só o hash do token é gravado) com as vagas abertas escolhidas, e o modal monta a mensagem para WhatsApp, e-mail, LinkedIn ou copiar.
- Página pública de vagas, sem login: mostra só cargo, local, tipo e áreas das vagas abertas do link; remuneração, escritório, contatos e requisitos não saem do servidor. Visões "Procuro vaga" e "Quero contratar", perguntas frequentes.
- Inscrição "Receba vagas da sua área por e-mail" com consentimento LGPD (data e versão do texto), CSRF e limite de tentativas por IP. Nenhum e-mail é enviado por esta entrega.
- `security.yaml`: `^/vagas-juridicas` com `PUBLIC_ACCESS`. `framework.yaml`: limitador `vagas_alertas`. Migration `Version20261019160801`: tabelas `trabalho_externo_link_vagas` e `trabalho_externo_interessado`.
- "Divulgar vaga(s)": qualquer pessoa que vê a vaga aberta divulga; "Indicadas por" leva o primeiro nome de quem gerou o link. Cada canal usado (WhatsApp, E-mail, LinkedIn, mensagem copiada, link) soma uma divulgação e entra no histórico da vaga (`POST /trabalho-externo/api/vagas/divulgacao`).
- Trabalho Externo: aba Vagas, ao lado de Demandas. A Central publica vagas na hora; o escritório usa "Divulgar uma vaga" e a vaga espera a aprovação da Central (aprovar, não aprovar com motivo, pausar, retomar, marcar como preenchida, encerrar), com histórico da oferta.
- Trabalho Externo: oferta relâmpago com prazo em minutos e cronômetro, visível só para a Central; o período de divulgação e a oferta vencem sozinhos, sem tarefa agendada.
- Trabalho Externo: quem oferece pode esconder dados de contato da vaga; o que fica escondido não sai para os outros escritórios.
- Trabalho Externo: configuração interna das vagas (tempo padrão, divulgação máxima de vagas de escritório, mínimo de candidatos enviados e avisos).
- Migration `Version20261019132051`: tabelas `trabalho_externo_vaga` e `trabalho_externo_vaga_historico` e a coluna `trabalho_externo_configuracao.vagas`.

### Alterado

- Revisão de OAB do Master (`/admin/platform/oab`) passa a listar por inscrição (principal e suplementares), com o selo PRINCIPAL ou SUPLEMENTAR, número/UF, usuário, situação e data da verificação.
- Confirmar, reverter e re-verificar agem pelo id da inscrição (`ConfirmarInscricaoOabUseCase`, `ReverterInscricaoOabUseCase`, `VerificarInscricaoOabUseCase`); a principal segue espelhada no usuário. Id inexistente devolve 404.
- Confirmar e reverter valem para o número e a UF exibidos na fila: se a inscrição mudou depois, a ação é recusada com aviso.
- Ficam sem uso, para remover depois: `UserRepository::buscarParaRevisaoOab` e `contarParaRevisaoOab`, `ConfirmarOabManualmenteUseCase` e `ReverterOabUseCase`.
- Pasta: os prazos estimados lidos dos registros ("prazo de N dias") passam a usar o calendário do sistema (`CalendarioDoSistema` da Agenda) e a UF do escritório (`Tenant::estado`), pelo serviço `DiasUteisDoEscritorio`. Não é dia útil: fim de semana, feriado nacional (inclui Sexta-feira Santa, que faltava), feriado estadual da UF e o recesso. Ponto facultativo (Carnaval, Corpus Christi), dias de tribunal, eleições e comemorativas continuam úteis.
- Prazo em dias corridos que termina em dia não útil passa para o próximo dia útil (CPC art. 224, §1º). Data escrita pelo advogado, audiência, "amanhã" e "hoje" não mudam.
- A regra nova só vale para registros criados a partir de `PRAZOS_CALENDARIO_DESDE` (variável de ambiente, data `AAAA-MM-DD`). Vazia ou inválida, nada muda: o cálculo é o de antes, inclusive na releitura automática dos prazos já gravados. Parâmetro `prazos_calendario_desde` em `config/services.yaml`; linha comentada no `.env`. Sem migration.
- Só passa a valer quando `PRAZOS_CALENDARIO_DESDE` for definida no deploy (o dia seguinte ao deploy); valor inválido desliga e avisa no log.

### Corrigido

- Seletores de responsável (Chamado, Pasta, Meta e Kanban) passam pelo ColaboradoresAtribuiveis: quem está suspenso ou em análise na equipe da conta não é oferecido nem aceito como atribuição nova, e editar um registro mantém quem já estava atribuído (antes o chamado perdia a responsável suspensa ao salvar, e a meta e o Kanban ofereciam suspensos).
- Login: o Master com mais de um escritório volta a cair na seleção do escritório depois de entrar (pelo formulário ou pelo lembrar-me), em vez de receber acesso negado no Expediente.

### Segurança

- Google Agenda: a conexão pede só os escopos da agenda (sem herdar outras autorizações) e recusa e revoga token com permissões além das pedidas; o `id_token` tem cliente, emissor e validade conferidos; sem a chave que cifra o token a integração fica desligada e um retorno sem como guardar revoga a autorização em vez de dar erro; desconectar respeita a trava da sincronia; limitador nas rotas `/agenda/google/*`.

## [2.9.0] - 2026-10-20

### Adicionado

- Página Meu Perfil conforme o desenho 07 (VS 18.10.26): nome em caixa alta com os selos MASTER e USUÁRIO PRIMÁRIO/SECUNDÁRIO, aviso de cadastro incompleto com percentual e o que falta, grade de dados conforme o vínculo (CLT, associado, PJ, sócio, estagiário), copiar e-mail, CPF, CNPJ e telefone.
- Modal "Editar informações" com vínculo com o escritório (só o usuário primário ou o Master troca o tipo), frase do perfil, telefone/WhatsApp e as datas do contrato conforme o vínculo; tabela nova vinculo_no_escritorio e telefone/revisado_em no perfil (migration Version20261020131000). Alterações auditadas.
- Card Preferências (idioma, notificações no sistema, sons de alerta e resumo diário por e-mail, gravadas por pessoa) e revisão mensal do cadastro.
- Trabalho Externo, aba Demandas: marcações pessoais por demanda (seleção em lote, estrela, importância calculada com escolha manual, lixeira, marcar como lida ou não lida, excluir da própria lista e desfazer em 8 segundos). Nada disso muda a demanda para os outros participantes.
- Trabalho Externo, chat da demanda: seleção de mensagens, favoritas, lixeira pessoal que se esvazia sozinha em 30 dias, excluir definitivamente e apagar para todos (pelo autor ou pelo Master).
- Migration `Version20261019120126`: tabelas `trabalho_externo_marcacao` e `trabalho_externo_marcacao_mensagem` e a coluna `trabalho_externo_mensagem.apagada_em`.

## [2.8.0] - 2026-10-20

### Adicionado

- Agenda nova: acertos finais contra o desenho 09: dica ao passar o mouse nos compromissos do Mês, da Semana e do Dia; contagem por calendário em qualquer data; lista de @ reabre ao clicar no campo; menu do dia pelo teclado (Menu, Shift+F10) e por toque longo no celular; botão direito só no Mês, como no desenho; lembretes refeitos em outras abas na hora e clique no push foca a aba aberta; avisos da Agenda na Central com o tipo azul do desenho.
- Agenda nova: a grade mostra também os prazos e audiências cumpridos das pastas, riscados como concluídos; os lembretes continuam só com os abertos.
- Agenda nova: "Avisar no Chat" também no prazo e na audiência de pasta, com o atalho para a pasta (só para quem vê as pastas).
- Agenda: o aviso do colega no Chat sai com o título "Agenda: <compromisso>" e o resumo no corpo (antes, os dois saíam trocados).
- Master: bloco "Nova campanha de e-mail" do desenho 05 na seção "Comercial e e-mails", com os modelos do desenho (convite, vagas, agradecimento, novidades, newsletter e as cópias em blocos dos e-mails das automações) e os modelos próprios do Master: "Novo modelo", "Duplicar", "Importar HTML", "Salvar modelo" e "Excluir", com trilha de auditoria sem o conteúdo.
- Master: editor de blocos (título, texto, imagem, botão, notícias, 2 colunas e divisor), prévia do e-mail que atualiza enquanto se digita, "Baixar HTML" e "Enviar teste" (só para o próprio Master, até 10 por hora).
- E-mails: o HTML importado é sanitizado ao salvar e em toda montagem (sem script, eventos, links que não sejam http(s) ou mailto, e estilos que buscam recursos de fora) e sai sempre com o rodapé legal.
- E-mails: o Carteiro ganha o layout do conteúdo (padrão, linhas e cru); automações e demais consumidores seguem no padrão, sem mudança no que enviam.
- Master: no bloco "Nova campanha de e-mail" do desenho 05, a escolha do público (Base de contatos, Usuários primários, Usuários secundários, Todos os cadastrados, Importar lista CSV e Individual), a contagem de destinatários sem repetidos e sem descadastrados, a frequência (envio único, semanal, quinzenal ou mensal), o agendamento e o botão "Enviar campanha", com confirmação.
- Master: bloco "Monitoramento" com a lista das 12 últimas campanhas (nome, modelo, público, data, total e estado); os números de entregas, aberturas e cliques ficam para a próxima fatia.
- E-mails: as campanhas saem pelo servidor em lotes de até 10 por minuto, com teto diário próprio (1.000 por padrão) e sem acumular fila; nada atrasado é recuperado (agendamento com mais de 24 h de atraso é cancelado, a recorrente pula para a próxima data).
- E-mails: no CSV e no Individual só entra quem tem conta ativa ou confirmou o endereço na lista de campanhas; a regra vale de novo a cada envio da recorrente.
- E-mails: a chave `COMERCIAL_CAMPANHAS_LIGADAS` nasce desligada (recusa o envio e, desligada, cancela as campanhas ativas) e o comando `app:comercial:encerrar-campanha <id>` encerra uma campanha sem deploy; o que já estava na fila não sai.
- LGPD: destinatários de campanha com mais de 24 meses ficam com o endereço em hash e sem o nome.
- Indicar o BlueJus: a conta criada pelo "Criar minha conta" por quem chegou pelo link curto `/i/{codigo}` fica ligada a quem indicou (colunas novas `indicacao.indicado_user_id`, uma vez por conta com índice único parcial, e `indicacao.por_cadastro`, que mantém a atribuição fora dos envios mesmo se a conta nova for apagada).
- Ouvinte do evento `ContaCriadaPeloCadastro` no domínio Indicação: lê o código da sessão, tira a chave depois de usar e nunca derruba o cadastro (falha só vai para o log). Código inexistente, de pessoa inativa ou da própria conta nova é ignorado; a pausa não impede, porque atribuir não envia nada.
- A atribuição conta em "Viraram conta" e em "Suas indicações" (cada conta uma vez só), mas não nos limites, no alerta de disparo alto nem nas contagens de envio do painel.
- Indicar o BlueJus: item no menu do usuário (para todos os logados, com ou sem escritório) que abre a janela do desenho, com convite por e-mail (modelo fixo pelo Carteiro, lista `indicacao`, com descadastro), WhatsApp e cópia do link curto `/i/{codigo}`, e a lista "Suas indicações" com "Conta criada" ou "Enviada".
- Regras: cadastro completo para indicar (nome com duas palavras e CPF; o telefone passa a valer quando o Meu Perfil tiver o campo), pausa global, pausa por pessoa, limite de 20 por dia e 200 por mês em janela móvel, contagem travada por pessoa contra envio simultâneo.
- Alerta "Disparo alto de convites" no sino dos Masters quando a contagem de 24h de alguém cruza o alerta configurado.
- Domínio novo `App\Indicacao` (tabelas `indicacao`, `indicacao_codigo`, `indicacao_config`, `indicacao_pausa`, `indicacao_alerta`) e os UseCases do card "Indicações do BlueJus" do Painel do Master (a tela do card chega em outra entrega).
- Link curto `/i/{codigo}` público: guarda o código na sessão e leva ao cadastro.
- Relatório mensal de pastas paradas (GET /expediente/pastas-paradas e /pdf, módulo Expediente): as pastas ativas sem movimentação há mais de 30 dias, da mais parada para a menos, com quatro cartões (pastas paradas, mais de 90 dias, sem contrato, sem processo), a tabela-resumo e um cartão por pasta com nível (Acompanhar, Atenção, Crítico), pré-análise (abertura, última movimentação, andamento, processo, contrato, documentos e financeiro) e sugestão. A última movimentação é a mais recente entre as movimentações dos processos, o Push, os registros de pessoas e a entrada na fase atual do andamento; sai da lista a pasta com andamento encerrado ou, sem andamento, com o processo arquivado. Cada leitor vê só as pastas que pode ver, e o financeiro só aparece para quem pode vê-lo.
- Envio automático pelo comando app:pastas:relatorio-pastas-paradas (primeira segunda-feira do mês, a partir das 10h, uma vez por escritório; --dry-run e --tenant): avisa no sino e na Caixa da Central (tipo relatorio_pastas_paradas) cada responsável com as pastas dele e a Controladoria e os primários com a lista, só quem tem o módulo Expediente. O mês é gravado antes dos avisos (tabela relatorio_pastas_paradas_envio, INSERT com ON CONFLICT), então duas execuções não duplicam. Linha do cron em DEPLOY.md.

## [2.7.0] - 2026-10-20

### Adicionado

- Agenda: lembretes por push com o navegador fechado (Service Worker `sw-lembretes.js`, inscrição em `POST /agenda/push/inscrever`, varredura a cada minuto pelo Messenger sem cron, envio sem repetição); comando `app:agenda:push-varredura`; tabelas `agenda_push_inscricao`, `agenda_push_enviado` e `agenda_push_varredura`; dependência `minishlink/web-push`.
- Master: seção "Comercial e e-mails", com o bloco "Automações" do desenho 05: ligar e desligar cada automação, escolher o atraso, "Simular novo cadastro", "Últimos disparos" (com o destinatário mascarado) e a prévia de cada modelo.
- E-mails: envio pela fila própria do Comercial, com limite por minuto e por dia, rodapé legal em todo e-mail, link "Não quero mais receber" e cabeçalhos de descadastro em um clique.
- E-mails: boas-vindas ao confirmar o cadastro e ao aceitar um convite, uma vez só por pessoa e escritório; dicas 7 dias depois (nascem desligadas).
- LGPD: confirmação de endereço (double opt-in) reaproveitável por qualquer lista, com a prova do consentimento guardada; o rastreio de aberturas e cliques fica desligado até a Política de Privacidade citá-lo.
- Explorador de documentos da pasta: o menu de um item ganha "Encaminhar via Chat I.A" (desenho 02, D:5071), logo depois de "Copiar caminho". Numa pasta do explorador encaminha a seção; num arquivo, o documento. Abre a tela "Despachar via Chat I.A" da Central. Só aparece com a Central na página e fora da pasta excluída.
- "Encaminhar via Chat I.A" nos registros da pasta (desenho 02, D:1405, D:1936, D:2463, D:6305, D:6869 e D:6958): botão ↪ espelhado no cartão de registro das abas Dados, Detalhes e Financeiro, item no menu do registro (Dados e Financeiro, depois de Copiar texto), no ⋮ da observação de Detalhes (entre Copiar texto e Citar no novo registro) e no ⋮ do arquivo do Financeiro (depois de Copiar nome do arquivo). O clique dispara `bj-central:despachar` com `{pastaId, tipo, itemId}` (`public/js/pasta-encaminhar.js`); só aparece com a Central disponível e fora da pasta excluída.
- "Encaminhar via Chat I.A" nas metas e nos prazos (desenho 02, D:3737 e D:6561): item no ⋮ da meta da aba Metas (depois de Editar nome, antes de Copiar link), no ⋮ do prazo do trilho "Próximos prazos" da aba Dados (logo depois de Ver nas metas; a meta vai como `meta`, o prazo registrado como `prazo`). O clique dispara `bj-central:despachar` com `{pastaId, tipo, itemId}`; só aparece com a Central disponível e fora da pasta excluída.
- Minhas Metas: item "Encaminhar via Chat I.A" por último no ⋮ de cada cartão (R223). O clique dispara `bj-central:despachar` com a pasta e a meta; só aparece com a Central disponível e fora da pasta excluída.
- Pasta, aba Metas: em "Responsáveis nas metas", cada pessoa vira um botão que abre a conversa 1:1 no Chat I.A (desenho 02, L.1686; `bj-central:conversar`). O resumo agrupa pelo usuário, não mais pelo nome.
- Tela "Despachar via Chat I.A" na janela da Central (desenho BC:6218-6238): cartão do item relido pelo servidor (`GET /central/encaminhar/item`), instrução, prioridade Normal, Urgente ou Para hoje e "Para quem?" só com quem vê a pasta; ao escolher, envia, abre a conversa e avisa "Despachado para NOME.".
- API da janela `window.BJCentral` (`abrir`, `despachar`, `conversar`) e os eventos `bj-central:despachar` e `bj-central:conversar`, onde o lançador flutuante está na página. Os itens de menu nas telas da pasta vêm nos próximos PRs.
- Mensagem de despacho mostra o chip "Pasta N" (BC:6041), que abre a pasta, a partir da referência do servidor; despacho de pasta sem acesso aparece apagado como "Item de pasta sem acesso".

### Corrigido

- Login: o painel de contas do menu e os mapas do Master não viram mais destino depois de entrar; um quadro interno com a sessão vencida mostra a tela de login em vez de uma mensagem técnica.
- Login: depois de entrar, o sistema só volta para uma página de verdade (Expediente, Agenda, pasta, Clientes, Cobrança e as demais telas); endereço de dados, download ou exportação leva à página inicial.
- Login com várias abas abertas: um aviso automático de outra aba não apaga mais a página que a pessoa tentava abrir.
- Sessão vencida: o sino de notificações e o alerta do ponto param de consultar o servidor até a pessoa entrar de novo.
- Cadastro do cliente: o seletor de autores da pasta tem ordem fixa (o principal primeiro, depois o nome e, no empate, o id). Antes a ordem vinha da tabela de ligação sem ordenação e podia trocar entre aberturas, o que também deixava instável o teste `ClienteCadastroControllerTest::testAutores`.

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

[Não lançado]: https://github.com/sfbdata/Prime/compare/v2.12.0...HEAD
[2.12.0]: https://github.com/sfbdata/Prime/compare/v2.11.1...v2.12.0
[2.11.1]: https://github.com/sfbdata/Prime/compare/v2.11.0...v2.11.1
[2.11.0]: https://github.com/sfbdata/Prime/compare/v2.10.0...v2.11.0
[2.10.0]: https://github.com/sfbdata/Prime/compare/v2.9.0...v2.10.0
[2.9.0]: https://github.com/sfbdata/Prime/compare/v2.8.0...v2.9.0
[2.8.0]: https://github.com/sfbdata/Prime/compare/v2.7.0...v2.8.0
[2.7.0]: https://github.com/sfbdata/Prime/compare/v2.6.0...v2.7.0
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
