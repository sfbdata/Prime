# SPEC — Reorganização rápida da página de cobrança

> **Tipo:** ajuste de UX sobre o sistema atual  
> **Timebox:** 1 dia de trabalho  
> **Referência:** montagem enviada pelo dono com a orientação “Sugestão de organização das atividades”  
> **Objetivo:** facilitar o uso por pessoas com pouca habilidade tecnológica, sem reconstruir o domínio

> ### ⚠️ Estado da entrega — leia antes de usar esta SPEC como verdade
>
> Executada em 2026-07-26 na branch `feature/cobranca-ux-rapida` (**nada publicado**).
> **Três itens desta SPEC ficaram deliberadamente de fora**, cada um por um impedimento medido:
> o botão de **link** na barra (§11.1), o **editor rico no relato do contato** (§11/§12) e, do delta
> posterior, o **modal de pessoa com qualificação e contatos**.
>
> A SPEC abaixo é o que foi **pedido**, não o que foi **entregue**. O que de fato entrou, o que ficou
> fora e por quê, mais o ambiente da frente: **[HANDOFF_UX_RAPIDA.md](HANDOFF_UX_RAPIDA.md)**.
> O bug de homônimos que afetava a troca de responsável foi **corrigido em 2026-07-26** (com uma
> limitação de rótulo que o handoff descreve e o dono precisa avaliar).

## 1. Objetivo

Reorganizar a página atual de cobrança para que o usuário consiga:

- compreender a situação da unidade ao abrir a página;
- registrar uma anotação imediatamente;
- localizar as principais funções sem procurar em várias áreas;
- registrar contato, consultar pessoas, dívida, honorários, documentos e histórico;
- editar e excluir anotações;
- utilizar formatação nos campos narrativos;
- executar o trabalho com poucos cliques.

O sistema já possui as funcionalidades necessárias. Esta entrega deve apenas apresentá-las de forma
mais clara, previsível e acessível.

## 2. Restrição principal

Esta entrega possui timebox máximo de um dia.

O timebox é uma regra de corte de escopo. Se uma melhoria exigir alteração estrutural, migration,
novo fluxo de negócio ou refatoração ampla, ela fica fora desta entrega.

## 3. Fonte de verdade

Esta SPEC é independente da frente canônica de cobrança.

Para esta entrega:

- o código atual da `master` define as funcionalidades existentes;
- esta SPEC define somente a reorganização da experiência;
- a imagem do dono é referência de intenção, não layout para reprodução literal;
- a SPEC canônica e sua branch permanecem congeladas;
- nenhuma decisão estrutural da frente canônica deve ser antecipada aqui.

## 4. Problema

A página atual possui as funções necessárias, mas exige familiaridade com o sistema para encontrá-las
e entender a sequência do trabalho.

O usuário precisa identificar rapidamente:

1. qual unidade está aberta;
2. qual é a situação financeira resumida;
3. onde registrar o que aconteceu;
4. onde registrar um contato;
5. onde consultar pessoas, dívida, honorários, documentos e histórico;
6. onde executar ações menos frequentes.

## 5. Princípios de UX

1. A anotação é a atividade inicial da página.
2. Funções importantes permanecem visíveis e enfileiradas.
3. Texto e ícone são usados juntos nas ações principais.
4. O usuário não precisa conhecer termos técnicos internos.
5. A aba ativa precisa ser evidente.
6. Ações destrutivas exigem confirmação.
7. O sistema apresenta feedback imediato após salvar, editar ou excluir.
8. O usuário não perde o texto digitado por erro de validação.
9. A reorganização não pode remover funcionalidades existentes.
10. A página deve continuar utilizável em telas menores.

## 6. Escopo

### 6.1 Cabeçalho

Reorganizar o cabeçalho atual para mostrar de forma compacta:

- identificação da unidade;
- carteira;
- status atual;
- total em aberto;
- total vencido;
- quantidade de obrigações vencidas;
- alertas já existentes;
- ações de situação já existentes, como `Judicializar` e `Encerrar cobrança`.

Não criar novos cálculos nem novos estados.

### 6.2 Barra de atividades

As funções devem aparecer enfileiradas em uma barra logo abaixo do cabeçalho.

Ordem inicial:

1. **Cobrança**
2. **Documentos**
3. **Histórico**
4. **Ficha completa**
5. **Trocar responsável**
6. **Registrar contato**
7. **Envolvidos**
8. **Dívida**
9. **Honorários**

