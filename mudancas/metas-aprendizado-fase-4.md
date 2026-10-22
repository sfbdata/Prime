---
tipo: novidade
---
## Changelog
- Aprendizado das Metas, Fase 4 do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, 1.1 itens 1 e 2, C2 e D4):
  - **Despacho:** com o campo vazio, a página da meta sugere as 3 frases que a pessoa mais usa naquela etapa (filtradas pela natureza marcada), já marca a natureza preferida e troca o prazo padrão pelo prazo usual dela (mínimo de 2 despachos).
  - **Passagem de etapa:** o modal da meta mostra "Você costuma pedir:" com as 5 pendências mais frequentes da pessoa para a etapa destino.
  - **Meu Perfil:** chave "Sugestões a partir dos meus despachos" (ligada por padrão) e "Apagar o que foi aprendido". Desligada, nada é gravado nem sugerido.
  - O aprendizado fica no servidor, por pessoa e escritório, e nunca é compartilhado com colegas.
- Migration `Version20261020150400`: tabelas `tarefa_aprendizado_despacho` e `tarefa_aprendizado_pendencia`.

## Para o cliente
- A meta aprende com os seus despachos: sugere as frases que você mais usa, a natureza e o prazo de costume, e lembra as pendências que você costuma pedir. Dá para desligar e apagar no Meu Perfil.
