---
description: Analisa os commits do dia e monta um relatório pronto para colar no WhatsApp, em linguagem simples para o chefe (não-T.I.), profissional, detalhado mas enxuto. Use ao pedir "relatório do dia", "recap do dia", "o que fiz hoje pro chefe" ou similar. Aceita uma data opcional (ex.: /relatorio-dia 19/06/2026).
---

Gera o **relatório do dia** a partir dos commits do git, traduzido para
linguagem simples e pronto para o humano **copiar e colar no WhatsApp** e
enviar ao chefe — que **não é da área de T.I.**. O tom é profissional e
confiável, detalhado o suficiente para mostrar valor, mas **sem ser extenso**
nem usar jargão técnico. Você (orquestrador) só **lê** o git; nunca executa
git de escrita.

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

2. **Entender de verdade cada mudança.** O assunto do commit às vezes é técnico
   ou vago. Quando não der para descrever o impacto real só pelo título, abra o
   diff (`git show <hash>` — leitura, permitido) para entender o que muda **na
   prática para quem usa o sistema**. O relatório descreve benefícios, não código.

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
- **Detalhado, mas enxuto.** Mire em poucos temas e bullets diretos; cada bullet
  começa com um *destaque em negrito* e uma explicação curta. Evite parágrafos
  longos. Se o dia teve muita coisa, priorize o que importa ao chefe.
- **Datas em português:** "DD de Mês de AAAA" (ex.: "22 de Junho de 2026").

## Exemplo de referência (estilo aprovado)

Este é o padrão de qualidade a imitar:

```
📋 *Relatório do Dia — 22 de Junho de 2026*

Resumo do que foi melhorado no sistema hoje:

📌 *Metas e tarefas*
• *Mudar prazo de uma meta:* quem criou a meta agora pode alterar a data (ou remover) na tela da tarefa, clicando num lápis. Só o criador mexe; os outros apenas visualizam.

📁 *Pastas e processos*
• *Observações:* o texto agora aparece exatamente como foi digitado, sem formatação estranha.
• *Vincular processo:* a tela atualiza sozinha; se algo falhar, recarrega para não perder nada.

🔔 *Notificações*
• O painel do sino não fecha mais sozinho ao clicar nas abas ou marcar como lido — fica aberto até você terminar.

📊 *Tabelas mais organizadas*
• *Processos:* colunas alinhadas; textos longos cortados com "…".
• *Todas as tabelas:* ao passar o mouse sobre um texto cortado, aparece o conteúdo completo num balãozinho.

📎 *Anexos*
• O sistema agora aceita envio de arquivos do Word (.doc e .docx).

🛠️ *Bastidores* (sem impacto visível)
• Página de manutenção mais amigável durante as atualizações.

✅ *Resumo:* dia produtivo, com melhorias no dia a dia (prazos, notificações, tabelas) e a novidade do envio de documentos do Word. Tudo testado e funcionando.
```

## Regras

- **Nunca execute git de escrita.** Só leitura (`git log`, `git show`, `git diff`).
- Não invente funcionalidades: se o commit não deixa claro o impacto, abra o
  diff para confirmar antes de descrever.
- Entregue o relatório pronto num bloco de código; depois, ofereça ajustes
  (juntar/separar dias, versão mais curta, salvar em arquivo) se o humano quiser.
