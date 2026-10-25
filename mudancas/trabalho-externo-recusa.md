---
tipo: novidade
---
## Changelog
- Trabalho Externo (desenho 06 de 24/10, D11b, PR 3): botão "Recusar" ao lado de "Aceitar oferta" na oferta com tempo, com a confirmação da própria tela ("Recusar esta oferta? O escritório e a Central BlueJus serão avisados."). A recusa fica gravada por vaga e pessoa, entra no histórico como "Oferta recusada por {quem}" e avisa no sino o dono da vaga (sem o nome de quem recusou) e os Master da Central (com o nome; tipo novo `trabalho_externo_oferta_recusada`). Depois, o botão vira "Você recusou · aceitar mesmo assim", que tira a recusa e aceita. "Ofertar novamente" não apaga a recusa.
- Trabalho Externo (D4): o profissional externo vê, junto com as abertas, as vagas expiradas há até 30 dias, com véu, carimbo EXPIRADA, faixa "Expirou em dd/mm/aaaa", selo "Expirada" e sem cronômetro, AO VIVO nem NOVA; o clique mostra o aviso "Vaga expirada" em vez de abrir. A vaga expirada há mais de 30 dias sai da lista de todos, inclusive da Central (o dado continua no banco).
- Rotas novas: `POST /trabalho-externo/api/vagas/{codigo}/recusa` e `.../recusa/desfazer`.
- Migration `Version20261024150600`: tabela `trabalho_externo_vaga_recusa` (única por vaga e usuário) e `trabalho_externo_vaga.encerrada_em`, gravada em "Encerrar vaga", "Finalizar oferta" e no fim da divulgação (reabrir zera), com o backfill das encerradas pelo histórico.

## Para o cliente
- No Trabalho Externo, o profissional pode recusar uma oferta com um clique, e o escritório e a Central BlueJus ficam sabendo. As vagas que expiraram há pouco continuam visíveis, marcadas como expiradas; as de mais de 30 dias saem da lista.
