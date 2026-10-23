# 07. Roteiro de smoke da pasta_show

> Para o dono, no navegador do Windows. O Claude não executa este roteiro (o smoke é do dono). Serve para três coisas: (A) confirmar na tela os achados desta auditoria, (B) medir o que a análise estática não resolve, (C) servir de roteiro de regressão para cada entrega do `06-plano-incremental.md`.
> **Ambiente:** `http://127.0.0.1:8000` (servidor da worktree, banco `saas_ux`), login de dev `farlei.rocha@gmail.com`, escritório "Farlei Assis da Rocha Sociedade Individual de Advocacia". No aviso do ponto, **fechar sem registrar**. Protótipo de referência: o online no Claude Design (`02 - EXPEDIENTES 1.2.3.dc.html`); a 8765 serve o pacote de 13/10, **sem** as novidades de 16/10.
> **Pastas de dev:** 304 (Duplicar, Editar dados), 588 (processo vinculado), 1170/1171 (checklist), 433 (comentário do Financeiro).
> Passos marcados **[grava]** alteram o `saas_ux`: parar no Cancelar, salvo indicação. Passos **[perfil]** pedem um segundo usuário com perfil restrito (só VER a pasta; ou módulo Tarefas sem acesso à pasta).

## A. Confirmar os achados (estado de hoje, antes de qualquer correção)

| # | Passo | Esperado HOJE (achado) | Esperado depois da correção |
|---|---|---|---|
| A1 | [perfil] Usuário com módulo Tarefas e só VER a pasta 304: aba Metas, ⋮ de uma meta | "Marcar como concluída" aparece (P0-1) | Não aparece; `/tarefas/{id}/concluir` devolve 403 |
| A2 | [perfil] Mesmo usuário abre `/tarefas/{id}` de uma meta da 304 | Abre e mostra os botões de ação (P0-1) | Abre só leitura (ou 403 se não vê a pasta) |
| A3 | [perfil] Usuário com módulo Expediente e só VER a pasta: "+" dos marcadores no cabeçalho | O "+" aparece e salva (P0-2) [grava: cancelar] | O "+" não aparece; o POST devolve 403 |
| A4 | Trilho de Dados, lixeira ao lado de um cliente (pasta com 2 clientes) | Desvincula **sem perguntar** (P0-4) [grava: não clicar; observar o título do botão] | Janela vermelha "Desvincular cliente"; nenhum POST antes do "sim" |
| A5 | Aba Documentos | Moldura dupla (cartão dentro de cartão, respiro de 18/20px) (VIS-02) | Um cartão só |
| A6 | Botão BLUEJUS IA (drawer) | Cabeçalho e X do drawer cobertos pela barra do topo; véu não escurece a barra (VIS-01, provável) | Drawer inteiro visível, véu sobre a barra |
| A7 | ⋮ do cabeçalho > Excluir pasta | Caixa nativa do navegador pedindo o número (PZ-CAB-25) [grava: cancelar] | Janela vermelha com campo do número |
| A8 | Trocar a prioridade com a rede desligada (DevTools > Offline) | Erro em `alert()` nativo | Aviso da tela |
| A9 | Drawer do Histórico: evento de meta com detalhe | Texto com travessão "«título» — detalhe" (VIS-12) | Sem travessão |
| A10 | Pasta excluída (lápide; NUP 1214 no dev) | Faixa com travessão (VIS-12) | Sem travessão |
| A11 | Trilho "Próximos prazos" numa pasta com 4+ metas abertas com prazo | Mostra 3 e conta 3; selo "Vence hoje" / "Falta 1 dia" | Até 8, conta o total; "Hoje" / "Faltam 1 dia" |
| A12 | Botão "Cadastro" (linha 3 do cabeçalho) | Abre "Detalhes do cliente" (PZ-CAB-47) | Conforme decisão (modal de Cadastro, P1-8) |
| A13 | Marcadores: alterar e salvar [grava: só em pasta de teste] | Etiquetas do cabeçalho só mudam ao recarregar (PZ-MOD-11) | Mudam na hora |

## B. Medir na tela (o que a análise estática não resolve)

Larguras: 1440×900, 1024×768, 390×844. Comparar com o protótipo online nas mesmas larguras.

| # | O que medir | Onde | Referência |
|---|---|---|---|
| B1 | Rolagem lateral da página a 390px (cabeçalho, Registro com recuo de 46px, explorador) | Aba Dados e Documentos | VIS-15, PZ-GLB-N06 |
| B2 | Abas a 1024px: rolam escondidas antes do modo compacto? | Linha 3 do cabeçalho | VIS-04 |
| B3 | Véu, posição (topo 4vh) e altura dos campos do modal Editar dados | ⋮ > Editar dados (304) | VIS-06 |
| B4 | Ícone do tipo no trilho Documentos | Trilho de Dados | VIS-13 |
| B5 | Largura e sombra de cada menu ⋮ (220, 240, 250, 260px) contra 230px do desenho | Todos os ⋮ | PZ-CTX-GM |
| B6 | Selo carimbado (ATIVO/ARQUIVADO) com `zoom` | Trocar situação [grava: voltar ao estado] | relatório visual §7 |
| B7 | Barra do editor com `editor-rico.css` carregado depois | Registro e Detalhes | relatório visual §7 |
| B8 | Menu do responsável com `_resp_estilo` inline | Cabeçalho | relatório visual §7 |
| B9 | Tema escuro da página inteira; lista de @menção | Todas as abas | VIS-16 |
| B10 | Tempos dos chips do editor (3,6 s, 6 s, 7 s) e animação do aviso | Registro | PZ-EDT-10 a 13, PZ-GLB-12 |
| B11 | Desfazer de "mover" no explorador devolve à origem sem duplicar | Documentos [grava: mover e desfazer em pasta de teste] | PZ-DOC-N02 |

