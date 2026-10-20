# Changelog

Todas as mudanças relevantes do BlueJus ficam registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota o
[Versionamento Semântico](https://semver.org/lang/pt-BR/). A política de versões, os critérios de
cada nível e o procedimento de release estão em [docs/versionamento.md](docs/versionamento.md).

## [Não lançado]

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

[Não lançado]: https://github.com/sfbdata/Prime/compare/v2.3.0...HEAD
[2.3.0]: https://github.com/sfbdata/Prime/compare/v2.2.2...v2.3.0
[2.2.2]: https://github.com/sfbdata/Prime/compare/v2.2.1...v2.2.2
[2.2.1]: https://github.com/sfbdata/Prime/compare/v2.2.0...v2.2.1
[2.2.0]: https://github.com/sfbdata/Prime/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/sfbdata/Prime/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/sfbdata/Prime/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/sfbdata/Prime/releases/tag/v1.0.0
