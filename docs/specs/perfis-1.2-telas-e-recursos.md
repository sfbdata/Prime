# Perfis 1.2 · Telas e recursos (liga e desliga global pelo Master)

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
| R8 | Item do catálogo cuja tela ou função **ainda não existe no sistema** (Contribua, Vagas públicas, Push compartilhado, Idioma, Ajuda e atalhos, Contribua atalho, Chat I.A) tem a chave e o interruptor funcionando; o efeito passa a valer quando a tela nascer, usando a mesma função Twig e o mesmo mapa de rotas. |

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
| contribua, pushpub, vagaspub | (não existem ainda) | R8 | |
| instalar | Instalar aplicativo | | `#linkInstalarApp`, `#btnInstalarApp` |
| idioma, ajuda | (não existem ainda) | R8 | |
| tema | Tema escuro | | `#toggleTema` |
| novidades | Novidades | `novidades_` | item do menu do usuário |
| privacidade | Política de Privacidade | (a rota pública continua aberta: é texto legal) | item do menu do usuário |
| chat | Chat I.A (não existe ainda) | R8 | |
| assistente | Assistente de pastas | `inteligencia_agente` | gaveta de agentes da pasta |

O mapa mora no catálogo (uma fonte só). Twig: `recurso_visivel('chave')` (false só para não-Master com o item
desligado) e `recurso_marca('chave')` (devolve a classe de marcação vermelha para o Master quando desligado).

## 5. Testes

Unit: catálogo (19 linhas, 18 chaves, 2 essenciais), estado com data vencida, UseCase de alternar (essencial recusado,
chave desconhecida recusada, motivo e data gravados, religar limpa motivo e data). Functional: não-Master
recebe 503 na tela desligada e o item some do menu; Master abre a tela com a faixa e vê o item marcado;
POST de não-Master 403; CSRF; essencial 422; data vencida reativa.
