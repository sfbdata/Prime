# Minhas Metas: esteira da pasta, despacho e aprendizado (desenho de 22/10/2026)

Frente `metas-esteira`, aberta em 22/10/2026 a partir de `origin/master`. Desenho: `docs/design/minhas-metas/vs-22-10-26/`
(`04 - Minhas Metas 22.10.26.dc.html` e `04B - Meta 22.10.26.dc.html`, importados do Claude Design). Risco: BAIXO pelo
CLAUDE.md, tratado como MÉDIO porque mexe no modelo da meta, em prazo e em texto de valor jurídico (assinatura).

## Estado da frente

- 22/10: inventário do desenho e do código feito (seções 1 e 2). Nada implementado ainda.
- 22/10: o dono decidiu D1 a D4 (repassado pelo coordenador, bluejus-72). Migration da Fase 1: 149900.
- Em andamento: Fase 1 (seção 4).

### Decisões do dono (22/10)

- **D1 = (b).** O despacho mostra "Registrado por FULANO (Cargo) em DD/MM/AAAA às HH:MM · Cód. X", e o código é conferível
  por um link de validação. Não se escreve "assinado eletronicamente".
- **D2 = proposta.** Configura caixa o usuário primário e quem administra perfis.
- **D3 = proposta.** Concluir e passar adiante: responsável da etapa ou criador. Devolver: só o responsável. Prazo: só o
  criador, como hoje.
- **D4 = proposta.** Chave "Sugestões a partir dos meus despachos" no Meu Perfil, por usuário e ligada por padrão,
  com o botão "Apagar o que foi aprendido".

## 1. O que o desenho de 22/10 traz de novo

O desenho troca o modelo da meta. Hoje a meta é uma `Tarefa` com 3 status (pendente, em revisão, concluída). No desenho, a
meta é **a passagem da pasta por uma etapa** de uma esteira de 8 caixas, e concluir uma etapa cria a meta da próxima.

| Chave | Etapa | Setor | Descrição (texto do desenho) |
|---|---|---|---|
| com | Comercial | Comercial | Contrato assinado e honorários definidos |
| abe | Abertura e relatório | Atendimento | Pasta aberta, relato inicial e objetivo do cliente |
| ctl | Controladoria | Controladoria | Conferir pasta, cadastro e listar pendências |
| doc | Documentação | Secretaria | Reunir documentos e procuração que faltam |
| jur | Jurídico | Advogado(a) | Análise, estratégia e elaboração da peça ou parecer |
| pro | Protocolo | Advogado(a) | Ajuizar, protocolar requerimento ou entregar parecer |
| aco | Acompanhamento | Advogado(a) | Prazos, publicações, audiências e diligências |
| enc | Encerramento | Controladoria | Resultado, prestação de contas e arquivamento |

Sequência por tipo de demanda: Judicial e Administrativo/Previdenciário passam pelas 8; Consultoria pula Documentação e
Acompanhamento.

### 1.1 Funcionalidades ocultas (só aparecem por clique, hover, condição ou dado guardado)

1. **Aprendizado dos despachos** (04B L145, L151-156). Cada "Despachar" grava `{usuário, etapa, natureza, texto, dias
   úteis do prazo}`. Com o campo vazio, a tela sugere as 3 frases mais usadas pelo usuário naquela etapa (filtradas pela
   natureza marcada), pré-seleciona a natureza preferida e troca o prazo padrão pela mediana dos prazos dele (mínimo de 2
   amostras). Dica: "Aprendido com seus despachos nesta etapa · prazo usual: N dias úteis" ou "Sugestões dos seus
   despachos anteriores". Por usuário; sem liga/desliga no desenho.
2. **Aprendizado das pendências** (04B L157-158, L173). Ao passar a etapa adiante, grava as pendências por usuário e etapa
   destino; o modal mostra "Você costuma pedir:" com as 5 linhas mais frequentes como chips "+ linha".
3. **Ciência automática** (04B L117). Abrir a meta marca como "Ciente · Fulano, data" cada despacho de outra pessoa.
4. **Certificar cumprimento** num despacho: "Certifico que ..." vira certidão com nome e data; some o "Cumprir até".
5. **Modal "Configurar caixa"**: abre pelo ⋮ (só no hover), por duplo clique ou botão direito em "CAIXA N". Renomeia caixa
   e etapa e cadastra quem é avisado quando chega demanda (@menções). "Restaurar padrão" volta tudo.
6. **Gargalo**: selo na caixa com mais metas atrasadas.
7. **Etapas saltadas**: no trilho da meta ficam riscadas, tracejadas em vermelho, com "N etapa(s) saltada(s): X, Y".
8. **Concluir etapa com volta/salta**: o ▾ do "PRÓXIMO PASSO" lista as outras etapas com "salta"/"volta" e avisos
   "Saltando: ..." e "Voltando para X ...".
