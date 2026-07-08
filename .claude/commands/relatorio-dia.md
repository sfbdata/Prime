---
description: Analisa os commits do dia (lendo os diffs de verdade) e monta um relatório pronto para colar no WhatsApp, em linguagem simples para o chefe (não-T.I.), profissional, concreto e detalhado — refletindo o tamanho real do dia. Use ao pedir "relatório do dia", "recap do dia", "o que fiz hoje pro chefe" ou similar. Aceita uma data opcional (ex.: /relatorio-dia 19/06/2026).
---

Gera o **relatório do dia** a partir dos commits do git, traduzido para
linguagem simples e pronto para o humano **copiar e colar no WhatsApp** e
enviar ao chefe — que **não é da área de T.I.**. O tom é profissional e
confiável, e o relatório deve ser **concreto e detalhado** — mostrar o
tamanho real do dia, com os detalhes do que mudou na prática — **sem jargão
técnico** e sem parágrafos longos. O inimigo a evitar é o relatório **raso**,
que faz um dia cheio parecer pouca coisa. Para este relatório você (orquestrador)
só **lê** o git — ele não precisa escrever nada. (Commit local é permitido no
fluxo geral do projeto; operações remotas e sensíveis — push/merge/rebase/reset —
permanecem proibidas.)

## Passo a passo

1. **Descobrir a data alvo.**
   - Sem argumento → hoje. Liste com:
     ```bash
     git log --since=midnight --pretty=format:"%h%x09%s" --reverse
     ```
   - Com argumento de data (ex.: `19/06/2026`) → use a faixa daquele dia:
     ```bash
     git log --since="AAAA-MM-DD 00:00" --until="AAAA-MM-DD 23:59:59" \
       --pretty=format:"%h%x09%s" --reverse
     ```
   - Se não houver commit no dia, diga isso de forma simples e não invente nada.

