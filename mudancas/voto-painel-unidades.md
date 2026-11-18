---
tipo: novidade
---
## Changelog
- Voto: a aba Acompanhar ganhou o botão "Unidades", ao lado de "Assistir". Abre uma janela com todas as unidades da pauta (as que ela aceita, em ordem de bloco e identificação), que se atualiza a cada 5 segundos. A unidade que votou fica com fundo azul claro e letra azul escura; as que ainda não votaram ficam brancas e as inaptas, cinza. Mostra só a identificação da unidade (CPF mascarado, como na ata), nunca o nome, o voto ou a hora. Só aparece, e a rota só abre, para quem pode ver a relação de votantes, em assembleias com lista de votantes (condomínio, associação, sociedade); as demais dão 404. A regra de como a unidade é escrita passou para `IdentificacaoDaUnidade`, usada também pela ata (sem mudança nela).

## Para o cliente
- No Voto, a aba Acompanhar ganhou o botão Unidades: um painel com todas as unidades da pauta, que fica azul quando a unidade vota. Dá para deixar numa tela da assembleia e ver, ao vivo, quem já votou e quem ainda falta. O painel mostra só o número ou nome da unidade, nunca quem votou em quê. Aparece só para quem pode ver a lista de votantes.