9. **Ctrl+Enter** no despacho = Despachar. **Extrato** imprime a tramitação em PDF.
10. **Lembrar**: envia lembrete a quem está com a meta e registra no histórico.

### 1.2 Prazo e dias úteis

- **Despacho:** prazo padrão de **5 dias úteis** a partir de hoje, com "N dias úteis" ao lado, recalculado ao mudar a data.
  Pode ser trocado pelo prazo aprendido (1.1.1) e pode ficar vazio.
- **Concluir etapa e passar adiante:** prazo padrão de **2 dias úteis** (não 5).
- O protótipo conta só segunda a sexta. O sistema conta com a regra única do escritório (`DiasUteisDoEscritorio`: feriado
  nacional, estadual da UF e recesso). Desenho omisso sobre feriado, então vale a convenção.
- Isto **não** é a "sugestão de prazo legal" travada na `meta-analise-do-prazo.md`: é prazo interno de cumprimento do
  despacho, sem cálculo de CPC nem rótulo de I.A.

## 2. Desenho × sistema hoje

Legenda: ✓ existe · ≈ parcial · ∅ não existe.

| Item | Hoje | Onde |
|---|---|---|
| Cartões (atrasadas, 7 dias, revisão, sem prazo) | ✓ | `TarefaRepository::contarPainelMinhasMetas`, `minhas.html.twig` L46-68 |
| Abas, busca, limpar filtros | ✓ | `AbaMetas`, `TarefaRepository::aplicarFiltrosDaTela` |
| Filtro de prioridade | ✓ desde o #252 (21/10): `PrioridadeDaMeta` na meta, herdada da pasta (decisão da coordenação) | `Tarefa/Enum/PrioridadeDaMeta.php` |
| Seu andamento, Precisa de atenção, Atualizado às | ✓ | `_resultado.html.twig` |
| Esteira de 8 caixas, contadores, gargalo, filtro "Só a etapa X" | ∅ | |
| Configurar caixa e avisados | ∅ (menções da pasta são reaproveitáveis) | `Pasta/Service/MencoesDoRegistro.php` |
| Chip "i/8 · Etapa" e "→ próximo" na linha | ∅ | |
| "Concluir etapa" na lista | ∅ (só concluir a meta inteira) | |
| Título "Meta # N" | ≈ mostra o id global, não o número do escritório | `tarefa/show.html.twig` L3, L25 |
| Trilho "ETAPA DA PASTA" | ∅ | |
| Aguardando revisão + Lembrar | ≈ status existe; Lembrar não (o sino da pasta avisa o responsável, 1/hora) | `AlertarResponsavelDaMetaUseCase` |
| Despacho com natureza e prazo | ∅ (mensagem livre em Quill) | `show.html.twig` L164-199 |
| @menção no despacho | ∅ | |
| Contador 5.000 | ≈ só `maxlength` no navegador; servidor não valida | `TarefaController` L354 |
| Anexar arquivo | ≈ grava em `public/uploads/tarefas/chat` (caminho público, fora da regra de chaves) | `TarefaController` L357-363 |
| Despachar / Enviar para revisão | ≈ "Só comentar" e "Enviar para revisão" | |
| Extrato | ∅ (existe só o da pasta) | `Pasta/DTO/PastaExtratoImpressaoOutput.php` |
| Linha do tempo com assinatura e código | ≈ linha do tempo existe, sem assinatura | `TarefaTimelineAssembler` |
| Ciência, certidão | ∅ | |
| Concluir e passar para X (volta/salta, responsável, prazo, pendências) | ∅ | |
| Devolver com motivo e "o que falta" | ≈ volta para pendente, sem motivo | `show.html.twig` L188-192 |
| Acompanhar | ✓ | `Tarefa::alternarAcompanhamento` |
| Alterar prazo / responsáveis | ✓ (prazo só o criador) | `AtualizarPrazoTarefaUseCase` |
| Aprendizado (despachos e pendências) | ∅ | |
| Dias úteis com feriado | ✓ no servidor, sem `somarUteis` | `Pasta/Service/DiasUteisDoEscritorio.php`, `Agenda/Service/DiasUteisNacionais.php` |

### 2.1 Configurações internas e o que está desligado

- **Liga/desliga do Master:** o recurso `metas` (`Master/Service/CatalogoDeRecursos.php` L36, L70) bloqueia só a rota
  `tarefa_minhas`. Com Metas desligado, `tarefa_show`, `/tarefas/equipe` e os POST continuam abertos. Corrigir na Fase 1.
- **Motor `AnaliseDoPrazoDaMeta`:** pronto e testado, sem tela, travado pelo dono (rótulo I.A. e prazo legal visível).
  Não entra nesta frente.
