# Perfis 1.2 · Telas e recursos (liga e desliga global pelo Master)

## Estado da frente (Perfis 1.2, sessão b5; atualizado em 19/10/2026)

**Decisões do dono**
- 19/10: o Master primário promove e remove Masters secundários (o D2 "Master é único" deixa de valer).
- 19/10: "o Master pode tudo".
- 19/10: excluir conta = quarentena de 365 dias, com o texto do modal "A conta X sai do ar agora e é apagada de vez em 365 dias. Até lá, pode ser recuperada pelo Master." e a confirmação "digite EXCLUIR".
- 19/10: planos Free 10 GB/R$ 0/3 usuários, Essencial 20/R$ 19,90/8, Profissional 50/R$ 39,90/15, Avançado 100/R$ 69,90/sem limite, editáveis pelo Master.
- 19/10: o bloqueio de envio sai desligado.
- 19/10: verificação visual no navegador autorizada (porta 8103, banco isolado).

**Decisões da coordenação**
- Q2 a Q4 da conta: a conta suspensa ocupa vaga; as rotas antigas recusam a conta do Master.
- Suspender a conta do Master é recusado (C5).
- C12 (bypass do Master em escritório suspenso): pendente do dono; recomendação: manter sem bypass.

**Contratos com outras sessões**
- 7d (Meu Perfil):
  - `PedidoDeExclusaoDeConta` (pendente | desistida | reativada | excluida), "suspensa a pedido" como estado próprio;
  - barreiras de acesso no listener na ordem conta → pedido → vínculo → situação;
  - o `ReativarContaUseCase` não mexe em pedido "excluida";
  - menu do avatar nos parciais `menu_usuario/*`;
  - inscrições de OAB: os três UseCases são deles.
