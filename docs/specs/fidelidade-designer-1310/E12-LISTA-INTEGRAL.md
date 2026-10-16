# E12 · Lista do Expediente integral contra o 1.1 online (16/10/2026)

> **Para quem:** o dono e quem revisar ou continuar esta entrega da frente `fidelidade-designer`.
> **Risco:** MÉDIO (migration + permissão nova "só administrador do escritório"). Por isso esta spec.
> **Fonte:** `03 - EXPEDIENTE Lista 1.1.dc.html` lido pelo MCP `claude_design` em 16/10 (680 linhas,
> etag `1792155187406274`). É **mais novo** que a cópia local de 13/10 (544 linhas) contra a qual a E8d
> auditou. Referências `D:nnn` abaixo são linhas do arquivo de 16/10.

## 1. Pedido do dono (16/10)

"Implementar e atualizar a expediente_index: aplicar todas as funcionalidades, efeitos visuais, comandos
com o botão direito, ajustes e comandos visíveis e não visíveis, respeitando o projeto na íntegra do Claude
Designer." Respostas às perguntas de escopo:

- Blocos A (só tela), B (backend sem migration), C (migration e decisão) e D (efeitos de IA): **todos**,
  "aplicar forma idêntica do projeto".
- Frente: `fidelidade-designer`, em cima da E8 a E8d.
- Protocolar: **mostrar desativado** (sem contagem, dica "em breve").

Isso **substitui** as decisões anteriores que tiravam itens do desenho: "menus só com ações que existem",
"2 modos de visualização", "rótulo do sistema na prioridade", "cliente opcional na Nova Pasta",
"só Ativo/Arquivado". Continuam valendo (não contrariam o desenho, ou são regra global): hierarquia e vários
marcadores por pasta (o desenho já tem submarcadores), lápide da pasta excluída, sem travessão em texto
visível, foco visível, multi-tenant.

## 2. Inventário do desenho e destino de cada item

Legenda do destino: **T** só tela · **B** backend sem migration · **M** com migration · **X** sem lastro.

### 2.1 Trilho (D:74-95)
| Item | Destino |
|---|---|
| Largura 290 aberto / 64 recolhido, fundo #e9eff2, borda #d3dde4 | T |
| "Todas as pastas" 42px com contagem na dica e botão ↻ (zera filtros, efeito de busca, aviso) | T |
| Seção EXPEDIENTES com cadeado "filtros do sistema" e 6 filtros com contagem; botão direito abre nota | T + B |
| Cabeçalho MARCADORES com ⋮ (Novo marcador, Ver todas as pastas, Recolher/Expandir painel) | T |
| Marcador: seta de submarcadores, pasta cheia na cor, ⋮ no hover, contagem | T |
| Arrastar e soltar para reordenar (mesmo nível; submarcador só entre irmãos) | T + B |
| Botão direito no marcador: Ver pastas, Novo marcador, Novo submarcador, Mover para cima, Mover para baixo, Editar, Duplicar, Excluir | T + B |

### 2.2 Filtros EXPEDIENTES (D:571) e lastro medido
| Filtro | Regra implementada | Lastro |
|---|---|---|
| Respondidos nos últimos 10 dias | pasta com mensagem, observação (detalhes ou financeira), meta criada ou concluída, ou resposta de meta nos últimos 10 dias | existe |
| Prazo findou nos últimos 10 dias sem resposta | meta não concluída com prazo entre hoje−10 e ontem, sem atividade na pasta depois do prazo | só metas (não há prazo manual nem prazo do Push gravado) |
| Audiência | **desativado** como o Protocolar | não existe vínculo de audiência com pasta |
| Expediente com prazo | meta não concluída com prazo hoje ou depois | só metas |
| Metas não respondidas | meta não concluída sem nenhuma resposta | existe |
| Protocolar | **desativado** (decisão do dono) | não existe fase de andamento |

As dicas dizem o que a regra realmente conta (ex.: "prazo das metas"), não o texto do protótipo que cita
prazo manual e Push. Audiência segue o mesmo tratamento que o dono escolheu para Protocolar.

### 2.3 Barra do acervo e filtros (D:119-182)
| Item | Destino |
|---|---|
| Título clicável que volta ao acervo zerado | T |
| Nova Pasta (botão) | T |
| Situação em menu próprio: Todas, Ativo, Suspenso, Cancelado, Arquivado, com ponto pulsante | T + B |
| Busca 34px | já ✓ |
| Responsável em menu próprio com busca e avatar | T (o `<select>` fica escondido como fonte do formulário) |
| Prioridade em menu próprio: Todas, Normal, **Alta**, Urgente | T (rótulo do desenho; valor `prioridade` do banco) |
| Período, limpar (ícone) | já ✓ / T |
| Visualizar: Detalhes, Detalhes compactos, Lista, Cartões, Cartões grandes; Pastas por página 25/50/100; Painel de marcadores | T + B |
| Paginação e resumo | já ✓ |
| Botão de colunas (clique e botão direito): liga/desliga cada coluna (NUP fixo), Mostrar todas, Só as essenciais, Restaurar padrão, Exportar colunas visíveis (CSV) | T + B (CSV de todas as pastas filtradas, no servidor) |