- **Enum `EstadoDoPrazoJuridico`:** sem uso. Fica com o motor.
- **Nenhum parâmetro de Metas** em `config/` ou `.env`.

### 2.2 Defeitos do protótipo que não serão copiados

- Devolver grava o novo responsável no estado errado: os responsáveis não mudam. No sistema, a meta volta para quem enviou.
- "Concluir etapa" aparece em meta já concluída. No sistema, só em meta aberta.
- "Seu andamento · últimos 30 dias" conta o escritório inteiro e sem janela. No sistema, continua como hoje (30 dias, do usuário).
- Nome da etapa renomeada só muda na esteira. No sistema, vale em toda a tela (chip, modais, trilho, avisos).
- O modal da meta (04B) não avisa os seguidores da caixa destino; o da lista (04) avisa. No sistema, avisa nos dois.
- "Ela recebeu o aviso" fixo no toast de devolução: sem gênero no texto.
- O motivo da volta de etapa "deve ir nas pendências", mas não é exigido. No sistema, exigido ao voltar.
- Tipos de notificação `meta`, `lembrete` e `devolucao` não existem na Central: mapear para os do `NotificacaoService`.

## 3. Decisões

Por convenção (desenho omisso), registradas aqui:

- **C1.** Dias úteis pela regra única do escritório (com feriados), não seg-sex.
- **C2.** Aprendizado guardado no servidor, por usuário e escritório (o protótipo usa o navegador). Sem rótulo "I.A.".
- **C3.** A @menção do despacho usa o mesmo mecanismo das menções da pasta e avisa na Central.
- **C4.** Anexo do despacho entra nos documentos da pasta pela regra de chaves (`armazenamento-de-arquivos`).
- **C5.** Etapa das metas antigas: deduzida pelo título, com a mesma regra do desenho (`faseDe`, 04 L151); o que não casar
  vai para Jurídico. Metas concluídas também recebem etapa, para a esteira contar certo.

Para o dono (Farlei), antes da fase indicada:

- **D1 (Fase 2). Assinatura eletrônica.** O desenho mostra "Assinado eletronicamente por FULANO (Advogado) em ... · Cód.
  7150.6463". Hoje o sistema não assina nada: o código é só um número. Escrever "assinado eletronicamente" sem assinatura
  de fato é o mesmo problema do login que promete criptografia. Opções: (a) como no desenho, sob responsabilidade do P.O.;
  (b) "Registrado por FULANO em ... · Cód." com o código conferível por um link de validação.
- **D2 (Fase 1). Quem configura caixa.** O dado é do escritório inteiro. Proposta: usuário primário e quem tem permissão de
  administrar perfis.
- **D3 (Fase 3). Quem conclui, devolve e altera prazo.** Proposta: concluir e passar adiante, o responsável da etapa ou o
  criador; devolver, só o responsável (não o próprio criador); prazo, como hoje (só o criador).
- **D4 (Fase 4). Liga/desliga do aprendizado.** O desenho não tem. Proposta: chave "Sugestões a partir dos meus despachos"
  no Meu Perfil, ligada por padrão, com "Apagar o que foi aprendido".

## 4. Fases (um PR por fase, uma migration por vez)

1. **Esteira.** Enum `EtapaDaPasta` e `TipoDeDemanda`; `Tarefa` ganha etapa, tipo de demanda e etapas saltadas;
   `ConfiguracaoDaCaixa` (tenant, etapa, nome da caixa, nome da etapa) e avisados; backfill C5. Tela: esteira com
   contadores, atrasadas, gargalo, "Só a etapa X", modal Configurar caixa; chip "i/8 · Etapa" e "→ próximo" na linha.
   Fecha o furo do recurso `metas` nas outras rotas. Migration: número pedido ao coordenador (próxima livre em 22/10:
   149900).
2. **Despacho.** `TarefaMensagem` ganha natureza, prazo de cumprimento, ciência, certidão e código; validação de 5.000 no
   servidor; prazo padrão de 5 dias úteis; @menção; anexo pela regra de chaves; Ctrl+Enter; linha do tempo nova com
   assinatura (D1); Extrato; Lembrar; título "Meta # N" pelo número do escritório.
3. **Passagem de etapa.** Trilho "ETAPA DA PASTA"; modal Concluir etapa e passar adiante (próxima, volta, salta,
   responsável, prazo de 2 dias úteis, pendências), criando a meta da próxima etapa e avisando responsável e avisados da
   caixa; "Concluir etapa" na lista; Devolver com motivo e "o que falta".
4. **Aprendizado.** Tabelas de registro de despachos e de pendências por usuário; sugestões de frase, natureza, prazo usual
   e "Você costuma pedir"; liga/desliga (D4).

Cada fase: UseCase e testes primeiro (unit e functional), revisão pelo `feature-review-agent`, item a item do desenho com
✓/✗ no PR.
