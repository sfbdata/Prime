# Changelog

Todas as mudanças relevantes do BlueJus ficam registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota o
[Versionamento Semântico](https://semver.org/lang/pt-BR/). A política de versões, os critérios de
cada nível e o procedimento de release estão em [docs/versionamento.md](docs/versionamento.md).

## [Não lançado]

## [2.25.0] - 2026-10-28

### Alterado

- Quem abre o BlueJus por www.bluejus.com.br passa a ser levado para bluejus.com.br (redirecionamento 301, HTTP e HTTPS), o endereço canônico. Corrige o erro 400 "redirect_uri_mismatch" ao entrar com o Google, que acontecia só pelo endereço com www.

## [2.24.0] - 2026-10-28

### Alterado

- Agenda: /agenda volta a mostrar a tela de 18/10; a tela 1A fica guardada em /agenda/1a, sem link. Tipos novos, local e descrição seguem valendo.

## [2.23.0] - 2026-10-28

### Adicionado

- Meu Escritório refeito conforme o desenho 13 (28/10): cartão "Informações do Escritório" com logo, código da conta, nome, CNPJ ou CPF (com conferência dos dígitos), registro na OAB, e-mail, telefone, endereço, data de criação e situação, edição no próprio cartão e o rodapé "Alterado por X em".
- Só o usuário primário (e o Master) edita os dados e a logo, conferido no servidor; a tentativa de um secundário é recusada e fica na trilha de auditoria.
- Novo cartão "Backup de arquivos" ligado à conexão do Google Drive que já existia, e cartão "Excluir conta" com senha do primário e nome do escritório, que segue para o pedido de exclusão já em vigor (guarda de 365 dias).
- Migration `Version20261028150000`: colunas novas e nulas em `tenant` (OAB, e-mail, telefone, logo, quem e quando alterou, código da conta) e índice único parcial. Sem UPDATE e sem DROP. O código da conta é gerado no primeiro acesso à tela.
- Nova categoria de arquivo `TENANT_LOGO` (logo do escritório, guardada por chave, apagada junto com o escritório).

### Alterado

- Cabeçalho da pasta: o botão "BlueJus Intelligence" virou "BlueJus IA". O nome aparece letra a letra, o símbolo da marca entra e sai e fica o ícone IA. Com o painel da IA aberto, o botão recolhe para só o ícone.
- Aba Perguntar da IA: o carregamento da resposta ganhou o cartão novo, com faixa que varre, símbolo girando, passos (consultando, lendo, redigindo), frases e barra de progresso. Os passos avançam por tempo decorrido, não por etapas reais do servidor.
- Linha do tempo da pasta: o título só aparece quando o mouse passa pela faixa.

### Corrigido

- Pastas: na coluna Cliente, o cliente principal de uma pasta comum passa a aparecer pelo nome fantasia (senão a razão social ou o nome completo), a mesma regra das pastas judicializadas. Cadastros com razão social composta ("a - b") deixam de aparecer assim na lista do Expediente.
- Pastas: o cartão da lista de demandas, os cartões e a tela de Metas, o resumo do processo, o financeiro da pasta, o relatório de registros do Dashboard, o LEIA-ME do ZIP de documentos e as análises de pastas paradas e sem processo passam a mostrar o mesmo nome da coluna Cliente, e não mais o texto composto gravado nas pastas antigas.
- O comando `app:cobranca:regravar-nome-pasta-judicial` também alinha as pastas que não são judiciais, têm cliente principal e ainda guardam texto composto ("a - b"): passam a guardar o nome do cliente principal. Simula por padrão e é idempotente.

## [2.22.0] - 2026-10-27

### Adicionado

- Contribua C3: assinatura mensal de espaço no cartão. "Quero +N GB" abre o checkout "Pagamento seguro"; no Asaas o cartão é digitado na página segura do gateway (cliente por escritório e checkout recorrente pela API).
- O webhook do gateway (`/api/webhooks/<gateway>`) só avisa: o BlueJus reconsulta a assinatura antes de mudar o espaço. Conciliação de hora em hora (`app:contribua:conciliar-assinaturas`).
- Modal "Pagamento por cartão · só Master": gateway, chave pública, links por pacote (fora do Asaas), página de retorno, carência da inadimplência (7 dias) e "Confirmar pagamento" manual.
- Cancelar no bloco "Assinatura" do Meu Perfil: o espaço vale até o fim do mês pago e nada é apagado.
- Limite próprio do Master nunca deixa um plano pago com menos espaço ou usuários do que o plano.
- Contribua C4: integração bancária do Master com a Cora (Integração Direta, mTLS, token de 24 h; certificado e chave cifrados com `CONTRIBUA_CRYPTO_KEY`, a tela mostra só os nomes); PIX dinâmico por conta (QR Code Pix v2) no lugar do estático quando a integração está conectada, com volta ao estático sem CNPJ, abaixo de R$ 5 ou com falha do banco; webhook `/api/webhooks/cora/pix` só como aviso, com reconsulta mTLS que confirma o apoio uma vez; conciliação `app:cora:conciliar`; prêmio do apoiador na página Contribua; interface `BancoPix` para Inter, Banco do Brasil e API PIX do BACEN depois. Tabelas `contribua_integracao_bancaria` e `contribua_cobranca_pix`; colunas novas em `contribua_apoio`.
- Chat com o BlueJus IA na tela de uma pasta: novo interruptor "Incluir outras pastas do cliente" na barra da pasta (desligado por padrão, vale só na conversa aberta). Ligado, a IA lê também as outras pastas dos clientes da pasta aberta que a pessoa pode ver (no máximo 5 pastas, a aberta primeiro), e a barra mostra o aviso de dados pessoais.
- O servidor decide quais pastas entram: mesmo escritório, não excluídas, só as que a pessoa vê (cadeado), financeiro e abas decididos pasta a pasta. Pasta em segredo de justiça é omitida e a IA recebe só "N pastas não puderam ser consultadas". Pasta aberta em segredo continua recusada.
- O escritório precisa ter dado o aceite da BlueJus IA; sem ele a pergunta com outras pastas responde 409 `cruzamento_sem_consentimento`.
- `POST /central/ia/perguntas` aceita `escopo` (`pasta` ou `clientes`; `clientes` exige `pastaId`). Prompt `chat-central-v3`. Sem migration.
- Chat com o BlueJus IA: a pergunta pode levar o número da pasta que a pessoa tem aberta na tela (`pastaId`). O servidor confere se a pasta é do escritório e se a pessoa pode vê-la (cadeado e permissão), recusa pasta com processo em segredo de justiça e só então envia à IA os dados da pasta (cadastro, processos, movimentações, metas, anotações, documentos e, se a pessoa pode ver, o financeiro). Sem `pastaId`, tudo segue como antes.
- Pasta inexistente, de outro escritório ou oculta para a pessoa: o mesmo aviso fixo "Não encontrei essa pasta ou você não tem acesso." (404). Pasta com segredo de justiça: recusada (409). Nada é gravado nessas recusas.
- Antes de chamar a IA o sistema confere de novo o acesso: quem perdeu o acesso à pasta (ou ao financeiro) entre a pergunta e a resposta tem a pergunta encerrada sem chamar a IA.
- Com pasta, a IA só recebe as perguntas anteriores da própria pessoa, nunca as respostas antigas dela (que podem ter lido outra pasta). Prompt novo `chat-central-v2`; o `chat-central-v1` segue para perguntas sem pasta.
- Registro da análise guarda só números e marcas (pasta, financeiro, abas ocultas), nunca texto nem número de processo. Nada vai para log nem auditoria.
- Backend apenas: ainda sem tela (a barra "PASTA N · cliente" e a sugestão "Faça um resumo desta pasta" vêm no 4b). Sem migration.
- Chat com o BlueJus IA na tela de uma pasta: a conversa mostra a barra azul "Pasta {NUP}" com o nome do cliente e o botão "Abrir pasta". Enquanto a barra existe, a pergunta leva o contexto da pasta (o servidor revalida acesso, escritório e segredo de justiça).
- Estado vazio ganha a sugestão "Faça um resumo desta pasta", só com a barra. As outras três continuam.
- Nova rota `GET /central/ia/pasta/{pastaId}` (só NUP, cliente principal e link; pasta inexistente, de outro escritório, excluída ou sem acesso responde o mesmo 404; sem cache).
- Pergunta recusada (segredo de justiça ou pasta sem acesso) mostra o aviso fixo do servidor; no 404 a barra some.
- Sem migration.
- Chat com o BlueJus IA na Central: nasce o caso de uso da pergunta (`PerguntarABlueJusIaUseCase`). Confere vínculo, texto (1 a 2000 caracteres) e disponibilidade da IA (plataforma, escritório, módulo, cota, orçamento), grava a mensagem da pessoa, cria a análise e a enfileira só depois do commit. Pergunta já em andamento devolve a mesma, sem gastar cota. Ainda sem tela nem rota (vêm no PR seguinte).
- Retenção: a rotina diária `app:inteligencia:expirar-conteudo` passa a apagar também as mensagens do chat com a IA (pergunta e resposta) anteriores ao prazo escolhido pelo Master. A saída e o log do comando trazem só números.
- Sem migration.
- Chat com o BlueJus IA na Central, parte do servidor: a conversa com a IA passa a aparecer só para o próprio dono (lista de conversas, histórico, não lidas e novidades). A lista sempre traz a conversa e a cria na primeira vez. Mensagem comum nessa conversa é recusada: nela só se pergunta.
- Rotas novas: `POST /central/ia/perguntas` (faz a pergunta; 201 nova, 202 se já há uma em andamento; só devolve ids e estado, nunca o texto) e `GET /central/ia/perguntas/{id}` (estado da pergunta, só do próprio usuário). Mesmo limite de pedidos por pessoa da aba Perguntar da pasta.
- A Equipe e os destinos do Encaminhar seguem sem a conversa com a IA: o uso do chat é pessoal e não aparece a colegas.
- Pergunta sem resposta há mais de 10 minutos (fila parada) deixa de bloquear o chat: a próxima pergunta a encerra como falha e segue.
- Sem migration.
- Chat com o BlueJus IA na Central: a conversa "BlueJus IA" aparece fixa no topo da lista (avatar do desenho, prévia e não lidas). Abre como as outras, com cabeçalho "Assistente · inteligência do BlueJus", sem ligar nem informações de grupo, e menu só com lida/não lida e silenciar.
- Perguntar: o campo envia por `POST /central/ia/perguntas`; enquanto a resposta não chega aparece "digitando..." e o campo fica travado (sem custo duplo). A resposta chega pelo polling como mensagem do BlueJus IA (selo IA, fundo verde claro, alinhada à esquerda). Pergunta que falha ou fica indisponível mostra o aviso fixo do servidor, nunca texto da IA.
- Estado vazio com três sugestões clicáveis e aviso fixo de que a resposta é gerada por IA e pode errar. Textos em `central.ia.*` (translations), entregues à tela por `data-ia-*`.
- A lista repete uma vez o pedido quando duas abas criam a conversa ao mesmo tempo (409).
- Sem migration.
- Configurações globais do Master passam a ter efeito: novo leitor `ConfiguracaoDaPlataforma` (valor gravado, senão o padrão do catálogo; nunca derruba a página).
- Cadastro aberto, só por convite ou fechado: com "Só por convite" ou "Fechado", as três portas públicas (e-mail, Google, Facebook) respondem 403 com a mensagem da política e nada é criado; a tela de entrada esconde o "Criar conta" e mostra o aviso em `/cadastro`, `?abrir=cadastro` e `/i/{codigo}`.
- Google e Facebook sem conta, com o cadastro fechado ao público, voltam ao login com o aviso e não guardam a identidade.
- Com "Fechado", também não nascem contas por convite de escritório nem do Trabalho Externo; o convite da plataforma (emitido pelo Master) continua. Login de quem já tem conta não muda. Padrão "Aberto": nada muda.
- Login: o limite de tentativas de entrada com senha errada passa a vir da configuração "Tentativas" do Master (Segurança), de 1 a 50, padrão 5. Antes era 5 fixo no `security.yaml`.
- Novo `App\Security\LimitadorDeTentativasDeLogin` (`login_throttling.limiter`): lê o valor a cada requisição, mantém a janela fixa de 15 minutos e o limite por IP em 5 vezes o limite do usuário+IP. Valor ausente, inválido ou falha na leitura valem 5; o login nunca cai por causa da configuração. Sem migration.
- A mudança vale para as próximas janelas de contagem; a janela já aberta termina, em no máximo 15 minutos, com o limite com que começou.
- Nova meta da pasta: bloco "Análise do prazo" (desenho 02). A tela estima o prazo jurídico pelo tipo da providência (tabela do CPC, dias úteis, feriados e recesso), mostra data, termo inicial e fonte, a folga e a prioridade, e sugere o prazo interno.
- A meta só é criada depois de confirmar a análise, informar o prazo jurídico ou corrigir as informações. Datas de publicação e ciência podem ser informadas.
- Migration `Version20261027153400`: a meta guarda prazo jurídico, estado, datas de origem e se usou o prazo sugerido.
- Aba Modelos da pasta (antes "Detalhes"): banco de modelos de petições do escritório, com lista, busca (título, tipo, matéria e texto), filtros por matéria e tipo, ordenação, estrela nos modelos e nas matérias (por usuário), visualização em folha A4 e editor de texto. Quem pode editar a pasta cria, edita, duplica e exclui; quem só vê, consulta. O Relatório inicial da pasta continua na aba, agora recolhido embaixo. Entram 3 tabelas novas (migration aditiva). Usar o modelo na pasta, variáveis, Word e importação ficam para as próximas etapas.
- Aba Modelos da pasta: ao abrir um modelo, dá para alternar entre "Modelo" e "Preenchido com esta pasta". As variáveis {cliente}, {cpf}, {processo}, {pasta}, {cidade}, {data}, {advogado} e {oab} vêm da pasta e podem ser ajustadas antes de gerar; o que não tem dado aparece como ___ para completar. Botões novos: Copiar, Word (arquivo .docx de verdade) e "Usar na pasta", que guarda a minuta preenchida nos documentos da pasta e conta o uso do modelo. Quem só consulta a pasta pode copiar e baixar; usar na pasta exige poder editá-la. Entra 1 tabela nova (migration aditiva). Salvar em Documentos/Metas, checklist, teses, timbrado e importação ficam para as próximas etapas.
- Painel BlueJus Intelligence, perfil individual: botão "Criar acompanhamento" com confirmação (prazo de revisão de 24 horas, 48 horas ou 7 dias), chip "Em acompanhamento" (ou "Revisar acompanhamento" quando o prazo passa), "Renovar prazo" e "Encerrar acompanhamento". Só o Master e os usuários primários criam, renovam e encerram; nada é criado sozinho e a pessoa acompanhada não recebe aviso. Nova tabela `dashboard_acompanhamento_pessoa` (sem texto livre) e auditoria `acomp_cria`, `acomp_rnv` e `acomp_fim`.
- Painel Intelligence, perfil da pessoa: a comparação com o período anterior ganha Conclusões e Tempo médio, contados pela data de conclusão das metas nos dois períodos (e não pela criação com o status de hoje, que inflava o anterior). Sem conclusão registrada antes do período anterior, a comparação não é feita e o limite é declarado. Zerar e cadeado valem nas duas pontas.
- O perfil passa a ter Matriz de desempenho, leituras com as etiquetas Observado, Correlação e Hipótese, fatores, "O que isso pode causar?" e Plano de ação sugerido, todos por regras fixas sobre números reais do período (a regra de cada frase está na spec dashboard-parte-1, bloco PR-M). Apoio ao gestor: nada vira nota ou decisão sobre a pessoa. Sem migration.
- As setas de Metas concluídas, Taxa de conclusão e Tempo médio na tabela seguem retiradas: o número da célula é por criação e a comparação honesta é por conclusão.
- Painel, BlueJus Intelligence: com a meta de pastas do mês definida e o painel no mês inteiro (sem responsável nem cargo), o painel ganha o selo do ritmo (Ritmo adequado, de atenção, abaixo do necessário ou crítico), o gauge de quatro faixas, o "Ritmo atual" e o "Ritmo necessário", contados em dias úteis. Sem meta ou em outro recorte, o bloco não aparece.
- Painel, Central de Inteligência: cada alerta ganha os botões Resolvido, Ignorar, Aplicar e Não se aplica. A marca é pessoal (cada usuário vê a sua), fica gravada no servidor (chave `dashboard.ia_alertas` em `preferencia_usuario`, sem migration), esmaece o alerta e o leva para o fim da lista; tocar de novo desfaz. Novo endpoint `POST /dashboard/inteligencia/alerta`.
- Perguntas guiadas: abre uma resposta por vez.
- Fora desta entrega, de propósito: "Criar tarefa" (não existe tela de tarefa avulsa para receber o título) e "simular dia" (controle de teste do protótipo).
- Dashboard, painel BlueJus Intelligence: novo bloco "Aumentar o nível" (desenho 01 VS 25.10.26, dc L1284-1325). Quatro níveis (Manter, +10%, +20%, +35%) sobre a meta de pastas do mês: nova meta, ritmo por dia útil, viabilidade, resumo, meta por colaborador (pelas pastas que cada um já criou), marcos por semana útil e alavancas. Tudo por regras fixas sobre dado real, sem IA e sem migration.
- O bloco é só do usuário primário (quem define a meta) e só aparece com meta definida, no mês inteiro, sem filtro de responsável ou cargo e sem pasta oculta pelo cadeado.
- "Definir X como meta do mês" grava a meta pelo mesmo POST `/dashboard/meta-pastas` (1 a 9999, CSRF, módulo bi, 403 para quem não é primário).
- Painel do Escritório: cada linha da tabela Desempenho ganha o botão "Análise de desempenho de {nome}". Ele abre o painel BlueJus Intelligence no modo individual, com "← Equipe", o nome da pessoa no título e o perfil dela, por regras fixas (sem IA): Recebidas, Concluídas, Pendentes, Atrasadas, Prazos próximos e Taxa de conclusão, comparação com o período anterior, diagnóstico com a tag "Observado" e "Por que chegou a essa conclusão".
- Os números saem do mesmo cálculo da tabela (cadeado e "Zerar" já aplicados) e levam às tarefas da pessoa quando a lista conta o mesmo número. O que o sistema não registra (prazos cumpridos, situação, causa, plano de ação) não aparece.
- Só o Master ou um primário ativo abre a análise; quem não pode vê o botão bloqueado ("Seu perfil não pode abrir análises individuais"). "Marcar como analisado" e "Essa análise ajudou?" guardam a marca de cada usuário; cada visualização e cada marca entram no registro de auditoria (`ia_pessoa`, `ia_marca`). Novas rotas `GET /dashboard/inteligencia/pessoa/{id}` e `POST /dashboard/inteligencia/pessoa/{id}/marca`. Sem migration.
- Painel BlueJus Intelligence, perfil individual: botão "Redistribuir" (aparece quando a pessoa tem meta ativa e está acima da média de metas do escritório). Abre uma janela com as metas ativas da pessoa, todas desmarcadas, com uma sugestão de destino por regra fixa (quem tem menos metas ativas, sem IA e sem avaliação de desempenho) que o gestor pode trocar. Só "Confirmar redistribuição" grava, com no máximo 50 metas por vez. Só o Master e os usuários primários redistribuem. Cada meta ganha um registro no histórico ("Responsável alterado de A para B por C."), a auditoria guarda `meta_redis` e `ia_redis`, e quem recebe e quem entrega recebem um aviso neutro. Sem alteração de banco.
- Painel BlueJus Intelligence: rodapé com o controle "Teste, simular dia" do desenho. Arrastar o controle recalcula o "dia X de Y", o dia útil, o selo, o gauge e o ritmo necessário como se hoje fosse outro dia do período; o painel avisa que é simulação.
- A simulação é só de leitura (parâmetro `dia_simulado` da tela): não grava nada, não muda o "hoje" do sistema, só vale dentro do período e até o fim do mês, e os números realizados continuam os de hoje. Fechar o painel desfaz. Pasta oculta pelo cadeado continua fora do selo. Sem migration.
- Painel: a foto diária do estoque (`dashboard_foto`) passa a guardar também metas em revisão, pastas urgentes e total de metas (migration `Version20261026152200`: três colunas anuláveis, sem preenchimento do passado).
- Painel: Metas ativas, Demandas ativas, Em revisão e Pastas urgentes ganham a seta de tendência (e a pílula no Total) comparando o estoque de hoje com a foto do dia anterior ao período. Sem foto, a seta fica oculta.
- Painel: o histórico mensal dos cards Metas ativas, Demandas urgentes e Meta global passa a mostrar a série a partir da primeira foto; meses sem foto aparecem vazios ("sem histórico").
- Metas concluídas, Taxa de conclusão e Tempo médio seguem sem seta, de propósito: o período anterior distorceria a comparação.
- Painel BlueJus Intelligence, Modo avançado: cada alerta com ação sugerida (exceto o nível Normal) ganha o botão "Criar tarefa" (desenho 01, dc L967), só para quem acessa o módulo de tarefas.
- Como toda tarefa pertence a uma pasta, o botão abre antes um seletor de pasta (busca por número, cliente ou ação, a mesma do Push: só pastas do escritório, sem excluídas e só as que a pessoa edita no cadeado). Ao escolher, abre a pasta com o modal "Nova meta" já preenchido com a ação e o resumo do alerta, e com o responsável que o alerta nomeia marcado (quando ele está na lista do escritório). O prazo e a criação seguem as regras de sempre.
- Nova rota `GET /dashboard/inteligencia/pastas-para-tarefa` (módulos bi e tarefas, 403 sem eles) e `BuscarPastasParaTarefaDoAlertaUseCase`. Sem migration.
- Meu Perfil, "Editar informações": novo campo "Chave PIX para recompensas" (desenho 07), depois do telefone. Aceita CPF, CNPJ, e-mail, celular ou chave aleatória, conferidos pelo formato; vazio vale. A chave só aparece no próprio modal de edição (não entra na grade de dados, na revisão do cadastro nem na completude) e a tela de dados pessoais do admin não a apaga.
- Nova coluna nula `user_profiles.chave_pix_recompensa` (migration `Version20261027153900`); nenhum dado existente é alterado.
- Painel do Master, Recompensas, "Dados para pagamento": quando o indicador informou uma chave PIX no Meu Perfil e o Master ainda não cadastrou a dele, o diálogo mostra "Chave informada pelo indicador: X" com o botão "Usar". Só copia para o campo; o Master confere e salva. A lista do painel não mostra a chave do perfil. Sem migration.
- Painel do Master, seção "Recompensas": antifraude. Uma conta indicada fica "Em análise" e NÃO conta para a recompensa (nem como ativa, nem em carência) quando pertence ao mesmo escritório de quem indicou, tem o mesmo CNPJ de escritório ou o mesmo domínio de e-mail corporativo (provedores públicos não valem: gmail, hotmail, outlook, yahoo, icloud, bol, uol e, ampliando o desenho, live, msn, terra, ig, globo, globomail, proton, protonmail e zoho; lista a confirmar com o dono). CPF e aparelho, que o desenho também cita, não são guardados pelo BlueJus e ficam para depois de a decisão de LGPD.
- Menu ⋮ do indicador ganha "Revisar suspeitas e aprovar" (só aparece com suspeita): mostra a quantidade e os motivos, sem nome nem e-mail de quem foi indicado, e aprova todas as pendentes de uma vez; se a lista mudou desde que o Master abriu o diálogo, a aprovação é recusada para ele revisar de novo. Só contam vínculos ativos e escritórios não excluídos. A aprovação é por conta, fica na auditoria e repetir não grava de novo.
- Situações da lista, na ordem do desenho: Bloqueado, Em análise, Alerta (contas aceitas nos últimos 7 dias iguais ou acima do alerta do programa; só sinaliza), Liberado, em carência e Em andamento, com os motivos no balão.
- "Bloquear recompensa" e "Desbloquear recompensa": o indicador mostra "Bloqueado" e sai do filtro "A pagar"; saldo e extrato não mudam. "Apagar da lista" exige digitar APAGAR (conferido também no servidor): o indicador sai da lista e dos KPIs e o extrato continua guardado. Ainda não há tela para desfazer o "apagar" nem nova indicação o traz de volta.
- "Voltar à regra geral" não desfaz mais bloqueio nem apagado. Novas rotas só do Master, com CSRF: aprovar suspeitas, bloqueio e apagar.
- Nova tabela `aprovacao_de_conta_de_recompensa` e duas colunas nulas (`bloqueado_em`, `apagado_em`) em `condicao_de_recompensa`; nenhum dado existente é alterado. Ninguém é pago nesta entrega.
- Painel do Master, seção "Recompensas": "Dar baixa (registrar pagamento)" no menu ⋮ de cada indicador. O Master registra um pagamento que fez fora do sistema, com valor (padrão: o saldo), forma (PIX, transferência, desconto na mensalidade ou crédito de espaço), observação e comprovante opcional em PDF, PNG, JPG ou WEBP de até 5 MB. O BlueJus não movimenta dinheiro: ele só registra e confere.
- A baixa só é aceita para indicador liberado, não bloqueado, sem contas em análise, com nome e CPF ou CNPJ cadastrados e valor até o saldo. Dois pedidos ao mesmo tempo nunca pagam acima do saldo, e repetir o mesmo pedido (duplo clique) não paga duas vezes. As colunas "Saldo" e "Já pago" da lista e o KPI "Já pago" passam a refletir os pagamentos.
- Novo item "Dados para pagamento" no menu ⋮: nome, CPF ou CNPJ (conferido pelos dígitos verificadores) e chave PIX de quem recebe. A lista mostra só os 2 últimos dígitos do documento. O sistema guarda o valor bruto e não calcula imposto; o cadastro é o que o contador usa.
- O extrato mostra o tipo "Pagamento", a forma e um clipe para abrir o comprovante (só o Master consegue). O CSV do extrato ganha a coluna "Forma".
- Nova tabela `dados_de_recebimento_de_recompensa` e três colunas nulas em `lancamento_de_recompensa` (forma e comprovante); nenhum dado existente é alterado. Comprovantes ficam em `recompensa-comprovantes` (arquivo da plataforma, fora dos escritórios).
- Peticionar: a lista de documentos segue a ordem manual da pasta, a mesma da aba Documentos. Cada linha mostra o número da posição e pode ser arrastada; o menu da linha ganha "Mover para cima" e "Mover para baixo". Saem os separadores por dia.
- Push Processual, aba Processos da OAB: nova sub-aba "Acervo por jurisdição". Agrupa os processos acompanhados pela situação do prazo da publicação mais recente (pendentes de ciência ou de resposta, ciência dada, prazo findou, sem prazo, respondidos nos últimos 10 dias) e, dentro de cada grupo, por órgão. Tem busca por número e botão Atualizar.
- Push Processual, aba Diário da OAB: marcar publicações como lidas (por seleção ou ao abrir o texto), excluir as selecionadas e "Limpar lidas". Excluir e limpar pedem confirmação e a palavra EXCLUIR.
- O número de novas na aba Diário atualiza ao ler.
- Push Processual, aba Diário da OAB: cada publicação mostra "Pasta N" ou "Avulsa" e pode ser vinculada, trocada ou desvinculada de uma pasta (quem edita a pasta).
- A coluna Prazo mostra o selo de dias úteis restantes (vence em N d, vence hoje, vencido), como nas Publicações.
- "Criar meta com este prazo": a meta nasce na pasta vinculada, com o vencimento do prazo, e só existe uma por publicação. Sem pasta ou sem prazo, a tela avisa o que falta.
- Push Processual, aba Diário da OAB: agora dá para registrar à mão uma publicação do Diário (botão "Registrar publicação"), com data, seccional, tipo, ato, processo, órgão, prazo e texto.
- A lista mostra as publicações de cada usuário, com busca por processo, ato ou texto, filtros Todas, Éticas e Comunicados, texto que abre ao clicar e cadeado nas éticas (sigilosas).
- O número da aba Diário passa a contar as publicações ainda não lidas de quem está logado.
- Push Processual, aba Publicações: clicar na linha abre o detalhe da publicação (marca como lida), com a caixa PRAZO (5, 10, 15, 30 ou outro número de dias úteis, sem opção pré-marcada), "Prazo cumprido" e o botão "Criar meta com este prazo". A linha ganha o selo do prazo e o aviso "meta criada".
- Push Processual, aba Publicações: seleção em lote (Selecionar, Marcar como lidas, Excluir), Limpar lidas e menu do botão direito na linha (abrir, marcar lida ou não lida, copiar número, excluir). A exclusão é lógica (coluna `excluida_em`), para a sincronização com o DJEN não trazer a publicação de volta.
- Push Processual, aba Publicações: vincular e desvincular a pasta pela linha (botão e menu do botão direito), com o modal "Vincular a uma pasta". "Vinculada" passa a significar processo dentro de uma pasta (cartão, filtro e selo com o número da pasta). Se o processo ainda não existe no escritório, ele é cadastrado a partir da publicação.
- Push Processual, modal "Vincular a uma pasta": mostra as partes da publicação, oferece "Vincular à Pasta N" quando o número digitado não está na lista e "Abrir nova pasta" abre a Nova Pasta do Expediente; ao criar a pasta, o processo já fica vinculado a ela.
- Service Desk (desenho 12), fatia 3: tela "Meus chamados" em `/service-desk` (saldo, extrato, filtro por status, busca, abrir chamado, ver chamado e adicionar saldo por PIX). O item de menu Service Desk passa a abrir a tela nova; as rotas do help desk antigo (`/servicedesk`) seguem no ar.
- `RecargaManual` passa a devolver o PIX copia e cola estático da BlueJus, com a referência da recarga (BJSD...) como txid. O PIX é só mostrado ao abrir "Adicionar saldo" (referência assinada, nada gravado); o pendente nasce em "Já paguei". Cartão fica fora da tela até existir o adaptador do provedor.
- Arquivos quentes tocados: `_sidebar.html.twig` e `base.html.twig` (uma linha cada).
- Trabalho Externo, Nova solicitação: os botões "Novo escritório" e "Salvar edição" da seção SOLICITANTE passam a funcionar. "Novo escritório" guarda nome, e-mail, telefone, UF e município digitados e já o seleciona; "Salvar edição" aparece só com um escritório guardado selecionado e dados alterados.
- Os escritórios guardados ficam numa tabela própria do usuário (`trabalho_externo_escritorio_solicitante`, migration `Version20261027153100`), listados depois dos escritórios da conta no mesmo select. O escritório da plataforma (Tenant) nunca é criado nem alterado por aqui, e um escritório guardado nunca vira o escritório da solicitação.
- Novas rotas `POST /trabalho-externo/api/escritorios-solicitantes` e `POST /trabalho-externo/api/escritorios-solicitantes/{id}` (CSRF, só o dono; id de outro usuário é 404). Teto de 20 por usuário (409). Nome vazio: 422 "Informe o nome do escritório para salvar."
- Trabalho Externo, aba Pagamento: o Master ganha o botão "Recusar comprovante" (com motivo de 10 a 300 caracteres), ao lado de "Confirmar recebimento", quando há comprovante enviado e o pagamento ainda não foi confirmado.
- A recusa remove o comprovante (registro e arquivo, o arquivo só depois do COMMIT), grava o marco "Comprovante recusado" no histórico (arquivo e motivo; o Convidado não vê), deixa a mensagem de sistema do tipo Pagamento no chat (sem o motivo) e avisa o solicitante com o motivo. Sem migration.
- Sem comprovante o valor volta a ser editável pelo Master e o solicitante pode enviar outro. Nova rota `POST /trabalho-externo/api/demandas/{codigo}/pagamento/recusa` (Master, CSRF, trava de linha).
- O termo vem DESLIGADO: nada dele aparece (rotas, botões, filtro, coluna do CSV, indicador, avisos) até o Master ligar. Liga e desliga pelo comando `trabalho-externo:termo on|off|status` ou pela configuração da Central (chave `termo`). Os termos já gravados continuam no banco.
- Trabalho Externo: termo de voluntariado do banco de talentos. A Equipe e o Master geram o termo no currículo (menu da linha ou detalhe), conferem a prévia, baixam em Word, imprimem e enviam para aceite. O voluntário aceita em Meu currículo, com registro de data, hora e código do aceite.
- Banco de talentos: filtro por termo (sem termo, aguardando aceite, aceito), coluna Termo no CSV e indicador "N aceitos · M aguardando".
- Avisos no sino: o voluntário é avisado quando recebe o termo e o escritório quando ele é aceito (sem CPF nem RG no aviso).
- CPF e RG do voluntário ficam só no termo gravado: não aparecem em lista, filtro, CSV, aviso nem histórico de auditoria.

### Alterado

- Objeto (desenho 25/10): a tela da unidade passa a ter cantos de 4px, como o desenho. Só a tela do Objeto muda; a lista e a carteira ficam como estão. Os textos do desenho com travessão já estavam com pontuação própria e ficam como estão.
- Agenda: a tela de `/agenda` passa a seguir o desenho "Agenda 1A": barra com Hoje, setas, busca e Mês/Semana/Lista; coluna direita com Detalhe do evento, Hoje e A seguir, Legendas e Filtros (Pessoa, Status, Mostrar cancelados). Seis tipos de evento (Audiência, Prazo, Reunião, Urgente, Pessoal e Lembrete) na janela de criar e editar.
- A tela antiga continua disponível em `/agenda/classica` até a nova ser aprovada. Nenhum backend foi apagado; a ligação com o Google Agenda segue num cartão no fim da coluna direita.
- Perdas declaradas: a alternância "Minha agenda / Equipe" (o filtro Pessoa cobre "só eu" e "todo o escritório") e os cartões de prazos de hoje, amanhã e 7 dias. Feriados e aniversários não aparecem mais na grade. A engrenagem "Gerenciar legendas" aparece sem ação (não há cadastro de legendas). Sem migration.
- Painel do Master, seção Custos: lançar custo, excluir lançamento e copiar os lançamentos do mês anterior. Tudo fica na trilha de auditoria da plataforma.
- Painel do Master: a seção Custos mostra o custo do mês, a receita recorrente, a margem, o custo por conta e por GB, os custos por categoria, a margem por plano com a projeção de 3, 6 e 12 meses e os lançamentos do mês.
- Página de descadastro dos e-mails (link "Não quero mais receber"): novo visual do desenho Vagas Públicas, com motivo opcional em quatro chips ("Recebo e-mails demais", "Não me interessa mais", "Não me cadastrei", "Outro motivo"), botões "Continuar recebendo" e "Confirmar descadastro" e tela final "Pronto, você não receberá mais". O motivo escolhido é gravado no registro do descadastro. O link assinado e o descadastro com um clique do provedor de e-mail continuam como antes.
- Painel do Master, seção Desempenho: abrir e resolver incidente, registrar backup e teste de restauração e limpar os registros medidos. Tudo fica na trilha de auditoria da plataforma.
- Painel do Master: a seção Desempenho mostra a saúde do sistema (disponibilidade, tempo de carregamento, erros, falhas de login e upload, backups), os incidentes, os backups, a situação das integrações e os erros recentes, por período de 24 horas, 7 ou 30 dias.
- Excluir pasta: a pergunta de confirmação e o aviso de erro dizem qual número digitar ("Digite 1391 (o NUP da pasta, não o número da URL)"), no navegador e no servidor.
- Se o navegador bloquear a janela de confirmação ("impedir novos diálogos"), a tela agora avisa em vez de não fazer nada. Sem migration.
- Base interna do gateway de pagamentos: registro único de pagamentos (quem, quando, quanto) e trilha de cada um. Sem tela e sem cobrança ainda; os consumidores entram nas próximas etapas.
- Documentação: spec do gateway de pagamentos (PIX com confirmação automática pela Cora). Sem mudança de código.
- Instância "Juizado Especial" nas opções de cadastro de processo (modal Vincular processo do Push e janela de processo da pasta), como no desenho 11. A lista de processos mostra o rótulo.
- E-mails do sistema (confirmação de cadastro, redefinição de senha, convites e demais avisos) passam a usar o nome e o e-mail remetente das Configurações globais do Master. Sem mudança no Master, o remetente e o prefixo do assunto continuam exatamente os de hoje (BlueJus e nao-responda@bluejus.com.br). A tela do Master avisa que trocar o remetente exige SPF, DKIM e uma conta SMTP da Hostinger.
- Véu "Abrindo o seu escritório digital": a saudação passa a usar o começo do e-mail (antes do `@` e do primeiro ponto, sublinhado ou hífen), como no desenho, tanto no cadastro quanto no `/login/entrando`; o nome completo deixa de entrar.
- Tela de entrada: o botão "Conexão segura" da tarja deixa de quebrar linha no celular, como no desenho.
- Sessão expira por inatividade conforme a configuração "Sessão" do Master (padrão 24 minutos, o mesmo de hoje). Passado o prazo, a pessoa volta ao login com o aviso "Sua sessão expirou por inatividade. Entre novamente."
- Pasta judicial aberta pela cobrança passa a se chamar só pelo cliente da carteira (nome fantasia, senão razão social ou nome completo), sem o nome da pessoa cobrada. A ordenação da coluna Cliente do Expediente acompanha a mesma regra.
- Expediente e demais telas: pasta que não veio da cobrança e tem cliente vinculado mostra o cliente principal; o nome gravado na pasta vira reserva e aparece só quando não há cliente. Pasta comum continua com o nome editável.
- Novo comando pontual `app:cobranca:regravar-nome-pasta-judicial --tenant-id=<id>` regrava o nome gravado das pastas judiciais no padrão novo. Simula por padrão; só grava com `--aplicar`. Sem migration.
- Painel Inteligência: as Perguntas guiadas viram pílulas lado a lado com uma única caixa de resposta abaixo, como no desenho. Tocar na pílula ativa fecha a resposta. As respostas continuam vindas das regras fixas, sem mudança de número. Sem migration.
- Financeiro da pasta (desenho de 27/10, parte B1): Contrato, Pró-bono, Total contratado e Média por CPF viram linhas de um cartão único, com faixa vermelha de parcelas vencidas no topo (atualizada a cada pagamento). O trilho "Arquivos" passa a "Contratos" e o Relatório financeiro ganha subtítulo. O contrato só consta como Assinado com o arquivo do contrato anexado.
- Pagamentos da pasta (desenho de 27/10, parte B2): novo tipo "Recorrente" (honorários mensais fixos: valor mensal × número de meses, sem entrada e sem juros) e o tipo "Êxito" passa a se chamar "Proveito econômico" na tela. Migration `Version20261027154600` amplia o CHECK do tipo em `pasta_pagamento`.
- Pasta, aba Dados: rótulo "Linha do tempo da pasta" acima das fases e chave "Marcadores do sistema" no Registro dos expedientes. Com a chave desligada (padrão do desenho), o marcador do sistema vira uma bolinha na linha do tempo, e marcadores seguidos do mesmo mês dividem a bolinha com a contagem. O cartão do marcador ganhou cabeçalho branco com o título e "de → para". A escolha fica no navegador.
- Lista de clientes da pasta mostra o telefone ao lado do CPF.
- Cadastro do cliente: a aba Réu passa para depois de Documentos.
- Painel do Master, Visão geral (desenho 05 de 27/10): os números dos KPIs encolhem a fonte conforme a largura do cartão (de 36px até 20px), com o valor completo na dica, e o ícone do canto saiu.
- Painel do Master, Armazenamento: nova faixa no topo com espaço cobrado, espaço físico, espaço economizado e quantidade de arquivos (reais e virtuais). O cálculo agrupa por impressão digital (sha256) os documentos de pasta de todos os escritórios, sem contar escritório excluído. Sem migration.
- Peticionar: cabeçalho enxuto como no desenho 07. Mostra o número da pasta, a ação, o processo, "Voltar à pasta" e o nome do usuário. Saem o sino, a tela cheia e o menu do avatar.
- Peticionar: lista de documentos, rodapé e pré-visualização refeitos conforme o desenho 07. Adicionar peça mostra as opções Anexo e Texto com as dicas do desenho.
- Peticionar (F7): o seletor de processo do cabeçalho passa a gravar o processo de destino de cada peça (principal ou apensado da pasta). Ao selecionar uma peça, o botão mostra o destino dela; sem destino, o processo principal. Migration `Version20261027154400` (coluna `processo_destino_id` em `pasta_documento`).
- Peticionar (F9): peças em DOCX e XLSX passam a abrir numa prévia só de leitura no visualizador, convertida no servidor (sem biblioteca nova). A prévia sai sem script e sem acesso à rede; planilhas mostram até 500 linhas por 40 colunas; arquivo muito grande ou inválido mostra o aviso para baixar. Sem migration.
- Peticionar: seletor do processo no cabeçalho como no desenho 07. Lista o processo principal e os apensados, com o escolhido marcado. Sai o cartão com classe, assunto e jurisdição, que o desenho não tem.
- Push Processual, aba Processos da OAB: "Buscar processos da OAB" agora consulta os últimos 90 dias de cada OAB ativa no DJEN, fica na aba e avisa "Busca concluída: N processos encontrados para NNNN/UF". Sem OAB ativa, avisa e leva a OABs monitoradas.
- Página pública do Push compartilhado (`/p/{endereco}`): passa a receber o tema comemorativo do dia (cores da barra, fita e ícones no cabeçalho, mais o selo com a mensagem), como no desenho. Sem tema ativo nada muda.
- Push Processual, conferência com o desenho: ao parar de acompanhar processos, a confirmação agora pede digitar EXCLUIR; o campo do número do processo ganhou a máscara CNJ; a observação mostra o contador "N | 150"; o botão "Incluir N no acompanhamento" mostra a quantidade marcada; os botões de prazo da publicação mostram "5 dias", "10 dias" etc. e a nota do prazo cita o recesso forense (20/12 a 20/01).
- Push Processual, conferência com o desenho: os avisos das ações (vincular, sincronizar, excluir, etc.) agora aparecem no toast escuro do rodapé, igual ao desenho. Na aba Processos da OAB, o botão "Pasta" abre o modal "Vincular a uma pasta" (inclusive "Abrir nova pasta"), o botão de publicações mostra a quantidade e o botão direito na linha abre o menu do processo (ver publicações, abrir/trocar/desvincular pasta, editar anotações, copiar número).
- Push Processual, aba Processos da OAB: novo "Excluir processo" no menu do botão direito da linha (com confirmação e digitar EXCLUIR). O processo sai do acompanhamento, das pastas em que estava e do Relatório; as publicações já recebidas continuam no Push. Incluir o número de novo desfaz a exclusão.
- Push Processual: o botão "Cadastrar processo" abre o modal "Vincular processo" nas abas Publicações e Processos da OAB. Dá para vincular um processo já cadastrado (busca por número ou pasta) ou cadastrar um novo pelo número CNJ, com "Buscar no CNJ" preenchendo dados, partes e movimentações.
- Painel de Recompensas do Master (desenho 05): cada indicador com saldo liberado ganha o botão verde "Dar baixa" direto na linha, ao lado de "Ajustar". Abre o mesmo diálogo de baixa do menu.
- Menu ⋮ ganha "Copiar e-mail" (copia o e-mail do indicador) e o item "Voltar à regra geral" passa a se chamar "Usar a regra geral". Sem migration.
- Trabalho Externo, ajustes finos conforme o desenho: placeholders da proposta ("Prazo para executar", "Forma de pagamento"), nome dos documentos na cor do texto sem sublinhado, título do modal com reticências, hover do botão fechar e modal sem animação de entrada.
- Trabalho Externo, segunda rodada de conferência com o desenho: espaçamento dos botões da configuração de vagas, menus de contexto (largura, papel de acessibilidade e sem borda entre itens), menu do ⋮ do banco de currículos aberto no ponto do clique, cores dos chips de áreas e dos cartões de tipo da vaga, e tamanho final da foto do currículo, do lápis e do campo de quantidade.
- Trabalho Externo, alinhamento ao desenho 06: "Apagar para todos" (mensagem do chat) confirma com a caixa nativa do navegador; "Não aprovar" uma vaga pede o motivo numa caixa nativa (vazio vale, cancelar aborta).
- O item "Apagar para todos" do menu da mensagem perde o selo "sem prazo". Os textos do item travado seguem os do desenho.
- "Disponível por" na vaga com oferta passa a ter só 5, 10, 15, 30 e 60 min. Valores antigos (2 h, 24 h) caem em 15 min sem erro; ofertas já criadas valem até o fim.
- Pré-visualização do currículo: "Baixar PDF" aparece sempre; sem PDF pronto, abre o documento para imprimir ou salvar em PDF.

### Corrigido

- BlueJus IA: "Analisar esta pasta" (e os outros comandos longos, como Briefing e Raio-X) falhava em pasta grande com "a resposta foi cortada no limite de tokens". O limite dos comandos dos agentes subiu de 2500 para 4500 e uma resposta cortada agora é repetida uma vez com o dobro do limite antes de falhar.
- Sem migration.
- Tela de entrada: os avisos do sistema (sucesso e erro) sobre o céu passam a ter o verde e o vermelho do desenho; antes saíam como caixa branca neutra porque o CSS só conhecia as chaves em inglês.
- Login com senha: o cartão sai com a animação do desenho (0,35 s) antes da página "Abrindo o seu escritório digital" entrar.
- Comentários do login atualizados para o desenho VS 25.10.26.
- Aba Modelos da pasta: quem só visualiza a pasta agora também marca as próprias estrelas (modelo e matéria), que são preferência pessoal. Criar, editar e excluir modelo continuam só para quem edita a pasta.
- Modelos da pasta: ao usar um modelo na pasta, a minuta, a contagem de usos e o registro do uso passam a ser confirmados juntos (ou entra tudo, ou nada). O nome do cliente em negrito agora só é aplicado no texto da peça, nunca dentro de marcações, então nomes curtos não estragam o documento.

### Segurança

- Aviso de login novo: a cada login interativo (formulário, Google, Facebook; não o lembrar-me) o sistema guarda uma impressão do acesso (HMAC-SHA256 de família do navegador (sem versão), sistema e faixa do IP, /24 no IPv4 e /48 no IPv6; o IP cru nunca é gravado). Quando a impressão é nova para quem já tinha acessos, sai o e-mail "Novo acesso à sua conta" (data e hora no fuso do sistema, navegador, sistema e link para trocar a senha). O primeiro login só grava. Falha de gravação ou de e-mail nunca derruba o login.
- Nova tabela `acesso_conhecido` (migration `Version20261027153500`), sem tenant. Nova configuração do Master `avisoLoginNovo` (Ligado/Desligado, padrão Ligado), no grupo Segurança.
- A purga `app:purgar-dados-expirados` apaga os acessos não vistos há `retencao` dias (padrão 365).
- Painel: quem não enxerga alguma pasta (cadeado) deixa de receber a meta de pastas do mês no card "Pastas criadas" (barra, "X de Y", dica com "Faltam", ritmo e previsão). A meta é do escritório inteiro e, comparada ao realizado visível, revelava o total. Achado de auditoria interna. Sem migration.
- Teste novo varre a página e o fragmento do painel e afirma que nenhum número do escritório inteiro aparece para quem tem pasta oculta; o primário continua vendo a meta.

## [2.21.0] - 2026-10-26

### Adicionado

- Bônus de espaço por indicação: a conta nova que chegou por indicação e confirmou o e-mail ganha 5 GB extras por 30 dias, uma vez só. O bônus soma ao limite do plano (ou ao limite definido pelo Master) e vence sozinho pela data; nada é apagado.
- Página do espaço (Contribua): linha "Inclui 5 GB de bônus até dd/mm/aaaa" enquanto o bônus vale.
- Painel do Master: a conta mostra a origem (indicação) e a validade do bônus.
- Nova tabela `contribua_bonus_de_espaco` (migration `Version20261026151600`, reversível). Contas já confirmadas antes desta versão não recebem o bônus.
- Painel: o Total de "Pastas concluídas" agora abre o Expediente com as pastas da equipe (ou só do cargo escolhido no filtro), com o mesmo número do Total. Fica sem link quando há busca, quando a coluna foi zerada ou quando o Total é 0.
- Painel: na coluna "Relatório", clicar no número (ou usar "Ver relatório de X" no botão direito) abre uma janela com os registros da pessoa agrupados por pasta, com data e hora de cada um. O total da janela é igual ao número da célula e respeita o período, o "Zerar" e as pastas ocultas pelo cadeado. Nas abas Financeiro e Detalhes (e Dados) que a pessoa não pode ver, a linha e o total aparecem, mas o texto do registro é trocado por um aviso.
- Expediente: o filtro de responsável entende "Equipe" e "Equipe · cargo" (vindos do Painel) e mostra o rótulo no campo.
- Sem migration.
- Painel do Master, seção "Recompensas": novo bloco "Extrato de pagamentos e liberações" com os 60 lançamentos mais recentes (data, indicador, tipo e valor) e o botão "Exportar CSV" com o extrato completo.
- Menu ⋮ de cada indicador ganha "Lançar crédito ou estorno" (valor em reais, negativo para estornar, com observação), "Ver extrato deste indicador" (filtra o extrato, com o botão para mostrar todos) e "Baixar extrato (CSV)".
- O saldo e o "A pagar" passam a somar os créditos e estornos manuais. O saldo nunca fica negativo: estorno maior que o crédito zera o saldo.
- O extrato é um livro: lançamento não se edita nem se apaga, só se corrige com outro de sinal oposto. Cada pedido leva uma chave única, então um duplo clique ou reenvio não lança duas vezes. Tudo entra na auditoria da plataforma.
- O CSV usa ponto e vírgula, abre com acentos no Excel e protege células que começam com =, +, - ou @ contra fórmulas. Não traz dado de quem foi indicado.
- Nova tabela `lancamento_de_recompensa` (dado da plataforma, sem escritório). Ninguém é pago nesta entrega: a baixa de pagamento fica para a próxima.

## [2.20.0] - 2026-10-26

### Adicionado

- Painel do Master: a seção "Configurações globais" deixa de ser vazia e ganha os 14 campos do desenho (identidade, contas novas, segurança, região, manutenção programada), com Restaurar padrão, Descartar e Salvar. O espaço do Plano Free e o aviso de espaço gravam em Planos e Faixas; os outros 12 ficam na nova tabela configuracao_global, com auditoria.
- Aviso de manutenção: o texto escrito em "Manutenção programada" aparece no topo de toda tela de quem está logado, até a data e hora escolhidas.
- Os demais campos (cadastro, sessão, tentativas de login, verificação em duas etapas, retenção da auditoria, idioma, fuso, nomes e e-mails) já são gravados, mas ainda não mudam o comportamento do sistema: serão ligados um a um.
- Ao mudar o convênio OAB de um estado no painel do Master (valor diferenciado, sem convênio ou grátis de novo), o sistema enfileira um e-mail para cada usuário primário ativo dos escritórios daquele estado, com o valor em reais, a data de vigência e, quando houver, o motivo.
- Só avisa quando o convênio realmente mudou: salvar o mesmo estado duas vezes não repete o e-mail. Um e-mail por pessoa, sem expor a lista de destinatários, pela fila de envio e dentro da cota diária: todos entram na fila e o envio adia o que passar da cota, então em estado grande a entrega pode se espalhar por mais de um dia. A mudança e a fila andam juntas: se os avisos não entram na fila, nada é alterado.
- A resposta de POST /master/convenios ganhou o campo `avisados` (quantos e-mails entraram na fila). Sem migration.
- Painel do Master, seção Contas: novo painel recolhível "Convênio OAB por estado". Para cada um dos 27 estados o Master escolhe convênio grátis, convênio com valor diferenciado (valor por mês e data de início) ou sem convênio (mensalidade, data e motivo). Cada mudança fica na auditoria.
- Na lista de contas, abaixo do plano, aparece o convênio do estado da conta (verde grátis, azul valor diferenciado, vermelho sem convênio). Conta sem estado cadastrado fica sem rótulo.
- Por enquanto o convênio só é registrado e mostrado: o aviso por e-mail aos primários do estado e o efeito na cobrança entram em outra entrega.
- Painel do Master, mapa "Onde estamos atuando": novo botão "Indicações" ao lado de Pessoas, Escritórios e OAB (só no Brasil), com três visões: Indicações enviadas, Contas abertas e Recompensas pagas (R$). Cada indicação conta no estado e na cidade de quem indicou.
- Recompensas pagas aparece zerada até existir o extrato de recompensas.
- Painel do Master, Planos e limites: faixa do convênio OAB com a logo, rótulo "Planos de espaço", selos "Convênio OAB · toda conta nova começa aqui" (Free) e "Mais escolhido" (Profissional), botão "Ajustar" no topo do cartão, linhas "usuários ilimitados" e "Todos os recursos liberados", cor roxa do Avançado e nota de rodapé nova, conforme o desenho 05 de 25/10.
- Os planos Free, Essencial e Profissional deixam de limitar o número de usuários (antes 3, 8 e 15), como o Avançado: a tela passa a dizer "usuários ilimitados" e o convite de usuários não é mais recusado por limite de plano. O Master ainda pode definir um limite próprio para uma conta.
- Painel do Master: nova seção "Recompensas" (ícone de presente), com as regras do programa Indique e ganhe: chave de programa ativo ou pausado, recompensa por escritório (em reais), quantos escritórios ativos liberam, carência em dias, alerta antifraude e a opção "Só conta escritório com pastas abertas".
- A seção traz os KPIs (Indicadores, Contas abertas, Liberados, A pagar e Já pago) e a lista de Indicadores com busca, filtros e a situação de cada um (Liberado, em carência ou em andamento). O valor é calculado na hora da leitura: nada é gravado nem pago ainda.
- Condição individual por indicador (valor, "libera com", carência, parceiro recorrente e motivo), que não muda quando a regra geral muda, e "Voltar à regra geral". Tudo registrado na auditoria da plataforma.
- Novas tabelas `programa_de_recompensa` e `condicao_de_recompensa` (dado da plataforma, sem escritório). Baixa de pagamento, caixa, extrato e Fechamento do mês ficam para as próximas entregas.

### Corrigido

- Link de indicação `/i/{codigo}`: o código só é guardado na sessão quando a pessoa abre o link como página (navegação). Requisição vinda de `<img>`, `<iframe>` ou `fetch` de outro site deixa de atribuir a conta nova a quem indicou.
- Push Processual: corrige a grade da lista de Processos da OAB, que desalinhava cabeçalhos e células, sobrepunha número e órgão e quebrava os ícones de ação.

## [2.19.0] - 2026-10-26

### Adicionado

- Login: no "Criar conta", o cupom de indicação é conferido enquanto se digita ("Cupom válido" ou "Cupom não encontrado"), sem mostrar quem indicou.
- Novo endpoint público `POST /cadastro/cupom/conferir`, limitado a 20 consultas por 15 minutos por IP (`indicacao_cupom`).
- Login (desenho 10 VS 25.10.26): "Tenho um cupom de indicação" no Criar conta, com o campo BJ-XXXXXX; o código digitado liga a conta nova a quem indicou, pelo e-mail e pelo Google, e cupom inválido nunca impede o cadastro.
- Login: `?ind=`, `?codigo=`, `?ref=` e `#cadastro` abrem o Criar conta (com o cupom preenchido); o link curto `/i/{codigo}` leva o cupom junto.
- Login: título do Criar conta centralizado e sem ícone, como no desenho.
- Login: "Continuar com Facebook" volta ao Criar conta, ligado só quando as credenciais da Meta estão configuradas (sem elas o botão não aparece). O e-mail do Facebook é só sugestão: a conta nasce não confirmada e o e-mail é confirmado pelo link, como no cadastro por e-mail.
- Login: quem já ligou a conta ao Facebook entra por ele; o e-mail do Facebook nunca liga, confirma nem acessa uma conta existente.
- Nova coluna `user.facebook_id` (migration 150800).
- Painel do Escritório: meta de pastas do mês editável no card "Pastas criadas", como no desenho VS 25.10.26: barra de progresso, traço do ritmo esperado, "X de Y no mês" e status (No ritmo, Abaixo do ritmo, Meta batida). Sem meta, o primário vê o botão "Definir meta do mês".
- Só o usuário primário define, altera ou remove a meta (lápis do card ou botão direito no card: "Alterar meta do mês / Ver histórico"); os demais veem a meta sem o botão, e a rota recusa com 403. A meta vale do mês em que foi definida em diante, até ser trocada; remover encerra a meta a partir do mês corrente.
- O ritmo esperado conta dias úteis do mês (fim de semana, feriados nacionais e recesso), decisão provisória: o dono pode rever.
- Sem migration: usa a tabela `dashboard_meta_pastas` do PR3. Rotas novas `dashboard_meta_pastas_definir` e `dashboard_meta_pastas_remover` (POST, CSRF).
- Cabeçalho: botão discreto "Indicar o BlueJus" (ícone de compartilhar) antes do sino, em todas as telas; abre a janela de indicação que já existia no menu do usuário.
- Painel do Master, Visão geral: novo bloco "Metas de divulgação" depois do mapa. Mostra o mercado (advogados regulares), a meta de usuários em fases (1.000, 10.000, percentuais do mercado), o progresso, a tabela por estado com meta própria e as cidades de cada estado, ao lado do mapa do Brasil.
- Atualização automática do mercado pela tabela da OAB (semanal, mensal ou trimestral), com "Atualizar agora", avanço de fase ao bater a meta e "voltar a automático" quando o mercado foi fixado à mão.
- Clique com o botão direito num estado para destacá-lo no mapa com uma de seis cores. Metas, destaques e ajustes ficam gravados no servidor (tabela meta_de_divulgacao) e valem para todos os Masters.
- Trabalho Externo: nova aba "Pagamento" na demanda (entre Chat e Histórico), conforme o desenho de 25/10. Mostra o valor da última proposta aceita, a forma de pagamento, o QR Code e o código PIX "copia e cola" (gerados no servidor, só enquanto não pago) e o estado do pagamento em três faixas: aguardando, comprovante enviado e confirmado.
- Trabalho Externo: o solicitante envia o comprovante do PIX (PDF ou imagem, até 20 MB, um por demanda) e a Central confirma o recebimento pela própria aba; a confirmação usa a ação "Registrar pagamento" (histórico "PIX R$ x", aviso de andamento e de WhatsApp). "Enviar no chat" grava o aviso do PIX na conversa.
- O convidado não vê a aba, o valor, o comprovante nem os registros de pagamento no chat e no histórico.
- Nova dependência: `bacon/bacon-qr-code` (QR Code em SVG, PHP puro). Nova coluna `trabalho_externo_documento.comprovante`, com índice único parcial (um comprovante por demanda); confirmar recebimento e enviar comprovante travam a demanda na transação.
- Decisão a confirmar pelo dono: hoje a Equipe só confirma o recebimento com a opção "Alterar a situação das demandas" ligada, a mesma regra do botão "Registrar pagamento".
- Trabalho Externo: o Master altera o valor do pagamento na aba "Pagamento" (lápis ao lado do valor), informando o novo valor e o motivo (10 a 300 caracteres). Só com proposta aceita, antes do pagamento e sem comprovante enviado; o valor é lido e validado no servidor, com trava da demanda na transação.
- O novo valor passa a valer em tudo: QR Code, código PIX, mensagem do chat, histórico ("PIX R$ x") e confirmação do recebimento. A proposta aceita não muda; aceitar outra proposta zera o ajuste.
- Trilha: marco no histórico (valor antigo, novo, quem, quando e motivo), mensagem de sistema no chat sem o motivo e aviso de andamento ao solicitante. O convidado não vê nada disso.
- Nova coluna `trabalho_externo_demanda.valor_pagamento_ajustado` (migration `Version20261024150900`) e rota `POST /trabalho-externo/api/demandas/{codigo}/pagamento/valor`.

### Alterado

- Dashboard: a tela passa a se chamar "Painel do Escritório" (título, aba, PDF e migalha) e "Painel" no menu lateral, como o desenho VS 25.10.26.
- Dashboard: visual do desenho VS 25.10.26: cor primária #0a6fa0, título sem brilho, cabeçalhos azuis sólidos, cantos de 3px, cards sem glifo, Total sem calculadora e com fundo #f5f8fa, pílulas de Vencidas e Prazos sem fundo, contador de pessoas maior.
- Dashboard: dica em cada um dos 4 cards, que some depois de 3 passagens do mouse por card (localStorage `bj-dica-paineis`).
- Dashboard: "Este mês" vai do dia 1º ao último dia do mês; 60 e 90 dias passam a contar hoje incluído.
- Push Processual: tela de Publicações refeita conforme o desenho 11 (faixa de abas, cartões Não lidas/Hoje/Vinculadas/Avulsas que filtram a lista, filtros por tribunal, vínculo, leitura e período, tabela nova). Novo filtro de leitura. Entra a aba Diário da OAB, ainda sem publicações (casca com o estado vazio). A tela de OABs monitoradas ganha a faixa de abas.
- Push Processual, aba OABs monitoradas refeita conforme o desenho 11: formulário com UF em lista, tabela com a coluna Publicações (quantas cada OAB captou), botões Pausar/Retomar e confirmação ao remover.
- Push Processual: aba Processos da OAB refeita conforme o desenho 11, com inclusão de processo por número, sub-aba Relatório, anotações por processo e exportação em CSV. Nova tabela `djen_processo_acompanhado`.
- Painel do Master: saem da aba "Configurações da I.A." os blocos "Questões jurídicas" e "Documentos jurídicos" e o aviso global "Questões jurídicas a serem verificadas". Tabelas, rotas, UseCases e catálogo ficam dormentes.

## [2.18.0] - 2026-10-24

### Adicionado

- BlueJus IA: comando "Ler N documentos" na aba Documentos do drawer da pasta (`ler_documentos`, versão `agente-leitura-v1`). PDF e imagem (até 3 por vez; PDF até 10 MB, imagem até 3 MB, 20 MB juntos, 100 páginas somadas) vão inline ao provedor como blocos `document`/`image` em base64, com o tipo medido pelo conteúdo; sem Files API e sem OCR próprio; timeout de 300 s e `max_tokens` 8000 só nessa leitura. O worker relê cada documento por id e escritório, fora da lixeira, reconfere o acesso de quem pediu e as abas ocultas de agora, e conta as páginas que o upload não contou; falha de I/O do armazenamento volta à fila. Pasta em segredo nunca envia; `contexto_resumo` guarda só id e sha256. Versão do texto do aceite em `inteligencia_configuracao.consentimento_versao` (migration aditiva `Version20261024150400`, linhas atuais na 1): só a versão 2, que cita os documentos, libera a leitura; o administrador renova salvando a configuração com a IA ligada.
- BlueJus IA (ia-anthropic-ligar, PR C, sem migration): o orçamento mensal em US$ de cada escritório passa a valer. `DisponibilidadeDeInteligencia::para()` ganha, depois da cota mensal, o caso `orcamento_atingido` ("Orçamento mensal da BlueJus IA atingido; volta no dia 1º"), que responde 429 com o código `limite_atingido`, como as cotas. Vale nos modos `incluso` e `adicional`; orçamento vazio não trava; o worker (`paraProcessamento`) não confere o orçamento.
- BlueJus IA: no modo `limite_gratis` o orçamento é a parte grátis e NÃO bloqueia; o painel do Master mostra "acima do grátis". Padrão adotado em 24/10/2026, o dono pode rever.
- BlueJus IA: serviço `CustoDaIa` com o custo estimado em centavos de dólar, pelo preço de tabela de `ModeloDeIa` (modelo fora da lista paga o preço do Opus 5.5; região `us` custa 1,1x; arredonda para cima). O gasto do mês soma todas as análises com tokens desde o dia 1º, inclusive as excluídas e as que falharam, numa consulta agrupada por modelo com tenant explícito.
- Painel do Master, aba "Configurações da I.A.": colunas "Análises no mês" e "Gasto no mês (US$)" na tabela de escritórios, com os selos "orçamento atingido" e "acima do grátis" (uma consulta para todos os escritórios).
- `/admin/inteligencia`: bloco "Consumo do mês" com as análises e o limite mensal, o gasto estimado, o orçamento (quando houver) e o modo de cobrança, só do próprio escritório.
- Painel do Master, aba "Configurações da I.A.": bloco novo "Questões jurídicas". Cada questão mostra problema, risco, opções, sugestão e fontes. O conteúdo vem do catálogo em código (`config/inteligencia/questoes_juridicas.json`, lido por `CatalogoDeQuestoesJuridicas`).
- Download da versão atual de cada documento: Política de Privacidade, Termos de Uso, rascunho da política da IA (cópias em `app/resources/legal/`, porque `docs/` não vai para a imagem de produção) e o texto do aceite da IA, gerado na hora em .txt com os mesmos textos da tela do aceite.
- Upload da versão ajustada de cada documento (.docx, .doc, .pdf, .md, .txt e .odt, até 20 MB, com o tipo conferido pelo conteúdo), com histórico: quem enviou, quando, nome original, documento e questão. O download das versões enviadas também vale. Arquivos na categoria nova `inteligencia_documento_juridico` (escopo global, `public/uploads/documentos-juridicos`).
- Status de cada questão (pendente, em análise, resolvida), alterável pelo Master, com histórico de quem mudou e quando, e auditoria no escopo de plataforma.
- Aviso no topo das telas do Master, "Questões jurídicas a serem verificadas", enquanto houver questão não resolvida, com o link para o bloco.
- Tabelas `inteligencia_questao_juridica`, `inteligencia_questao_juridica_historico` e `inteligencia_documento_juridico_versao` na migration `Version20261024150300`. Rotas `master_ia_juridico_*`, só para o Master.
- Trabalho Externo (desenho 06 de 24/10, D8, PR 2): DESPACHO no Resumo da demanda. Só o Master despacha: natureza (5 opções que desmarcam), prazo (padrão hoje mais 5 dias úteis, pulando sábado, domingo e os feriados nacionais do calendário do sistema), texto até 5.000 com contador, anexos (arquivos de verdade, baixáveis pela rota dos documentos e fora da lista DOCUMENTOS) e o registro "Novo despacho da Central BlueJus{ · natureza}." no chat, que avisa as partes.
- Trabalho Externo: chave "Aceitar resposta" do Master; com ela ligada e um despacho publicado, o solicitante, a Equipe e o convidado vigente respondem (até 3.000), com o registro "Resposta ao despacho de {quem}.". Cada resposta avisa no sino os Master da Central, menos quem respondeu (tipo novo `trabalho_externo_despacho_resposta`, ação "Abrir a demanda").
- Trabalho Externo: lista numerada (o mais novo em cima), respostas recuadas em verde, "{Cidade}, {data por extenso}." e a assinatura eletrônica com o código (único), que segue a privacidade do nome do responsável e não repete "Central BlueJus"; "Extrato" para impressão (também na pré-visualização do Master) em `GET /trabalho-externo/demandas/{codigo}/despachos/extrato`.
- Rotas novas: `POST /trabalho-externo/api/demandas/{codigo}/despachos`, `.../despachos/respostas` e `.../despachos/aceitar-resposta`.
- Migration `Version20261024150500`: tabela `trabalho_externo_despacho`, `trabalho_externo_documento.despacho_id` e `trabalho_externo_demanda.respostas_ao_despacho_liberadas`.
- Trabalho Externo (desenho 06 de 24/10, D11b, PR 3): botão "Recusar" ao lado de "Aceitar oferta" na oferta com tempo, com a confirmação da própria tela ("Recusar esta oferta? O escritório e a Central BlueJus serão avisados."). A recusa fica gravada por vaga e pessoa, entra no histórico como "Oferta recusada por {quem}" e avisa no sino o dono da vaga (sem o nome de quem recusou) e os Master da Central (com o nome; tipo novo `trabalho_externo_oferta_recusada`). Depois, o botão vira "Você recusou · aceitar mesmo assim", que tira a recusa e aceita. "Ofertar novamente" não apaga a recusa.
- Trabalho Externo (D4): o profissional externo vê, junto com as abertas, as vagas expiradas há até 30 dias, com véu, carimbo EXPIRADA, faixa "Expirou em dd/mm/aaaa", selo "Expirada" e sem cronômetro, AO VIVO nem NOVA; o clique mostra o aviso "Vaga expirada" em vez de abrir. A vaga expirada há mais de 30 dias sai da lista de todos, inclusive da Central (o dado continua no banco).
- Rotas novas: `POST /trabalho-externo/api/vagas/{codigo}/recusa` e `.../recusa/desfazer`.
- Migration `Version20261024150600`: tabela `trabalho_externo_vaga_recusa` (única por vaga e usuário) e `trabalho_externo_vaga.encerrada_em`, gravada em "Encerrar vaga", "Finalizar oferta" e no fim da divulgação (reabrir zera), com o backfill das encerradas pelo histórico.

### Alterado

- BlueJus IA: a tarefa diária `app:inteligencia:expirar-conteudo` usa o prazo de guarda que o Master escolhe na aba "Configurações da I.A." (12 meses por padrão), em vez de 12 meses fixos. Decidido pelo dono em 24/10/2026. O comando e o log mostram o prazo usado.
- Telas do Master: 9 textos passam a mostrar só o estado, sem nome técnico e sem mandar o Master acionar alguém (regra de tom de 24/10/2026). - Comercial: "depende de decisão do dono" e "Aguardando a decisão" viram "ainda não está disponível". - "Integração no servidor" vira "integração ainda não disponível". - Relatório comercial: "quando o servidor registrar as leituras" vira "assim que as leituras forem registradas". - Aviso da cota de e-mail: sai "(limite da Hostinger)". - Criar escritório sem e-mail: sai "Verifique SMTP".
- Aviso de falha do agendador e Central de Notificações do Master mostram o nome da tarefa ("Captação das publicações do DJEN"), não o comando do console. Serviço novo `NomeDaTarefaAgendada`; um teste garante que toda tarefa da agenda tem nome. "Detalhe no log do agendador" vira "Os detalhes ficam registrados pela equipe BlueJus."
- Painel do Master, bloco "Questões jurídicas": cada questão mostra só o nome dos documentos afetados. Um bloco único no fim da página lista os 4 documentos (Política de Privacidade, Termos de Uso, rascunho da política da IA e texto do aceite), cada um com baixar e enviar, e avisa que cada envio substitui o documento em todas as questões. O envio pelo bloco não vincula a versão a uma questão.
- Catálogo de questões jurídicas: campo opcional `oculta` no JSON. Questão oculta some da listagem, da contagem de "em aberto" e do aviso do topo, sem apagar o texto e sem renumerar as demais. A questão `retencao_zero` (2. Retenção zero) foi ocultada.
- Aviso do topo "Questões jurídicas a serem verificadas": some depois do clique em "ver as questões" e só volta quando surge uma questão em aberto que o Master ainda não viu (guardado no navegador, por usuário).
- Envio de versão de documento jurídico no Master deixa de aceitar o formato antigo .doc (binário legado, sem validação confiável de conteúdo). Continuam aceitos .docx, .pdf, .md, .txt e .odt.

### Corrigido

- Pasta, cabeçalho: o círculo do cliente abre o menu "Carregar foto" também na pasta sem cliente cadastrado (desenho 02 VS 18.10.26); antes ficava inerte. A foto fica na própria pasta (migration `Version20261024150000`: `pasta.foto` e `pasta.foto_atualizada_em`, categoria `pasta_foto` em `uploads_dir/fotos`), com as mesmas regras da foto do cliente: editar a pasta, escritório, CSRF, lápide e cadeado, imagem até 3 MB, a anterior apagada depois do commit, registro na pasta e purga do escritório.
- Com cliente vinculado depois, a foto da pasta continua aparecendo até o cliente ter foto própria. Rotas novas `pasta_foto_trocar`, `pasta_foto_remover` e `pasta_foto`; as respostas das rotas da foto passam a dizer a origem da foto mostrada.

## [2.17.0] - 2026-10-24

### Adicionado

- Dashboard: nova coluna "Pastas concluídas" no Adicionar coluna, com as pastas que chegaram à etapa Finalizada do andamento no período, por responsável, e a seta de tendência contra o período anterior; voltar de fase ou reativar tira a pasta da conta, finalizar de novo conta na nova data e pasta excluída não conta.
- Pasta: a data de chegada à Finalizada passa a ser guardada (`pasta.finalizada_em`), também na troca entre via judicial e administrativa; as pastas já finalizadas recebem a data do andamento, e as arquivadas sem andamento, a do último arquivamento pelo Registro ou pela auditoria.
- Pasta: arquivar pelo "Editar dados" sai como na pílula (cartão "Situação da pasta" e andamento em Finalizada) também no caso de uso.
- Dashboard: tabelas `dashboard_zeramento` e `dashboard_meta_pastas` criadas para o Zerar de coluna e a meta de pastas (as telas vêm nos próximos PRs).
- Dashboard: botão direito no título de uma coluna (ou na célula de uma pessoa) abre o menu Zerar, que recomeça a contagem a partir de agora para a equipe ou só para aquela pessoa; nada é apagado e o menu mostra "Contando desde".
- Dashboard: "Desfazer zeramentos" devolve a contagem completa da coluna.
- Dashboard: só o usuário primário ou o Master zera; os demais veem "Pedir para zerar", que avisa o primário e a Controladoria pela Central (um pedido igual por dia).
- Dashboard: "Zerar" nos Comentários do cartão do colaborador e "Zerar relatório de todos" no menu da tabela.
- Login: "Entrar com o Google" para quem já tem conta. Pede só `openid email profile`, com `state`, `nonce` e PKCE; o `id_token` tem cliente, emissor, validade e e-mail confirmado conferidos. A conta é ligada pelo identificador do Google (coluna nova `user.google_sub`, migration `Version20261020151700`) ou, na primeira vez, pelo e-mail, só para conta Gmail ou Workspace (`hd`). Conta com o e-mail "a confirmar" que entra pelo Google é confirmada e tem a senha antiga trocada por uma inutilizável, antes do `UserChecker`. Mesmo destino e barreiras do login por senha; "Manter conectado" vale quando a ida sai do próprio botão. Cliente OAuth próprio (`GOOGLE_LOGIN_OAUTH_CLIENT_ID/SECRET`); sem ele, os botões do Google não aparecem. O conferente do `id_token` passa a ser compartilhado com o Google Agenda.
- Criar conta (desenho 10 VS 24.10.26): "Continuar com Google" e "Continuar com Facebook" acima dos campos. Pelo Google, a conta nasce confirmada e ligada, com o e-mail do Google guardado na sessão (`CadastroPeloGoogle`, 15 minutos), e entra na hora: cartão "conectado pelo Google" com "Usar outro e-mail", sem o campo E-mail. Rotas `POST /cadastro/google/criar` e `POST /cadastro/google/esquecer`. O botão do Facebook está na tela, sem app na Meta: o clique avisa que ainda não está disponível.
- Painel do Master: aba nova "Configurações da I.A." (`data-aba="ia"`). Ela tem: - o aviso da retenção zero (ZDR) com o que pedir à Anthropic; - o estado da instalação, que diz só se a chave está gravada, nunca o valor; - a chave geral para todos os escritórios; - a escolha do modelo, Haiku 5.5 por padrão; - o prazo de guarda das respostas, de 1 a 120 meses, 12 por padrão; - a tabela de escritórios: liberar, modo de cobrança e orçamento mensal em US$.
- Configuração da plataforma em linha única: `inteligencia_plataforma`, Auditavel.
- Três colunas novas em `inteligencia_configuracao`, todas na migration `Version20261020151800`.
- Rotas `master_ia_*`: POST JSON com CSRF no header.
- A disponibilidade ganhou o caso `nao_liberada_pelo_master`: a IA só funciona com a chave geral ligada E o escritório liberado pelo Master.
- Depois do deploy a IA fica desligada para todos até o Master ligar a chave geral e liberar o escritório piloto.
- O orçamento ainda não bloqueia (PR C).
- Pasta, menu ⋮: item "Acompanhar alterações" na seção Preferências, antes de "Fixar nos favoritos", como no desenho 02 EXPEDIENTES VS 18.10.26. Padrão ligado para quem vê a pasta; desligar grava a exceção em `pasta_acompanhamento_desligado` (migration `Version20261020151100`). Rota `pasta_acompanhamento_alternar` (POST, CSRF, guardas de escritório e de VER; passa no cadeado em Visualizar, recusada em Oculto e na pasta excluída).
- Mensagem nova no Registro da pasta avisa quem acompanha (tipo `pasta_acompanhada`, no sino e na Caixa da Central), menos o autor e quem recebeu o aviso próprio (o respondido e os mencionados avisados); no máximo um aviso não lido por pasta e pessoa, com o índice `idx_notificacao_tenant_tipo_lida` na mesma migration. O aviso sai depois do commit da mensagem: se falhar, vai para o log e a mensagem fica gravada.
- Documento novo na pasta (upload e peça de texto) e troca de situação pela pílula também avisam quem acompanha: "Novo documento na pasta N" (link da aba Documentos) e "Situação da pasta N alterada para X" (link do cartão no Registro). Sai o autor; quem tem a aba do evento oculta (Documentos ou Dados) não recebe; o limite de um não lido por pasta soma os três eventos. Meta e Push ficam de fora, porque já têm aviso próprio.
- Pasta, cabeçalho: foto do cliente (desenho 02 VS 18.10.26). O círculo do cliente vira botão para quem edita o cliente, com o menu "Carregar foto" / "Carregar nova foto", "Ajustar foto" e "Remover foto" e o editor de recorte do desenho (arrastar, zoom de 1 a 3, girar 90°); o navegador salva um JPEG 320x320. Quem só vê o cliente vê a foto sem menu; pasta excluída ou só de visualizar não deixa trocar.
- Migration `Version20261020151200`: colunas `cliente.foto` e `cliente.foto_atualizada_em`. Arquivo na categoria nova `cliente_foto` (`clientes_uploads_dir/fotos`), entregue por `cliente_foto` só a quem vê o cliente; a foto antiga sai do disco depois do commit; a purga do escritório apaga as fotos.
- Rotas `pasta_cliente_foto_trocar` e `pasta_cliente_foto_remover` (JSON, CSRF, editar cliente + escritório + cliente da pasta). A troca e a remoção ficam registradas no histórico da pasta e no do cliente.
- Trabalho Externo (desenho 06 de 24/10, PR 1, sem migration): "Meu currículo" compacto (Editar e Word), com o cartão "Prévia do currículo" (o documento formatado e "Baixar PDF"); o cartão "Minhas candidaturas" sai da aba e o toast da candidatura fica só com "Candidatura enviada".
- Trabalho Externo: rota `GET /trabalho-externo/curriculos/meu/word` (`.doc` com BOM, "Currículo - {nome}.doc", só o próprio currículo), com a foto embutida e o logotipo com URL absoluta.
- Trabalho Externo: animação depois de enviar a solicitação (busca, parceiro e confirmação, com som e o mapa do Google da cidade da execução).
- Trabalho Externo: aviso novo `trabalho_externo_demanda_andamento` a quem pediu e ao convidado vigente a cada mudança de situação e registro da demanda (um por pessoa, sem quem agiu nem a Central; o convidado só recebe o que leria no chat). A tela aberta lê os novos por `GET /trabalho-externo/api/andamentos` e mostra "Andamento · {ID}", com um som só (o da Central, quando o lançador está na página).
- Trabalho Externo: "Alertas de vagas" no menu do Master liga e desliga o sininho das vagas; "Sua vaga · acompanhe os candidatos acima" no detalhe da própria vaga; dica "Desmarcar todas" no chat.

### Alterado

- Telas que são regra deixam de dizer que são IA (decisão do dono de 24/10): rodapé da previsão do Push ("Previsão por regras do CPC, não é ato oficial.") e a abertura e a nota final do relatório de processos ("por regras"). Guarda nova: `RotuloDeIaSoNoQueChamaOModeloTest`. A volta da IA nessas telas, quando houver dado real, está em `docs/specs/ia-anthropic-ligar.md` §10.3.
- Painel do Master, aba Configurações da I.A.: saem o aviso da retenção zero (ZDR) e a linha da região de processamento; ficam no runbook.
- Painel do Master, aba "Configurações da I.A.": os textos separam o que é do Master (as chaves e as opções da aba, o pedido da ZDR) do que é do desenvolvedor (a chave da API no servidor, a chave de segurança do servidor e a região na Console da Anthropic), com "peça ao desenvolvedor". - "Trava do servidor (IA_HABILITADA)" passou a se chamar "Chave de segurança do servidor", com a explicação de que a IA só funciona com ela E a chave geral ligadas. - Nenhum nome de variável aparece mais no texto da tela.
- Painel do Master, aba "Configurações da I.A.": os textos mostram só o estado, em tom neutro, sem mandar o Master acionar o desenvolvedor (regra de 24/10/2026, substitui o "peça ao desenvolvedor" do #375). - A chave da API não instalada aparece como "ainda não instalada (configuração técnica da equipe BlueJus)". - A instrução técnica da Console da Anthropic saiu da tela e foi para o runbook (`docs/runbooks/ligar-ia-em-producao.md` §0.1); na tela fica "Região de processamento: EUA". - O teste da aba recusa "desenvolvedor", "peça ao", "avise", "suporte" e nome de variável no texto.
- Login (desenho 10, VS 24.10.26): o tema comemorativo ativo vale na tela de entrada e no véu "Abrindo o seu escritório digital": cores do tema na tarja, nos topos dos painéis e no véu, fita no topo, ícones na tarja e brilho no logo no tema animado. Sem selo nas telas de entrada, como no desenho. Reaproveita o parcial e o JS do tema com o modo `bjtSemSelo`. Placeholder do Nome do escritório no Criar conta: "Ex.: BlueJus Advogados Associados". Sem migration.
- Login: o botão "Continuar com Facebook" sai do "Criar conta" até existir o app na Meta (ponto de ligação na spec `entrar-com-google.md`).
- Login: "Entrar com o Google" (cartão) e "Continuar com Google" (Criar conta) aparecem sempre; sem `GOOGLE_LOGIN_OAUTH_CLIENT_ID`/`GOOGLE_LOGIN_OAUTH_CLIENT_SECRET`, a ida `/login/google` não vai ao Google e volta à tela com o aviso "O acesso pelo Google ainda não está configurado neste ambiente. Use o e-mail." (no cartão, ou no modal com `origem=cadastro`). Com a credencial, nada muda.
- Pasta, janela "Editar dados" (desenho 02, VS 18.10.26): a Ação vira só leitura ("Ação · vem do menu Processo"), com o botão "Abrir", que fecha a janela, vai à aba Processo e abre a edição da Ação. O campo `nome_acao` saiu do formulário e o `pasta_edit` passa a ignorá-lo no servidor.
- O CPF/CNPJ do cliente principal aparece só leitura, formatado (000.000.000-00 ou 00.000.000/0000-00), com atalho "Cadastro" para a edição do cliente; sem cliente ou sem documento, texto neutro e sem atalho. Sem migration.

### Corrigido

- Pasta: a página dava erro 500 quando a pasta tinha um cliente pessoa física com o CPF vazio. A lista de clientes da janela "Editar dados" lia a razão social, que a pessoa física não tem; agora usa o nome de exibição do cliente (`nomeExibicao`). Teste `PastaClienteSemCpfTelaTest`.
- Login: o botão "Entrar com o Google" e o "Continuar com Google" do Criar conta só aparecem quando o cliente OAuth do login com o Google está configurado.
- Ponto: o lançamento manual e a edição manual de batida pelo admin (aba Ponto do colaborador) recusam data e hora acima de agora no relógio do servidor, com a mesma régua de `App\Ponto\Service\HorarioFuturoDaBatida`. O lançamento volta com o aviso; a edição reabre o formulário com o aviso no campo Hora. Nada é gravado. A hora exata de agora passa. Sem migration.
- Auditoria: o desfazer passa a seguir as regras de estado da tela, pelo novo `App\Auditoria\Service\EstadoDaTelaNoDesfazer`. Recusa, com aviso e sem mudar nada: o registro que mudou depois do log; a pasta excluída (lápide) e as filhas, a meta e a mensagem dela; a reabertura da meta que seguiu adiante; a reconclusão com data impossível; a etapa de meta concluída; a meta que ficaria concluída sem data ou aberta com data; o nome do cliente montado pela carteira; a situação e a situação do contrato da pasta. Na meta, roda com a meta travada numa transação. As regras foram extraídas das telas (`ReabrirMetaUseCase::seguiuAdiante`, `DesfazerReaberturaDaMetaUseCase::motivoParaRecusarAData`, `MontarEtapaDaMetaUseCase::podeMexerNaEtapa`, `RenomearClienteDaPastaUseCase::nomeEMontadoPelaCarteira`, `PastaSomenteLeituraListener::MENSAGEM`), que passam a chamá-las também. Sem migration.
- Auditoria: desfazer as etapas saltadas de uma meta dava erro 500; os valores voltam como etapas.

### Segurança

- Infra: imagem base fixada em `php:8.2.35-fpm` (correções de segurança do PHP; o deploy não faz `--pull` e a tag flutuante podia reusar a 8.2.34 do cache) e `session.upload_progress.enabled = Off` em `conf.d/seguranca.ini`, conforme o aviso de segurança do PHP de 23/10/2026. Sem mudança de comportamento no app.

## [2.16.0] - 2026-10-24

### Adicionado

- Pasta, aba Financeiro: os tipos "Êxito" e "Sucumbência" do "Adicionar pagamento" passam a funcionar como no desenho 02 VS 18.10.26. Pedem só o percentual (padrão 20 e 10) e gravam uma linha prevista, sem vencimento ("Honorários de êxito (N%)" / "Sucumbência estimada (N%)"), com selo azul "Previsto" e "N% sobre o total contratado · condicionado ao resultado".
- O previsto fica fora do contratado, do recebido, da barra, dos vencidos, das pendências e do relatório de pastas paradas; aparece só como "+ R$ X previsto em êxito | sucumbência" abaixo da barra e, na impressão, como "Previsto (êxito/sucumbência)". O valor é calculado na leitura (`BaseDoPercentual`) e congela ao "Marcar como recebido" ou ao "Corrigir valor".
- O card "Honorários contratuais" da faixa vira "Total contratado": soma automática, só leitura, dos lançamentos não previstos (base do percentual). A edição do valor da causa sai desta tela (segue no cartão do Processo).
- Migration `Version20261020151400`: `pasta_pagamento.tipo` (padrão 'contrato'), `percentual` e `vencimento` nulo, com CHECKs de coerência. Sem backfill: lançamentos antigos ficam como contrato.

### Alterado

- E-mails: o limite de envio passa a contar as últimas 24 horas corridas (Hostinger Business Standard/Premium, 3.000 por caixa), com teto de 2.400 no total, 1.600 para campanhas e 800 reservados aos e-mails de conta, convite e avisos, e 10 por minuto; todos os padrões são configuráveis por ambiente.
- E-mails: os enviados direto pelo sistema (cadastro, convite, senha, avisos, agendador) passam a contar no limite, registrados como "sistema".
- Master: aviso no topo quando os e-mails chegam a 80% do limite e quando as campanhas ficam pausadas pelo limite, com a hora em que voltam a enviar e o botão Dispensar.
- Pasta, Nova meta: campo obrigatório "Caixa / etapa", entre Prazo e Responsáveis (desenho 02 de 22/10, L179). Oito botões na ordem da esteira, com o nome que o escritório deu à etapa e o setor; a sugestão pelo título e pela descrição só pinta o botão e mostra a dica, sem marcar. A regra da sugestão sai do `EtapaDaPasta` por um data-attribute (fonte única). "Preencha:" cobra também a etapa, no fim.
- `tarefa_criar_para_pasta` exige `etapa` (valor do enum): ausente ou inválida responde 422; a etapa escolhida é gravada antes do PrePersist do título.
- Duplicar meta: a cópia herda a caixa / etapa da original.
- Sem migration (a coluna `tarefa.etapa` já existe).
- Agendador: o envio ao Google Drive de hora em hora (`app:sync:reconciliar`) roda sem `--tenant-id` e percorre todos os escritórios com Drive conectado; o escritório que desconecta para de enviar (decisão do dono, 24/10/2026). Sem nenhum conectado, termina com sucesso, sem aviso de falha.

### Corrigido

- Campos de formulário escuros em tela clara para quem usa o sistema operacional em modo escuro: `base.html.twig` passa a declarar `<meta name="color-scheme" content="light">` (antes "light dark"); o tema escuro do sistema segue pelo `data-bs-theme="dark"`.
- Equipe > Editar Permissões, aba Dados Pessoais: os 5 campos ganham `form-control`. Cobrança > Acordo, janela Editar: o campo do valor total negociado também.
- Ponto: batida esquecida com data e hora acima de agora no relógio do servidor (a mesma régua da gravação da batida) é recusada no lote de justificativas do admin, no pedido de esquecimento do colaborador e na aprovação desse pedido (individual e "Aprovar todos", que deixa o pedido pendente). Antes, uma batida de hoje com hora futura passava. Regra única em `App\Ponto\Service\HorarioFuturoDaBatida`. Sem migration.

## [2.15.0] - 2026-10-23

### Adicionado

- Pasta, aba Metas: "Duplicar" no menu ⋮ da meta (desenho 02, PMC-032), entre "Editar nome" e "Encaminhar via Chat I.A". A cópia nasce na hora na mesma pasta, aberta, com descrição, prazo (mesmo vencido), prioridade exata (Normal fica Normal, sem herdar a da pasta) e tipo de demanda da original e o título com " (cópia)" (até 120 caracteres); não notifica ninguém. Dos responsáveis, só vão os que a Nova meta aceitaria (vínculo ativo); se nenhum sobrar, a duplicação é recusada com "Nenhum responsável desta meta pode receber metas agora. Crie pela Nova meta." Sem XHR, recusa e sucesso respondem com aviso e volta para a aba Metas. O aviso "Meta duplicada" traz "Desfazer", que exclui a cópia. Rota nova `tarefa_duplicar` (POST, CSRF por id, mesmas guardas da criação de meta; recusada na pasta excluída). `tarefa_excluir` passa a responder JSON por XHR.
- Pasta, modal "Nova meta" (D-META): prazo e ao menos um responsável passam a ser obrigatórios, com o "*" nos rótulos e o "Criar meta" desabilitado com "Preencha: título, descrição, prazo, responsável" (só os que faltam). Aberto pelo botão "Nova meta", o prazo já vem em hoje + 7 dias. O servidor responde 422 sem prazo válido ou sem responsável do escritório. Sem migration.
- Pasta, menu ⋮ do cabeçalho: "Mover para outra carteira", como no desenho 02 VS 18.10.26. O submenu "Mover pasta N para" lista as seis carteiras (Contencioso cível, Condominial, Família e sucessões, Trabalhista, Previdenciário, Empresarial), marca a atual com "· atual" e grava pela rota `pasta_mover_carteira` (POST, CSRF, permissão de editar a pasta). O Registro ganha o cartão "Carteira: movida de "X" para "Y"." e a Timeline mostra "Carteira alterada".
- Migration `Version20261020151500`: coluna nula `pasta.carteira`. As pastas existentes ficam "Sem carteira"; nada é preenchido para trás.
- Pasta, aba Processo: "Editar ação da pasta" no ⋮ do cartão do processo principal e no ⋮ do cabeçalho da aba (decisão do dono de 23/10/2026; desenho 02: "Ação · vem do menu Processo"). Formulário no padrão da edição no cartão (P5b), com XHR; rota `pasta_acao_salvar` (POST + CSRF), `EditarAcaoDaPastaUseCase` com a guarda de EDIT na pasta (teto do cadeado incluído) e do escritório; a lápide e o cadeado em Visualizar são recusados pelos listeners. A troca escreve o cartão do sistema "Dados da pasta: ação alterada pela aba Processo" no Registro, entra no audit_log e, se o nome da pasta mudou, enfileira a renomeação no Drive. Sem migration.

### Alterado

- Contribua, desenho 08 VS 21.10.26: selo "CONVÊNIO OAB" no pacote Free, que abre o modal do convênio (8 benefícios, "Uma parceria para durar", rodapé OAB + BlueJus); etiqueta "+ Espaço" com nuvem nos pacotes pagos maiores que o atual; botões dos cartões alinhados no rodapé. Sem migration.
- Central: os avisos do Contribua mostram "Ver sugestão" no lugar de "Abrir" (campo `acao` em `/central/avisos`, nulo nos demais tipos).
- Ponto: esquecimento nunca trava a batida. O ponto aberto em outro escritório e a interjornada contada de uma saída vinda de esquecimento viram aviso na resposta da batida; o "Regularizar ponto de ontem" do cabeçalho vira aviso ao lado do "Bater ponto"; a tela do ponto lista as pendências dos últimos 30 dias, deste e dos outros escritórios da pessoa; a saída sem entrada passa com aviso; um cartão diário lembra a pendência; os avisos só vão para quem registra ou bateu ponto no escritório; a ficha do admin sinaliza o dia com registro em outro escritório, sem dizer qual. Sem migration.

## [2.14.0] - 2026-10-23

### Adicionado

- Agenda: janela que orienta a conectar o Google Agenda ao abrir a tela, para quem ainda não conectou, com "Agora não", "Sincronizar agora" (mesmo fluxo do botão do painel) e a caixa "Não mostrar esta mensagem novamente". A dispensa fica em `preferencia_usuario` (chave `agenda.google_aviso_dispensado`, por escritório e usuário), gravada por `POST /agenda/google/aviso/dispensar` com CSRF. Não abre com o servidor sem as credenciais do Google. Sem migration.
- Relatório da Central de envios: "Cancelar envio" também nas campanhas na fila, enviando e enviadas com e-mails ainda na fila (antes só em agendada e recorrente), com a pergunta "Cancelar o envio desta campanha? O que ainda está na fila não sai.".
- Dashboard: o painel abre no período Este mês; "Limpar filtros" só aparece quando o filtro muda e volta para Este mês.
- Dashboard: nova coluna "Relatório" no Adicionar coluna, com os registros de cada pessoa em Dados da pasta, Financeiro e Detalhes no período.
- Dashboard: troféu para quem concluiu mais metas no período (empate: menos vencidas), com fanfarra no máximo duas vezes por página, desligável em Sons.
- Dashboard: botão direito no nome (ou toque longo no celular) abre o cartão do colaborador: posição no período, Comentários, 1º do mês por ano e Rendimento contra a média dos outros, com recorte por mês, trimestre, semestre ou ano.
- Dashboard: a coluna Relatório mostra a seta de tendência contra o período anterior.
- Pasta, aba Financeiro: selo "Assinado" na linha de cada arquivo, entre o nome e o menu ⋮, como no desenho 02 VS 18.10.26 (L1974-1976).
- Upload de documento da pasta (Financeiro, aba Documentos e peticionar): `.p7s`/`.p7m` e PDF com assinatura digital embutida (PAdES, ICP-Brasil, gov.br: `/Type /Sig` ou `/FT /Sig` com `/ByteRange` e `/SubFilter` conhecido) nascem assinados, origem `digital` (`DetectorDeAssinaturaDigital`, lê só o começo e o fim do arquivo, sem biblioteca nova). PDF assinado que a opção "reduzir tamanho" regravou nasce sem o selo, porque a assinatura não sobrevive à compressão.
- Menu ⋮ do arquivo no Financeiro: "Marcar como assinado" (assinado à caneta e escaneado) e, na marcação manual, "Desmarcar assinatura". Assinatura digital não se desmarca pela tela. Exige editar a pasta; pasta excluída fica somente leitura.
- Migration `Version20261020151000`: `pasta_documento.assinado_em`, `assinatura_origem` ('digital' ou 'manual') e `assinado_por_id` (FK user, ON DELETE SET NULL), todas nulas.
- Comando `app:pasta:detectar-assinatura-antigos` para os documentos que já existiam: sem `--aplicar` só lista e conta por escritório; com `--aplicar` grava a origem `digital`. Idempotente (pula o já marcado, digital ou manual, e nunca sobrescreve a marca manual), em lotes, lendo pelo armazenamento; arquivo ausente ou ilegível conta como erro e segue. Rodar uma vez depois da publicação, primeiro a simulação e depois `--aplicar` (DEPLOY.md, passo do dono).
- Gênero obrigatório (Feminino ou Masculino) na criação da conta: cadastro da tela de entrada, aceite de convite do escritório e da plataforma sem conta e convite do Trabalho Externo. O perfil nasce com o gênero, sem migration.
- Janela "Como devemos tratar você?" no layout para quem está logado sem gênero preenchido (sem perfil, nulo ou o legado `nao_informado`); Salvar grava no próprio perfil (`POST /perfil/genero`, CSRF) e o X fecha só na sessão (`POST /perfil/genero/fechar`).
- "Prefiro não informar" saiu das opções em todo lugar, inclusive no Meu Perfil; o valor `nao_informado` fica no enum como legado e conta como não preenchido (decisão do dono de 23/10).
- Esteira de Minhas Metas: avisado com OAB no cadastro e gênero declarado aparece como "Dra. Nome" ou "Dr. Nome" (desenho 04, `trat()`), sem adivinhar o gênero pelo nome.
- Menu da nota técnica do processo (aba Processo e Push Processual da pasta): novo item "Encaminhar via Chat I.A", depois de "Copiar texto", que abre o despacho da Central com a nota como item (tipo `nota_tecnica`, relido no servidor preso à pasta e ao escritório). Só com a Central disponível e fora da pasta excluída.

### Alterado

- Editar e excluir sem prazo para Master e usuário primário (spec `docs/specs/edicao-sem-prazo-master-primario.md`): - Nos 8 pontos com prazo (registro e mensagem da pasta, observações dos Detalhes e do Financeiro, nota técnica, exclusão da meta, anotação e desfazer qualificação da cobrança, apagar mensagem do Trabalho Externo), o Master edita e exclui qualquer conteúdo a qualquer momento; o primário também, quando a opção do escritório está ligada (padrão). Os demais seguem o prazo de sempre. - Opção na página do perfil "Usuário Primário" em Perfis: "Usuário primário pode editar e excluir qualquer registro sem limite de tempo". Só um primário altera. - Selo dourado "EXCLUSIVO DO MASTER" ou "EXCLUSIVO DO PRIMÁRIO" ao passar o mouse no botão liberado por esse poder. - Despacho da meta com código continua imutável para todos. Meta com despacho registrado só é excluída pelo Master ou primário, com confirmação explícita. - Toda edição ou exclusão feita por esse poder fica na auditoria. - Na página da meta saiu o "(editado)".
- Migration `Version20261020150600`: tabela `escritorio_configuracao_edicao`.
- Idioma do sistema: o seletor (menu do usuário e Meu Perfil) lista só Português (Brasil), Inglês e Espanhol (`Idioma::oferecidos()`); os outros sete saem da lista, sem apagar caso do enum nem catálogo. Só o português está liberado: inglês e espanhol seguem "em breve" e o `POST /perfil/idioma` continua recusando tudo que não é `pt-BR` (422). Quem tinha outro idioma gravado no perfil cai em português, sem erro. Sem migration.
- Termos de Uso (`/termos/aceitar`, GET e POST) e Política de Privacidade (página e PDF) fixam o locale `pt_BR` na rota, e o `IdiomaDaRequisicaoListener` respeita: o aceite e a ciência ficam amarrados ao texto em português, qualquer que seja o idioma da pessoa.
- Pasta, "Editar dados": a janela do desenho 02 (VS 18.10.26) no lugar do modal do Bootstrap. Sobreposição e caixa próprias, X, Fechar, clique no fundo e Esc fecham, foco no número com o texto selecionado ao abrir. Campos, regras e o POST para `pasta_edit` não mudam. Ação só leitura e CPF/CNPJ seguem com o dono (matriz PZ-MOD-N05 e PZ-MOD-16).
- Excluir pasta passa a mandá-la sempre para a lixeira (lápide restaurável), inclusive a última da sequência, que antes era apagada de verdade com os arquivos; o número da pasta excluída continua ocupado.
- Confirmação e aviso da exclusão com os textos do desenho 02 Expedientes, sem o prazo (decisão do dono em 23/10): "Essa ação vai para a lixeira e fica registrada no histórico." e "Pasta {n} enviada para a lixeira (restaurável a qualquer momento)".
- Sai o caminho de remoção definitiva do ExcluirPastaUseCase (ResultadoExclusaoPasta, NumeracaoDePasta::existeNumeroMaiorQue, PastaDocumentoRepository::listarParaRemocaoDaPasta). Sem purga (decisão do dono em 23/10): a pasta fica na lixeira até ser restaurada e o espaço dos arquivos dela continua contando no armazenamento.
- As varreduras periódicas (push dos lembretes a cada minuto, Google Agenda a cada 5 minutos, automações do Comercial a cada 15 minutos e expansor de campanhas a cada minuto) passam a ser disparadas pelo Symfony Scheduler, no worker de produção, em vez de se reagendarem sozinhas. Uma volta que falha não interrompe mais a varredura.
- Saem os comandos `app:agenda:push-varredura`, `app:agenda:google-varredura` e `app:comercial:varredura`: não é mais preciso semear nem religar as varreduras.
- Uma campanha confirmada começa a sair na volta do minuto seguinte do expansor.
- A importação dos índices monetários do BCB (INPC, IPCA e taxa legal) passa a rodar sozinha todo dia 11, à 1h de Brasília, pelo agendador. O resumo diário e a cobrança do checklist seguem desligados.
- As tarefas agendadas do sistema que estavam no crontab real da VPS (purga, DJEN, resumo semanal aos clientes, relatórios mensais de pastas e envio ao Drive) saem do crontab do host e passam ao Symfony Scheduler, no contêiner `jusprime_scheduler_prod`, com horários em Brasília, trava e estado no banco (migration `Version20261023150800`).
- O resumo diário, a cobrança do checklist e a importação de índices monetários, que nunca rodaram em produção, ficam desligados até decisão do dono.
- Falha de tarefa agendada gera erro no log e, com `AGENDADOR_ALERTA_EMAIL` preenchida, um e-mail de aviso.
- Falha de tarefa agendada aparece num aviso vermelho no topo da tela para o Master e para quem tem o e-mail de alerta, com o botão Dispensar, e fica listada na Central do Master.
- A foto diária do Dashboard passa a ser agendada (23h55), o que liga as tendências de Vencidas e Prazos.
- O deploy automático guarda e devolve também a imagem do agendador na volta.
- Se o crontab do host ainda tiver as tarefas do sistema, o deploy publica a versão sem ligar o agendador e avisa, para nada rodar duas vezes.
- Trabalho Externo, modal "Equipe e privacidade": a 6ª chave da Equipe, "Publicar vagas e analisar currículos" (`equipe.talentos`), como no desenho VS 18.10.26. O backend já respeitava a permissão (nasce ligada); faltava a chave na tela. Sem migration.

### Corrigido

- Ponto: o "Aprovar todos" do lote de justificativas passa a gravar a batida dos pedidos de esquecimento de registro, igual à aprovação individual. A gravação saiu do `TenantController` para `App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase`, chamado pelos dois caminhos, com guarda de escritório. As outras justificativas seguem só abonadas. Sem migration.
- Ponto: a justificativa em lote lançada pelo administrador aceita de novo a data de hoje, que era ignorada como se fosse futura.
- Painel do Master: os itens "Exclusivo do Master" do menu do usuário passam a abrir a aba e a seção certas também quando o painel já está aberto (a âncora é relida no hashchange).
- Convites: as telas de quem gerencia convites (escritório e plataforma) e as listas do convidado logado deixam de levar o código do link do convite; revogar, reenviar, aceitar e recusar passam a usar o número do convite (achados de auditoria interna).
- Convites: quem gerencia convites só vê e mexe no convite de perfil que ele próprio poderia atribuir; convite de outro escritório ou fora do alcance responde 404.
- Convites: o e-mail do convite é validado na criação (mesma regra do cadastro) e a confirmação de reenviar e revogar sai do JavaScript da página.
- Convites: o banco guarda só o hash do código do link do convite; os links já enviados continuam valendo (achado de auditoria interna).
- Convites: reenviar um convite gera um link novo, e o link anterior deixa de valer.
- Auditoria: o código do link do convite não entra mais no registro de auditoria, e o que já estava gravado foi apagado.
- Cadastro: criar conta com um e-mail que já tem conta dá a mesma resposta do e-mail novo; a dona da conta recebe um e-mail avisando que já tem conta, com o caminho do "Esqueci a senha" (achado de auditoria interna).

### Segurança

- Auditoria: desfazer uma alteração de meta ou de pasta passa a exigir a mesma regra da tela que faria a alteração; a resposta da meta e a notificação deixam de ser desfeitas por aqui.
- A jornada pessoal e a chave "Registra ponto eletrônico" passam a valer por escritório, sem choque de horário entre escritórios (achado de auditoria interna F8/F11).
- Ponto eletrônico: correções de segurança no nome da sede, na planilha da folha e no desfazer da auditoria.
- Conta com pedido de exclusão não acessa as telas do escritório pelo endereço direto; o botão Sair passa a exigir a confirmação de segurança da sessão.

## [2.13.0] - 2026-10-22

### Adicionado

- Aprendizado das Metas, Fase 4 do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, 1.1 itens 1 e 2, C2 e D4): - **Despacho:** com o campo vazio, a página da meta sugere as 3 frases que a pessoa mais usa naquela etapa (filtradas pela natureza marcada), já marca a natureza preferida e troca o prazo padrão pelo prazo usual dela (mínimo de 2 despachos). - **Passagem de etapa:** o modal da meta mostra "Você costuma pedir:" com as 5 pendências mais frequentes da pessoa para a etapa destino. - **Meu Perfil:** chave "Sugestões a partir dos meus despachos" (ligada por padrão) e "Apagar o que foi aprendido". Desligada, nada é gravado nem sugerido. - O aprendizado fica no servidor, por pessoa e escritório, e nunca é compartilhado com colegas.
- Migration `Version20261020150400`: tabelas `tarefa_aprendizado_despacho` e `tarefa_aprendizado_pendencia`.
- Página da meta: bloco "Despacho" do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, Fase 2a). Natureza do despacho (Para ciência, Para providência, Para manifestação, Para conferência, Cumpra-se), prazo de cumprimento com padrão de 5 dias úteis pela regra do escritório (feriados nacionais, da UF e recesso) e "N dias úteis" ao lado, contador "N / 5.000" (limite conferido também no servidor), Ctrl+Enter e os botões "Despachar" e "Enviar para revisão". Título "Meta #N" com o número da meta no escritório.
- Linha do tempo: cada despacho como "DESPACHO N · natureza", com "Cumprir até", ciência automática ("Ciente · Fulano, data") quando o responsável ou o criador abre a meta, "Certificar cumprimento" (`tarefa_mensagem_certificar`) e a linha de registro "Registrado por NOME (Cargo) em DD/MM/AAAA às HH:MM · Cód. XXXX.XXXX" (decisão D1, nome e cargo do momento do despacho). Despacho registrado não pode ser editado.
- Conferência do código em `/tarefas/registro/{codigo}` (`tarefa_registro_validar`): só para quem é do mesmo escritório e está logado; mostra meta, autor, data e natureza, sem o texto.
- Página da meta alinhada ao desenho 04B: seta de voltar e trilha "Expediente / Pasta X / Meta", faixa "Aguardando despacho de" / "Aguardando revisão de" com os dias, painel de situação (Pendente, Aguardando revisão, Atrasada · N dia(s), Concluída) com "Etapa i/n · Nome", bloco "Detalhes da meta" (prazo com "Alterar", criada em, despachante), "Pasta: X · cliente", "DEMANDA" e a linha do tempo num formato único ("DESPACHO N", "MAIS RECENTE"). "Enviar para revisão" sem texto grava "Meta enviada para revisão do autor.". Despachar só com anexo e Cmd+Enter.
- Saem da página da meta "Excluir meta" e "Voltar à Pasta" (decisão do dono, o desenho não tem); excluir continua na aba Metas da pasta.
- "Precisa de atenção" em Minhas Metas abre a pasta no Expediente e deixa de fora metas de pasta excluída.
- `DiasUteisDoEscritorio` ganhou `somarUteis`, `uteisEntre` e `naoUteisEntre`.
- Migration `Version20261020150100`: colunas novas em `tarefa_mensagem` (natureza, prazo de cumprimento, ciência, certidão, código único por escritório e quem registrou).
- Página da meta, Fase 2b do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, seção 3.2 L9 e L10 e C3/C4): - **@menção no despacho:** mesmo mecanismo da pasta, com aviso na Central para quem é marcado e "quem → para" na linha do tempo. O placeholder volta a ser "Escreva o despacho. Digite @ para marcar alguém da equipe.". - **Anexo do despacho:** vira documento da pasta, pela regra de chaves, com a origem "Despacho · Meta #N", e aparece na linha do tempo como "nome · juntado à pasta". Os anexos antigos continuam como estavam. - **Extrato de tramitação:** o botão "Extrato" abre a tramitação para imprimir ou salvar em PDF pelo navegador, com a DEMANDA e cada despacho com natureza, quem → para, Cumprir até, Ciente e a certidão. - **Lembrar:** o botão na faixa "Aguardando" avisa na Central quem está com a meta (o autor, quando a meta está em revisão), no máximo 1 vez por hora por pessoa, e grava "Lembrete enviado para ..." no histórico. Tipo de aviso novo `tarefa_lembrete`.
- Sem migration.
- Passagem de etapa da pasta, Fase 3 do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, seção 3.2 L11 a L15): - **Faixa ETAPA DA PASTA** no topo da página da meta, com as etapas feitas, a atual, a próxima e as saltadas. - **Concluir e passar adiante:** botão "Concluir etapa" no cartão de Minhas Metas e "Concluir e passar para X" na lateral da meta. O modal escolhe a próxima etapa (com salto ou volta, na página da meta), o tipo de demanda, o responsável, o prazo (2 dias úteis) e as pendências. Conclui a meta, cria a da etapa seguinte, grava o histórico com código de registro e avisa o responsável novo e os avisados da caixa. - **Devolver para X** na lateral, para o responsável, com os motivos fixos do desenho. Em revisão, o despacho de quem criou a meta a devolve para pendente. - Concluir uma meta (inclusive na aba Metas da pasta) passa a ser só do responsável ou de quem criou.
- Migration `Version20261020150200`: `tarefa.etapas_saltadas` e `tarefa_mensagem.destinatario_id`.