Regras:

- `Dívida` e `Honorários` são opções diferentes;
- não criar uma aba separada de encargos;
- `Editar configuração de encargos` permanece como ação do contexto financeiro;
- usar os nomes já conhecidos pelos usuários quando não houver motivo claro para mudar;
- mostrar quantidade em `Documentos` e `Envolvidos` quando o dado já existir;
- não usar apenas ícone em ação importante;
- manter a opção ativa visualmente destacada;
- preservar URLs, modais, formulários e permissões existentes;
- não criar uma nova tela quando já existir modal, seção ou destino funcional.

### 6.3 Abas e ações

A barra pode reunir dois comportamentos:

#### Áreas de conteúdo

Trocam o conteúdo principal da página ou navegam para a seção existente:

- Cobrança;
- Documentos;
- Histórico;
- Ficha completa;
- Dívida;
- Honorários.

#### Ações

Abrem modal, formulário ou comportamento já existente:

- Trocar responsável;
- Registrar contato;
- Envolvidos;
- Editar configuração de encargos;
- Judicializar;
- Encerrar cobrança.

Visualmente, todas ficam organizadas na mesma região. Tecnicamente, não é necessário transformar
comandos simples em novas páginas.

### 6.4 Responsividade

Em telas onde todas as opções não couberem:

- manter visíveis `Cobrança`, `Registrar contato`, `Documentos` e `Histórico`;
- colocar opções menos frequentes em `Mais ações`;
- não permitir quebra desorganizada em várias linhas;
- preservar texto legível e área de clique adequada.

Não é necessário redesenhar todo o sistema para dispositivos móveis.

## 7. Aba Cobrança

### 7.1 Estado inicial

`Cobrança` é a aba aberta por padrão.

O primeiro elemento útil da aba é o editor de anotação.

### 7.2 Editor

Exibir:

- título ou orientação curta, como `Nova anotação`;
- editor de texto com barra de formatação;
- placeholder:
  `Anote o que foi combinado, o que o devedor disse ou qualquer contexto importante`;
- botão primário `Salvar anotação`.

O botão deve ficar próximo ao editor e ser identificável sem treinamento.

### 7.3 Salvamento

Ao salvar:

- validar conteúdo conforme a regra atual;
- impedir envio acidental duplicado;
- manter o texto se houver erro;
- apresentar confirmação clara;
- inserir a anotação na lista sem exigir que o usuário procure outra aba, quando isso puder ser
  feito reaproveitando o comportamento atual;
- limpar o editor somente após confirmação do sucesso.

Se já existir fluxo AJAX seguro, reutilizá-lo. Não criar uma arquitetura nova apenas para evitar
recarregamento.

## 8. Anotações recentes

Abaixo do editor, mostrar as anotações recentes da cobrança.

Cada item deve exibir:

- conteúdo formatado;
- autor;
- data e hora;
- indicação `Editada`, quando aplicável;
- ação `Editar`;
- ação `Excluir`.

Não é necessário trazer todo o histórico para essa área. O histórico completo continua disponível
na opção `Histórico`.

## 9. Editar anotação

### 9.1 Comportamento

Ao clicar em `Editar`:

- carregar o conteúdo existente em editor com barra de formatação;
- permitir salvar ou cancelar;
- não criar outra anotação;
- apresentar confirmação após salvar;
- indicar visualmente que a anotação foi editada, se essa informação já puder ser registrada sem
  migration.

### 9.2 Limite do timebox

Reutilizar a capacidade atual de edição, se existir.

Se não existir:

- implementar somente a alteração do conteúdo da anotação existente;
- preservar autorização e tenant;
- não criar versionamento complexo;
- não criar migration apenas para histórico de versões.

Auditoria técnica já existente deve continuar funcionando.

## 10. Excluir anotação

Ao clicar em `Excluir`:

- solicitar confirmação;
- identificar claramente qual anotação será excluída;
- executar o fluxo existente;
- exibir mensagem `Anotação excluída`;
- remover o item da área de anotações recentes;
- manter as proteções atuais de autorização, tenant e CSRF.

Não permitir exclusão acidental por clique único.

Somente anotações livres são editáveis ou excluíveis. Eventos automáticos do sistema permanecem
imutáveis.