### 2.4 Tabela (D:190-234)
| Item | Destino |
|---|---|
| Colunas NUP · Cliente · Réu · Ação · Prioridade · Responsável · Metas · Marcadores · Situação | T + B (Réu) |
| Réu: primeira parte de polo passivo/réu dos processos da pasta, "e outros" se houver mais; "Não informado" em cinza | B |
| Selo "Nx" ao lado do cliente: pastas do mesmo cliente principal | B |
| Cadeado no NUP da pasta trancada, com dica de quem trancou e quando | M |
| Destaque de linha em 6 cores, por usuário | M |
| Hover azul, menus de responsável e marcador | já ✓ |
| Situação Suspenso (#8a5a12) e Cancelado (#a3232b) | B |

### 2.5 Menu de botão direito da linha (D:346-367)
Abrir pasta · Abrir em nova aba · Editar nome rápido (F2) · Alterar responsável · Alterar marcador ·
Copiar número · Copiar nome · Trancar/Destrancar (só administrador; rótulo "só primário" para os demais) ·
Destacar linha (6 cores + sem destaque). Pasta trancada aberta por quem não é administrador: janela
"Pasta N protegida" com Entendi / Pedir acesso.

### 2.6 Modais
| Item | Destino |
|---|---|
| Nova Pasta 520px, arrastável pelo cabeçalho, aviso "PASTA {próximo número}", cliente obrigatório com sugestões e "já cadastrado em N pasta(s)", ação liberada só depois do cliente | T + B (próximo número e sugestões) |
| Novo/Editar marcador 420px: nome, descrição opcional, "Dentro de (submarcador)", 10 cores do desenho, prévia, erro "já existe" | T + M (descrição) + B (trocar pai, nome único) |
| Excluir marcador | já ✓ (E8b) |
| Sigilo | M |

### 2.7 Efeitos (D:22-50, 102-117, 184-189)
Pasta do título que fecha e abre (já ✓), ponto pulsante (já ✓), efeito "Só um instante, estamos buscando
em todo o sistema…" ao trocar de filtro do trilho (1,3 s), sobreposição "BlueJus I.A." disparada pelo
evento `bj-ai-fx` (casca: nada no BlueJus dispara o evento ainda), dicas no estilo do desenho (fundo #e6f2fa,
só quando o texto está cortado ou não está visível). Todos respeitam "reduzir movimento".

## 3. Decisões técnicas (onde o desenho é omisso)

1. **"Usuário primário" = administrador do escritório** (`TenantRole.isSystem`, o mesmo bypass do
   `PermissionChecker`). Não existe dono de escritório no modelo; isto é o mais próximo e já é a
   autoridade máxima do escritório.
2. **Trancar** grava `pasta.trancada_em` e `pasta.trancada_por_id`. A proteção é a do desenho: aviso na
   Lista ("o acesso não é recomendado"), **não** bloqueio no servidor da `pasta_show`. Bloquear de fato
   mexeria em autorização de outra tela e fica para decisão do dono.
3. **Pedir acesso** cria notificação real para cada administrador do escritório (tipo novo
   `pasta_pedido_acesso`). O aviso diz "Pedido de acesso enviado ao administrador do escritório", não "pelo
   Chat I.A.", porque o Chat I.A. não existe no sistema.
4. **Destaque** é por usuário (tabela `pasta_destaque`: usuário, pasta, tenant, cor), como o desenho guarda
   por navegador; não aparece para os colegas.
5. **Suspenso e Cancelado** entram como valores de `pasta.situacao` (coluna já é texto de 20). Consultas
   que hoje pedem "ativa" continuam pedindo só `ativo`. Esta entrega cobre filtro e exibição na Lista;
   **como marcar** uma pasta como suspensa ou cancelada é da tela da Pasta (desenho 02), fora desta entrega.
6. **Editar nome rápido** altera `pasta.nome_cliente` (o identificador da pasta, ver memória
   `pasta.nome_cliente é identificador`), recusado em pasta excluída; não reativa pasta arquivada.
7. **CSV** é gerado no servidor com os mesmos filtros e a mesma ordem, todas as páginas, separador `;`,
   BOM UTF-8, colunas visíveis (Metas fora, como o desenho).
8. **Preferências de tela** (modo, colunas, por página, trilho, submarcadores abertos) ficam no
   `localStorage`, como o desenho; nada disso é dado de negócio.
9. **Próximo número** da Nova Pasta é só informativo (o servidor continua gerando o número ao salvar).

## 4. Migration

Uma só: `pasta.trancada_em` (timestamp nulo), `pasta.trancada_por_id` (FK user nula, `ON DELETE SET NULL`),
`marcador.descricao` (varchar 255 nulo), tabela `pasta_destaque` (id, tenant_id, usuario_id, pasta_id, cor
varchar 20, único usuário+pasta, `ON DELETE CASCADE`).

## 5. Testes

Unit dos UseCases novos (reordenar, duplicar, renomear, trancar, destacar, pedir acesso, editar marcador
com pai e descrição); functional dos endpoints (isolamento entre escritórios em cada um, CSRF, permissão de
administrador no trancar); repositório dos filtros EXPEDIENTES e do Réu/Nx; testes de arranjo da tela com
combinador de filho direto. Suíte completa no fim, rodada sozinha.

## 6. Fora desta entrega

Bloqueio real de acesso à pasta trancada no servidor; marcar Suspenso/Cancelado na Pasta; prazo manual,
prazo do Push e audiência (sem dado); Protocolar (sem fase); Chat I.A. e Assistente de pastas.