- f6 (Contribua, #64):
  - `SituacaoDoEspaco`/`RetratoDoEspaco`, `DefinirLimiteDe{Armazenamento,Usuarios}UseCase`, `ConfigurarFaixasDoEspacoUseCase`, `AlterarPlanoUseCase`, `CatalogoDePlanos::todos()`;
  - a auditoria é envolvida pelo meu controller: `executarComoConta` para limites, plataforma para faixas e planos.
- 5d (Comercial e e-mails): `#sec=com`, parcial `master/_comercial.html.twig` (include ignore missing) e evento `mp:secao`.
- 4b: "agenda" não é chave do catálogo de Telas e recursos.

**PRs e ordem de merge**
- #52 Masters (base master; migration de dado `Version20261019120000`).
- #72 Contas: suspender, reativar e excluir (empilhado sobre o #52, sem migration).
- #59 Lista de perfis (base master, sem migration).
- #77 IP do cliente (base master, sem migration; depois do merge, o dono troca `SYMFONY_TRUSTED_PROXIES=127.0.0.1` na VPS).
- #56 Telas e recursos (empilhado sobre o #52; migration `Version20261019172536`).
- Ordem: #52 → #72; #59 e #77 a qualquer momento; #56 quando a fila de migrations chegar.

**Fila de migrations:** #52 → Push (0d) → #56 → PR 2 da 7d → C1 da f6. Antes do merge do #56, renomear a migration dele com o timestamp do momento, porque o #55 entra antes.

**Pendências**
- C12, que é do dono.
- Bloco "Pedidos de exclusão" (espera o PR 5 da 7d).
- Planos e limites (espera o #52 e o #72 no master e a f6 trazer o master para a contribua-cora). O rascunho da spec está em `docs/specs/perfis-1.2-planos-e-limites.md`, depois de criado; a referência visual está em `scratchpad/ref-planos/`.
- Religar a revisão de OAB para as inscrições suplementares (depois do PR 3 da 7d).

**Próximo passo:** cortar `perfis-planos` sobre a `contribua-cora` quando ela tiver o #52 e o #72. Até lá, manter as PRs atualizadas com o `origin/master` depois de cada merge.


Frente `perfis-telas-recursos` · **empilhada sobre `perfis-masters`** (mesmos `master/painel.html.twig` e
`painel.js`; o deploy será as duas juntas) · risco **MÉDIO** (bloqueio de rota para todos os escritórios) ·
aberta em 19/10/2026.

## 1. Fontes

- Desenho aprovado `05 - Perfis 1.2 - VS 18.10.26.dc.html` (cópia em `docs/design/perfis-1.2/`): aba
  "Telas e recursos" (linhas 299-309; lógica 671-677) e o script `bj-recursos.js` do mesmo projeto (cópia parcial ao
  lado, com o catálogo), que diz como o sistema real deve ser: tabela `recurso_estado` lida pelo backend; rota de tela
  desativada devolve a página "em preparação"; item de menu desativado nem é renderizado.
- Pedido do dono (19/10/2026): "viabilize todos os recursos necessários… para garantir que fiquem funcionais
  cada comando, no modo global".

## 2. Regras

| # | Regra |
|---|---|
| R1 | Catálogo **fixo no código** (`CatalogoDeRecursos`), na ordem e com os rótulos, ícones e grupos do `bj-recursos.js`: 10 Telas, 7 Menu do usuário, 2 Funções (19 linhas). "Contribua" (tela) e "Contribua (atalho)" usam a **mesma chave** `contribua`, como no protótipo: são 18 chaves, e as duas linhas mostram o mesmo estado e mudam juntas. |
| R2 | Estado **global** (vale para todas as contas), na tabela `recurso_estado`: chave, ativo, motivo, ate (data), alterado_por, alterado_em. Sem linha = ativo. |
| R3 | **Essenciais** não desligam: `lista` (Expediente · lista de pastas) e `meuperfil` (Meu Perfil). Cadeado com title "Essencial"; clique mostra o aviso "Tela essencial: não pode ser desativada." e o servidor também recusa. |
| R4 | Desligado com data `ate` volta a valer como ativo depois das 23:59:59 desse dia (sem cron: a leitura compara com hoje). |
| R5 | **Quem não é Master** não vê o item (menu, botão, atalho) e, ao abrir a rota de uma tela desligada, recebe a página "em preparação" (status 503): ícone `bi-tools`, "<Título> em preparação", o motivo (ou "Estamos finalizando esta tela para ela chegar com tudo funcionando. Volte em breve."), "Previsão: dd/mm/aaaa" quando houver data, e o botão "Voltar ao Expediente". Chamada de API (não-GET ou `Accept: application/json`) da mesma função recebe 503 JSON com a mesma mensagem. |
| R6 | **O Master** continua vendo e usando tudo: o item desligado aparece apagado com contorno vermelho tracejado (`opacity:.45; outline:1px dashed #c0392b; outline-offset:-2px`) e a tela desligada mostra a faixa vermelha fixa "Esta tela está desativada para os usuários. Só o Master está vendo." |
| R7 | Só `ROLE_SUPER_ADMIN` liga e desliga. Cada mudança vai para a auditoria (`RecursoEstado` é `Auditavel`). |
| R8 | Item do catálogo cuja tela ou função **ainda não existe no sistema** (Contribua, Vagas públicas, Contribua atalho) tem a chave e o interruptor funcionando; o efeito passa a valer quando a tela nascer, usando a mesma função Twig e o mesmo mapa de rotas. |

## 3. Tela (aba "Telas e recursos", só no perfil Master)

Terceira aba `bi-toggles` "Telas e recursos". Cabeçalho lilás `#f5effa`: "Telas e recursos do sistema" e
"Desative uma tela ou função enquanto não estiver pronta, por regularidade ética ou por falha. Quem não é
Master deixa de ver; o Master continua vendo, marcado em vermelho." Resumo à direita: "Tudo ativo" (verde
`#1e7b45`) ou "N desativado(s) para os usuários" (vermelho `#a3232b`); busca "Buscar tela ou função" (filtra
título e grupo). Grupos "Telas · 10", "Menu do usuário · 7", "Funções · 2"; grade
`repeat(auto-fill, minmax(320px, 1fr))`; cada item é um botão com ícone, título (cadeado se essencial),
descrição (`d`, ou "Tela inteira" para tela, ou "Item do menu da foto"), linha vermelha "motivo · volta em
dd/mm/aaaa" ou "Sem motivo informado" quando desligado, rótulo "Ativo"/"Desativado" e a chave 36x20.

Desligar (como no desenho): 1º `prompt('Motivo exibido aos usuários (opcional). Ex.: "Tela em preparação",
"Revisão ética", "Correção de falha":', 'Tela em preparação')` (cancelar aborta); 2º `prompt('Reativar
automaticamente em (AAAA-MM-DD), ou deixe em branco:', '')` (data inválida vira "sem data"). Religar: um
clique. `POST /master/recursos/{chave}` (JSON, CSRF `master_recursos`), resposta com o novo estado; a tela se
redesenha sem recarregar.

## 4. Onde cada chave age

| Chave | Rótulo | Rotas (prefixo do nome) | Menu / elemento |
|---|---|---|---|
| dashboard | Dashboard | `dashboard_` | sidebar "Dashboard" |
| expedientes | Pasta (Expedientes) | `pasta_show` | links "abrir pasta" seguem; a rota bloqueia |
| lista | Expediente · lista de pastas | essencial | |
| metas | Minhas Metas | `tarefa_minhas` | sidebar e subnav |
| trabalho | Trabalho Externo | `trabalho_externo_` (exceto as 3 rotas públicas do link de convite, por nome exato: `trabalho_externo_convite`, `trabalho_externo_convite_criar_conta` e `trabalho_externo_convite_aceitar`; `_convite_reenviar` e `_convite_revogar` são API logada e seguem a chave) | sidebar e subnav |
| peticionar | Peticionar | `pasta_peticionar` | botão em `pasta/_processos_vinculados` |
| meuperfil | Meu Perfil | essencial | |
| pushpub | Push compartilhado (link público) | `push_compartilhado` (página pública /p/…) e `pasta_push_links_` (API que gera, renova e revoga) | item "Link externo do Push" no menu da pasta |
| vagaspub | Vagas públicas (link) | `trabalho_externo_vagas_publicas`, `_vagas_link`, `_vagas_compartilhar` (vence o prefixo mais longo, antes de `trabalho`) | |
| contribua | Contribua | `contribua_` (o webhook `contribua_webhook_cora` fica sempre aberto) | o atalho do menu do usuário é envolvido pela f6 com `recurso_visivel('contribua')` |
| instalar | Instalar aplicativo | | `#linkInstalarApp`, `#btnInstalarApp` |
| idioma | Idioma | | `[data-mu-idioma]` no menu do usuário (`menu_usuario/_dropdown`) |
| ajuda | Ajuda e atalhos | | `[data-mu-ajuda]` no menu do usuário |
| tema | Tema escuro | | `#toggleTema` |
| novidades | Novidades | `novidades_` | item do menu do usuário |
| privacidade | Política de Privacidade | (a rota pública continua aberta: é texto legal) | item do menu do usuário |
| chat | Chat I.A | (a página /central continua aberta: a chave é só a bolha) | bolha flutuante da Central (`central/_flutuante`, `data-recurso="chat"`, como no bluejus-central.js de 18/10) |
| assistente | Assistente de pastas | `inteligencia_agente` | gaveta de agentes da pasta |

O mapa mora no catálogo (uma fonte só). Twig: `recurso_visivel('chave')` (false só para não-Master com o item
desligado) e `recurso_marca('chave')` (devolve a classe de marcação vermelha para o Master quando desligado).

## 5. Testes

Unit: catálogo (19 linhas, 18 chaves, 2 essenciais), estado com data vencida, UseCase de alternar (essencial recusado,
chave desconhecida recusada, motivo e data gravados, religar limpa motivo e data). Functional: não-Master
recebe 503 na tela desligada e o item some do menu; Master abre a tela com a faixa e vê o item marcado;
POST de não-Master 403; CSRF; essencial 422; data vencida reativa.