### Alterado

- Minhas Metas e a página da meta ficam iguais aos desenhos 04 e 04B de 22/10, com os componentes do desenho no lugar dos do Bootstrap/AdminLTE (spec `docs/specs/minhas-metas-esteira-2210.md`, "Estado da frente"): - **Página da meta:** moldura centralizada com a lateral em coluna própria, título, trilha, faixa do DESPACHO no mesmo cartão, traço da linha do tempo, Acompanhar e as janelas de responsáveis, prazo e certidão no estilo do desenho. - **Minhas Metas:** KPIs refeitos (sem o ícone do canto, decisão do dono), cartões separados sobre fundo cinza, pílulas, barra de filtros, trilho, janela "Configurar caixa" e aviso de sucesso embaixo, que some em 3s. "Limpar filtros" também limpa a etapa da esteira. - **Saem, por decisão do dono (o que o desenho não tem):** traço vermelho do título, migalha, modo lista, menu ⋮ do cartão, selo "Nº", número da meta na pasta (vira a posição no grupo), contador de comentários, bloco "Pessoas" e o lápis de editar mensagem antiga na página da meta ("(editado)" continua). "Editar nome" e "Encaminhar via Chat I.A" seguem no ⋮ da aba Metas da pasta. - Avisos de erro continuam na tela até a pessoa fechar.
- Sem migration.
- Trabalho Externo: o Convidado de uma demanda cujo escritório está fora do ar (suspenso pelo Master ou excluído e em quarentena) só lê. Não manda mensagem, não registra andamento nem checklist, não envia documento, não apaga mensagem e não usa os botões de execução. Ver a demanda, o chat e o histórico, baixar documento e marcar como lida continuam. A tela mostra "Esta demanda está pausada. Fale com a Central." no lugar dos botões, sem o nome nem a situação do escritório. A Central segue agindo (spec `docs/specs/te-convidado-escritorio-fora-do-ar.md`).
- O link do convite de demanda de escritório fora do ar responde como o convite vencido ("Convite indisponível"), sem formulário: nem aceitar logado nem criar a conta gravam o aceite.
- Regra num lugar só: `App\Tenant\Service\SituacaoDaConta::foraDoAr`, consultada pelo `LocalizadorDeDemanda`, que marca o contexto do Convidado como só leitura. Sem migration.
- Vagas: a vaga aberta de escritório fora do ar sai da lista e do detalhe de quem é de fora, do link público de vagas e do e-mail da campanha, e recusa candidatura ("Vaga indisponível"). A pendente desse escritório sai da fila de aprovação do Master, e aprovar, reabrir ou ofertar de novo é recusado. Quem já se candidatou continua abrindo a vaga por "Minhas candidaturas".
- Avisos e entrada: o escritório fora do ar não recebe os avisos do Trabalho Externo, e quem tem vínculo com escritório fora do ar não é mandado direto ao Trabalho Externo depois do login: passa pela seleção de escritório e vê o aviso da conta.
- A janela "Vincular processo" (e "Vincular apensado"), dentro da pasta, ficou igual ao desenho 02 de 18/10: janela própria no lugar do modal do Bootstrap, busca de processo com a lista do desenho no lugar do Select2 (abre com os 8 primeiros do escritório, filtra enquanto se digita, aceita setas, Enter e Esc e mostra a origem de cada processo: "Pasta N", "Apensado · pasta N" ou "Push processual", e "Outra pasta" quando o usuário não pode ver a pasta), máscaras do número CNJ e de CPF/CNPJ, tribunal detectado enquanto se digita pela mesma tabela que o servidor grava, "Buscando…" no próprio botão "Buscar no CNJ", validação "Preencha: …" no clique que volta para a aba Informações, movimentação nova no topo, lixeira na própria linha e o aviso de sucesso "Processo vinculado". O aviso do rodapé da pasta ganhou a entrada animada do desenho.

