---
tipo: alteracao
---
## Changelog
- Painel do Master, bloco "Questões jurídicas": cada questão mostra só o nome dos documentos afetados. Um bloco único no fim da página lista os 4 documentos (Política de Privacidade, Termos de Uso, rascunho da política da IA e texto do aceite), cada um com baixar e enviar, e avisa que cada envio substitui o documento em todas as questões. O envio pelo bloco não vincula a versão a uma questão.
- Catálogo de questões jurídicas: campo opcional `oculta` no JSON. Questão oculta some da listagem, da contagem de "em aberto" e do aviso do topo, sem apagar o texto e sem renumerar as demais. A questão `retencao_zero` (2. Retenção zero) foi ocultada.
- Aviso do topo "Questões jurídicas a serem verificadas": some depois do clique em "ver as questões" e só volta quando surge uma questão em aberto que o Master ainda não viu (guardado no navegador, por usuário).

## Para o cliente