## 11. Campos narrativos e barra de formatação

A barra de formatação é obrigatória nos campos narrativos usados nos fluxos acessíveis a partir
desta página.

Aplicar, quando existirem:

- anotação livre;
- relato de contato;
- observação de acordo;
- observação de boleto;
- descrição ou justificativa extensa.

Não aplicar editor rico a campos estruturados ou curtos:

- nome;
- CPF/CNPJ;
- telefone;
- e-mail;
- número do acordo;
- identificação da unidade;
- valores;
- datas;
- seletores;
- motivos curtos já representados por opções.

### 11.1 Barra mínima

Reaproveitar o editor já adotado pelo sistema. Manter, no mínimo:

- negrito;
- itálico;
- sublinhado;
- tachado;
- listas;
- alinhamento;
- citação;
- link;
- limpar formatação.

Não criar um novo editor nem trocar de biblioteca nesta entrega.

### 11.2 Segurança

Conteúdo rico deve:

- usar a sanitização já existente;
- impedir scripts, handlers e HTML perigoso;
- respeitar tenant e autorização;
- ser exibido de forma segura em anotações recentes e histórico.

Se a sanitização atual tiver uma falha evidente, corrigir somente o necessário para o fluxo
alterado. Não iniciar uma refatoração geral do editor.

## 12. Registrar contato

`Registrar contato` deve:

- permanecer visível na barra;
- reutilizar o modal ou formulário existente;
- preservar canal, resultado e relato;
- usar editor formatado no relato, se ele for narrativo;
- continuar gerando o evento/histórico existente;
- retornar o usuário à mesma unidade.

Não alterar resultados, métricas ou fluxo de contato.

## 13. Pessoas

As funções existentes devem ficar fáceis de localizar:

- Ficha completa;
- Trocar responsável;
- Envolvidos.

Não alterar:

- modelo de pessoa;
- regras de vínculo;
- responsável atual;
- deduplicação;
- importação;
- permissões.

## 14. Financeiro

### 14.1 Dívida

`Dívida` deve preservar a visualização financeira atual, seguindo a mesma composição apresentada
pelo relatório da contabilidade e já utilizada pelo sistema.

Na mesma área, mostrar conforme os dados existentes:

- competência ou parcela;
- vencimento;
- principal;
- juros;
- multa;
- correção;
- honorários;
- total;
- situação;
- informações do acordo, quando aplicável.

Não separar juros, multa, correção e honorários da composição da dívida. Esses valores precisam
continuar visíveis juntos para o usuário compreender como o total foi formado.

`Editar configuração de encargos` permanece acessível como ação dentro do contexto de `Dívida` ou em
menu de ações financeiras. Não será uma aba própria.

### 14.2 Honorários

`Honorários` recebe uma aba exclusiva para métricas, sem substituir a coluna de honorários exibida
na composição da dívida.

A aba deve apenas reorganizar métricas que o sistema já consegue calcular ou apresentar, como:

- honorários projetados;
- honorários realizados;
- honorários pendentes;
- base de cálculo;
- percentual ou forma configurada;
- valores por obrigação, acordo ou recebimento, quando esses dados já existirem.

Não criar uma nova regra financeira nem uma nova fonte de dados para preencher essa aba. Se uma
métrica não existir no sistema atual, ela fica fora do timebox.

### 14.3 Preservação

Preservar integralmente:

- obrigações;
- acordos;
- pagamentos;
- calculadora;
- cálculo diário;
- juros;
- multa;
- correção;
- honorários;
- configurações existentes.

Nenhuma fórmula ou regra financeira pode mudar nesta entrega.

## 15. Documentos e histórico

### 15.1 Documentos

- manter upload;
- manter download;
- manter organização e quantidade;
- preservar CSRF e autorização;
- apenas melhorar o acesso pela barra.

### 15.2 Histórico

- manter todos os eventos atuais;
- manter ordenação atual;
- exibir conteúdo formatado de forma segura;
- deixar claro que edição e exclusão se aplicam apenas às anotações livres.

Não redesenhar a linha do tempo nesta entrega.

## 16. Feedback visual

Apresentar mensagens claras para:

- anotação salva;
- anotação editada;
- anotação excluída;
- erro ao salvar;
- ação não autorizada;
- sessão ou token expirado, conforme o padrão atual.

Reutilizar alertas/toasts existentes.