### Corrigido

- Painel de presença do cabeçalho (a bolinha ao lado do avatar, "N on-line"): a lista mostrava só as iniciais, mesmo para quem tem foto, inclusive o próprio usuário. O `/central/equipe` já mandava a URL da foto (a mesma `app_profile_foto_serve` do avatar do topo), mas o `presenca-cabecalho.js` descartava o campo ao guardar a equipe. Agora a lista mostra a foto do perfil e deixa as iniciais só para quem não tem foto; a bolinha de status continua sobre o avatar. A cópia da equipe guardada na sessão do navegador mudou de chave, para não reaproveitar a antiga sem foto. A lista continua sendo só a equipe ativa do escritório.
- Trabalho Externo: o Convidado de uma demanda cujo escritório foi excluído (em quarentena) perde também a leitura, como o dono decidiu em 22/10. A demanda responde 404 para ele, inclusive no download e no marcar como lida, e some do painel dele. No escritório suspenso nada muda: ele segue só lendo. A Central continua agindo, e o acesso volta se o escritório for reativado.

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

[Não lançado]: https://github.com/sfbdata/Prime/compare/v2.25.0...HEAD
[2.25.0]: https://github.com/sfbdata/Prime/compare/v2.24.0...v2.25.0
[2.24.0]: https://github.com/sfbdata/Prime/compare/v2.23.0...v2.24.0
[2.23.0]: https://github.com/sfbdata/Prime/compare/v2.22.0...v2.23.0
[2.22.0]: https://github.com/sfbdata/Prime/compare/v2.21.0...v2.22.0
[2.21.0]: https://github.com/sfbdata/Prime/compare/v2.20.0...v2.21.0
[2.20.0]: https://github.com/sfbdata/Prime/compare/v2.19.0...v2.20.0
[2.19.0]: https://github.com/sfbdata/Prime/compare/v2.18.0...v2.19.0
[2.18.0]: https://github.com/sfbdata/Prime/compare/v2.17.0...v2.18.0
[2.17.0]: https://github.com/sfbdata/Prime/compare/v2.16.0...v2.17.0
[2.16.0]: https://github.com/sfbdata/Prime/compare/v2.15.0...v2.16.0
[2.15.0]: https://github.com/sfbdata/Prime/compare/v2.14.0...v2.15.0
[2.14.0]: https://github.com/sfbdata/Prime/compare/v2.13.0...v2.14.0
[2.13.0]: https://github.com/sfbdata/Prime/compare/v2.12.0...v2.13.0
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
