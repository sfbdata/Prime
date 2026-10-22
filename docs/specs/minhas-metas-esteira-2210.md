# Minhas Metas: esteira da pasta, despacho e aprendizado (desenho de 22/10/2026)

Frente `metas-esteira`, aberta em 22/10/2026 a partir de `origin/master`. Desenho: `docs/design/minhas-metas/vs-22-10-26/`
(`04 - Minhas Metas 22.10.26.dc.html` e `04B - Meta 22.10.26.dc.html`, importados do Claude Design). Risco: BAIXO pelo
CLAUDE.md, tratado como MÉDIO porque mexe no modelo da meta, em prazo e em texto de valor jurídico (assinatura).

## Estado da frente

- 22/10: inventário do desenho e do código feito (seções 1 e 2). Nada implementado ainda.
- 22/10: o dono decidiu D1 a D4 (repassado pelo coordenador, bluejus-72). Migration da Fase 1: 149900.
- 22/10: Fase 1 implementada na branch `metas-esteira` (esteira, caixas configuráveis, etapa no cartão; #298).
  Arrastar meta entre caixas não entra (seção 3.2). Ficam para a Fase 3: as "etapas saltadas" da meta e o AVISO aos marcados na caixa. No protótipo o aviso sai
  na passagem de etapa (04 L180-181), que é a Fase 3; até lá a caixa guarda quem será avisado, mas ninguém recebe
  nada. A esteira e a lista por etapa deixam de fora metas de pasta excluída (lápide), como o Dashboard.
  Avisado que saiu do escritório continua gravado na caixa até o próximo "Salvar" (o modal só oferece a equipe ativa).
- 22/10: Fase 2a (#302) e 2b (#305) no master. Fase 3 implementada na branch `metas-passagem` (migration 150200).
- 22/10: o dono exige a aparência EXATAMENTE igual ao desenho, com os componentes do desenho e nunca os do sistema.
  A auditoria do que já entrou (#298, #302, #305) virou a lista do PR de acerto visual, que vem antes da Fase 4.
  O que o desenho não tem sai das telas de Metas: traço vermelho do título, migalha, alternância cartões/lista,
  menu ⋮ do cartão, selo "Nº", número da meta na pasta (vira o índice no grupo), contador de comentários e bloco
  "Pessoas". Na caixa ativa da esteira, os números ficam brancos (o dc os deixa quase invisíveis).
- 22/10: Fase 3 no master (#309). PR de acerto visual na branch `metas-acerto-visual`: além da lista acima, saem os
  ícones dos KPIs (decisão do dono) e o lápis de editar mensagem antiga na página da meta (o "(editado)" fica). Os
  avisados da esteira seguem com nome simples (C7). Próximo: Fase 4.

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
  `tarefa_minhas`. Com Metas desligado, `tarefa_show`, `/tarefas/equipe` e os POST continuam abertos. **Revisto na
  Fase 1:** não bloquear. Essas rotas servem também à aba Metas da pasta (Expedientes), e o recurso `metas` é o
  liga/desliga da TELA Minhas Metas (`'tela' => true`). As rotas novas da esteira (`tarefa_minhas_caixa_*`) caem
  no prefixo e desligam junto. Desligar as metas da pasta inteira seria outro recurso, decisão do dono.
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
- **C6.** Clicar numa caixa lista as metas abertas daquela etapa de TODA a equipe do escritório (texto do desenho: "ver
  as metas dela de toda a equipe"), na aba Todas; o cadeado de pasta oculta continua valendo. Os KPIs e as abas
  continuam individuais ("SUAS METAS · INDIVIDUAL").
- **C7.** Nomes dos avisados aparecem sem o "Dr./Dra." do protótipo: ele deduz o gênero pelo nome. Nome simples.
- **C8.** Duplo clique no "CAIXA N": o clique simples no rótulo espera 250 ms antes de filtrar, para o duplo abrir o modal.

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

### 3.1 Convenções da Fase 2 (desenho omisso, 22/10)

- **C9. Duas entregas.** 2a (migration 150100): natureza, prazo de cumprimento, ciência, certidão, registro com código
  (D1), validação de 5.000 no servidor, prazo padrão, "Meta # N". 2b (sem migration nova, se der): @menção, anexo do
  despacho nos documentos da pasta, Extrato em PDF, Lembrar.
- **C10. Texto do despacho.** O desenho troca o editor rico por um campo simples. O texto continua guardado como HTML
  saneado (parágrafos e quebras), para as mensagens antigas e as novas aparecerem iguais na linha do tempo.
- **C11. Natureza.** Para ciência, Para providência, Para manifestação, Para conferência, Cumpra-se (04B L159), opcional.
  O chip preenche o começo do texto só com o campo vazio, como no desenho.
- **C12. Prazo de cumprimento.** Padrão de 5 dias úteis pela regra do escritório (C1: feriados nacionais, da UF e
  recesso), opcional, nunca antes de hoje. O "N dias úteis" ao lado conta pela mesma regra (lista de dias não úteis
  enviada pela página).
- **C13. Registro (D1).** Cada despacho guarda um código de 8 dígitos (XXXX.XXXX) sorteado, único no escritório. A linha
  mostra "<Cidade>, D de mês de AAAA." e "Registrado por NOME (Cargo) em DD/MM/AAAA às HH:MM · Cód. XXXX.XXXX". O link de
  validação abre uma página **só para quem é do escritório** (logado), que confirma meta, autor, data e natureza, sem
  o texto. **Decidido pelo dono (22/10):** a validação é só para quem é do mesmo escritório e está logado; não há
  página pública.
- **C14. Ciência automática.** Abrir a meta marca "Ciente" nos despachos de outra pessoa ainda sem ciência, só quando
  quem abre é responsável ou criador da meta (quem só olha, como um administrador, não dá ciência).
  Só despachos registrados (com código) e fora de pasta excluída; a primeira ciência vale (com dois responsáveis,
  quem abrir primeiro dá a ciência do despacho, como o desenho mostra uma só). Gravação condicional, sem sobrescrever.
- **C15. Certificar cumprimento.** Responsável ou criador; texto obrigatório começando por "Certifico que", até 1.000
  caracteres; grava quem e quando; some o "Cumprir até". Uma certidão por despacho, e só em despacho (envio, devolução
  e mensagem antiga são recusados também no servidor). Ciência e certidão são gravadas por UPDATE condicional: quem e
  quando ficam na própria linha do despacho, não no log de auditoria.
- **C16. Registro imutável.** Despacho com código não pode ser editado (o "Registrado por ... · Cód." deixaria de valer
  para o texto). Mensagens antigas, sem código, continuam editáveis.
- **C17. Quem registrou fica congelado.** Nome e cargo da linha de registro são os do momento do despacho, não os de
  hoje.
- **C18. Enviar para revisão** não grava natureza nem prazo (04B L146): o prazo padrão viraria "Cumprir até" na entrega.
  A mensagem guarda a ação (despacho, envio, devolução) para o rótulo do 04B L127 ("Enviado para revisão", "Devolvida")
  e para "Certificar cumprimento" aparecer só em despacho (04B L161).
- **C19. Placeholder** sem "Digite @ para marcar alguém da equipe." até a 2b trazer a @menção; "quem → para" também
  espera a 2b (menção) ou a Fase 3 (passagem).

### 3.2 Itens do desenho que faltavam (conferência do coordenador, 22/10)

Regra do dono: tudo que está no desenho entra. Arrastar meta entre caixas NÃO entra: o protótipo tem a função `mover`
(04 L168-172), mas nada a aciona.

**Fase 2a**
- L1. "Precisa de atenção" abre a pasta no Expediente, não a meta (04 L236; `_resultado.html.twig` L99).
- L2. Faixa "AGUARDANDO DESPACHO DE" / "AGUARDANDO REVISÃO DE": iniciais e nome, "N dias com a demanda" / "desde o envio
  para revisão", vermelha se atrasada (04B L50, L131-132). O botão "Lembrar" da faixa é da 2b.
- L3. Painel lateral de situação: Pendente / Aguardando revisão / Atrasada · N dia(s) / Concluída, fundo vermelho se
  atrasada, "Etapa i/n · Nome" (04B L77, L136).
- L4. Bloco lateral DETALHES DA META: prazo com "Alterar" e quadro "Atrasada · venceu em X · N dias"; "CRIADA EM ... · há
  N dias"; rótulo "DESPACHANTE" (hoje "Autor"); substitui as 4 caixas do topo (04B L85).
- L5. Seta de voltar para Minhas Metas (04B L36) e trilha "Expediente / Pasta X / Meta" (04B L39).
- L6. Linha do tempo: "DESPACHO N" numerado, "MAIS RECENTE", "Cumprir até" vermelho quando vencido (04B L71, L161).
- L7. Cmd+Enter no Mac, junto do Ctrl+Enter (04B L144). Despachar só com anexo, sem texto (04B L145).
- L8. "Enviar para revisão" sem texto grava "Meta enviada para revisão do autor." e avisa o autor (04B L146).

Convenções desta parte: "desde o envio para revisão" conta do envio mais recente gravado (o dc usa o último evento
de qualquer tipo, que mudaria com uma troca de prazo); sem envio gravado (metas antigas), da última alteração. Os
"N dias" são períodos de 24h, como no dc. "Alterar" do prazo só para o criador (D3). "Devolver" fica dentro do
formulário até a Fase 3 levá-lo para a lateral (04B L81). **Decisão do dono (22/10):** a lateral termina em DETALHES;
"Voltar à Pasta" sai (o voltar é a seta e a trilha) e "Excluir meta" deixa de existir nas telas de Metas (Minhas Metas
e página da meta), porque o desenho não tem a opção em lugar nenhum.

**Fase 2b**
- Convenções da 2b: o anexo novo fica em `arquivo_anexo` como `pasta_documento:<id>` (sem migration); o lembrete aparece
  como "Comentário", sem código de registro, como no desenho; a menção avisa com o tipo de menção da pasta e o lembrete
  com o tipo novo `tarefa_lembrete`; o Extrato é a janela de impressão do navegador (salvar em PDF por ela); juntar o
  anexo à pasta exige só o acesso à meta, como o anexo já exigia; os tokens de menção contam nos 5.000 caracteres.
  O cadeado da pasta já valia dentro da meta (`BarreiraDoCadeadoListener`, 403 na leitura); a guarda da meta
  (`AcessoAMeta`) passa a conferir também, como segunda camada, na página, no Extrato, no Lembrar, no despacho e na
  certidão. O limite de 1 lembrete por hora tem trava por meta. O Extrato
  mostra só o que o 04B L150 mostra ("Ciente: nome", sem hora; sem a linha do anexo; sem botão Imprimir).
- L9. Extrato: Demanda no topo; cada despacho numerado com natureza, quem → para, Cumprir até, Ciente, certidão;
  "Emitido em"; aviso "Permita pop-ups para gerar o extrato." se bloqueado (04B L150).
- L10. Lembrar grava "Lembrete enviado para X, Y." no histórico; em revisão, avisa o autor (04B L115, L133).

**Fase 3**
- L11. Modal Concluir etapa: TIPO DE DEMANDA (Judicial / Administrativo-Previdenciário / Consultoria), que muda a
  sequência (04 L131, L196); "O QUE FOI FEITO" ("Resumo para o registro da pasta"); faixa da sequência (atual verde,
  próxima azul, passadas cinza) e PRÓXIMO PASSO com a descrição da etapa (L197).
- L12. Seletor de responsável com iniciais/foto, ordenado pelo setor da etapa destino (04 L195).
- L13. Menu salta/volta rola até a próxima etapa e tem separador (04B L101, L169).
- L14. Passagem: nova meta com título "Etapa: primeira linha das pendências" (60 caracteres, 04 L175); herda prioridade
  e etapas saltadas; histórico "X concluída → Y (Resp)" e "Pendências: ..." (L178). Linha de registro também em "Etapa
  concluída" (04B L161).
- L15. Devolução: motivos fixos (Faltam informações / Falta documento / Não é da minha competência / Prazo inviável /
  Outro motivo); erros "Escolha o motivo da devolução." e "Explique o que falta..." (mínimo 5); texto "A meta volta
  para quem enviou... A etapa da pasta não muda." (04B L166-167).

Convenções e decisões da Fase 3 (22/10):
- Cada tela tem o modal do seu desenho: a página da meta usa o do 04B (L92-102, com menu salta/volta e sem tipo,
  faixa e "o que foi feito"); a lista usa o do 04 (L131, com tipo, faixa, foto no seletor e sem menu). O servidor é o
  mesmo. "Você costuma pedir" (04B L99) é da Fase 4.
- **Decisão do dono:** o Devolver fica só na lateral, para o responsável que não criou a meta, com a meta pendente.
  Em revisão, o despacho (texto ou anexo) de quem criou devolve a meta para pendente, grava a ação "devolução" com
  código e avisa os responsáveis; o despacho de outra pessoa só registra.
- **Decisão do dono:** a regra do D3 vale também para o "Concluir" (e o Desfazer) da aba Metas da pasta. Só o
  responsável ou quem criou; o botão some para os outros. O "Reabrir" da mesma aba segue a mesma regra.
- **Decisão do dono:** reabrir uma meta concluída pela passagem (inclusive a que encerrou a pasta) é bloqueado com
  "Esta meta foi concluída na passagem de etapa e não pode ser reaberta."; para corrigir, usa-se o "voltar etapa".
  O marcador é o ato "Etapa concluída" que a passagem grava (sem coluna nova).
- **Decisão do dono:** ao voltar de etapa, a meta nova herda todas as etapas saltadas, como no desenho (04B L175),
  inclusive as que ficam depois do destino (`RotaDaPassagem::herdadasQueFicam`).
- A passagem e a devolução travam a meta (lock pessimista) e conferem de novo o status dentro da transação: duas
  passagens ao mesmo tempo não criam duas metas.
- A descrição da meta nova é o "o que foi feito" mais as pendências (04 L178). Ao encerrar a pasta não há meta nova, e
  o "o que foi feito" fica no histórico da meta concluída. Sem responsável, o histórico mostra o setor da etapa destino.
- Avisos com os tipos que já existem: `tarefa_criada` na passagem e `tarefa_pendente` na devolução. Quem passa não é
  avisado. O toast da devolução só diz que avisou quando avisou, e sem gênero.
- Os botões da passagem e da devolução não aparecem em pasta excluída (lápide).

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