2. **Abrir o diff de cada commit — sempre.** Nunca escreva um item só a partir
   do título: ele esconde o tamanho real da mudança. Abra o diff (`git show
   <hash>` — leitura, permitido) e leia o que importa em **todas** as camadas
   tocadas (template, controller, use case), não só uma. De cada commit, extraia
   os detalhes concretos que têm valor para quem usa o sistema:
   - **O que mudou na prática** e, quando fizer sentido, o **antes → agora**
     ("antes subia tudo de uma vez; agora vai um por um com barra de progresso").
   - **Regras e limites** que o usuário percebe ("só o autor edita", "apenas nas
     primeiras 24h", "separado em abas Pessoais e Gestão").
   - **Tratamento de erro / robustez** ("se um arquivo falha, os outros
     continuam; no fim mostra um resumo").
   - **Onde aparece** ("na lista do computador e nos cartões do celular").
   Um commit grande (muitos arquivos, novas telas/ações) rende um tema com
   **vários sub-bullets**; um ajuste pequeno rende uma linha. O relatório
   descreve benefícios e comportamento, nunca código.

3. **Agrupar por tema, não por commit.** Junte commits relacionados num só item,
   funda merges/duplicatas e **remova redundância**. O leitor quer saber "o que
   mudou para mim", não a lista crua de commits.

4. **Separar o que é visível do que é bastidor.** Mudanças de infraestrutura,
   deploy, testes, documentação e refatoração interna geralmente **não têm
   impacto visível** para o usuário final — agrupe-as num bloco curto de
   "Bastidores (sem impacto visível)", ou omita se forem irrelevantes para o
   chefe. Não encha o relatório com detalhe técnico.

5. **Escrever no formato abaixo** e entregar dentro de um único bloco de código,
   para o humano copiar inteiro de uma vez.

## Formato de saída

Entregue **sempre** dentro de um bloco ```` ``` ```` (para copiar de uma vez).
Use a formatação que o **WhatsApp entende**: negrito com `*asteriscos simples*`
(nunca `**duplos**`), sem títulos markdown (`#`), sem tabelas. Estrutura:

```
📋 *Relatório do Dia — DD de Mês de AAAA*

Resumo do que foi feito no sistema hoje:

<emoji> *Tema*
• *Destaque:* explicação simples do que mudou e por que é útil no dia a dia.
• ...

<emoji> *Outro tema*
• ...

🛠️ *Bastidores* (sem impacto visível)
• Itens internos (deploy, testes, organização) — curtos, só quando relevantes.

⏳ *Pendências em aberto*   ← inclua só se houver
• 🔴 *Urgente:* ...   (use 🔴 para o que precisa de atenção imediata)
• ...

✅ *Resumo:* uma frase fechando o dia — o que avançou e o que fica pendente.
```

## Tom e linguagem

- **Simples e humano**, como explicando a um cliente o que ele encontra de
  diferente no sistema. Zero jargão (nada de "endpoint", "migration", "deploy",
  "CSRF", "refactor"). Traduza: "modal" → "janelinha", "badge" → "etiqueta",
  "tooltip" → "balãozinho que aparece ao passar o mouse".
- **Profissional e confiável**, sem ser seco. Pode usar emojis de seção com
  parcimônia (um por tema), como no modelo.
- **Concreto e proporcional ao dia.** O relatório deve refletir o esforço real:
  um dia cheio gera um relatório encorpado. Cada tema pode ter **vários
  sub-bullets** detalhando comportamentos específicos; cada bullet começa com um
  *destaque em negrito* seguido de uma explicação curta e direta (uma a três
  linhas, sem parágrafos longos). Use o **antes → agora** para mostrar o ganho.
  Não confunda detalhe com jargão: detalhe é *o que o usuário ganha*, não *como
  foi feito*. Prefira pecar por mostrar valor a mais do que deixar o dia parecer
  raso — mas sem inventar nada além do que o diff comprova.
- **Datas em português:** "DD de Mês de AAAA" (ex.: "22 de Junho de 2026").

## Exemplo de referência (estilo aprovado)

Este é o padrão de qualidade a imitar — note o **detalhe real** extraído dos
diffs: antes → agora, regras e limites, robustez e onde aparece. Cada commit
relevante vira um tema com sub-bullets, e a abertura dá um tom do peso do dia.

```
📋 *Relatório do Dia — 24 de Junho de 2026*

Hoje o foco foi deixar o sistema mais robusto e prático no dia a dia. Resumo das melhorias:

📁 *Anexos das pastas — envio refeito*
• *Um arquivo por vez, com barra de progresso:* antes os arquivos subiam todos de uma vez e, se algo falhasse, você não sabia o quê. Agora cada arquivo é enviado individualmente, com uma barrinha mostrando o progresso em tempo real — inclusive a etapa de compactação dos arquivos maiores.
• *Mais resistente a falhas:* se um arquivo der erro, os outros continuam normalmente. No final aparece um resumo claro (ex.: "3 enviados com sucesso, 1 com erro"), em vez de perder tudo.
• *Funciona dos dois jeitos:* tanto arrastando os arquivos para a tela quanto pelo botão de envio.

📝 *Relatórios da pasta (histórico)*
• *Editar e excluir:* quem escreveu um relatório no histórico da pasta agora pode corrigi-lo ou removê-lo na hora, pelo lápis e pela lixeira. Só o próprio autor mexe, e apenas nas primeiras 24 horas — depois fica registrado de forma definitiva, preservando o histórico.
• *Marca de "(editado)":* quando um texto é alterado, fica visível que houve correção, mantendo a transparência.

🔔 *Notificações reorganizadas*
• *Separadas em abas:* "Pessoais" e "Gestão", cada uma com seu contador, para você achar mais rápido o que importa.
• *Divididas em páginas:* quando há muitas, aparecem em páginas numeradas, deixando a tela leve em vez de uma lista gigante.
• *Excluir o que não precisa mais:* agora dá para selecionar notificações e apagá-las, com uma mensagem amigável quando a lista fica vazia.

🧹 *Tela mais limpa no Expediente*
• Os botõezinhos de ação de cada item do menu do Expediente ficam escondidos e só aparecem ao passar o mouse, deixando a lista mais organizada.

🛠️ *Bastidores* (sem impacto visível)
• Todas as novidades de hoje entraram acompanhadas de testes automáticos, garantindo que continuem funcionando e que nada quebre com as próximas atualizações.

✅ *Resumo:* dia cheio e produtivo — destaque para o envio de anexos totalmente refeito (mais confiável e com progresso visível), a possibilidade de corrigir relatórios das pastas, e a reorganização das notificações em abas e páginas. Tudo testado e funcionando.
```

## Regras

- **Para este relatório, use só leitura** (`git log`, `git show`, `git diff`) — ele
  não precisa escrever. Commit local é permitido no fluxo geral, mas
  push/merge/rebase/reset seguem proibidos (só o humano).
- Não invente funcionalidades: se o commit não deixa claro o impacto, abra o
  diff para confirmar antes de descrever.
- Entregue o relatório pronto num bloco de código; depois, ofereça ajustes
  (juntar/separar dias, versão mais curta, salvar em arquivo) se o humano quiser.
