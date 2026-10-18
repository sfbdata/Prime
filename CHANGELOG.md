# Changelog

Todas as mudanças relevantes do BlueJus ficam registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota o
[Versionamento Semântico](https://semver.org/lang/pt-BR/). A política de versões, os critérios de
cada nível e o procedimento de release estão em [docs/versionamento.md](docs/versionamento.md).

## [Não lançado]

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

[Não lançado]: https://github.com/sfbdata/Prime/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/sfbdata/Prime/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/sfbdata/Prime/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/sfbdata/Prime/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/sfbdata/Prime/releases/tag/v1.0.0