Não criar novo sistema de notificações.

## 17. Acessibilidade e facilidade

- textos claros;
- contraste compatível com temas claro e escuro;
- foco visível;
- botões com rótulos;
- confirmação de exclusão compreensível;
- campos com instrução curta;
- navegação por teclado preservada;
- `Ctrl + Enter` para salvar anotação somente se for simples e não conflitar com o editor atual;
- tooltips apenas quando o texto da ação não for suficiente.

## 18. Fora do escopo

- remover ou unificar `CasoCobranca`;
- migrations estruturais;
- nova importação;
- reconciliação de relatórios;
- prescrição;
- responsável principal importado;
- novo fluxo de acordo;
- novo fluxo de boletos;
- novo dashboard;
- nova central gerencial;
- nova permissão;
- nova auditoria;
- refatoração de controllers;
- refatoração de JavaScript não necessária;
- troca do editor de texto;
- redesenho completo da página;
- mudanças no menu global;
- mudanças em outros domínios;
- alterações na frente canônica;
- merge, push ou deploy automáticos.

## 19. Critérios de aceite

### 19.1 Organização

- cabeçalho preserva o resumo atual;
- funções aparecem em uma região única e organizada;
- Dívida e Honorários aparecem separadamente;
- aba ativa é evidente;
- barra não quebra de forma desorganizada;
- funções existentes continuam acessíveis.

### 19.2 Anotação

- editor é o primeiro elemento útil de `Cobrança`;
- barra de formatação aparece;
- salvar funciona;
- erro não apaga o texto;
- anotação salva aparece entre as recentes;
- editar funciona;
- excluir exige confirmação;
- feedback de salvar, editar e excluir aparece;
- eventos automáticos não podem ser editados ou excluídos.

### 19.3 Texto rico

- campos narrativos da página e de seus modais usam o editor existente;
- conteúdo é sanitizado;
- formatação aparece corretamente;
- não existe execução de HTML perigoso;
- campos estruturados permanecem simples.

### 19.4 Regressão

- registrar contato funciona;
- trocar responsável funciona;
- envolvidos funciona;
- dívida funciona;
- honorários funciona;
- edição da configuração de encargos continua acessível no contexto financeiro;
- documentos funcionam;
- histórico funciona;
- judicializar funciona;
- encerrar cobrança funciona;
- cálculos financeiros não mudam;
- isolamento por tenant continua coberto;
- CSRF continua válido.

### 19.5 Visual

- smoke em tema claro;
- smoke em tema escuro;
- smoke em resolução desktop comum;
- barra permanece utilizável em largura menor;
- nenhuma ação fica ilegível ou inacessível.

## 20. Estratégia de execução

### 20.1 Isolamento

Criar uma nova worktree baseada na `origin/master`.

Sugestão:

- branch: `feature/cobranca-ux-rapida`;
- worktree: `.claude/worktrees/cobranca-ux-rapida`;
- banco de desenvolvimento e teste próprios;
- porta de preview diferente.

Não usar:

- branch canônica;
- banco migrado da frente canônica;
- localhost que esteja servindo outra worktree.

### 20.2 Ordem

1. confirmar baseline e ambiente;
2. mapear templates, endpoints e editor já existentes;
3. registrar lista curta do que será apenas movido/reutilizado;
4. reorganizar cabeçalho e barra;
5. colocar editor e anotações recentes na aba Cobrança;
6. implementar ou ajustar edição e exclusão;
7. aplicar editor existente aos campos narrativos acessíveis;
8. ajustar responsividade;
9. executar testes focados;
10. executar suíte;
11. fazer smoke real no navegador;
12. revisar tema claro e escuro;
13. corrigir somente regressões da entrega;
14. apresentar resultado sem merge, push ou deploy.

### 20.3 Regra contra expansão

Se surgir necessidade fora desta SPEC:

- registrar como follow-up;
- não implementar;
- continuar com o escopo de um dia.

## 21. Entrega esperada

Ao final do dia, apresentar:

- página reorganizada;
- funções existentes acessíveis;
- anotação em destaque;
- edição e exclusão funcionando;
- formatação nos campos narrativos acessíveis;
- screenshots em tema claro e escuro;
- testes executados;
- lista de arquivos modificados;
- eventuais limitações;
- branch e worktree limpas;
- nenhum merge, push ou deploy.