## C. Regressão por área (rodar depois de cada entrega na área tocada)

### C1. Cabeçalho
1. Voltar, número, setas entre pastas (anterior/seguinte), atalhos de teclado das setas.
2. Prioridade: menu Normal / Prioridade / Urgente [grava: voltar ao valor].
3. Responsável: menu com busca, "Sem responsável" [grava: voltar].
4. Marcadores: "+", modal, etiquetas.
5. ⋮: Editar dados (Salvar só acende com alteração; abas internas pedem "Descartar as alterações?"), Duplicar (janela azul; Cancelar), Alterar número, Timeline (atalho T), Histórico (atalho H), Imprimir, Fixar nos favoritos, Arquivar, Excluir (Cancelar).
6. Situação: pílula e menu; selo carimbado.
7. Faixa Processo · Ação · Responsável (P1 preservada): copiar CNJ, "trocar", chip "N vinculados", bloco Cobrança.
8. Linha vermelha de pendência nas abas.

### C2. Cadastro do cliente e trilho de Dados
1. "Cadastro" / "Detalhes do cliente": contatos, Copiar qualificação, Editar cadastro.
2. Trilho Clientes: estrela (tornar principal), Abrir ficha, lixeira (com janela depois da P0-4).
3. Próximos prazos: linhas, selos, ⋮ (Abrir na meta, .ics, Alertar).
4. Documentos do trilho.
5. Registro dos expedientes: enviar (Ctrl+Enter), responder, editar e excluir dentro de 15 min, botão direito (Responder, Copiar), @menção, colar (Manter, Mesclar, Somente texto).

### C3. Metas (pasta 304)
1. Nova meta: campos, aviso de título parecido, calendário [grava: Cancelar].
2. ⋮ da meta: Abrir relatório, Copiar link, Abrir em Metas, Editar nome (`?acao=renomear`), Responsáveis, Concluir/Reabrir com Desfazer [grava: desfazer].
3. Drawer da meta: Esc fecha, histórico real.
4. Filtros e realce vindo de Minhas Metas.

### C4. Processo (pasta 588)
1. Cartão do processo, situação legível ("Em andamento"), Tornar principal, Ver processo completo.
2. Vincular processo (DataJud) [grava: Cancelar]; Desvincular (janela) [grava: Cancelar].
3. Notas técnicas: criar, editar, excluir (janela) [grava: Cancelar].
4. Peticionar.

### C5. Financeiro (pasta 433)
1. Faixa: Honorários contratuais, Média do cliente principal, Contrato, Pró-bono.
2. Pagamentos: Adicionar (descrição), parcelamento, Marcar recebido / Desfazer [grava: desfazer], "Ver todos", limite de 3 + "ver os outros N".
3. Observações do Financeiro (comentário da 433), ⋮ e botão direito.
4. Arquivos financeiros: renomear, duplo clique abre.

### C6. Detalhes
1. "Como usar esta aba" (P2 preservada).
2. Observações: adicionar, editar, excluir (janela) [grava: Cancelar].
3. Histórico do sistema (audit_log).

### C7. Documentos (pasta 1170)
1. Explorador: Nova pasta, Anexar, arrastar e soltar, Mover para…, Editar…, ordem manual, Copiar link, lixeira e restauração [grava: em pasta de teste].
2. Visor: abrir, setas, baixar.
3. Checklist (1170/1171): marcar, Sugerir, modelos (com a P0-5, conferir quem pode renomear e excluir modelo).
4. Documentos sugeridos.

### C8. Push
1. Lista, filtros, "Marcar como lida", teor DJEN no acordeão, "Ver todas no módulo".
2. Análise IA: interna do escritório, recolher, ⋮, excluir [grava: Cancelar].

### C9. BLUEJUS IA e globais
1. Drawer: agentes, polling, auditoria, Esc fecha (e camada, A6).
2. Janela de confirmação: foco inicial no Cancelar, moldura de alerta, Esc.
3. X automático e arraste nos menus e janelas.
4. `#aba` e `#pasta-msg-N` na URL.
5. Tema escuro.

## D. Novidades do desenho de 16/10 (só depois das entregas P1-4 a P1-7)

1. Prazos e audiências: "+", janela (Prazo/Audiência, descrição, data, hora, responsável escolhido da lista), Esc fecha e Enter salva, cumprir com Desfazer, editar, excluir com janela vermelha, selos "Hoje"/"Faltam"/"Vencido", até 8 itens, contagem total.
2. Agregação: prazo do Push e data escrita num registro entram na lista, com "estimado: conferir" quando estimado.
3. Réu: aba no Cadastro, documento com dígito verificador, duplicado avisado, remover com janela vermelha; coluna Réu na Lista do Expediente.
